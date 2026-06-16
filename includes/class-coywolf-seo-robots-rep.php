<?php
/**
 * Full Robots Exclusion Protocol evaluator — a faithful PHP port of Google's
 * open-source robots.txt Parser and Matcher Library (RFC 9309 reference,
 * https://github.com/google/robotstxt), beyond the single-pattern matcher in
 * {@see Coywolf_SEO_Robots_Matcher}.
 *
 * This evaluates a URL against an ENTIRE robots.txt for a given user-agent the
 * way Googlebot does: it parses the file into directives (with Google's BOM /
 * CRLF / missing-colon / frequent-typo tolerances and line cap), selects the
 * most-specific matching user-agent group, and resolves Allow vs Disallow by
 * longest match with ties going to Allow — so interacting rules across the file
 * are evaluated together, not one rule in isolation.
 *
 * Ports robots.cc: ParseRobotsTxt / RobotsTxtParser, GetPathParamsQuery,
 * GetKeyType (+ typo keys), MaybeEscapePattern (via the matcher), and
 * RobotsMatcher (HandleUserAgent group logic, HandleAllow incl. the index.htm
 * -> "/" optimization, HandleDisallow, and the disallow() decision).
 *
 * Fidelity note: Google escapes only Allow/Disallow VALUES, at parse time, and
 * leaves the request path raw, comparing them with the raw matcher. So this
 * class escapes values during parse() and matches against the un-escaped path
 * via {@see Coywolf_SEO_Robots_Matcher::match_raw()} — NOT matches(), which
 * normalizes both sides.
 *
 * @package Coywolf_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whole-file, agent-aware REP evaluator.
 */
final class Coywolf_SEO_Robots_Rep {

	/**
	 * Certain browsers cap URLs at 2083 bytes; Google allows 8x that per line
	 * and drops the overflow. Mirrors kBrowserMaxLineLen * 8 in robots.cc.
	 */
	const MAX_LINE_LEN = 16664; // 2083 * 8.

	/**
	 * Extract path + params + query from a URL, dropping scheme, authority, and
	 * fragment. Always returns a string starting with "/". Port of
	 * GetPathParamsQuery() (robots.cc).
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	public static function path_params_query( $url ) {
		$url = (string) $url;
		$n   = strlen( $url );

		// Initial two slashes are ignored.
		$search_start = ( $n >= 2 && '/' === $url[0] && '/' === $url[1] ) ? 2 : 0;

		$early_path   = self::find_first_of( $url, '/?;', $search_start );
		$proto        = ( $search_start <= $n ) ? strpos( $url, '://', $search_start ) : false;
		$protocol_end = ( false === $proto ) ? PHP_INT_MAX : $proto;
		if ( $early_path < $protocol_end ) {
			// Path/param/query starts before "://", so it isn't a protocol.
			$protocol_end = PHP_INT_MAX;
		}
		if ( PHP_INT_MAX === $protocol_end ) {
			$protocol_end = $search_start;
		} else {
			$protocol_end += 3;
		}

		$path_start = self::find_first_of( $url, '/?;', $protocol_end );
		if ( PHP_INT_MAX !== $path_start ) {
			$hash     = strpos( $url, '#', $search_start );
			$hash_pos = ( false === $hash ) ? PHP_INT_MAX : $hash;
			if ( $hash_pos < $path_start ) {
				return '/';
			}
			$path_end = ( PHP_INT_MAX === $hash_pos ) ? $n : $hash_pos;
			if ( '/' !== $url[ $path_start ] ) {
				// Prepend a slash if the result would start e.g. with '?'.
				return '/' . substr( $url, $path_start, $path_end - $path_start );
			}
			return substr( $url, $path_start, $path_end - $path_start );
		}

		return '/';
	}

	/**
	 * First index at or after $offset of any byte in $chars; PHP_INT_MAX if none
	 * (mirrors std::string::find_first_of returning npos).
	 *
	 * @param string $s      Haystack.
	 * @param string $chars  Characters to find.
	 * @param int    $offset Start index.
	 * @return int
	 */
	private static function find_first_of( $s, $chars, $offset ) {
		$n = strlen( $s );
		for ( $i = $offset; $i < $n; $i++ ) {
			if ( false !== strpos( $chars, $s[ $i ] ) ) {
				return $i;
			}
		}
		return PHP_INT_MAX;
	}

