<?php
/**
 * Recursive parsed-block walker.
 *
 * Several image tools transform core/image blocks anywhere in a post — including
 * inside galleries, columns, and groups — by walking the parse_blocks() tree
 * depth-first. This houses that traversal once: callers pass a visitor that
 * handles a single block; the walker recurses into innerBlocks and rebuilds the
 * tree. The visitor owns all per-block logic and any side effects (counts,
 * flags) through its own captured state.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Depth-first block-tree mapper (static).
 */
final class Coywolf_SEO_Block_Walker {

	/**
	 * Map over parsed blocks depth-first: call $visitor on each block, then
	 * recurse into the (possibly modified) block's innerBlocks, and return the
	 * rebuilt tree. Non-array entries (raw strings) pass through untouched.
	 *
	 * @param array    $blocks  Parsed blocks (from parse_blocks()).
	 * @param callable $visitor function( array $block ): array — returns the
	 *                          block to keep (modified or not).
	 * @return array Rebuilt blocks.
	 */
	public static function map( array $blocks, callable $visitor ) {
		foreach ( $blocks as $i => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$block = (array) call_user_func( $visitor, $block );
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::map( $block['innerBlocks'], $visitor );
			}
			$blocks[ $i ] = $block;
		}
		return $blocks;
	}
}
