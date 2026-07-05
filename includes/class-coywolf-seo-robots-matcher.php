<?php
/**
 * Robots.txt path matcher — a faithful PHP port of Google's open-source
 * Robots.txt Parser and Matcher Library (https://github.com/google/robotstxt),
 * the reference implementation of the Robots Exclusion Protocol (RFC 9309) that
 * Googlebot itself uses.
 *
 * Why port it rather than match with a regex:
 *  - Correctness. It implements the REP wildcard semantics exactly — `*` matches
 *    any run of characters (including `/`), `$` anchors the end of the path, and
 *    everything else matches literally — the same way Google evaluates them.
 *  - Safety. The matcher is a linear O(pattern × path) sweep with no
 *    backtracking, so a hostile or clumsy pattern full of `*` can never trigger
 *    catastrophic regex backtracking (ReDoS) the way a translated-to-regex
 *    matcher can.
 *  - Normalization. Pattern and path are canonicalised the same way Google does
 *    (uppercase existing %XX escapes, percent-encode non-ASCII bytes) so
 *    equivalent encodings compare equal.
 *
 * Ports `RobotsMatcher::Matches()` and `MaybeEscapePattern()` from robots.cc.
 *
 * @package Coywolf_SEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stateless REP path matcher.
 */
final class Coywolf_SEO_Robots_Matcher {

	/**
	 * Whether a robots.txt path pattern matches a URL path, using Google's REP
	 * semantics: `*` matches any sequence of characters, a trailing `$` anchors
	 * the end of the path, and all other characters match literally. Both inputs
	 * are normalised with {@see self::escape_pattern()} first so equivalent
	 * percent-encodings compare equal.
	 *
	 * Direct port of `RobotsMatcher::Matches()` (robots.cc): a forward sweep that
	 * tracks the set of path offsets still reachable, so it runs in linear time
	 * and never backtracks.
	 *
	 * @param string $pattern Directive value (e.g. "/private/", "/*.php$").
	 * @param string $path    URL path (e.g. "/private/page").
	 * @return bool
	 */
	public static function matches( $pattern, $path ) {
		// Normalize BOTH sides so equivalent encodings compare equal — the right
		// behavior for the admin's per-rule tester and for covers_subtree(),
		// where both inputs are author-entered. Google's full-file matcher instead
		// escapes only the pattern at parse time and leaves the request path raw,
		// comparing that raw path with match_raw() directly. See Coywolf_SEO_Robots_Rep.
		return self::match_raw( self::escape_pattern( (string) $pattern ), self::escape_pattern( (string) $path ) );
	}

	/**
	 * The raw REP wildcard match with NO normalization of either argument — a
	 * direct port of `RobotsMatchStrategy::Matches()`. `*` matches any run of
	 * bytes, a trailing `$` anchors the end of the path, every other byte
	 * (including a non-terminal `$`) matches literally, and the pattern is
	 * anchored at the start of the path. Linear and backtracking-free.
	 *
	 * Callers that need percent-encoding normalization should escape their
	 * argument(s) first (see {@see self::matches()}).
	 *
	 * @param string $pattern Pattern (already in whatever encoding you intend to compare).
	 * @param string $path    Path (compared byte-for-byte against the pattern).
	 * @return bool
	 */
	public static function match_raw( $pattern, $path ) {
		$pattern = (string) $pattern;
		$path    = (string) $path;

		$pathlen = strlen( $path );
		$plen    = strlen( $pattern );

		// $pos holds the set of reachable offsets into $path, kept in ascending
		// order. We start able to be at offset 0 only.
		$pos    = array( 0 );
		$numpos = 1;

		for ( $pi = 0; $pi < $plen; $pi++ ) {
			$pc = $pattern[ $pi ];

			// A `$` as the final pattern character anchors the end of the path:
			// the path matches iff its full length is currently reachable.
			if ( '$' === $pc && $pi + 1 === $plen ) {
				return $pos[ $numpos - 1 ] === $pathlen;
			}

			if ( '*' === $pc ) {
				// `*` makes every offset from the smallest currently reachable
				// position through the end of the path reachable.
				$numpos = $pathlen - $pos[0] + 1;
				for ( $i = 1; $i < $numpos; $i++ ) {
					$pos[ $i ] = $pos[ $i - 1 ] + 1;
				}
			} else {
				// A literal (this includes a `$` that is NOT the last character):
				// keep only the reachable offsets whose next path byte matches,
				// advanced by one.
				$newnumpos = 0;
				for ( $i = 0; $i < $numpos; $i++ ) {
					if ( $pos[ $i ] < $pathlen && $path[ $pos[ $i ] ] === $pc ) {
						$pos[ $newnumpos++ ] = $pos[ $i ] + 1;
					}
				}
				$numpos = $newnumpos;
				if ( 0 === $numpos ) {
					return false;
				}
			}
		}

		// Whole pattern consumed with at least one reachable offset (an empty
		// pattern matches everything, as in the reference implementation).
		return true;
	}

