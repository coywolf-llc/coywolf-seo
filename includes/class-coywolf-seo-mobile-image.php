<?php
/**
 * Mobile alternative image for the core Image block.
 *
 * Adds an "Add mobile alternative" control to the core/image block inspector.
 * When the author picks a mobile-specific image, the front end wraps the image
 * in a <picture> with a media-query <source>, so small screens get the
 * alternative and larger screens keep the desktop image.
 *
 * Why <picture> and not a swapped srcset: srcset width-descriptors are
 * *resolution switching* — the browser is free to choose any candidate for the
 * viewport/DPR (and won't downgrade an already-downloaded one), so swapping the
 * 300w/768w URLs does not guarantee phones get the alternative; the browser
 * often picks a neighbouring desktop candidate instead. A media-query <source>
 * is *art direction*: below the breakpoint the mobile source is used,
 * deterministically.
 *
 * The mobile attachment ID lives in the block's `coywolfMobileImageId`
 * attribute (declared in js/image-block.js). WordPress adds sizes/srcset to
 * content images late — at `the_content` priority 12 (wp_filter_content_tags) —
 * so it's a two-step pass:
 *
 *   1. render_block_core/image (during do_blocks, priority 9) stamps a
 *      transient `data-coywolf-mobile-id` marker onto the <img>.
 *   2. wp_content_img_tag (fired by wp_filter_content_tags once the <img> has
 *      its final sizes attribute) reads the marker, builds the mobile <source>
 *      reusing that sizes value, wraps the untouched <img> in <picture>, and
 *      removes the marker.
 *
 * The desktop <img> (and its srcset) is left intact, so it stays the source
 * above the breakpoint and the fallback for browsers without <picture>. The
 * block's lightbox still enlarges the desktop image — core derives the enlarged
 * srcset from the attachment metadata, not the inline tag.
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
	 * Default max-width (px) at or below which the mobile alternative is served.
	 */
	const BREAKPOINT = 768;

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_filter( 'render_block_core/image', array( $this, 'stamp_marker' ), 10, 2 );
		add_filter( 'wp_content_img_tag', array( $this, 'wrap_in_picture' ), 10, 3 );
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
			'coywolf_seo_image_block',
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
	 * Wrap a stamped <img> in a <picture> with a media-query <source> for the
	 * mobile alternative, and drop the marker. Runs only on images this module
	 * stamped.
	 *
	 * @param string $filtered_image The full <img> tag (sizes/srcset already added).
	 * @param string $context        Filter context (unused).
	 * @param int    $attachment_id  Desktop attachment ID (unused — the mobile
	 *                               ID rides on the marker).
	 * @return string
	 */
	public function wrap_in_picture( $filtered_image, $context, $attachment_id ) {
		unset( $context, $attachment_id );

		if ( false === strpos( $filtered_image, self::MARKER ) ) {
			return $filtered_image;
		}

		$mobile_id = 0;
		$img_sizes = '';

		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$processor = new WP_HTML_Tag_Processor( $filtered_image );
			if ( $processor->next_tag( 'img' ) ) {
				$mobile_id = absint( $processor->get_attribute( self::MARKER ) );
				// Always remove the marker so it never reaches the page.
				$processor->remove_attribute( self::MARKER );
				$sizes     = $processor->get_attribute( 'sizes' );
				$img_sizes = is_string( $sizes ) ? $sizes : '';
			}
			$filtered_image = $processor->get_updated_html();
		} else {
			// WP < 6.2 fallback: pull the id + sizes, strip the marker by regex.
			if ( preg_match( '/' . preg_quote( self::MARKER, '/' ) . '=(["\'])(.*?)\1/i', $filtered_image, $m ) ) {
				$mobile_id = absint( $m[2] );
			}
			if ( preg_match( '/\ssizes=(["\'])(.*?)\1/i', $filtered_image, $s ) ) {
				$img_sizes = $s[2];
			}
			$filtered_image = preg_replace( '/\s*' . preg_quote( self::MARKER, '/' ) . '=(["\']).*?\1/i', '', $filtered_image );
		}

		if ( ! $mobile_id ) {
			return $filtered_image;
		}

		$source = $this->build_mobile_source( $mobile_id, $img_sizes );
		if ( '' === $source ) {
			return $filtered_image;
		}

		// Wrap the untouched desktop <img> so it stays the source above the
		// breakpoint and the fallback for browsers without <picture>.
		return '<picture>' . $source . $filtered_image . '</picture>';
	}

	/**
	 * Build the mobile <source> tag: a media query up to the breakpoint, the
	 * mobile image's own (resolution-switching) srcset so high-DPR phones still
	 * get a crisp version, and the <img>'s sizes so it respects the block width.
	 *
	 * @param int    $mobile_id Mobile attachment ID.
	 * @param string $img_sizes The <img>'s sizes attribute (may be empty).
	 * @return string Source tag, or '' if the attachment can't be resolved.
	 */
	private function build_mobile_source( $mobile_id, $img_sizes ) {
		$meta = wp_get_attachment_metadata( $mobile_id );
		if ( ! is_array( $meta ) || empty( $meta['width'] ) ) {
			return '';
		}

		$srcset = wp_get_attachment_image_srcset( $mobile_id, 'full', $meta );
		if ( ! $srcset ) {
			// Single-size image: hand-build a one-candidate srcset.
			$url = wp_get_attachment_image_url( $mobile_id, 'full' );
			if ( ! $url ) {
				return '';
			}
			$srcset = $url . ' ' . (int) $meta['width'] . 'w';
		}

		/**
		 * Filters the max-width (px) at or below which the mobile alternative is
		 * served. Default 768.
		 *
		 * @param int $breakpoint Breakpoint in pixels.
		 * @param int $mobile_id  Mobile attachment ID.
		 */
		$breakpoint = (int) apply_filters( 'coywolf_seo_mobile_image_breakpoint', self::BREAKPOINT, $mobile_id );
		if ( $breakpoint <= 0 ) {
			$breakpoint = self::BREAKPOINT;
		}

		// Reuse the <img>'s own sizes so the source respects the block's layout
		// width; under the source's media (<= breakpoint) it resolves to 100vw.
		$sizes = ( '' !== $img_sizes ) ? $img_sizes : '100vw';

		return sprintf(
			'<source media="(max-width: %dpx)" srcset="%s" sizes="%s" />',
			$breakpoint,
			esc_attr( $srcset ),
			esc_attr( $sizes )
		);
	}
}
