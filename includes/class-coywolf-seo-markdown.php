<?php
/**
 * Tiny Markdown -> HTML renderer for the bundled readme.md, used by the
 * Documentation admin page. Deliberately minimal: it handles only the
 * constructs the readme uses (ATX headings, paragraphs, unordered/ordered
 * lists, pipe tables, and inline bold / code / links). All text is escaped
 * first and only a known set of tags is emitted, so it's safe for output even
 * though the input is our own bundled file.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coywolf_SEO_Markdown {

	/**
	 * Convert Markdown to an HTML fragment.
	 *
	 * @param string $md Markdown source.
	 * @return string HTML.
	 */
	public static function to_html( $md ) {
		// Strip HTML comments (e.g. the wporg-strip build markers) so they never
		// surface as visible text in the rendered documentation.
		$md = preg_replace( '/<!--.*?-->/s', '', (string) $md );

		$lines        = explode( "\n", str_replace( "\r\n", "\n", (string) $md ) );
		$count        = count( $lines );
		$out          = '';
		$para         = array();
		$i            = 0;
		$in_faq       = false; // Inside the "Frequently Asked Questions" section.
		$details_open = false; // A FAQ <details> block is currently open.

		while ( $i < $count ) {
			$raw  = $lines[ $i ];
			$line = trim( $raw );

			// Blank line ends a paragraph.
			if ( '' === $line ) {
				$out .= self::flush_para( $para );
				$i++;
				continue;
			}

			// ATX heading: # .. ######
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $m ) ) {
				$out  .= self::flush_para( $para );
				$level = strlen( $m[1] );
				$htext = trim( $m[2] );

				if ( 2 === $level ) {
					// A new top-level section closes any open FAQ item and decides
					// whether the following content is inside the FAQ.
					if ( $details_open ) {
						$out         .= "</details>\n";
						$details_open = false;
					}
					$in_faq = ( 'Frequently Asked Questions' === $htext );
					$out   .= '<h2>' . self::inline( $htext ) . "</h2>\n";
				} elseif ( 3 === $level && $in_faq ) {
					// Inside the FAQ each question becomes a collapsible <details>.
					if ( $details_open ) {
						$out .= "</details>\n";
					}
					// The shared name makes the questions an exclusive accordion:
					// opening one closes any other that is open.
					$out         .= '<details class="coywolf-seo-faq" name="coywolf-seo-faq">' . "\n<summary>" . self::inline( $htext ) . "</summary>\n";
					$details_open = true;
				} else {
					$out .= '<h' . $level . '>' . self::inline( $htext ) . '</h' . $level . ">\n";
				}
				$i++;
				continue;
			}

			// Pipe table: a "| … |" row immediately followed by a "|---|---|" rule.
			if ( '|' === substr( $line, 0, 1 ) && isset( $lines[ $i + 1 ] )
				&& preg_match( '/^\s*\|?[\s:|-]+\|?\s*$/', $lines[ $i + 1 ] )
				&& false !== strpos( $lines[ $i + 1 ], '-' ) ) {
				$out   .= self::flush_para( $para );
				$header = self::cells( $line );
				$i     += 2; // Skip header + separator.
				$body   = array();
				while ( $i < $count && '' !== trim( $lines[ $i ] ) && '|' === substr( trim( $lines[ $i ] ), 0, 1 ) ) {
					$body[] = self::cells( trim( $lines[ $i ] ) );
					$i++;
				}
				$out .= "<table class=\"widefat striped\">\n<thead><tr>";
				foreach ( $header as $c ) {
					$out .= '<th>' . self::inline( $c ) . '</th>';
				}
				$out .= "</tr></thead>\n<tbody>\n";
				foreach ( $body as $row ) {
					$out .= '<tr>';
					foreach ( $row as $c ) {
						$out .= '<td>' . self::inline( $c ) . '</td>';
					}
					$out .= "</tr>\n";
				}
				$out .= "</tbody>\n</table>\n";
				continue;
			}

			// List: "- "/"* " (unordered) or "1." (ordered).
			if ( preg_match( '/^([-*]|\d+\.)\s+/', $line ) ) {
				$out    .= self::flush_para( $para );
				$ordered = (bool) preg_match( '/^\d+\.\s+/', $line );
				$tag     = $ordered ? 'ol' : 'ul';
				$out    .= '<' . $tag . ">\n";
				while ( $i < $count ) {
					$lt = trim( $lines[ $i ] );
					if ( '' === $lt || ! preg_match( '/^(?:[-*]|\d+\.)\s+(.*)$/', $lt, $mm ) ) {
						break;
					}
					$out .= '<li>' . self::inline( $mm[1] ) . "</li>\n";
					$i++;
				}
				$out .= '</' . $tag . ">\n";
				continue;
			}

			// Otherwise accumulate into the current paragraph.
			$para[] = $line;
			$i++;
		}

		$out .= self::flush_para( $para );
		if ( $details_open ) {
			$out .= "</details>\n";
		}
		return $out;
	}

	/**
	 * Close an accumulated paragraph (if any) and reset the buffer.
	 *
	 * @param array $para Lines (passed by reference).
	 * @return string
	 */
	private static function flush_para( &$para ) {
		if ( empty( $para ) ) {
			return '';
		}
		$html = '<p>' . self::inline( implode( ' ', $para ) ) . "</p>\n";
		$para = array();
		return $html;
	}

	/**
	 * Split a "| a | b |" table row into trimmed cell strings.
	 *
	 * @param string $row Row text.
	 * @return array
	 */
	private static function cells( $row ) {
		$row = preg_replace( '/^\||\|$/', '', trim( $row ) );
		return array_map( 'trim', explode( '|', $row ) );
	}

	/**
	 * Render inline Markdown (bold, code, links) into safe HTML. Escapes first,
	 * then introduces the known tags, so raw HTML in the source is shown as text.
	 *
	 * @param string $text Inline Markdown.
	 * @return string
	 */
	private static function inline( $text ) {
		$text = esc_html( $text );

		// Protect `code` spans from the emphasis/link passes below: the readme
		// has `*` and `$` inside code, which they would otherwise mangle. Stash
		// each span behind a NUL placeholder, transform the rest, then restore.
		$codes = array();
		$text  = preg_replace_callback(
			'/`([^`]+)`/',
			static function ( $m ) use ( &$codes ) {
				$codes[] = $m[1];
				return "\x00C" . ( count( $codes ) - 1 ) . "\x00";
			},
			$text
		);

		// **bold** (before *italic* so the double-asterisk pairs match first).
		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );

		// *italic*
		$text = preg_replace( '/\*([^*]+)\*/', '<em>$1</em>', $text );

		// ![alt](path) — must run before the link rule, which would
		// otherwise swallow the bracketed part. $m[1] is already escaped
		// (ENT_QUOTES) by the esc_html() above, so it's attribute-safe.
		$text = preg_replace_callback(
			'/!\[([^\]]*)\]\(([^)\s]+)\)/',
			static function ( $m ) {
				$src = self::image_src( $m[2] );
				if ( '' === $src ) {
					return ''; // Image isn't bundled in this build — omit it rather than emit a broken <img>.
				}
				return '<img class="coywolf-seo-doc-image" src="' . esc_url( $src ) . '" alt="' . $m[1] . '" />';
			},
			$text
		);

		// [text](url)
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			static function ( $m ) {
				return '<a href="' . esc_url( $m[2] ) . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
			},
			$text
		);

		// Restore the protected code spans.
		if ( $codes ) {
			$text = preg_replace_callback(
				'/\x00C(\d+)\x00/',
				static function ( $m ) use ( $codes ) {
					return '<code>' . $codes[ (int) $m[1] ] . '</code>';
				},
				$text
			);
		}

		return $text;
	}

	/**
	 * Resolve a Markdown image path to a browser-loadable URL.
	 *
	 * Absolute URLs pass through. Repo-relative paths (the readme's
	 * `.wordpress-org/screenshot-*.png` references) are streamed through the
	 * admin-only {@see self::serve_doc_image()} endpoint when the file is
	 * bundled in this build. They live in the hidden `.wordpress-org/`
	 * directory, which many web servers refuse to serve, so reading them in PHP
	 * is the only reliable way to display them. When the file isn't bundled —
	 * e.g. the WordPress.org variant, whose screenshots live in SVN's separate
	 * assets tree — this returns an empty string and the image is omitted; the
	 * file is never fetched from a remote host.
	 *
	 * @param string $path Image path as written in the Markdown.
	 * @return string URL, or '' when the image isn't available locally.
	 */
	private static function image_src( $path ) {
		if ( preg_match( '#^https?://#i', $path ) ) {
			return $path;
		}
		$path = preg_replace( '#^\./#', '', $path ); // "./x" → "x"; keeps dot-prefixed names like ".wordpress-org".
		$root = dirname( __DIR__ );
		if ( ! file_exists( $root . '/' . $path ) ) {
			return '';
		}
		// Images in the hidden .wordpress-org/ directory (the readme's
		// screenshots) are streamed through the admin-only endpoint, since many
		// web servers refuse to serve dot-directories. Anything else loads as a
		// normal plugin asset.
		if ( 0 === strpos( $path, '.wordpress-org/' ) ) {
			return admin_url(
				'admin-ajax.php?action=coywolf_seo_doc_image&file=' . rawurlencode( basename( $path ) )
			);
		}
		return plugins_url( $path, $root . '/coywolf-seo.php' );
	}

	/**
	 * Streams a bundled documentation image (a screenshot from the hidden
	 * `.wordpress-org/` directory) to logged-in administrators over admin-ajax,
	 * so they display even on hosts that refuse to serve dot-directories.
	 * Read-only and capability-gated; the requested name is
	 * reduced to a whitelisted basename, so it can only ever read an image out
	 * of `.wordpress-org/`. Registered on `wp_ajax_coywolf_seo_doc_image`.
	 *
	 * @return void
	 */
	public static function serve_doc_image() {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			status_header( 403 );
			exit;
		}
		// Read-only, capability-gated asset serve keyed by a whitelisted
		// basename; no nonce (the URL is an <img src> that must not expire).
		$file = isset( $_GET['file'] ) ? basename( sanitize_file_name( wp_unslash( $_GET['file'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! preg_match( '/^[A-Za-z0-9_-]+\.(png|jpe?g|gif|webp|svg)$/i', $file ) ) {
			status_header( 404 );
			exit;
		}
		$full = dirname( __DIR__ ) . '/.wordpress-org/' . $file;
		if ( ! is_file( $full ) ) {
			status_header( 404 );
			exit;
		}
		$ext   = strtolower( pathinfo( $full, PATHINFO_EXTENSION ) );
		$types = array(
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'svg'  => 'image/svg+xml',
		);
		header( 'Content-Type: ' . ( isset( $types[ $ext ] ) ? $types[ $ext ] : 'application/octet-stream' ) );
		header( 'Content-Length: ' . (string) filesize( $full ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, max-age=3600' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a bundled, validated, read-only plugin asset.
		readfile( $full );
		exit;
	}
}
