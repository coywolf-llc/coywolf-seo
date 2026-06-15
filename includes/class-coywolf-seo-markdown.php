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
		$lines = explode( "\n", str_replace( "\r\n", "\n", (string) $md ) );
		$count = count( $lines );
		$out   = '';
		$para  = array();
		$i     = 0;

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
				$out  .= '<h' . $level . '>' . self::inline( trim( $m[2] ) ) . '</h' . $level . ">\n";
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

		return $out . self::flush_para( $para );
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

		// `code`
		$text = preg_replace_callback(
			'/`([^`]+)`/',
			static function ( $m ) {
				return '<code>' . $m[1] . '</code>';
			},
			$text
		);

		// **bold**
		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );

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

		return $text;
	}

	/**
	 * Resolve a Markdown image path to a browser-loadable URL.
	 *
	 * Absolute URLs pass through. Repo-relative paths (the readme's
	 * `.wordpress-org/screenshot-*.png` references) resolve to the
	 * installed plugin copy when the file shipped in the zip. When the file
	 * isn't bundled in this build — e.g. the WordPress.org variant, whose
	 * screenshots live in SVN's separate assets tree rather than the plugin
	 * folder — this returns an empty string and the image is omitted; the
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
		if ( file_exists( $root . '/' . $path ) ) {
			return plugins_url( $path, $root . '/coywolf-seo.php' );
		}
		return '';
	}
}