	/**
	 * Reduce a user-agent to its leading product token: the run of [a-zA-Z_-]
	 * from the start, stopping at the first other byte (digit, dot, space, …).
	 * Port of RobotsMatcher::ExtractUserAgent().
	 *
	 * @param string $user_agent Raw user-agent token.
	 * @return string
	 */
	public static function extract_user_agent( $user_agent ) {
		$user_agent = (string) $user_agent;
		$len        = strlen( $user_agent );
		$i          = 0;
		while ( $i < $len ) {
			$c = $user_agent[ $i ];
			if ( ( $c >= 'a' && $c <= 'z' ) || ( $c >= 'A' && $c <= 'Z' ) || '-' === $c || '_' === $c ) {
				++$i;
			} else {
				break;
			}
		}
		return substr( $user_agent, 0, $i );
	}

	/**
	 * Whether a user-agent token is well-formed enough to obey — non-empty and
	 * composed solely of [a-zA-Z_-]. Port of IsValidUserAgentToObey().
	 *
	 * @param string $user_agent Token.
	 * @return bool
	 */
	public static function is_valid_user_agent_to_obey( $user_agent ) {
		$user_agent = (string) $user_agent;
		return strlen( $user_agent ) > 0 && self::extract_user_agent( $user_agent ) === $user_agent;
	}

	/**
	 * Classify a directive key (case-insensitive prefix match, Google's typo
	 * tolerance), in the same priority order as GetKeyType().
	 *
	 * @param string $key Directive key.
	 * @return string One of 'user-agent', 'allow', 'disallow', 'sitemap', 'unknown'.
	 */
	private static function key_type( $key ) {
		$k      = strtolower( (string) $key );
		$starts = static function ( $needle ) use ( $k ) {
			return 0 === strncmp( $k, $needle, strlen( $needle ) );
		};
		if ( $starts( 'user-agent' ) || $starts( 'useragent' ) || $starts( 'user agent' ) ) {
			return 'user-agent';
		}
		if ( $starts( 'allow' ) ) {
			return 'allow';
		}
		if ( $starts( 'disallow' ) || $starts( 'dissallow' ) || $starts( 'dissalow' )
			|| $starts( 'disalow' ) || $starts( 'diasllow' ) || $starts( 'disallaw' ) ) {
			return 'disallow';
		}
		if ( $starts( 'sitemap' ) || $starts( 'site-map' ) ) {
			return 'sitemap';
		}
		return 'unknown';
	}

	/**
	 * Parse a robots.txt body into an ordered directive list. Port of
	 * RobotsTxtParser::Parse() + GetKeyAndValueFrom(): skips a (possibly partial)
	 * UTF-8 BOM, counts \n / \r / \r\n lines, drops `#` comments, recovers a
	 * missing colon when a line is exactly two whitespace-separated tokens,
	 * caps each line at MAX_LINE_LEN, and percent-normalizes Allow/Disallow
	 * values (only) via MaybeEscapePattern.
	 *
	 * @param string $body robots.txt contents.
	 * @return array { lines:int, directives: array<int,array{line:int,type:string,key:string,value:string}> }
	 */
	public static function parse( $body ) {
		$body    = (string) $body;
		$n       = strlen( $body );
		$utf_bom = "\xEF\xBB\xBF";

		$directives = array();
		$line       = '';
		$line_num   = 0;
		$too_long   = false;
		$bom_pos    = 0;
		$last_cr    = false;

		$emit = static function ( $raw, $ln, $tl ) use ( &$directives ) {
			$d = self::parse_line( $raw, $ln, $tl );
			if ( null !== $d ) {
				$directives[] = $d;
			}
		};

		for ( $i = 0; $i < $n; $i++ ) {
			$ch = $body[ $i ];

			// Skip a UTF-8 BOM at the very start, byte by byte (a partial BOM is
			// consumed; the first non-matching byte ends BOM handling).
			if ( $bom_pos < 3 ) {
				$expected = $utf_bom[ $bom_pos ];
				++$bom_pos;
				if ( $ch === $expected ) {
					continue;
				}
				$bom_pos = 3;
			}

			if ( "\n" !== $ch && "\r" !== $ch ) {
				if ( strlen( $line ) < self::MAX_LINE_LEN - 1 ) {
					$line .= $ch;
				} else {
					$too_long = true;
				}
			} else {
				// Don't emit an empty line for the \n half of a \r\n.
				$is_crlf_continuation = ( '' === $line ) && $last_cr && "\n" === $ch;
				if ( ! $is_crlf_continuation ) {
					$emit( $line, ++$line_num, $too_long );
					$too_long = false;
				}
				$line    = '';
				$last_cr = ( "\r" === $ch );
			}
		}
		$emit( $line, ++$line_num, $too_long );

		return array(
			'lines'      => $line_num,
			'directives' => $directives,
		);
	}

