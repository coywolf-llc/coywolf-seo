<?php
/**
 * Standalone regression tests for the WP-light logic of the Post ↔ Page type
 * switcher (Coywolf_SEO_Post_Type_Switch::switch_blocker /
 * should_create_redirect / build_undo_record / summary_message). Runs without
 * WordPress — the few i18n functions are stubbed:
 *
 *     php tests/test-post-type-switch.php
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
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) { return ( 1 === (int) $number ) ? $single : $plural; } // phpcs:ignore
}

require __DIR__ . '/../includes/class-coywolf-seo-post-type-switch.php';

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
		printf( "FAIL  %-52s\n        got=%s\n        want=%s\n", $label, var_export( $got, true ), var_export( $want, true ) );
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

$S = 'Coywolf_SEO_Post_Type_Switch';

/* 1. switch_blocker */
is_eq( 'blocker: post->page ok', $S::switch_blocker( 'post', 'page', 'publish', 5, 0, 0 ), '' );
is_eq( 'blocker: page->post ok', $S::switch_blocker( 'page', 'post', 'publish', 5, 0, 0 ), '' );
is_eq( 'blocker: same type', $S::switch_blocker( 'post', 'post', 'publish', 5, 0, 0 ), 'same_type' );
is_eq( 'blocker: current not switchable', $S::switch_blocker( 'attachment', 'page', 'publish', 5, 0, 0 ), 'not_switchable' );
is_eq( 'blocker: target not switchable', $S::switch_blocker( 'post', 'attachment', 'publish', 5, 0, 0 ), 'not_switchable' );
is_eq( 'blocker: trash', $S::switch_blocker( 'post', 'page', 'trash', 5, 0, 0 ), 'trash' );
is_eq( 'blocker: auto-draft not saved', $S::switch_blocker( 'post', 'page', 'auto-draft', 5, 0, 0 ), 'not_saved' );
is_eq( 'blocker: front page', $S::switch_blocker( 'page', 'post', 'publish', 7, 7, 0 ), 'front_page' );
is_eq( 'blocker: posts page', $S::switch_blocker( 'page', 'post', 'publish', 9, 0, 9 ), 'posts_page' );
is_eq( 'blocker: front id 0 never blocks', $S::switch_blocker( 'post', 'page', 'publish', 5, 0, 0 ), '' );
is_eq( 'blocker: non-front page passes', $S::switch_blocker( 'page', 'post', 'publish', 8, 7, 9 ), '' );

/* 2. should_create_redirect */
ok( 'redirect: happy path', true === $S::should_create_redirect( '/old/', '/new/', true, true, false ) );
ok( 'redirect: path unchanged', false === $S::should_create_redirect( '/x/', '/x/', true, true, false ) );
ok( 'redirect: old is /', false === $S::should_create_redirect( '/', '/new/', true, true, false ) );
ok( 'redirect: not public', false === $S::should_create_redirect( '/old/', '/new/', false, true, false ) );
ok( 'redirect: feature off', false === $S::should_create_redirect( '/old/', '/new/', true, false, false ) );
ok( 'redirect: already covered', false === $S::should_create_redirect( '/old/', '/new/', true, true, true ) );
ok( 'redirect: empty old path', false === $S::should_create_redirect( '', '/new/', true, true, false ) );

/* 3. build_undo_record */
$rec = $S::build_undo_record( 'post', 7, array( '3', 12 ), '41', array( 22, '23' ), 'coywolf_seo_switch_abc', 1754000000 );
is_eq( 'undo: from', $rec['from'], 'post' );
is_eq( 'undo: parent int', $rec['parent'], 7 );
is_eq( 'undo: cats coerced', $rec['cats'], array( 3, 12 ) );
is_eq( 'undo: redirect id int', $rec['redirect_id'], 41 );
is_eq( 'undo: children coerced', $rec['children'], array( 22, 23 ) );
is_eq( 'undo: batch', $rec['batch'], 'coywolf_seo_switch_abc' );
is_eq( 'undo: time int', $rec['time'], 1754000000 );
is_eq( 'undo: key set', array_keys( $rec ), array( 'from', 'parent', 'cats', 'redirect_id', 'children', 'batch', 'time' ) );

/* 4. summary_message */
$m1 = $S::summary_message( array( 'converted' => 3, 'redirects' => 2, 'skipped' => array( array( 'reason' => 'front_page' ) ) ), 'page' );
ok( 'summary: 3 posts to pages', false !== strpos( $m1, 'Changed 3 posts to pages.' ) );
ok( 'summary: 2 redirects', false !== strpos( $m1, '2 redirects created.' ) );
ok( 'summary: skipped front page', false !== strpos( $m1, 'Skipped 1 (front page).' ) );

$m2 = $S::summary_message( array( 'converted' => 1, 'redirects' => 0, 'skipped' => array() ), 'post' );
ok( 'summary: 1 page to a post', false !== strpos( $m2, 'Changed 1 page to a post.' ) );
ok( 'summary: no redirect clause when zero', false === strpos( $m2, 'redirect' ) );
ok( 'summary: no skipped clause when none', false === strpos( $m2, 'Skipped' ) );

$m3 = $S::summary_message( array( 'converted' => 2, 'redirects' => 1, 'skipped' => array( array( 'reason' => 'no_permission' ), array( 'reason' => 'no_permission' ) ) ), 'page' );
ok( 'summary: 1 redirect singular', false !== strpos( $m3, '1 redirect created.' ) );
ok( 'summary: skipped 2 grouped', false !== strpos( $m3, 'Skipped 2 (no permission).' ) );

printf( "\n%d checks, %d failed\n", $GLOBALS['n'], $GLOBALS['fail'] );
exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
