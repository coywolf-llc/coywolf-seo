<?php
/**
 * Rule engine.
 *
 * Owns the rule-type registry (shared with the editor JS), renders a stored
 * rule into robots.txt directive lines, parses an existing robots.txt into
 * consolidated rules, and evaluates a rule against a URL for the testing tool.
 *
 * A "rule" here is a directive (Disallow/Allow) + a target, applied to a set
 * of user-agents. On read, identical directives that differ only by agent are
 * consolidated into one rule carrying all of those agents.
 *
 * @package CoywolfRobotsTxtManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coywolf_SEO_Robots_Rules {

	/**
	 * The rule-type registry. Each entry drives the editor form (which fields
	 * to show, placeholders) and supplies the auto-filled name/description.
	 *
	 * `fields` is an ordered list of inputs; `key` maps to a stored rule key.
	 * `directive` is 'disallow', 'allow', or 'both' (Disallow + Allow).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function types() {
		// Built once per request: classify()/make() ask for this registry on
		// every parsed line during an import, and it's otherwise stable.
		static $types = null;
		if ( null !== $types ) {
			return $types;
		}

		// Derive the wp-admin / admin-ajax example paths from admin_url()
		// rather than hardcoding the wp-admin path — the admin-ajax endpoint
		// isn't guaranteed to live under /wp-admin/ on every install.
		$admin_dir = wp_parse_url( admin_url(), PHP_URL_PATH );
		if ( ! is_string( $admin_dir ) || '' === $admin_dir ) {
			$admin_dir = '/wp-admin/';
		}
		$admin_ajax = wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH );
		if ( ! is_string( $admin_ajax ) || '' === $admin_ajax ) {
			$admin_ajax = trailingslashit( $admin_dir ) . 'admin-ajax.php';
		}

		$types = array(
			'entire_site'        => array(
				'label'       => __( 'Entire site', 'coywolf-seo' ),
				'name'        => __( 'Entire site', 'coywolf-seo' ),
				'description' => __( 'The whole site (/).', 'coywolf-seo' ),
				'fields'      => array(),
			),
			'folder'             => array(
				'label'       => __( 'A specific folder', 'coywolf-seo' ),
				'name'        => __( 'A specific folder', 'coywolf-seo' ),
				'description' => __( 'A directory and everything inside it.', 'coywolf-seo' ),
				'fields'      => array(
					array(
						'key'         => 'path',
						'label'       => __( 'Folder path', 'coywolf-seo' ),
						'placeholder' => '/folder/',
					),
				),
			),
			'prefix'             => array(
				'label'       => __( 'A path prefix (starts-with)', 'coywolf-seo' ),
				'name'        => __( 'A path prefix', 'coywolf-seo' ),
				'description' => __( 'Any URL beginning with this string. With no trailing slash it also catches /privatedata, /private-page.html, etc.', 'coywolf-seo' ),
				'fields'      => array(
					array(
						'key'         => 'path',
						'label'       => __( 'Path prefix', 'coywolf-seo' ),
						'placeholder' => '/private',
					),
				),
			),
			'single_page'        => array(
				'label'       => __( 'A single page or file', 'coywolf-seo' ),
				'name'        => __( 'A single page or file', 'coywolf-seo' ),
				'description' => __( 'One specific page or file.', 'coywolf-seo' ),
				'fields'      => array(
					array(
						'key'         => 'path',
						'label'       => __( 'Full path', 'coywolf-seo' ),
						'placeholder' => '/folder/page.html',
					),
					array(
						'key'   => 'strict',
						'label' => __( 'Strict — append $ so nothing extends past it', 'coywolf-seo' ),
						'type'  => 'checkbox',
					),
				),
			),
			'exact_url'          => array(
				'label'       => __( 'An exact URL only', 'coywolf-seo' ),
				'name'        => __( 'An exact URL', 'coywolf-seo' ),
				'description' => __( 'Only the exact URL (anchored with $); /page-2 and /page/sub are not matched.', 'coywolf-seo' ),
				'fields'      => array(
					array(
						'key'         => 'path',
						'label'       => __( 'Exact path', 'coywolf-seo' ),
						'placeholder' => '/page',
					),
				),
			),
			'filetype'           => array(
				'label'       => __( 'A file type anywhere on the site', 'coywolf-seo' ),
				'name'        => __( 'A file type', 'coywolf-seo' ),
				'description' => __( 'Every file with this extension across the whole site.', 'coywolf-seo' ),
				'fields'      => array(
					array(
						'key'         => 'ext',
						'label'       => __( 'Extension', 'coywolf-seo' ),
						'placeholder' => 'pdf',
					),
				),
			),
			'filetype_in_folder' => array(
				'label'       => __( 'A file type within one folder', 'coywolf-seo' ),
				'name'        => __( 'A file type in a folder', 'coywolf-seo' ),
				'description' => __( 'Files with this extension inside a specific folder.', 'coywolf-seo' ),
				'fields'      => array(
					array(
						'key'         => 'path',
						'label'       => __( 'Folder path', 'coywolf-seo' ),
						'placeholder' => '/downloads/',
					),
					array(
						'key'         => 'ext',
						'label'       => __( 'Extension', 'coywolf-seo' ),
						'placeholder' => 'zip',
					),
				),
			),
			'contains'           => array(
				'label'       => __( 'Any URL containing a string', 'coywolf-seo' ),
				'name'        => __( 'URLs containing a string', 'coywolf-seo' ),
				'description' => __( 'Any URL that contains this string anywhere in the path.', 'coywolf-seo' ),
				'fields'      => array(
					array(
						'key'         => 'path',
						'label'       => __( 'String', 'coywolf-seo' ),
						'placeholder' => 'keyword',
					),
				),
			),
			'any_depth'          => array(
				'label'       => __( 'A pattern at any directory depth', 'coywolf-seo' ),
				'name'        => __( 'A folder at any depth', 'coywolf-seo' ),
				'description' => __( 'A folder wherever it appears below the first path segment.', 'coywolf-seo' ),
				'fields'      => array(
					array(
						'key'         => 'path',
						'label'       => __( 'Folder segment', 'coywolf-seo' ),
						'placeholder' => 'admin',
					),
				),
			),
			'query_any'          => array(
				'label'       => __( 'All URLs with query parameters', 'coywolf-seo' ),
				'name'        => __( 'All query-string URLs', 'coywolf-seo' ),
				'description' => __( 'Any URL containing a "?".', 'coywolf-seo' ),
				'fields'      => array(),
			),
			'query_param'        => array(
				'label'       => __( 'A specific query parameter', 'coywolf-seo' ),
				'name'        => __( 'A specific query parameter', 'coywolf-seo' ),
				'description' => __( 'URLs containing a specific query parameter, e.g. ?session=.', 'coywolf-seo' ),
				'fields'      => array(
					array(
						'key'         => 'path',
						'label'       => __( 'Parameter name', 'coywolf-seo' ),
						'placeholder' => 'session',
					),
				),
			),
			'wildcard_prefix'    => array(
				'label'       => __( 'A prefix with a wildcard mid-path', 'coywolf-seo' ),
				'name'        => __( 'A wildcard prefix', 'coywolf-seo' ),
				'description' => __( 'Everything beginning with a prefix, e.g. /pa_* for WooCommerce attribute pages.', 'coywolf-seo' ),
				'fields'      => array(
					array(
						'key'         => 'path',
						'label'       => __( 'Prefix', 'coywolf-seo' ),
						'placeholder' => '/pa_',
					),
				),
			),
			'allow_exception'    => array(
				'label'       => __( 'Block a folder but allow one item inside it', 'coywolf-seo' ),
				'name'        => __( 'Folder with an allowed exception', 'coywolf-seo' ),
				'description' => __( 'Blocks a folder but allows a single item inside it (Disallow + Allow). Allow overrules Disallow.', 'coywolf-seo' ),
				// Always emits Disallow (folder) + Allow (item); the Rule Type
				// choice does not apply to this combined pattern.
				'directive'   => 'both',
				'fields'      => array(
					array(
						'key'         => 'path',
						'label'       => __( 'Folder to block', 'coywolf-seo' ),
						'placeholder' => $admin_dir,
					),
					array(
						'key'         => 'allow',
						'label'       => __( 'Item to allow', 'coywolf-seo' ),
						'placeholder' => $admin_ajax,
					),
				),
			),
			'custom'             => array(
				'label'       => __( 'Custom (raw value)', 'coywolf-seo' ),
				'name'        => __( 'Custom rule', 'coywolf-seo' ),
				'description' => __( 'A raw robots.txt path/value entered manually.', 'coywolf-seo' ),
				'fields'      => array(
					array(
						'key'         => 'path',
						'label'       => __( 'Value', 'coywolf-seo' ),
						'placeholder' => '/path',
					),
				),
			),
		);
		return $types;
	}

	/**
	 * Detect rules that would conflict with a (new or edited) rule, comparing
	 * only rules that share a user-agent. Returns human-readable messages
	 * (empty when none): duplicates, Allow/Disallow contradictions on the same
	 * value, and allow-everything vs block clashes. Agent overlap is by exact
	 * token; '*' and a specific agent are treated as non-overlapping, since
	 * crawlers obey their most-specific group.
	 *
	 * @param array $rule     The rule being saved.
	 * @param array $existing All stored rules.
	 * @return array<int,string>
	 */
	public static function conflicts( $rule, $existing ) {
		$msgs = array();
		if ( ! is_array( $existing ) || empty( $existing ) ) {
			return $msgs;
		}

		$id        = isset( $rule['id'] ) ? (string) $rule['id'] : '';
		$new_dirs  = self::directives( $rule );
		$new_agent = self::agent_set( $rule );

		foreach ( $existing as $ex ) {
			if ( ! is_array( $ex ) ) {
				continue;
			}
			if ( '' !== $id && isset( $ex['id'] ) && (string) $ex['id'] === $id ) {
				continue; // Editing the same rule — not a conflict with itself.
			}
			$shared = array_intersect( $new_agent, self::agent_set( $ex ) );
			if ( empty( $shared ) ) {
				continue;
			}
			$who     = self::agents_phrase( $shared );
			$ex_name = ( isset( $ex['name'] ) && '' !== $ex['name'] ) ? $ex['name'] : __( 'an existing rule', 'coywolf-seo' );
			$ex_dirs = self::directives( $ex );

			foreach ( $new_dirs as $nd ) {
				$n_allow_all = ( 'Disallow' === $nd['directive'] && '' === $nd['value'] );
				foreach ( $ex_dirs as $ed ) {
					$e_allow_all = ( 'Disallow' === $ed['directive'] && '' === $ed['value'] );

					// Allow-everything vs a real block (either direction).
					if ( $n_allow_all && 'Disallow' === $ed['directive'] && '' !== $ed['value'] ) {
						$msgs[] = sprintf(
							/* translators: 1: agents, 2: existing rule name, 3: directive line */
							__( 'This rule lets %1$s crawl everything, but "%2$s" already blocks them with "%3$s".', 'coywolf-seo' ),
							$who,
							$ex_name,
							$ed['directive'] . ': ' . $ed['value']
						);
						continue;
					}
					if ( $e_allow_all && 'Disallow' === $nd['directive'] && '' !== $nd['value'] ) {
						$msgs[] = sprintf(
							/* translators: 1: agents, 2: existing rule name */
							__( 'This rule blocks %1$s, but "%2$s" already lets them crawl everything.', 'coywolf-seo' ),
							$who,
							$ex_name
						);
						continue;
					}

					if ( $nd['value'] !== $ed['value'] ) {
						continue;
					}
					if ( $nd['directive'] === $ed['directive'] ) {
						$msgs[] = sprintf(
							/* translators: 1: directive line, 2: agents, 3: existing rule name */
							__( 'Duplicate: "%1$s" for %2$s is already set by "%3$s".', 'coywolf-seo' ),
							$nd['directive'] . ': ' . $nd['value'],
							$who,
							$ex_name
						);
					} else {
						$msgs[] = sprintf(
							/* translators: 1: agents, 2: path value, 3: existing rule name */
							__( 'Contradiction: this rule and "%3$s" set opposite Allow/Disallow for "%2$s" on %1$s.', 'coywolf-seo' ),
							$who,
							$nd['value'],
							$ex_name
						);
					}
				}
			}
		}

		return array_values( array_unique( $msgs ) );
	}

	/**
	 * Detect when a (new or edited) rule is already covered by a broader
	 * existing rule for the same user-agent, so saving it would change nothing.
	 *
	 * Robots.txt Disallow matching is prefix/wildcard-based — "Disallow: /"
	 * blocks "/blahr/", so a narrower "Disallow: /blahr/" for the same agent is
	 * redundant and is refused. The new rule's path is required to be a plain
	 * literal (no "*"/"$"), but the EXISTING covering Disallow may use wildcards
	 * — the Google REP matcher ({@see Coywolf_SEO_Robots_Matcher}) confirms it
	 * blocks the whole subtree, so "Disallow: /a/*" correctly covers a later
	 * "Disallow: /a/b/". To stay free of false positives it skips a rule that
	 * also emits an Allow (an exception rule is meaningful on its own), and never
	 * flags a block when a shared-agent Allow would out-rank it on some path it
	 * covers. Exact duplicates and Allow/Disallow contradictions are handled by
	 * {@see self::conflicts()}.
	 *
	 * @param array $rule     The rule being saved.
	 * @param array $existing All stored rules.
	 * @return array<int,string>
	 */
	public static function redundancies( $rule, $existing ) {
		$msgs = array();
		if ( ! is_array( $existing ) || empty( $existing ) ) {
			return $msgs;
		}

		$new_dirs = self::directives( $rule );
		foreach ( $new_dirs as $nd ) {
			// A plain Allow, or an allow-exception's Allow, is a deliberate
			// carve-out — never a redundant block. Leave the whole rule alone.
			if ( 'Allow' === $nd['directive'] ) {
				return $msgs;
			}
		}

		$id        = isset( $rule['id'] ) ? (string) $rule['id'] : '';
		$new_agent = self::agent_set( $rule );

		foreach ( $new_dirs as $nd ) {
			$q = $nd['value'];
			if ( '' === $q || ! self::is_literal_path( $q ) ) {
				continue;
			}
			$q_len = strlen( Coywolf_SEO_Robots_Matcher::escape_pattern( $q ) );

			// Among rules that share an agent, find the most-specific existing
			// Disallow whose pattern (literal OR wildcard) blocks $q's entire
			// subtree, and gather the shared Allow directives so we can tell
			// whether any of them would survive the new block.
			$cover_value = '';
			$cover_len   = -1;
			$cover_name  = '';
			$cover_who   = array();
			$allows      = array();

			foreach ( $existing as $ex ) {
				if ( ! is_array( $ex ) ) {
					continue;
				}
				if ( '' !== $id && isset( $ex['id'] ) && (string) $ex['id'] === $id ) {
					continue; // Editing the same rule.
				}
				$shared = array_intersect( $new_agent, self::agent_set( $ex ) );
				if ( empty( $shared ) ) {
					continue;
				}
				foreach ( self::directives( $ex ) as $ed ) {
					$v = $ed['value'];
					if ( '' === $v ) {
						continue;
					}
					if ( 'Allow' === $ed['directive'] ) {
						$allows[] = $v;
						continue;
					}
					// A Disallow that subsumes $q's whole subtree (Google matcher,
					// so "/a/*" correctly covers a later "/a/b/").
					if ( $v !== $q && Coywolf_SEO_Robots_Matcher::covers_subtree( $v, $q ) ) {
						$vlen = strlen( Coywolf_SEO_Robots_Matcher::escape_pattern( $v ) );
						if ( $vlen > $cover_len ) {
							$cover_value = $v;
							$cover_len   = $vlen;
							$cover_name  = ( isset( $ex['name'] ) && '' !== $ex['name'] ) ? $ex['name'] : __( 'an existing rule', 'coywolf-seo' );
							$cover_who   = $shared;
						}
					}
				}
			}

			if ( '' === $cover_value ) {
				continue;
			}

			// The new block is redundant only if no shared Allow could win where
			// it applies. By REP longest-match an Allow whose escaped length is
			// >= the covering Disallow already out-ranks that cover on the paths
			// it reaches — ties go to Allow — so without the candidate $q those
			// paths are *allowed*, and $q (longer than both) is the only thing
			// that re-blocks them. Such an Allow therefore makes $q meaningful, so
			// $q is NOT redundant when an Allow is (a) at least as long as the
			// cover [>=, the tie included], (b) shorter than $q (so $q overrides
			// it), and (c) able to reach into the subtree (its literal head is
			// prefix-compatible with $q — a precondition for matching any path
			// under $q).
			$still_acts = false;
			foreach ( $allows as $a ) {
				$a_len = strlen( Coywolf_SEO_Robots_Matcher::escape_pattern( $a ) );
				if ( $a_len >= $cover_len && $a_len < $q_len && self::heads_overlap( $a, $q ) ) {
					$still_acts = true;
					break;
				}
			}
			if ( $still_acts ) {
				continue;
			}

			$msgs[] = sprintf(
				/* translators: 1: the new Disallow line, 2: agents, 3: existing rule name, 4: the existing Disallow line that already covers the path */
				__( 'Redundant: "%1$s" for %2$s is already covered by "%3$s" ("%4$s" blocks that path).', 'coywolf-seo' ),
				'Disallow: ' . $q,
				self::agents_phrase( $cover_who ),
				$cover_name,
				'Disallow: ' . $cover_value
			);
		}

		return array_values( array_unique( $msgs ) );
	}

	/**
	 * Whether a directive pattern could match somewhere inside literal path
	 * $q's subtree. A pattern can only match a path P that lies under $q when
	 * both $q and the pattern's literal head (everything before its first "*"
	 * or "$") are prefixes of P — i.e. one is a prefix of the other. A pattern
	 * that begins with a wildcard could match anywhere, so it always overlaps.
	 *
	 * @param string $pattern Directive value (may contain wildcards).
	 * @param string $q       Literal path.
	 * @return bool
	 */
	private static function heads_overlap( $pattern, $q ) {
		$head = (string) $pattern;
		$head = substr( $head, 0, strcspn( $head, '*$' ) );
		if ( '' === $head ) {
			return true;
		}
		return 0 === strpos( $q, $head ) || 0 === strpos( $head, $q );
	}

	/**
	 * Whether a path value is a plain literal (no "*" or "$"), so prefix
	 * coverage is unambiguous.
	 *
	 * @param string $value Path value.
	 * @return bool
	 */
	private static function is_literal_path( $value ) {
		return false === strpos( (string) $value, '*' ) && false === strpos( (string) $value, '$' );
	}

	/**
	 * The set of user-agent tokens a rule applies to (defaults to '*').
	 *
	 * @param array $rule Rule.
	 * @return array<int,string>
	 */
	private static function agent_set( $rule ) {
		$agents = ( ! empty( $rule['agents'] ) && is_array( $rule['agents'] ) ) ? $rule['agents'] : array( '*' );
		$out    = array();
		foreach ( $agents as $a ) {
			$a = (string) $a;
			if ( '' !== $a ) {
				$out[ $a ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * A short phrase naming a set of agents, for conflict messages.
	 *
	 * @param array $agents Agent tokens.
	 * @return string
	 */
	private static function agents_phrase( $agents ) {
		$agents = array_values( $agents );
		if ( in_array( '*', $agents, true ) ) {
			return __( 'all robots', 'coywolf-seo' );
		}
		$n = count( $agents );
		if ( $n <= 2 ) {
			return implode( ', ', $agents );
		}
		return sprintf(
			/* translators: 1: first agents, 2: count of the rest */
			__( '%1$s +%2$d more', 'coywolf-seo' ),
			implode( ', ', array_slice( $agents, 0, 2 ) ),
			$n - 2
		);
	}

	/**
	 * A blank rule with every key the storage layer expects.
	 *
	 * @return array<string,mixed>
	 */
	public static function blank() {
		return array(
			'id'          => '',
			'type'        => '',
			'directive'   => 'disallow',
			'name'        => '',
			'description' => '',
			'path'        => '',
			'ext'         => '',
			'allow'       => '',
			'strict'      => false,
			'agents'      => array( '*' ),
		);
	}

	/**
	 * Render a stored rule into one or more directive lines (no User-agent).
	 *
	 * @param array $rule Stored rule.
	 * @return array<int,array{directive:string,value:string}>
	 */
	public static function directives( $rule ) {
		$type   = isset( $rule['type'] ) ? $rule['type'] : 'custom';
		$path   = isset( $rule['path'] ) ? trim( (string) $rule['path'] ) : '';
		$ext    = isset( $rule['ext'] ) ? ltrim( trim( (string) $rule['ext'] ), '.' ) : '';
		$allow  = isset( $rule['allow'] ) ? trim( (string) $rule['allow'] ) : '';
		$strict = ! empty( $rule['strict'] );
		// The Rule Type — Disallow (default) or Allow — picks the directive for
		// the target the Rule Path describes. (allow_exception is the exception:
		// it always emits Disallow + Allow regardless of this choice.)
		$dir = ( isset( $rule['directive'] ) && 'allow' === $rule['directive'] ) ? 'Allow' : 'Disallow';
		$out = array();

		switch ( $type ) {
			case 'entire_site':
				$out[] = self::line( $dir, '/' );
				break;
			case 'folder':
				$out[] = self::line( $dir, self::dir_path( $path ) );
				break;
			case 'prefix':
				$out[] = self::line( $dir, self::lead( $path ) );
				break;
			case 'single_page':
				$out[] = self::line( $dir, self::lead( $path ) . ( $strict ? '$' : '' ) );
				break;
			case 'exact_url':
				$out[] = self::line( $dir, self::lead( $path ) . '$' );
				break;
			case 'filetype':
				$out[] = self::line( $dir, '/*.' . $ext . '$' );
				break;
			case 'filetype_in_folder':
				$out[] = self::line( $dir, self::dir_path( $path ) . '*.' . $ext . '$' );
				break;
			case 'contains':
				$out[] = self::line( $dir, '/*' . ltrim( $path, '/' ) );
				break;
			case 'any_depth':
				$out[] = self::line( $dir, '/*/' . trim( $path, '/' ) . '/' );
				break;
			case 'query_any':
				$out[] = self::line( $dir, '/*?' );
				break;
			case 'query_param':
				$out[] = self::line( $dir, '/*?' . ltrim( $path, '?' ) . '=' );
				break;
			case 'wildcard_prefix':
				$out[] = self::line( $dir, self::ensure_star( self::lead( $path ) ) );
				break;
			case 'allow_exception':
				$out[] = self::line( 'Disallow', self::dir_path( $path ) );
				if ( '' !== $allow ) {
					$out[] = self::line( 'Allow', self::lead( $allow ) );
				}
				break;
			case 'custom':
			default:
				$out[] = self::line( $dir, $path );
				break;
		}

		return $out;
	}

	/** Maximum robots.txt size Google reads — content past this is ignored. */
	const MAX_BYTES = 512000; // 500 KiB.

	/**
	 * Strip a leading UTF-8 BOM and enforce Google's 500 KiB read limit
	 * (truncating to the last complete line). Shared by {@see parse()} and the
	 * import "preserve outside" path so both see the same cleaned bytes.
	 *
	 * @param string $text robots.txt contents.
	 * @return string
	 */
	public static function preclean( $text ) {
		$text = (string) $text;
		if ( 0 === strncmp( $text, "\xEF\xBB\xBF", 3 ) ) {
			$text = substr( $text, 3 );
		}
		if ( strlen( $text ) > self::MAX_BYTES ) {
			$text = substr( $text, 0, self::MAX_BYTES );
			$nl   = strrpos( $text, "\n" );
			if ( false !== $nl ) {
				$text = substr( $text, 0, $nl );
			}
		}
		return $text;
	}

	/**
	 * Parse robots.txt text into clean, consolidated rules plus normalized
	 * Sitemap links, repairing the common real-world mistakes along the way:
	 *
	 * - Syntax: corrects misspelled directives (Disalow→Disallow) and a missing
	 *   colon; drops orphan rules (before any User-agent) and rules under an
	 *   empty User-agent.
	 * - Conflicts: consolidates each user-agent (case-insensitively) and, per
	 *   path, keeps the LAST directive (Allow/Disallow) seen. A blanket
	 *   "Disallow: /" is removed when the agent also has other directives (the
	 *   most-severe contradiction), and a redundant empty "Disallow:" is dropped
	 *   when other directives exist — but a lone full block is kept.
	 * - Deprecated/unsupported: Crawl-delay, Noindex, Nofollow, Host,
	 *   Request-rate, Visit-time are dropped.
	 * - SEO: render-critical blocks (/*.css, /*.js) and the overly broad
	 *   /wp-content/ are dropped.
	 * - Patterns: internal whitespace removed, "**" collapsed to "*", and a
	 *   missing leading slash added.
	 * - Sitemaps: made absolute and de-duplicated (preferring https).
	 *
	 * @param string $text robots.txt contents.
	 * @return array{rules:array<int,array<string,mixed>>,sitemaps:array<int,string>}
	 */
	public static function parse( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', self::preclean( $text ) );

		// Fields that belong to a user-agent group: seeing one means a following
		// User-agent line opens a NEW group. Only disallow/allow are kept; the
		// rest are tracked solely so group boundaries stay correct, then dropped.
		$group_member = array(
			'disallow'     => true,
			'allow'        => true,
			'crawl-delay'  => true,
			'noindex'      => true,
			'nofollow'     => true,
			'host'         => true,
			'request-rate' => true,
			'visit-time'   => true,
		);

		$agent_order = array(); // lower token => display token (first-seen order).
		$agent_dirs  = array(); // lower token => array of array{0:dir,1:value}.
		$current     = array(); // lower tokens for the current group.
		$valid_group = false;    // Current group names at least one agent.
		$saw_member  = false;    // Saw a group-member directive in this group.
		$sitemaps    = array();

		foreach ( $lines as $raw ) {
			$line = trim( (string) $raw );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			list( $field, $value ) = self::split_line( $line );
			if ( null === $field ) {
				continue;
			}

			if ( 'user-agent' === $field ) {
				if ( $saw_member ) {
					$current     = array();
					$valid_group = false;
					$saw_member  = false;
				}
				if ( '' !== $value ) {
					// Match the token against the bot catalog so imported
					// agents that name a known bot ("Bingbot", "Slurp",
					// "YandexBot") group under that bot's canonical token and
					// show up checked in its category — not as custom agents.
					// Unknown tokens (and "*") keep their written form.
					$display = $value;
					if ( class_exists( 'Coywolf_SEO_Robots_Bots' ) ) {
						$canon = Coywolf_SEO_Robots_Bots::canonical_token( $value );
						if ( '' !== $canon ) {
							$display = $canon;
						}
					}
					$key         = strtolower( $display );
					$current[]   = $key;
					$valid_group = true;
					if ( ! isset( $agent_order[ $key ] ) ) {
						$agent_order[ $key ] = $display;
						$agent_dirs[ $key ]  = array();
					}
				}
				continue;
			}

			if ( 'sitemap' === $field ) {
				$sitemaps[] = $value; // Non-group; doesn't affect boundaries.
				continue;
			}

			if ( isset( $group_member[ $field ] ) ) {
				$saw_member = true;
				if ( 'disallow' !== $field && 'allow' !== $field ) {
					continue; // Deprecated/unsupported — dropped.
				}
				if ( ! $valid_group ) {
					continue; // Orphan rule or empty User-agent — dropped.
				}
				$val = self::clean_pattern( $value );
				if ( self::is_seo_bad( $field, $val ) ) {
					continue;
				}
				$dir = ( 'allow' === $field ) ? 'Allow' : 'Disallow';
				foreach ( $current as $key ) {
					$agent_dirs[ $key ][] = array( $dir, $val );
				}
			}
			// Unknown fields are ignored entirely.
		}

		// Resolve each agent's directives, then consolidate identical
		// directive+value across agents into a single rule carrying all of them.
		$rules    = array();
		$rule_idx = array(); // "dir|value" => index in $rules.
		foreach ( $agent_order as $key => $display ) {
			foreach ( self::resolve_directives( $agent_dirs[ $key ] ) as $d ) {
				list( $dir, $val ) = $d;
				$rk                = $dir . '|' . $val;
				if ( ! isset( $rule_idx[ $rk ] ) ) {
					$rule            = self::classify( $dir, $val );
					$rule['agents']  = array();
					$rule['id']      = 'r' . ( count( $rules ) + 1 ) . substr( md5( $rk ), 0, 6 );
					$rules[]         = $rule;
					$rule_idx[ $rk ] = count( $rules ) - 1;
				}
				$idx = $rule_idx[ $rk ];
				if ( ! in_array( $display, $rules[ $idx ]['agents'], true ) ) {
					$rules[ $idx ]['agents'][] = $display;
				}
			}
		}

		return array(
			'rules'    => $rules,
			'sitemaps' => self::normalize_sitemaps( $sitemaps ),
		);
	}

	/**
	 * Split a robots.txt line into [field, value], repairing a misspelled
	 * directive and a missing colon. Returns [null, null] for lines we can't
	 * read as a directive. Inline comments are stripped from the value.
	 *
	 * @param string $line Trimmed, non-comment line.
	 * @return array{0:?string,1:?string}
	 */
	private static function split_line( $line ) {
		$pos = strpos( $line, ':' );
		if ( false !== $pos ) {
			$field = strtolower( trim( substr( $line, 0, $pos ) ) );
			$value = trim( substr( $line, $pos + 1 ) );
		} elseif ( preg_match( '/^([A-Za-z][A-Za-z-]*)\s+(.*)$/', $line, $m ) ) {
			// Missing colon, e.g. "Disallow /missing-colon/".
			$field = strtolower( $m[1] );
			$value = trim( $m[2] );
		} else {
			return array( null, null );
		}

		$hash = strpos( $value, ' #' );
		if ( false !== $hash ) {
			$value = rtrim( substr( $value, 0, $hash ) );
		}

		// Correct common directive misspellings.
		$typos = array(
			'disalow'     => 'disallow',
			'dissalow'    => 'disallow',
			'dissallow'   => 'disallow',
			'disallows'   => 'disallow',
			'alow'        => 'allow',
			'user-agents' => 'user-agent',
			'useragent'   => 'user-agent',
		);
		if ( isset( $typos[ $field ] ) ) {
			$field = $typos[ $field ];
		}

		return array( $field, $value );
	}

	/**
	 * Repair a Disallow/Allow pattern: drop internal whitespace, collapse runs
	 * of "*" to a single "*", and add a missing leading slash (leaving an empty
	 * value, or one already starting with "/" or "*", untouched).
	 *
	 * @param string $value Raw directive value.
	 * @return string
	 */
	private static function clean_pattern( $value ) {
		$v = trim( (string) $value );
		$v = preg_replace( '/\s+/', '', $v );
		$v = preg_replace( '/\*{2,}/', '*', (string) $v );
		$v = (string) $v;
		if ( '' !== $v && '/' !== $v[0] && '*' !== $v[0] ) {
			$v = '/' . $v;
		}
		return $v;
	}

	/**
	 * Whether a Disallow value blocks something it shouldn't: render-critical
	 * CSS/JS, or the overly broad /wp-content/ directory.
	 *
	 * @param string $field Lowercased field name.
	 * @param string $value Cleaned directive value.
	 * @return bool
	 */
	private static function is_seo_bad( $field, $value ) {
		if ( 'disallow' !== $field ) {
			return false;
		}
		$v = strtolower( trim( $value ) );
		if ( in_array( $v, array( '/*.css', '/*.css$', '/*.js', '/*.js$', '/wp-content/' ), true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Resolve one agent's ordered directive list: keep the LAST directive per
	 * path (so Disallow then Allow on the same path ends up Allow), remove a
	 * blanket "Disallow: /" (and a redundant empty "Disallow:") when the agent
	 * has other directives, and de-duplicate. A lone full block is kept.
	 *
	 * @param array<int,array{0:string,1:string}> $dirs Ordered [dir, value] pairs.
	 * @return array<int,array{0:string,1:string}>
	 */
	private static function resolve_directives( $dirs ) {
		$has_other = false;
		foreach ( $dirs as $d ) {
			if ( ! ( 'Disallow' === $d[0] && '/' === $d[1] ) ) {
				$has_other = true;
				break;
			}
		}

		$dir_for   = array(); // value => winning directive (last-wins).
		$first_pos = array(); // value => first-seen index (for stable order).
		$i         = 0;
		foreach ( $dirs as $d ) {
			list( $dir, $val ) = $d;
			if ( $has_other && 'Disallow' === $dir && '/' === $val ) {
				continue; // Most-severe contradiction.
			}
			if ( $has_other && 'Disallow' === $dir && '' === $val ) {
				continue; // Redundant "allow all".
			}
			if ( ! isset( $first_pos[ $val ] ) ) {
				$first_pos[ $val ] = $i++;
			}
			$dir_for[ $val ] = $dir;
		}

		asort( $first_pos );
		$out = array();
		foreach ( array_keys( $first_pos ) as $val ) {
			$out[] = array( $dir_for[ $val ], $val );
		}
		return $out;
	}

	/**
	 * Normalize Sitemap URLs: make relative ones absolute (against the site
	 * home URL), DROP any whose domain isn't this site's (a sitemap copied from
	 * another site doesn't belong here), then de-duplicate by host+path+query
	 * preferring https over http. Order follows first appearance.
	 *
	 * @param array<int,string> $raw Raw Sitemap values.
	 * @return array<int,string>
	 */
	private static function normalize_sitemaps( $raw ) {
		$site_host = self::site_host();
		$by_key    = array();
		foreach ( $raw as $u ) {
			$u = self::absolutize_url( trim( (string) $u ) );
			if ( '' === $u ) {
				continue;
			}
			$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $u ) : parse_url( $u ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			if ( empty( $parts['host'] ) ) {
				continue;
			}
			// Drop cross-domain sitemaps (only when we can tell what the site's
			// own host is). www. is treated as equivalent to the bare host.
			if ( '' !== $site_host && self::bare_host( $parts['host'] ) !== $site_host ) {
				continue;
			}
			$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
			$key    = strtolower( $parts['host'] ) . ( isset( $parts['path'] ) ? $parts['path'] : '' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
			if ( ! isset( $by_key[ $key ] ) ) {
				$by_key[ $key ] = $u;
			} elseif ( 'https' === $scheme && 0 === stripos( (string) $by_key[ $key ], 'http://' ) ) {
				$by_key[ $key ] = $u; // Upgrade a previously-stored http URL.
			}
		}
		return array_values( $by_key );
	}

	/**
	 * The site's own host (from home_url), lowercased with a leading "www."
	 * removed, or '' when it can't be determined (e.g. outside WordPress).
	 *
	 * @return string
	 */
	private static function site_host() {
		if ( ! function_exists( 'home_url' ) ) {
			return '';
		}
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( home_url( '/' ) ) : parse_url( home_url( '/' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return empty( $parts['host'] ) ? '' : self::bare_host( $parts['host'] );
	}

	/**
	 * Lowercase a host and strip a leading "www." for lenient comparison.
	 *
	 * @param string $host Hostname.
	 * @return string
	 */
	private static function bare_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		return preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Make a Sitemap URL absolute. Absolute URLs are returned as-is; a relative
	 * value is resolved against the site home URL.
	 *
	 * @param string $u Sitemap value.
	 * @return string
	 */
	private static function absolutize_url( $u ) {
		if ( '' === $u ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $u ) ) {
			return $u;
		}
		if ( function_exists( 'home_url' ) ) {
			return home_url( '/' . ltrim( $u, '/' ) );
		}
		return $u;
	}

	/**
	 * Infer a rule type (and its fields) from a directive + value, so an
	 * imported line shows a friendly name and edits correctly.
	 *
	 * @param string $directive 'Disallow' or 'Allow'.
	 * @param string $value     Directive value.
	 * @return array<string,mixed>
	 */
	public static function classify( $directive, $value ) {
		$dir = ( 'Allow' === $directive ) ? 'allow' : 'disallow';
		$v   = trim( (string) $value );

		// The path type is inferred from the value; the Disallow/Allow choice is
		// carried separately as the rule's directive. An empty value (e.g. the
		// "Disallow:" allow-all idiom, or a bare "Allow:") has no path type, so
		// keep it verbatim as a custom rule.
		if ( '' === $v ) {
			return self::make(
				'custom',
				array(
					'directive' => $dir,
					'path'      => '',
				)
			);
		}
		if ( '/' === $v ) {
			return self::make( 'entire_site', array( 'directive' => $dir ) );
		}
		if ( '/*?' === $v ) {
			return self::make( 'query_any', array( 'directive' => $dir ) );
		}
		if ( preg_match( '#^/\*\?([^=]+)=$#', $v, $m ) ) {
			return self::make(
				'query_param',
				array(
					'path'      => $m[1],
					'directive' => $dir,
				)
			);
		}
		if ( preg_match( '#^/\*\.([A-Za-z0-9]+)\$$#', $v, $m ) ) {
			return self::make(
				'filetype',
				array(
					'ext'       => $m[1],
					'directive' => $dir,
				)
			);
		}
		if ( preg_match( '#^(/[^*]+/)\*\.([A-Za-z0-9]+)\$$#', $v, $m ) ) {
			return self::make(
				'filetype_in_folder',
				array(
					'path'      => $m[1],
					'ext'       => $m[2],
					'directive' => $dir,
				)
			);
		}
		if ( preg_match( '#^/\*/([^*/]+)/$#', $v, $m ) ) {
			return self::make(
				'any_depth',
				array(
					'path'      => $m[1],
					'directive' => $dir,
				)
			);
		}
		if ( preg_match( '#^/\*([^*?/][^*?]*)$#', $v, $m ) ) {
			return self::make(
				'contains',
				array(
					'path'      => $m[1],
					'directive' => $dir,
				)
			);
		}
		if ( '*' === substr( $v, -1 ) && strrpos( $v, '*' ) === strlen( $v ) - 1 ) {
			return self::make(
				'wildcard_prefix',
				array(
					'path'      => substr( $v, 0, -1 ),
					'directive' => $dir,
				)
			);
		}
		if ( '$' === substr( $v, -1 ) ) {
			$base = substr( $v, 0, -1 );
			if ( false === strpos( $base, '*' ) && preg_match( '#/[^/]*\.[^/]+$#', $base ) ) {
				return self::make(
					'single_page',
					array(
						'path'      => $base,
						'strict'    => true,
						'directive' => $dir,
					)
				);
			}
			if ( false === strpos( $base, '*' ) ) {
				return self::make(
					'exact_url',
					array(
						'path'      => $base,
						'directive' => $dir,
					)
				);
			}
		}
		if ( false === strpos( $v, '*' ) && false === strpos( $v, '$' ) ) {
			if ( '/' === substr( $v, -1 ) ) {
				return self::make(
					'folder',
					array(
						'path'      => $v,
						'directive' => $dir,
					)
				);
			}
			if ( preg_match( '#/[^/]*\.[^/]+$#', $v ) ) {
				return self::make(
					'single_page',
					array(
						'path'      => $v,
						'directive' => $dir,
					)
				);
			}
			return self::make(
				'prefix',
				array(
					'path'      => $v,
					'directive' => $dir,
				)
			);
		}

		return self::make(
			'custom',
			array(
				'path'      => $v,
				'directive' => $dir,
			)
		);
	}

	/**
	 * Evaluate a rule against a URL path for the testing tool.
	 *
	 * Implements robots.txt wildcard matching (`*` = any run, `$` = end of
	 * URL) and longest-match precedence, with Allow winning ties — matching
	 * how Google evaluates groups.
	 *
	 * @param array  $rule Rule (stored shape; may be unsaved form values).
	 * @param string $path URL path beginning with "/".
	 * @return array{effect:string,pattern:string}
	 */
	public static function test_rule( $rule, $path ) {
		$path = self::lead( (string) $path );
		$best = array(
			'effect'  => 'none',
			'pattern' => '',
			'len'     => -1,
		);

		foreach ( self::directives( $rule ) as $d ) {
			$value  = $d['value'];
			$effect = ( 'Allow' === $d['directive'] ) ? 'allow' : 'disallow';

			// An empty Disallow value matches nothing (it means "allow all").
			if ( '' === $value ) {
				if ( $best['len'] < 0 ) {
					$best = array(
						'effect'  => 'allow',
						'pattern' => '(empty Disallow = allow all)',
						'len'     => 0,
					);
				}
				continue;
			}

			if ( Coywolf_SEO_Robots_Matcher::matches( $value, $path ) ) {
				// Longest-match priority is the normalized pattern length, exactly
				// as Google ranks Allow vs Disallow (full value, wildcards
				// included); a tie goes to Allow.
				$len = strlen( Coywolf_SEO_Robots_Matcher::escape_pattern( $value ) );
				if ( $len > $best['len'] || ( $len === $best['len'] && 'allow' === $effect ) ) {
					$best = array(
						'effect'  => $effect,
						'pattern' => $value,
						'len'     => $len,
					);
				}
			}
		}

		return array(
			'effect'  => $best['effect'],
			'pattern' => $best['pattern'],
		);
	}

	/**
	 * robots.txt path matching with `*` and `$`. Delegates to the REP matcher
	 * ({@see Coywolf_SEO_Robots_Matcher::matches()}) — Google's reference
	 * algorithm: linear, backtracking-free (ReDoS-safe), with percent-encoding
	 * normalization. An empty pattern is treated as "matches nothing" here (the
	 * "empty Disallow = allow all" case is handled by the caller).
	 *
	 * @param string $pattern Directive value.
	 * @param string $path    URL path.
	 * @return bool
	 */
	public static function path_matches( $pattern, $path ) {
		if ( '' === (string) $pattern ) {
			return false;
		}
		return Coywolf_SEO_Robots_Matcher::matches( $pattern, $path );
	}

	/* ----------------------------------------------------------------- *
	 * Internal helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Build a rule array from a registry type plus overrides.
	 *
	 * @param string $type      Type key.
	 * @param array  $overrides Field overrides.
	 * @return array<string,mixed>
	 */
	private static function make( $type, $overrides = array() ) {
		$types = self::types();
		$def   = isset( $types[ $type ] ) ? $types[ $type ] : $types['custom'];
		$base  = array(
			'type'        => $type,
			'directive'   => 'disallow',
			'name'        => $def['name'],
			'description' => $def['description'],
			'path'        => '',
			'ext'         => '',
			'allow'       => '',
			'strict'      => false,
		);
		return array_merge( $base, $overrides );
	}

	/**
	 * @param string $dir   Directive name.
	 * @param string $value Directive value.
	 * @return array{directive:string,value:string}
	 */
	private static function line( $dir, $value ) {
		return array(
			'directive' => $dir,
			'value'     => $value,
		);
	}

	/**
	 * Ensure a leading slash (empty stays empty).
	 *
	 * @param string $p Path.
	 * @return string
	 */
	private static function lead( $p ) {
		$p = trim( (string) $p );
		if ( '' === $p ) {
			return $p;
		}
		return ( '/' === $p[0] ) ? $p : '/' . $p;
	}

	/**
	 * Ensure a leading and trailing slash (a directory path).
	 *
	 * @param string $p Path.
	 * @return string
	 */
	private static function dir_path( $p ) {
		$p = self::lead( $p );
		if ( '' === $p ) {
			return '/';
		}
		if ( '/' !== substr( $p, -1 ) ) {
			$p .= '/';
		}
		return $p;
	}

	/**
	 * Ensure a trailing wildcard.
	 *
	 * @param string $p Path.
	 * @return string
	 */
	private static function ensure_star( $p ) {
		return ( '*' === substr( $p, -1 ) ) ? $p : $p . '*';
	}
}
