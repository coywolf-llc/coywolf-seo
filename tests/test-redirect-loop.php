<?php
/**
 * Standalone regression tests for the redirect loop guard's pure graph walk
 * (Coywolf_SEO_Redirects::chain_loops), which both the save-time and serve-time
 * loop checks share. Runs without WordPress — chain_loops only walks a caller-
 * supplied edge function:
 *
 *     php tests/test-redirect-loop.php
 *
 * Exits non-zero on any failure. Excluded from the distributed plugin zip.
 *
 * @package Coywolf_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require __DIR__ . '/../includes/class-coywolf-seo-redirects.php';

$GLOBALS['n']    = 0;
$GLOBALS['fail'] = 0;

/** @param string $label Label. @param bool $cond Condition. */
function ok( $label, $cond ) {
	$GLOBALS['n']++;
	if ( ! $cond ) {
		$GLOBALS['fail']++;
		printf( "FAIL  %s\n", $label );
	}
}

$R   = 'Coywolf_SEO_Redirects';
$MAX = 15;

// Build a $next closure over a simple path => next-path edge map.
$edges = function ( array $map ) {
	return function ( $path ) use ( $map ) {
		return isset( $map[ $path ] ) ? $map[ $path ] : '';
	};
};

/* 1. Acyclic chain terminating off-site/empty — no loop. */
ok( 'acyclic /b->/c->end', false === $R::chain_loops( '/b/', '/a/', $edges( array( '/b/' => '/c/', '/c/' => '' ) ), $MAX ) );

/* 2. Direct return to origin: A->B, B->A — loop. */
ok( '2-cycle /b->/a', true === $R::chain_loops( '/b/', '/a/', $edges( array( '/b/' => '/a/' ) ), $MAX ) );

/* 3. Longer cycle back to origin: A->B->C->A. */
ok( '3-cycle /b->/c->/a', true === $R::chain_loops( '/b/', '/a/', $edges( array( '/b/' => '/c/', '/c/' => '/a/' ) ), $MAX ) );

/* 4. Cycle among non-origin nodes: start /b, /b->/c->/b (revisits /b). */
ok( 'cycle not through origin', true === $R::chain_loops( '/b/', '/a/', $edges( array( '/b/' => '/c/', '/c/' => '/b/' ) ), $MAX ) );

/* 5. Start equals origin (self-loop framing). */
ok( 'start == origin', true === $R::chain_loops( '/a/', '/a/', $edges( array() ), $MAX ) );

/* 6. Terminal target (no rule) — chain ends cleanly. */
ok( 'terminal empty', false === $R::chain_loops( '/b/', '/a/', $edges( array() ), $MAX ) );

/* 7. Long but acyclic within the cap — no loop. */
$chain = array();
for ( $i = 0; $i < 10; $i++ ) {
	$chain[ "/n$i/" ] = ( $i < 9 ) ? '/n' . ( $i + 1 ) . '/' : '';
}
ok( 'acyclic length 10 within cap', false === $R::chain_loops( '/n0/', '/a/', $edges( $chain ), $MAX ) );

/* 8. Acyclic but longer than the cap — treated as a loop. */
$long = array();
for ( $i = 0; $i < 30; $i++ ) {
	$long[ "/m$i/" ] = '/m' . ( $i + 1 ) . '/';
}
ok( 'over-long chain treated as loop', true === $R::chain_loops( '/m0/', '/a/', $edges( $long ), $MAX ) );

/* 9. Off-site/terminal ('') at start — no loop. */
ok( 'empty start', false === $R::chain_loops( '', '/a/', $edges( array() ), $MAX ) );

printf( "\n%d checks, %d failed\n", $GLOBALS['n'], $GLOBALS['fail'] );
exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
