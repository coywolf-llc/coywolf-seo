<?php
/**
 * Standalone regression tests for the Robots.txt rule engine — the REP matcher
 * port and the rule validator/optimizer. Runs without a WordPress install (the
 * handful of WP functions the code touches are stubbed below), so it can be run
 * directly:
 *
 *     php tests/test-robots-rules.php
 *
 * Exits non-zero on any failure. Excluded from the distributed plugin zip.
 *
 * @package Coywolf_SEO
 */

// Minimal WordPress stubs (only what the exercised code paths call).
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return $text; } // phpcs:ignore
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { return $text; } // phpcs:ignore
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { return $text; } // phpcs:ignore
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) { return $url; } // phpcs:ignore
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return trim( (string) $s ); } // phpcs:ignore
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ); } // phpcs:ignore
}

require __DIR__ . '/../includes/class-coywolf-seo-robots-matcher.php';
require __DIR__ . '/../includes/class-coywolf-seo-robots-rules.php';

$GLOBALS['cys_n']    = 0;
$GLOBALS['cys_fail'] = 0;

/**
 * @param string $label Assertion label.
 * @param mixed  $got   Actual.
 * @param mixed  $want  Expected.
 */
function cys_is( $label, $got, $want ) {
	$GLOBALS['cys_n']++;
	if ( $got !== $want ) {
		$GLOBALS['cys_fail']++;
		printf( "FAIL  %-58s got=%s want=%s\n", $label, var_export( $got, true ), var_export( $want, true ) );
	}
}

$M = 'Coywolf_SEO_Robots_Matcher';
$R = 'Coywolf_SEO_Robots_Rules';

/** Build a single-directive rule. */
function cys_rule( $directive, $path, $id = '' ) {
	$r = array( 'agents' => array( '*' ), 'type' => 'custom', 'directive' => $directive, 'path' => $path );
	if ( '' !== $id ) {
		$r['id'] = $id;
	}
	return $r;
}

echo "== matcher conformance (Google REP spec examples) ==\n";
$matcher_cases = array(
	array( '/fish', '/fish', true ), array( '/fish', '/fish.html', true ), array( '/fish', '/fish/salmon.html', true ),
	array( '/fish', '/fishheads', true ), array( '/fish', '/fish.php?id=x', true ),
	array( '/fish', '/Fish.asp', false ), array( '/fish', '/catfish', false ), array( '/fish', '/desert/fish', false ),
	array( '/fish/', '/fish/', true ), array( '/fish/', '/fish/x', true ), array( '/fish/', '/fish', false ), array( '/fish/', '/fish.html', false ),
	array( '/*.php', '/index.php', true ), array( '/*.php', '/folder/x.php', true ), array( '/*.php', '/x.php?p', true ),
	array( '/*.php', '/x.php/', true ), array( '/*.php', '/', false ), array( '/*.php', '/windows.PHP', false ),
	array( '/*.php$', '/x.php', true ), array( '/*.php$', '/x.php?p', false ), array( '/*.php$', '/x.php/', false ), array( '/*.php$', '/x.php5', false ),
	array( '/fish*.php', '/fish.php', true ), array( '/fish*.php', '/fishheads/catfish.php?p', true ), array( '/fish*.php', '/Fish.PHP', false ),
);
foreach ( $matcher_cases as $c ) {
	cys_is( "matches('{$c[0]}','{$c[1]}')", $M::matches( $c[0], $c[1] ), $c[2] );
}
cys_is( 'escape_pattern uppercases %xx', $M::escape_pattern( '/caf%c3%a9' ), '/caf%C3%A9' );
cys_is( 'escape_pattern encodes raw UTF-8', $M::escape_pattern( "/caf\xC3\xA9" ), '/caf%C3%A9' );

echo "\n== Task 1: longest-match tie bug fix (>= boundary) ==\n";
// {Disallow:/*a, Allow:/*d}; adding Disallow:/a/a is NOT redundant — it re-blocks
// /a/a/d, which without it is a 3-vs-3 Allow-wins tie (allowed).
$exist_tie = array( cys_rule( 'disallow', '/*a', '1' ), cys_rule( 'allow', '/*d', '2' ) );
cys_is( 'tie: /a/a not redundant under {/*a,/*d}', count( $R::redundancies( cys_rule( 'disallow', '/a/a' ), $exist_tie ) ), 0 );
// Why: the matcher shows /a/a/d blocked WITH /a/a (len 4) and allowed WITHOUT it (3v3 tie -> Allow).
cys_is( 'matcher: /a/a matches /a/a/d', $M::matches( '/a/a', '/a/a/d' ), true );
cys_is( 'matcher: /*a matches /a/a/d', $M::matches( '/*a', '/a/a/d' ), true );
cys_is( 'matcher: /*d matches /a/a/d', $M::matches( '/*d', '/a/a/d' ), true );
cys_is( 'priority: /a/a (4) > /*a (3)', strlen( $M::escape_pattern( '/a/a' ) ) > strlen( $M::escape_pattern( '/*a' ) ), true );
// Control: no Allow -> genuine redundancy still detected.
cys_is( 'control: /a/a IS redundant under {/*a} alone', count( $R::redundancies( cys_rule( 'disallow', '/a/a' ), array( cys_rule( 'disallow', '/*a', '1' ) ) ) ) > 0, true );
// Control: an Allow SHORTER than the cover does not save it.
$exist_shortallow = array( cys_rule( 'disallow', '/a/', '1' ), cys_rule( 'allow', '/', '2' ) );
cys_is( 'control: /a/b/ redundant under {/a/} with only shorter Allow /', count( $R::redundancies( cys_rule( 'disallow', '/a/b/' ), $exist_shortallow ) ) > 0, true );
// Equal-length analog: a same-length Allow keeps a deeper literal block meaningful.
$exist_analog = array( cys_rule( 'disallow', '/*/admin/', '1' ), cys_rule( 'allow', '/*/public/', '2' ) );
cys_is( 'analog: /shop/admin/secret not flagged under {/*/admin/,/*/public/}', count( $R::redundancies( cys_rule( 'disallow', '/shop/admin/secret' ), $exist_analog ) ), 0 );

echo "\n== redundancy/optimizer regression (wildcard-aware, FP-safe) ==\n";
cys_is( 'literal cover /a/ -> /a/b/ redundant', count( $R::redundancies( cys_rule( 'disallow', '/a/b/' ), array( cys_rule( 'disallow', '/a/', '1' ) ) ) ) > 0, true );
cys_is( 'wildcard cover /a* -> /a/b/ redundant', count( $R::redundancies( cys_rule( 'disallow', '/a/b/' ), array( cys_rule( 'disallow', '/a*', '1' ) ) ) ) > 0, true );
cys_is( 'FP guard: Allow /a/*x keeps /a/sub/ meaningful', count( $R::redundancies( cys_rule( 'disallow', '/a/sub/' ), array( cys_rule( 'disallow', '/a/', '1' ), cys_rule( 'allow', '/a/*x', '2' ) ) ) ), 0 );
cys_is( 'disjoint Allow /xyz ignored -> /abcd/ redundant', count( $R::redundancies( cys_rule( 'disallow', '/abcd/' ), array( cys_rule( 'disallow', '/', '1' ), cys_rule( 'allow', '/xyz', '2' ) ) ) ) > 0, true );

echo "\n----------------------------------------\n";
printf( "%d checks, %d failures\n", $GLOBALS['cys_n'], $GLOBALS['cys_fail'] );
exit( $GLOBALS['cys_fail'] ? 1 : 0 );