	/**
	 * Parse one already-de-lined robots.txt line into a directive, or null for
	 * a blank/comment/garbage line.
	 *
	 * @param string $line     Raw line (no newline).
	 * @param int    $line_num 1-based line number.
	 * @param bool   $too_long Whether the line overflowed MAX_LINE_LEN.
	 * @return array{line:int,type:string,key:string,value:string}|null
	 */
	private static function parse_line( $line, $line_num, $too_long ) {
		unset( $too_long ); // captured for parity; not needed for matching.
		$ws = " \t\n\x0B\x0C\r";

		$hash = strpos( $line, '#' );
		if ( false !== $hash ) {
			$line = substr( $line, 0, $hash );
		}
		$line = trim( $line, $ws );
		if ( '' === $line ) {
			return null;
		}

		$sep = strpos( $line, ':' );
		if ( false === $sep ) {
			// Missing-colon recovery: accept a single run of whitespace as the
			// separator, but only when the line is exactly two tokens.
			if ( ! preg_match( '/[ \t]/', $line, $m, PREG_OFFSET_CAPTURE ) ) {
				return null;
			}
			$ws_pos    = $m[0][1];
			$after     = substr( $line, $ws_pos );
			$val_start = $ws_pos + strspn( $after, " \t" );
			if ( preg_match( '/[ \t]/', substr( $line, $val_start ) ) ) {
				return null; // More than two tokens.
			}
			$sep = $ws_pos;
		}

		$key = trim( substr( $line, 0, $sep ), $ws );
		if ( '' === $key ) {
			return null;
		}
		$value = trim( substr( $line, $sep + 1 ), $ws );

		$type = self::key_type( $key );
		if ( 'allow' === $type || 'disallow' === $type ) {
			$value = Coywolf_SEO_Robots_Matcher::escape_pattern( $value );
		}

		return array(
			'line'  => $line_num,
			'type'  => $type,
			'key'   => $key,
			'value' => $value,
		);
	}

	/**
	 * Evaluate a URL against a robots.txt body for one or more user-agents,
	 * returning a rich verdict. Port of RobotsMatcher::AllowedByRobots() plus
	 * matching_line()/the winning directive, for the testing UI.
	 *
	 * @param string            $body        robots.txt contents (e.g. the rendered managed rules).
	 * @param array<int,string> $user_agents Queried user-agent token(s).
	 * @param string            $url         URL or path to test.
	 * @return array{
	 *   allowed:bool, path:string, decision:string,
	 *   matched_directive:string, matched_value:string, matched_line:int, matched_scope:string
	 * }
	 */
	public static function evaluate( $body, array $user_agents, $url ) {
		$path   = self::path_params_query( $url );
		$parsed = self::parse( $body );

		// Match state, mirroring RobotsMatcher. Each Match is [ priority, line, value ].
		$no_match       = array( -1, 0, '' );
		$allow_global   = $no_match;
		$allow_specific = $no_match;
		$dis_global     = $no_match;
		$dis_specific   = $no_match;

		$seen_global    = false;
		$seen_specific  = false;
		$ever_specific  = false;
		$seen_separator = false;

		foreach ( $parsed['directives'] as $d ) {
			$type  = $d['type'];
			$value = $d['value'];
			$line  = $d['line'];

			if ( 'user-agent' === $type ) {
				if ( $seen_separator ) {
					$seen_specific  = false;
					$seen_global    = false;
					$seen_separator = false;
				}
				// "*" alone, or "*" followed by whitespace, is the global group.
				if ( strlen( $value ) >= 1 && '*' === $value[0]
					&& ( 1 === strlen( $value ) || self::is_space( $value[1] ) ) ) {
					$seen_global = true;
				} else {
					$token = self::extract_user_agent( $value );
					foreach ( $user_agents as $qa ) {
						if ( '' !== $token && 0 === strcasecmp( $token, (string) $qa ) ) {
							$ever_specific = true;
							$seen_specific = true;
							break;
						}
					}
				}
				continue;
			}

			if ( 'allow' === $type ) {
				if ( ! ( $seen_global || $seen_specific ) ) {
					continue;
				}
				$seen_separator = true;
				self::handle_allow( $path, $value, $line, $seen_specific, $allow_global, $allow_specific );
				continue;
			}

			if ( 'disallow' === $type ) {
				if ( ! ( $seen_global || $seen_specific ) ) {
					continue;
				}
				$seen_separator = true;
				$priority       = Coywolf_SEO_Robots_Matcher::match_raw( $value, $path ) ? strlen( $value ) : -1;
				if ( $priority >= 0 ) {
					if ( $seen_specific ) {
						if ( $dis_specific[0] < $priority ) {
							$dis_specific = array( $priority, $line, $value );
						}
					} elseif ( $dis_global[0] < $priority ) {
						$dis_global = array( $priority, $line, $value );
					}
				}
				continue;
			}
			// 'sitemap' / 'unknown': no effect on the crawl decision.
		}

		// disallow() — specific group shadows global; ties go to Allow.
		$scope    = 'global';
		$dis_pick = $dis_global;
		$alw_pick = $allow_global;
		$disallow = false;
		if ( $allow_specific[0] > 0 || $dis_specific[0] > 0 ) {
			$scope    = 'specific';
			$dis_pick = $dis_specific;
			$alw_pick = $allow_specific;
			$disallow = $dis_specific[0] > $allow_specific[0];
		} elseif ( $ever_specific ) {
			$scope    = 'specific';
			$disallow = false;
		} elseif ( $dis_global[0] > 0 || $allow_global[0] > 0 ) {
			$disallow = $dis_global[0] > $allow_global[0];
		}

		// Winning directive within the active scope (Allow wins ties).
		$matched_directive = 'none';
		$matched_value     = '';
		$matched_line      = 0;
		if ( $dis_pick[0] > 0 || $alw_pick[0] > 0 ) {
			if ( $dis_pick[0] > $alw_pick[0] ) {
				$matched_directive = 'disallow';
				$matched_value     = $dis_pick[2];
				$matched_line      = $dis_pick[1];
			} else {
				$matched_directive = 'allow';
				$matched_value     = $alw_pick[2];
				$matched_line      = $alw_pick[1];
			}
		}

		return array(
			'allowed'           => ! $disallow,
			'path'              => $path,
			'decision'          => $disallow ? 'disallow' : 'allow',
			'matched_directive' => $matched_directive,
			'matched_value'     => $matched_value,
			'matched_line'      => $matched_line,
			'matched_scope'     => $scope,
		);
	}

