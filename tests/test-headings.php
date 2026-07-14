<?php
/**
 * Standalone regression tests for the automatic heading-anchor engine
 * (Coywolf_SEO_Headings::inject / unique_slug) shared by the site-wide pass
 * and the Table of Contents block. Runs without WordPress (the few WP
 * functions it touches are stubbed), so it can be run directly:
 *
 *     php tests/test-headings.php
 *
 * Exits non-zero on any failure. Excluded from the distributed plugin zip.
 *
 * @package Coywolf_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ) { // phpcs:ignore
		return trim( wp_strip_tags_stub( (string) $string ) );
	}
	function wp_strip_tags_stub( $s ) { return preg_replace( '/<[^>]*>/', '', $s ); } // phpcs:ignore
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); } // phpcs:ignore
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) { // phpcs:ignore
		$title = strtolower( preg_replace( '/<[^>]*>/', '', (string) $title ) );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
		return trim( $title, '-' );
	}
}

require __DIR__ . '/../includes/class-coywolf-seo-headings.php';

$GLOBALS['n']    = 0;
$GLOBALS['fail'] = 0;

/**
 * @param string $label Label.
 * @param mixed  $got   Actual.
 * @param mixed  $want  Expected.
 */
function is_eq( $label, $got, $want ) {
	$GLOBALS['n']++;
	if ( $got !== $want ) {
		$GLOBALS['fail']++;
		printf( "FAIL  %-56s\n        got=%s\n        want=%s\n", $label, var_export( $got, true ), var_export( $want, true ) );
	}
}
/** @param string $label Label. @param bool $cond Condition. */
function ok( $label, $cond ) {
	$GLOBALS['n']++;
	if ( ! $cond ) {
		$GLOBALS['fail']++;
		printf( "FAIL  %s\n", $label );
	}
}

$H = 'Coywolf_SEO_Headings';

/* 1. Basic injection: jump- prefixed slugs into h2/h3. */
$html  = '<h2>Best Watches</h2><p>x</p><h3>Sub Heading</h3>';
$items = $H::inject( $html, array( 2, 3 ) );
ok( 'basic: h2 got jump-best-watches', false !== strpos( $html, '<h2 id="jump-best-watches">' ) );
ok( 'basic: h3 got jump-sub-heading', false !== strpos( $html, '<h3 id="jump-sub-heading">' ) );
is_eq( 'basic: item count', count( $items ), 2 );
is_eq( 'basic: item[0]', $items[0], array( 'level' => 2, 'id' => 'jump-best-watches', 'text' => 'Best Watches' ) );
is_eq( 'basic: item[1] id', $items[1]['id'], 'jump-sub-heading' );

/* 2. A manually set anchor is kept verbatim — never prefixed, never changed. */
$html  = '<h2 id="my-anchor">Title</h2>';
$items = $H::inject( $html, array( 2, 3 ) );
is_eq( 'manual anchor: content unchanged', $html, '<h2 id="my-anchor">Title</h2>' );
is_eq( 'manual anchor: item id kept', $items[0]['id'], 'my-anchor' );

/* 3. Uniqueness: duplicate texts get -2, -3 suffixes. */
$html  = '<h2>Watches</h2><h2>Watches</h2><h2>Watches</h2>';
$items = $H::inject( $html, array( 2 ) );
is_eq( 'unique: ids', array( $items[0]['id'], $items[1]['id'], $items[2]['id'] ), array( 'jump-watches', 'jump-watches-2', 'jump-watches-3' ) );
ok( 'unique: all three ids present in html', substr_count( $html, 'id="jump-watches' ) === 3 );

/* 4a. Exclude class (set): the excluded heading is skipped entirely — no id, not listed. */
$html  = '<h2 class="coywolf-seo-toc-exclude">Skip Me</h2><h2>Keep Me</h2>';
$items = $H::inject( $html, array( 2 ), 'coywolf-seo-toc-exclude' );
is_eq( 'exclude set: only one item', count( $items ), 1 );
is_eq( 'exclude set: kept item', $items[0]['text'], 'Keep Me' );
ok( 'exclude set: excluded heading got no id', false === strpos( $html, 'Skip Me</h2>' ) ? false : ( false === strpos( substr( $html, 0, strpos( $html, 'Skip Me' ) ), 'id=' ) ) );

/* 4b. Exclude class empty (site-wide pass): every heading gets an id, including one carrying the class. */
$html  = '<h2 class="coywolf-seo-toc-exclude">Skip In Toc</h2><h2>Normal</h2>';
$items = $H::inject( $html, array( 2 ), '' );
is_eq( 'exclude empty: both items', count( $items ), 2 );
ok( 'exclude empty: class-bearing heading still got a jump id', false !== strpos( $html, 'id="jump-skip-in-toc"' ) );

/* 5. Level filter: headings outside the requested levels are untouched. */
$html  = '<h2>Wanted</h2><h4>Unwanted</h4>';
$items = $H::inject( $html, array( 2, 3 ) );
is_eq( 'levels: only the h2 listed', count( $items ), 1 );
is_eq( 'levels: h4 left untouched', $html, '<h2 id="jump-wanted">Wanted</h2><h4>Unwanted</h4>' );

/* 6. Scroll offset adds scroll-margin-top to processed headings. */
$html = '<h2>Offset Me</h2>';
$H::inject( $html, array( 2 ), '', 40 );
ok( 'scroll: id injected', false !== strpos( $html, 'id="jump-offset-me"' ) );
ok( 'scroll: scroll-margin-top added', false !== strpos( $html, 'scroll-margin-top:40px' ) );

/* 7. Empty-text heading is skipped. */
$html  = '<h2></h2><h2>Real</h2>';
$items = $H::inject( $html, array( 2 ) );
is_eq( 'empty heading skipped', count( $items ), 1 );
is_eq( 'empty heading: real one kept', $items[0]['id'], 'jump-real' );

/* 8. Generated slug never collides with an existing id elsewhere in the doc. */
$html  = '<div id="jump-news"></div><h2>News</h2>';
$items = $H::inject( $html, array( 2 ) );
is_eq( 'collision: heading avoids the taken id', $items[0]['id'], 'jump-news-2' );

/* 9. unique_slug directly: prefix + fallback for empty. */
$taken = array();
is_eq( 'slug: basic', $H::unique_slug( 'Hello World', $taken ), 'jump-hello-world' );
is_eq( 'slug: dup', $H::unique_slug( 'Hello World', $taken ), 'jump-hello-world-2' );
$taken2 = array();
is_eq( 'slug: empty text falls back to section', $H::unique_slug( '###', $taken2 ), 'jump-section' );

printf( "\n%d checks, %d failed\n", $GLOBALS['n'], $GLOBALS['fail'] );
exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
