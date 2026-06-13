<?php
/**
 * Per-term SEO fields for categories and tags: a custom Page Title
 * (used for the document title and the Open Graph title when set) and an
 * Open Graph image, editable on both the add and edit term screens.
 *
 * The fields render through WordPress's term-form hooks, which only
 * append at the end of the form — a small script repositions them where
 * they belong: the title below Name (above Slug), the image below
 * Description.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Category/tag term fields and their output plumbing.
 */
final class Coywolf_SEO_Terms {

	/**
	 * Term meta key for the custom page title.
	 */
	const META_TITLE = '_coywolf_seo_title';

	/**
	 * Term meta key for the Open Graph image attachment ID.
	 */
	const META_OG_IMAGE = '_coywolf_seo_og_image_id';

	/**
	 * Taxonomies that get the fields.
	 *
	 * @var string[]
	 */
	private $taxonomies = array( 'category', 'post_tag' );

	/**
	 * Hook everything up.
	 */
	public function init() {
		// Term descriptions keep post-level HTML: core's save filter
		// (wp_filter_kses) allows only a minimal inline tag set for users
		// without unfiltered_html — swap it for the full safe post set.
		// Late on init, after core's kses_init() has decided the filters.
		add_action( 'init', array( $this, 'allow_description_html' ), 20 );

		foreach ( $this->taxonomies as $taxonomy ) {
			add_action( "{$taxonomy}_add_form_fields", array( $this, 'render_add_fields' ) );
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_edit_fields' ) );
			add_action( "created_{$taxonomy}", array( $this, 'save' ) );
			add_action( "edited_{$taxonomy}", array( $this, 'save' ) );

			register_term_meta(
				$taxonomy,
				self::META_TITLE,
				array(
					'type'              => 'string',
					'single'            => true,
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
			register_term_meta(
				$taxonomy,
				self::META_OG_IMAGE,
				array(
					'type'              => 'integer',
					'single'            => true,
					'sanitize_callback' => 'absint',
				)
			);
		}
	}

	/**
	 * Let term descriptions carry the same safe HTML as post content.
	 */
	public function allow_description_html() {
		if ( false !== has_filter( 'pre_term_description', 'wp_filter_kses' ) ) {
			remove_filter( 'pre_term_description', 'wp_filter_kses' );
			add_filter( 'pre_term_description', 'wp_filter_post_kses' );
		}
	}

	/**
	 * The custom page title for the current category/tag query.
	 *
	 * @return string '' when none is set.
	 */
	public static function custom_title() {
		if ( ! is_category() && ! is_tag() ) {
			return '';
		}
		return trim( (string) get_term_meta( get_queried_object_id(), self::META_TITLE, true ) );
	}

	/**
	 * The Open Graph image attachment ID for the current category/tag query.
	 *
	 * @return int 0 when none is set.
	 */
	public static function og_image_id() {
		if ( ! is_category() && ! is_tag() ) {
			return 0;
		}
		return (int) get_term_meta( get_queried_object_id(), self::META_OG_IMAGE, true );
	}

	/**
	 * Fields on the add form (Categories / Tags screens).
	 */
	public function render_add_fields() {
		wp_nonce_field( 'coywolf_seo_term_save', 'coywolf_seo_term_nonce' );
		?>
		<div class="form-field" id="coywolf-seo-term-title-row">
			<label for="coywolf-seo-term-title"><?php esc_html_e( 'Page Title', 'coywolf-seo' ); ?></label>
			<input type="text" id="coywolf-seo-term-title" name="coywolf_seo_term_title" value="" />
			<p><?php esc_html_e( 'Used for the page title and the Open Graph title. Leave blank to use the name.', 'coywolf-seo' ); ?></p>
		</div>
		<div class="form-field" id="coywolf-seo-term-og-row">
			<label for="coywolf-seo-term-og-select"><?php esc_html_e( 'Open Graph image', 'coywolf-seo' ); ?></label>
			<?php $this->render_og_picker( 0 ); ?>
		</div>
		<?php
	}

	/**
	 * Fields on the edit form (Edit Category / Edit Tag screens).
	 *
	 * @param WP_Term $term The term being edited.
	 */
	public function render_edit_fields( $term ) {
		$title    = (string) get_term_meta( $term->term_id, self::META_TITLE, true );
		$image_id = (int) get_term_meta( $term->term_id, self::META_OG_IMAGE, true );
		wp_nonce_field( 'coywolf_seo_term_save', 'coywolf_seo_term_nonce' );
		?>
		<tr class="form-field" id="coywolf-seo-term-title-row">
			<th scope="row"><label for="coywolf-seo-term-title"><?php esc_html_e( 'Page Title', 'coywolf-seo' ); ?></label></th>
			<td>
				<input type="text" id="coywolf-seo-term-title" name="coywolf_seo_term_title" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php echo esc_attr( $term->name ); ?>" />
				<p class="description"><?php esc_html_e( 'Used for the page title and the Open Graph title. Leave blank to use the name.', 'coywolf-seo' ); ?></p>
			</td>
		</tr>
		<tr class="form-field" id="coywolf-seo-term-og-row">
			<th scope="row"><?php esc_html_e( 'Open Graph image', 'coywolf-seo' ); ?></th>
			<td><?php $this->render_og_picker( $image_id ); ?></td>
		</tr>
		<?php
	}

	/**
	 * The image picker: hidden attachment ID, preview, select/remove.
	 *
	 * @param int $image_id Saved attachment ID.
	 */
	private function render_og_picker( $image_id ) {
		$src = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
		?>
		<input type="hidden" id="coywolf-seo-term-og-id" name="coywolf_seo_term_og_id" value="<?php echo esc_attr( (string) $image_id ); ?>" />
		<img id="coywolf-seo-term-og-preview" src="<?php echo esc_url( (string) $src ); ?>" alt="" style="max-width:300px;height:auto;display:<?php echo $src ? 'block' : 'none'; ?>;margin-bottom:8px;border:1px solid #dcdcde;border-radius:2px;" />
		<button type="button" class="button" id="coywolf-seo-term-og-select"><?php esc_html_e( 'Select image', 'coywolf-seo' ); ?></button>
		<button type="button" class="button-link button-link-delete" id="coywolf-seo-term-og-remove" style="<?php echo $image_id ? '' : 'display:none'; ?>"><?php esc_html_e( 'Remove', 'coywolf-seo' ); ?></button>
		<p class="description"><?php esc_html_e( 'Shown when this archive is shared. Recommended size: 1200 × 675 pixels. Falls back to the default Open Graph image.', 'coywolf-seo' ); ?></p>
		<?php
	}

	/**
	 * Save both fields when a term is created or edited.
	 *
	 * @param int $term_id Term ID.
	 */
	public function save( $term_id ) {
		if ( ! isset( $_POST['coywolf_seo_term_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['coywolf_seo_term_nonce'] ) ), 'coywolf_seo_term_save' )
			|| ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		if ( isset( $_POST['coywolf_seo_term_title'] ) ) {
			$title = sanitize_text_field( wp_unslash( $_POST['coywolf_seo_term_title'] ) );
			if ( '' !== $title ) {
				update_term_meta( $term_id, self::META_TITLE, $title );
			} else {
				delete_term_meta( $term_id, self::META_TITLE );
			}
		}

		if ( isset( $_POST['coywolf_seo_term_og_id'] ) ) {
			$image_id = absint( $_POST['coywolf_seo_term_og_id'] );
			if ( $image_id > 0 ) {
				update_term_meta( $term_id, self::META_OG_IMAGE, $image_id );
			} else {
				delete_term_meta( $term_id, self::META_OG_IMAGE );
			}
		}
	}
}