	/**
	 * Convenience boolean, mirroring OneAgentAllowedByRobots(): true if the
	 * single user-agent is allowed to fetch the URL.
	 *
	 * @param string $body       robots.txt contents.
	 * @param string $user_agent Queried user-agent.
	 * @param string $url        URL or path.
	 * @return bool
	 */
	public static function one_agent_allowed( $body, $user_agent, $url ) {
		$res = self::evaluate( $body, array( (string) $user_agent ), $url );
		return $res['allowed'];
	}

	/**
	 * HandleAllow with Google's index.htm/index.html -> "/" optimization: when
	 * the literal pattern doesn't match the path, if the last path segment of
	 * the pattern starts with "/index.htm", retry with the directory prefix
	 * (through the final slash) plus a "$" anchor.
	 *
	 * @param string $path           Request path (raw).
	 * @param string $value          Allow value (already escaped).
	 * @param int    $line           Line number.
	 * @param bool   $seen_specific  Whether the current group is the specific agent.
	 * @param array  $allow_global   Global Allow match [priority,line,value] (by ref).
	 * @param array  $allow_specific Specific Allow match [priority,line,value] (by ref).
	 */
	private static function handle_allow( $path, $value, $line, $seen_specific, &$allow_global, &$allow_specific ) {
		$priority = Coywolf_SEO_Robots_Matcher::match_raw( $value, $path ) ? strlen( $value ) : -1;
		if ( $priority >= 0 ) {
			if ( $seen_specific ) {
				if ( $allow_specific[0] < $priority ) {
					$allow_specific = array( $priority, $line, $value );
				}
			} elseif ( $allow_global[0] < $priority ) {
				$allow_global = array( $priority, $line, $value );
			}
			return;
		}

		$slash = strrpos( $value, '/' );
		if ( false !== $slash && 0 === strpos( substr( $value, $slash ), '/index.htm' ) ) {
			$new_pattern = substr( $value, 0, $slash + 1 ) . '$';
			self::handle_allow( $path, $new_pattern, $line, $seen_specific, $allow_global, $allow_specific );
		}
	}

	/**
	 * ASCII whitespace test matching C isspace (space, \t, \n, \v, \f, \r).
	 *
	 * @param string $c Single byte.
	 * @return bool
	 */
	private static function is_space( $c ) {
		return ' ' === $c || "\t" === $c || "\n" === $c || "\x0B" === $c || "\x0C" === $c || "\r" === $c;
	}
}
