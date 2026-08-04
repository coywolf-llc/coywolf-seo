<?php
/**
 * Standalone regression tests for the breadcrumb assembly, rendering, and
 * schema-node builders (Coywolf_SEO_Breadcrumbs::assemble / render_list /
 * schema_node). These are the pure, WordPress-light methods; the few WP
 * functions they touch are stubbed, so the suite runs directly:
 *
 *     php tests/test-breadcrumbs.php
 *
 * Exits non-zero on any failure. Excluded from the distributed plugin zip.
 *
 * @package Coywolf_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return $text; } // phpcs:ignore
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); } // phpcs:ignore
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); } // phpcs:ignore
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { return (string) $url; } // phpcs:ignore
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ) { return trim( preg_replace( '/<[^>]*>/', '', (string) $string ) ); } // phpcs:ignore
}
if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( $class ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class ); } // phpcs:ignore
}

// The renderer reads breadcrumbs_separator_custom to decide the preset vs
// custom modifier class; a stub with no custom separator exercises the presets.
if ( ! class_exists( 'Coywolf_SEO_Options' ) ) {
	class Coywolf_SEO_Options { // phpcs:ignore
		public static function get( $key ) { return ''; } // phpcs:ignore
	}
}

require __DIR__ . '/../includes/class-coywolf-seo-breadcrumbs.php';

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

$B = 'Coywolf_SEO_Breadcrumbs';

$home = array( 'label' => 'Acme', 'url' => 'https://ex.test/' );
$cats = array(
	array( 'label' => 'Guides', 'url' => 'https://ex.test/guides/' ),
	array( 'label' => 'Watches', 'url' => 'https://ex.test/guides/watches/' ),
);
$cur  = array( 'label' => 'Best Watches', 'url' => 'https://ex.test/best-watches/' );

/* 1. assemble: full trail, current shown. */
$items = $B::assemble( $home, $cats, $cur, array( 'show_current' => true ) );
is_eq( 'assemble: count', count( $items ), 4 );
is_eq( 'assemble: home first + flagged', array( $items[0]['label'], $items[0]['home'], $items[0]['current'] ), array( 'Acme', true, false ) );
is_eq( 'assemble: ancestors in order', array( $items[1]['label'], $items[2]['label'] ), array( 'Guides', 'Watches' ) );
is_eq( 'assemble: current last + flagged', array( $items[3]['label'], $items[3]['current'] ), array( 'Best Watches', true ) );

/* 2. assemble: current hidden. */
$items = $B::assemble( $home, $cats, $cur, array( 'show_current' => false ) );
is_eq( 'assemble: no current when hidden', count( $items ), 3 );
is_eq( 'assemble: last is the category', $items[2]['label'], 'Watches' );

/* 3. assemble: home omitted (show_home handled upstream => null home). */
$items = $B::assemble( null, $cats, $cur, array( 'show_current' => true ) );
is_eq( 'assemble: no home => 3 items', count( $items ), 3 );
is_eq( 'assemble: first is ancestor', $items[0]['label'], 'Guides' );
ok( 'assemble: no item flagged home', false === $items[0]['home'] );

/* 4. assemble: empty-label crumbs are dropped. */
$items = $B::assemble( array( 'label' => '  ', 'url' => 'x' ), array( array( 'label' => '', 'url' => 'y' ), $cats[0] ), $cur, array( 'show_current' => true ) );
is_eq( 'assemble: blanks skipped', count( $items ), 2 );
is_eq( 'assemble: kept ancestor', $items[0]['label'], 'Guides' );

/* 5. render_list: structure, roles, escaping. (sep_class is resolved upstream
   by resolve_options(); render_list consumes it verbatim.) */