	/**
	 * The longest-match priority of a pattern against a path: the length of the
	 * normalised pattern when it matches, or -1 when it does not. Mirrors
	 * Google's `LongestMatchRobotsMatchStrategy` (priority = pattern length), so
	 * that callers can compare Allow vs Disallow by specificity.
	 *
	 * @param string $pattern Directive value.
	 * @param string $path    URL path.
	 * @return int Length of the normalised pattern, or -1 if no match.
	 */
	public static function match_priority( $pattern, $path ) {
		if ( '' === (string) $pattern ) {
			return -1;
		}
		return self::matches( $pattern, $path ) ? strlen( self::escape_pattern( (string) $pattern ) ) : -1;
	}

	/**
	 * Canonicalise a pattern or path for comparison — a port of
	 * `MaybeEscapePattern()` (robots.cc). It uppercases the hex of any existing
	 * `%xx` escape and percent-encodes any byte with the high bit set (non-ASCII
	 * / UTF-8), leaving everything else untouched. Applying it to BOTH the
	 * pattern and the path means equivalent encodings (e.g. "/caf%C3%A9",
	 * "/caf%c3%a9", and a raw-UTF-8 "/café") all compare equal.
	 *
	 * @param string $s Pattern or path.
	 * @return string
	 */
	public static function escape_pattern( $s ) {
		$s   = (string) $s;
		$len = strlen( $s );

		// First pass: most inputs need no change, so scan before allocating.
		$need = false;
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $s[ $i ];
			if ( '%' === $c && $i + 2 < $len && ctype_xdigit( $s[ $i + 1 ] ) && ctype_xdigit( $s[ $i + 2 ] ) ) {
				if ( ctype_lower( $s[ $i + 1 ] ) || ctype_lower( $s[ $i + 2 ] ) ) {
					$need = true;
				}
				$i += 2;
			} elseif ( ord( $c ) & 0x80 ) {
				$need = true;
			}
		}
		if ( ! $need ) {
			return $s;
		}

		$out = '';
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $s[ $i ];
			if ( '%' === $c && $i + 2 < $len && ctype_xdigit( $s[ $i + 1 ] ) && ctype_xdigit( $s[ $i + 2 ] ) ) {
				$out .= '%' . strtoupper( $s[ $i + 1 ] ) . strtoupper( $s[ $i + 2 ] );
				$i   += 2;
			} elseif ( ord( $c ) & 0x80 ) {
				$out .= '%' . strtoupper( bin2hex( $c ) );
			} else {
				$out .= $c;
			}
		}
		return $out;
	}

	/**
	 * Whether a Disallow pattern $broad blocks every URL that a more-specific
	 * literal Disallow $specific would block — i.e. $specific is redundant under
	 * $broad. True when $broad matches $specific itself AND an arbitrary
	 * descendant of it, so an anchored ($-terminated) or otherwise narrower
	 * $broad correctly does NOT subsume the subtree.
	 *
	 * $specific must be a literal path (no wildcards); the caller guarantees it.
	 *
	 * @param string $broad    Existing Disallow value (may contain wildcards).
	 * @param string $specific Candidate literal Disallow value.
	 * @return bool
	 */
	public static function covers_subtree( $broad, $specific ) {
		$broad    = (string) $broad;
		$specific = (string) $specific;
		if ( '' === $broad || '' === $specific ) {
			return false;
		}
		// Normalize both sides so equivalent percent-encodings compare equal
		// (raw UTF-8 vs %xx, lower- vs upper-case hex) before the fast-path
		// prefix check. escape_pattern() only rewrites %xx hex case and
		// percent-encodes high-bit bytes — it never introduces '*' or '$', so
		// the wildcard detection below stays valid, and it is idempotent, so
		// the matches() calls in the wildcard branch (which escape again) are
		// unaffected.
		$broad    = self::escape_pattern( $broad );
		$specific = self::escape_pattern( $specific );
		// Fast path: a literal, non-anchored prefix covers by prefix match.
		if ( false === strpos( $broad, '*' ) && false === strpos( $broad, '$' ) ) {
			return 0 === strpos( $specific, $broad );
		}
		// Wildcard covering pattern: it must match the path itself and any
		// descendant under it. The sentinel is a byte run no real path or
		// pattern can produce, standing in for "anything deeper".
		$descendant = $specific . "\x01\x01";
		return self::matches( $broad, $specific ) && self::matches( $broad, $descendant );
	}
}
