<?php
/**
 * Mobile alternative image for the core Image block.
 *
 * Adds an "Add mobile alternative" control to the core/image block inspector.
 * When the author picks a mobile-specific image, this module swaps the URLs at
 * the small (≈300w) and medium (≈768w) srcset breakpoints on the front end, so
 * phones download the alternative instead of a downscale of the desktop image.
 *
 * The mobile attachment ID lives in the block's `coywolfMobileImageId`
 * attribute (declared in js/image-block.js). WordPress adds srcset to content
 * images late — at `the_content` priority 12 (wp_filter_content_tags) — so the
 * swap is a two-step pass:
 *
 *   1. render_block_core/image (during do_blocks, priority 9) stamps a
 *      transient `data-coywolf-mobile-id` marker onto the <img>.
 *   2. wp_content_img_tag (fired by wp_filter_content_tags once the srcset has
 *      been built) reads the marker, rewrites the breakpoint URLs, and removes
 *      the marker so it never reaches the page.
 *
 * The block's lightbox is unaffected: core derives the enlarged image's srcset
 * straight from the attachment metadata, not from the inline <img> tag.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core Image block mobile-alternative module.
 */
final class Coywolf_SEO_Mobile_Image {

	/**
	 * Block attribute holding the mobile attachment ID.
	 */
	const ATTR = 'coywolfMobileImageId';