$opts = array( 'separator' => 'chevron', 'sep_class' => 'coywolf-seo-breadcrumbs--sep-chevron', 'class' => '', 'aria_label' => 'Breadcrumb' );
$html = $B::render_list( $B::assemble( $home, $cats, $cur, array( 'show_current' => true ) ), $opts );
ok( 'render: nav with aria-label', false !== strpos( $html, '<nav class="coywolf-seo-breadcrumbs coywolf-seo-breadcrumbs--sep-chevron" aria-label="Breadcrumb">' ) );
ok( 'render: ordered list', false !== strpos( $html, '<ol class="coywolf-seo-breadcrumbs__list">' ) );
ok( 'render: home modifier on first item', false !== strpos( $html, 'coywolf-seo-breadcrumbs__item--home' ) );
ok( 'render: ancestor is a link', false !== strpos( $html, '<a class="coywolf-seo-breadcrumbs__link" href="https://ex.test/guides/">Guides</a>' ) );
ok( 'render: current is a span, not a link', false !== strpos( $html, '<span class="coywolf-seo-breadcrumbs__current" aria-current="page">Best Watches</span>' ) );
ok( 'render: current not linked', false === strpos( $html, 'href="https://ex.test/best-watches/"' ) );

/* 5b. render_list: HTML entities in a label are escaped. */
$html = $B::render_list( $B::assemble( $home, array( array( 'label' => 'Tom & Jerry', 'url' => 'https://ex.test/t/' ) ), $cur, array( 'show_current' => true ) ), $opts );
ok( 'render: ampersand escaped', false !== strpos( $html, 'Tom &amp; Jerry' ) );
ok( 'render: raw ampersand absent', false === strpos( $html, 'Tom & Jerry' ) );

/* 5c. render_list: extra class is sanitized and appended. */
$html = $B::render_list( $B::assemble( $home, $cats, $cur, array( 'show_current' => true ) ), array( 'separator' => 'slash', 'sep_class' => 'coywolf-seo-breadcrumbs--sep-slash', 'class' => 'my-crumbs bad!class', 'aria_label' => 'Breadcrumb' ) );
ok( 'render: sanitized extra class present', false !== strpos( $html, 'coywolf-seo-breadcrumbs--sep-slash my-crumbs badclass' ) );

/* 5d. render_list: below two crumbs renders nothing. */
is_eq( 'render: single crumb => empty', $B::render_list( array( array( 'label' => 'Only', 'url' => 'x', 'current' => false, 'home' => true ) ), $opts ), '' );

/* 6. schema_node: BreadcrumbList positions + item URLs. */
$node = $B::schema_node( $B::assemble( $home, $cats, $cur, array( 'show_current' => true ) ), 'https://ex.test/best-watches/#breadcrumb' );
is_eq( 'schema: type', $node['@type'], 'BreadcrumbList' );
is_eq( 'schema: id', $node['@id'], 'https://ex.test/best-watches/#breadcrumb' );
is_eq( 'schema: element count', count( $node['itemListElement'] ), 4 );
is_eq( 'schema: first position', $node['itemListElement'][0]['position'], 1 );
is_eq( 'schema: first name', $node['itemListElement'][0]['name'], 'Acme' );
is_eq( 'schema: first item url', $node['itemListElement'][0]['item'], 'https://ex.test/' );
is_eq( 'schema: last position', $node['itemListElement'][3]['position'], 4 );
is_eq( 'schema: last name', $node['itemListElement'][3]['name'], 'Best Watches' );

/* 6b. schema_node: a crumb with no URL omits `item`. */
$node = $B::schema_node( array(
	array( 'label' => 'Acme', 'url' => 'https://ex.test/', 'current' => false, 'home' => true ),
	array( 'label' => 'Here', 'url' => '', 'current' => true, 'home' => false ),
), 'https://ex.test/here/#breadcrumb' );
ok( 'schema: url-less crumb has no item', ! isset( $node['itemListElement'][1]['item'] ) );
is_eq( 'schema: url-less crumb keeps name', $node['itemListElement'][1]['name'], 'Here' );

/* 6c. schema_node: fewer than two crumbs => null. */
is_eq( 'schema: single crumb => null', $B::schema_node( array( array( 'label' => 'Home', 'url' => 'x', 'current' => false, 'home' => true ) ), 'x#breadcrumb' ), null );

printf( "\n%d checks, %d failed\n", $GLOBALS['n'], $GLOBALS['fail'] );
exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