	/**
	 * Transient attribute carried from render_block to the img-tag filter.
	 */
	const MARKER = 'data-coywolf-mobile-id';

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_filter( 'render_block_core/image', array( $this, 'stamp_marker' ), 10, 2 );
		add_filter( 'wp_content_img_tag', array( $this, 'swap_breakpoints' ), 10, 3 );
	}

	/**
	 * Enqueue the block-editor control. Loaded on every block-editor screen —
	 * image blocks can appear in posts, the site editor, and widgets.
	 */
	public function enqueue_editor_assets() {
		wp_enqueue_script(
			'coywolf-seo-image-block',
			COYWOLF_SEO_URL . 'js/image-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-compose', 'wp-hooks', 'wp-i18n', 'wp-data' ),
			Coywolf_SEO::VERSION,
			true
		);
		wp_set_script_translations( 'coywolf-seo-image-block', 'coywolf-seo' );

		// WP 7.0 moved the image block's "Alternative text" into a dedicated
		// "Content" inspector tab; the control belongs in that group there.
		// Older WP has no such group and silently drops the fill, so the
		// control falls back to the default Settings panel. Strip any
		// pre-release suffix so 7.0-RC counts as 7.0.
		$wp_version = preg_replace( '/[^0-9.].*$/', '', (string) get_bloginfo( 'version' ) );

		wp_localize_script(
			'coywolf-seo-image-block',
			'CoywolfSEOImageBlock',
			array(
				'contentGroup' => version_compare( $wp_version, '7.0', '>=' ),
			)
		);

		wp_register_style( 'coywolf-seo-image-block', false, array(), Coywolf_SEO::VERSION );
		wp_enqueue_style( 'coywolf-seo-image-block' );
		// Mirror the native inspector chrome: a 16px-padded section divided by
		// the same 1px border WordPress uses between panels, a full-width 40px
		// button matching "Add image", and help text styled like core's.
		wp_add_inline_style(
			'coywolf-seo-image-block',
			'.coywolf-seo-mobile-alt { display: flex; flex-direction: column; gap: 8px; padding: 16px; border-top: 1px solid #e0e0e0; }'
			. ' .coywolf-seo-mobile-alt__actions { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; }'
			. ' .coywolf-seo-mobile-alt__button { width: 100%; justify-content: center; }'
			. ' .coywolf-seo-mobile-alt__desc { margin: 0; font-size: 12px; line-height: 18px; color: #757575; }'
			. ' .coywolf-seo-mobile-alt__preview { display: flex; align-items: center; gap: 8px; }'
			. ' .coywolf-seo-mobile-alt__preview img { width: 36px; height: 36px; object-fit: cover; border-radius: 2px; }'
			. ' .coywolf-seo-mobile-alt__preview span { font-size: 13px; color: #1e1e1e; word-break: break-word; }'
		);
	}

	/**
	 * Stamp the mobile attachment ID onto the rendered image so the later
	 * img-tag filter can find it once the srcset exists.
	 *
	 * @param string $block_content Rendered block HTML.
	 * @param array  $block         Parsed block (attrs come from the comment JSON).
	 * @return string
	 */
	public function stamp_marker( $block_content, $block ) {
		if ( empty( $block['attrs'][ self::ATTR ] ) ) {
			return $block_content;
		}
		$mobile_id = absint( $block['attrs'][ self::ATTR ] );
		if ( ! $mobile_id || '' === trim( (string) $block_content ) ) {
			return $block_content;
		}

		// Stamp the first <img> only — the visible image.
		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$processor = new WP_HTML_Tag_Processor( $block_content );
			if ( $processor->next_tag( 'img' ) ) {
				$processor->set_attribute( self::MARKER, (string) $mobile_id );
				return $processor->get_updated_html();
			}
			return $block_content;
		}

		// WP < 6.2 fallback.
		return preg_replace(
			'/<img\b/i',
			'<img ' . self::MARKER . '="' . esc_attr( (string) $mobile_id ) . '"',
			$block_content,
			1
		);
	}

	/**
	 * Rewrite the small/medium srcset URLs to the mobile alternative and drop
	 * the marker. Runs only on images this module stamped.
	 *
	 * @param string $filtered_image The full <img> tag (srcset already added).
	 * @param string $context        Filter context (unused).
	 * @param int    $attachment_id  Desktop attachment ID (unused — the mobile
	 *                               ID rides on the marker).
	 * @return string
	 */
	public function swap_breakpoints( $filtered_image, $context, $attachment_id ) {
		unset( $context, $attachment_id );

		if ( false === strpos( $filtered_image, self::MARKER ) ) {
			return $filtered_image;
		}

		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$processor = new WP_HTML_Tag_Processor( $filtered_image );
			if ( ! $processor->next_tag( 'img' ) ) {
				return $filtered_image;
			}
			$mobile_id = absint( $processor->get_attribute( self::MARKER ) );
			// Always remove the marker so it never reaches the page.
			$processor->remove_attribute( self::MARKER );
			$srcset = $processor->get_attribute( 'srcset' );
			if ( $mobile_id && is_string( $srcset ) && '' !== $srcset ) {
				$new = $this->rewrite_srcset( $srcset, $mobile_id );
				if ( null !== $new ) {
					$processor->set_attribute( 'srcset', $new );
				}
			}
			return $processor->get_updated_html();
		}

		// WP < 6.2 fallback: pull the id, rewrite the srcset, strip the marker.
		$mobile_id = 0;
		if ( preg_match( '/' . preg_quote( self::MARKER, '/' ) . '=(["\'])(.*?)\1/i', $filtered_image, $m ) ) {
			$mobile_id = absint( $m[2] );
		}
		if ( $mobile_id && preg_match( '/\ssrcset=(["\'])(.*?)\1/i', $filtered_image, $s ) ) {
			$new = $this->rewrite_srcset( $s[2], $mobile_id );
			if ( null !== $new ) {
				$filtered_image = str_replace( $s[0], ' srcset=' . $s[1] . $new . $s[1], $filtered_image );
			}
		}
		return preg_replace( '/\s*' . preg_quote( self::MARKER, '/' ) . '=(["\']).*?\1/i', '', $filtered_image );
	}

	/**
	 * Replace the URLs at the small (medium) and medium (medium_large) width
	 * descriptors with the mobile image at those sizes. The descriptors are
	 * left unchanged so the browser still selects them at the same breakpoints
	 * — only the bytes served change.
	 *
	 * @param string $srcset    Existing srcset attribute value.
	 * @param int    $mobile_id Mobile attachment ID.
	 * @return string|null Rewritten srcset, or null if nothing changed.
	 */
	private function rewrite_srcset( $srcset, $mobile_id ) {
		// The two breakpoints to swap. Reading the size options (rather than
		// hardcoding 300/768) keeps this correct on sites that customized the
		// medium / medium_large dimensions.
		$medium_w       = (int) get_option( 'medium_size_w', 300 );
		$medium_large_w = (int) get_option( 'medium_large_size_w', 768 );
		if ( $medium_w <= 0 ) {
			$medium_w = 300;
		}
		if ( $medium_large_w <= 0 ) {
			$medium_large_w = 768;
		}

		$targets = array(
			$medium_w       => 'medium',
			$medium_large_w => 'medium_large',
		);

		// Resolve the mobile image's URL at each target size. wp_get_attachment_image_src
		// falls back to the full-size image when an intermediate size is absent.
		$replacements = array();
		foreach ( $targets as $width => $size ) {
			$src = wp_get_attachment_image_src( $mobile_id, $size );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				$replacements[ $width ] = $src[0];
			}
		}
		if ( empty( $replacements ) ) {
			return null;
		}

		$candidates = explode( ',', $srcset );
		$changed    = false;
		foreach ( $candidates as $i => $candidate ) {
			if ( preg_match( '/^\s*(\S+)\s+(\d+)w\s*$/', $candidate, $parts ) ) {
				$width = (int) $parts[2];
				if ( isset( $replacements[ $width ] ) ) {
					$candidates[ $i ] = $replacements[ $width ] . ' ' . $width . 'w';
					$changed          = true;
				}
			}
		}

		return $changed ? implode( ', ', $candidates ) : null;
	}
}
