<?php
/**
 * The "SEO" section on the Post and Page edit screens: schema type
 * overrides, noindex/nofollow, and a canonical link override. Stored in a
 * single `_coywolf_seo` post meta array; defaults mean "no meta saved".
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post/Page SEO meta box.
 */
final class Coywolf_SEO_Metabox {

	/**
	 * Post types that get the SEO box.
	 *
	 * @var string[]
	 */
	private $post_types = array( 'post', 'page' );

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_panel' ) );
	}

	/**
	 * Register the SEO post meta for the block editor (REST). The block
	 * editor's SEO panel in the document sidebar reads and writes this.
	 */
	public function register_meta() {
		$page_types    = array_keys( Coywolf_SEO_Options::page_types() );
		$article_types = array_keys( Coywolf_SEO_Options::article_types() );

		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'page_type'    => array(
					'type' => 'string',
					'enum' => array_merge( array( '' ), $page_types ),
				),
				'article_type' => array(
					'type' => 'string',
					'enum' => array_merge( array( '', 'none' ), $article_types ),
				),
				'noindex'      => array( 'type' => 'boolean' ),
				'nofollow'     => array( 'type' => 'boolean' ),
				'canonical'    => array(
					'type'   => 'string',
					'format' => 'uri',
				),
			),
			'additionalProperties' => false,
		);

		$default = array(
			'page_type'    => '',
			'article_type' => '',
			'noindex'      => false,
			'nofollow'     => false,
			'canonical'    => '',
		);

		foreach ( $this->post_types as $type ) {
			register_post_meta(
				$type,
				'_coywolf_seo',
				array(
					'type'          => 'object',
					'single'        => true,
					'default'       => $default,
					'auth_callback' => array( $this, 'can_edit_meta' ),
					'show_in_rest'  => array( 'schema' => $schema ),
				)
			);
		}
	}

	/**
	 * The protected meta key is editable by whoever can edit the post.
	 *
	 * @param bool   $allowed   Unused default.
	 * @param string $meta_key  Unused key.
	 * @param int    $object_id Post ID.
	 * @return bool
	 */
	public function can_edit_meta( $allowed, $meta_key, $object_id ) {
		unset( $allowed, $meta_key );
		return current_user_can( 'edit_post', $object_id );
	}

	/**
	 * Register the classic meta box. In the block editor the document
	 * sidebar panel replaces it ( __back_compat_meta_box ), so the box only
	 * renders in the classic editor.
	 */
	public function register() {
		foreach ( $this->post_types as $type ) {
			add_meta_box(
				'coywolf-seo',
				__( 'SEO', 'coywolf-seo' ),
				array( $this, 'render' ),
				$type,
				'normal',
				'default',
				array( '__back_compat_meta_box' => true )
			);
		}
	}

	/**
	 * Enqueue the document-sidebar SEO panel in the block editor.
	 */
	public function enqueue_editor_panel() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! in_array( (string) $screen->post_type, $this->post_types, true ) ) {
			return;
		}

		wp_enqueue_script(
			'coywolf-seo-editor',
			COYWOLF_SEO_URL . 'js/editor.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data' ),
			Coywolf_SEO::VERSION,
			true
		);

		// Breathing room between the panel's controls.
		wp_register_style( 'coywolf-seo-editor', false, array(), Coywolf_SEO::VERSION );
		wp_enqueue_style( 'coywolf-seo-editor' );
		wp_add_inline_style(
			'coywolf-seo-editor',
			'.coywolf-seo-panel .components-base-control, .coywolf-seo-panel .components-toggle-control { margin-bottom: 16px; }'
			. ' .coywolf-seo-panel .coywolf-seo-robots-label { font-size: 11px; font-weight: 500; line-height: 1.4; text-transform: uppercase; margin: 0 0 8px; }'
			. ' .coywolf-seo-panel .coywolf-seo-entity-status { margin: 4px 0 0; color: #50575e; }'
		);

		// The dropdowns name the site-wide default instead of just "Default".
		$is_page       = 'page' === (string) $screen->post_type;
		$page_types    = Coywolf_SEO_Options::page_types();
		$article_types = Coywolf_SEO_Options::article_types();

		$default_page_type = (string) Coywolf_SEO_Options::get( $is_page ? 'page_page_type' : 'post_page_type' );
		$default_art_type  = (string) Coywolf_SEO_Options::get( $is_page ? 'page_article_type' : 'post_article_type' );
		$default_art_label = ( 'none' === $default_art_type )
			? __( 'None', 'coywolf-seo' )
			: ( isset( $article_types[ $default_art_type ] ) ? $article_types[ $default_art_type ] : $default_art_type );

		$page_options = array(
			array(
				/* translators: %s: the site-wide default schema type. */
				'label' => sprintf( __( 'Default (%s)', 'coywolf-seo' ), isset( $page_types[ $default_page_type ] ) ? $page_types[ $default_page_type ] : $default_page_type ),
				'value' => '',
			),
		);
		foreach ( $page_types as $value => $label ) {
			$page_options[] = array(
				'label' => $label,
				'value' => $value,
			);
		}
		$article_options = array(
			array(
				/* translators: %s: the site-wide default schema type. */
				'label' => sprintf( __( 'Default (%s)', 'coywolf-seo' ), $default_art_label ),
				'value' => '',
			),
			array(
				'label' => __( 'None', 'coywolf-seo' ),
				'value' => 'none',
			),
		);
		foreach ( $article_types as $value => $label ) {
			$article_options[] = array(
				'label' => $label,
				'value' => $value,
			);
		}

		global $post;
		$entity_status = ( $post instanceof WP_Post ) ? Coywolf_SEO::instance()->ai()->status_text( $post->ID ) : '';
		$permalink     = ( $post instanceof WP_Post ) ? (string) get_permalink( $post ) : '';

		wp_localize_script(
			'coywolf-seo-editor',
			'coywolf_seo_editor',
			array(
				'pageTypeOptions'    => $page_options,
				'articleTypeOptions' => $article_options,
				'entityStatus'       => $entity_status,
				'permalink'          => $permalink,
				'aiEnabled'          => Coywolf_SEO::instance()->ai()->enabled(),
				'postId'             => ( $post instanceof WP_Post ) ? (int) $post->ID : 0,
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'reanalyzeNonce'     => wp_create_nonce( 'coywolf_seo_reanalyze' ),
				'i18n'               => array(
					'panelTitle'  => __( 'SEO', 'coywolf-seo' ),
					'pageType'    => __( 'Schema page type', 'coywolf-seo' ),
					'articleType' => __( 'Schema article type', 'coywolf-seo' ),
					'robots'      => __( 'Robots', 'coywolf-seo' ),
					'noindex'     => __( 'Noindex', 'coywolf-seo' ),
					'nofollow'    => __( 'Nofollow', 'coywolf-seo' ),
					'canonical'   => __( 'Canonical link', 'coywolf-seo' ),
					'reanalyze'   => __( 'Re-analyze entities', 'coywolf-seo' ),
				),
			)
		);
	}

	/**
	 * Render the box.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render( $post ) {
		$meta       = Coywolf_SEO_Options::post_meta( $post->ID );
		$is_page    = 'page' === $post->post_type;
		$page_types = Coywolf_SEO_Options::page_types();
		$art_types  = Coywolf_SEO_Options::article_types();

		$default_page_type = Coywolf_SEO_Options::get( $is_page ? 'page_page_type' : 'post_page_type' );
		$default_art_type  = Coywolf_SEO_Options::get( $is_page ? 'page_article_type' : 'post_article_type' );
		$default_art_label = ( 'none' === $default_art_type )
			? __( 'None', 'coywolf-seo' )
			: ( isset( $art_types[ $default_art_type ] ) ? $art_types[ $default_art_type ] : $default_art_type );

		wp_nonce_field( 'coywolf_seo_meta_' . $post->ID, 'coywolf_seo_meta_nonce' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="coywolf-seo-page-type"><?php esc_html_e( 'Schema page type', 'coywolf-seo' ); ?></label></th>
				<td>
					<select id="coywolf-seo-page-type" name="coywolf_seo_meta[page_type]">
						<option value="">
							<?php
							/* translators: %s: the site-wide default schema type. */
							printf( esc_html__( 'Default (%s)', 'coywolf-seo' ), esc_html( isset( $page_types[ $default_page_type ] ) ? $page_types[ $default_page_type ] : $default_page_type ) );
							?>
						</option>
						<?php foreach ( $page_types as $type => $label ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $meta['page_type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="coywolf-seo-article-type"><?php esc_html_e( 'Schema article type', 'coywolf-seo' ); ?></label></th>
				<td>
					<select id="coywolf-seo-article-type" name="coywolf_seo_meta[article_type]">
						<option value="">
							<?php
							/* translators: %s: the site-wide default schema type. */
							printf( esc_html__( 'Default (%s)', 'coywolf-seo' ), esc_html( $default_art_label ) );
							?>
						</option>
						<option value="none" <?php selected( $meta['article_type'], 'none' ); ?>><?php esc_html_e( 'None', 'coywolf-seo' ); ?></option>
						<?php foreach ( $art_types as $type => $label ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $meta['article_type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Robots', 'coywolf-seo' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="coywolf_seo_meta[noindex]" value="1" <?php checked( $meta['noindex'] ); ?> />
						<?php esc_html_e( 'Noindex', 'coywolf-seo' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Adds noindex to the robots metadata and removes index.', 'coywolf-seo' ); ?></p>
					<label>
						<input type="checkbox" name="coywolf_seo_meta[nofollow]" value="1" <?php checked( $meta['nofollow'] ); ?> />
						<?php esc_html_e( 'Nofollow', 'coywolf-seo' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Adds nofollow to the robots metadata and removes follow.', 'coywolf-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="coywolf-seo-canonical"><?php esc_html_e( 'Canonical link', 'coywolf-seo' ); ?></label></th>
				<td>
					<input type="url" class="large-text" id="coywolf-seo-canonical" name="coywolf_seo_meta[canonical]" value="<?php echo esc_attr( '' !== $meta['canonical'] ? $meta['canonical'] : get_permalink( $post ) ); ?>" />
					<p class="description"><?php esc_html_e( 'The URL in use — change it to set a different canonical for this content.', 'coywolf-seo' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the box.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['coywolf_seo_meta_nonce'] ) ) {
			return;
		}
		check_admin_referer( 'coywolf_seo_meta_' . $post_id, 'coywolf_seo_meta_nonce' );

		if ( ! in_array( $post->post_type, $this->post_types, true ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field below.
		$raw = isset( $_POST['coywolf_seo_meta'] ) && is_array( $_POST['coywolf_seo_meta'] ) ? wp_unslash( $_POST['coywolf_seo_meta'] ) : array();

		$page_types = Coywolf_SEO_Options::page_types();
		$art_types  = Coywolf_SEO_Options::article_types();

		$page_type = isset( $raw['page_type'] ) && isset( $page_types[ $raw['page_type'] ] ) ? (string) $raw['page_type'] : '';
		$art_type  = '';
		if ( isset( $raw['article_type'] ) && ( 'none' === $raw['article_type'] || isset( $art_types[ $raw['article_type'] ] ) ) ) {
			$art_type = (string) $raw['article_type'];
		}

		$canonical = isset( $raw['canonical'] ) ? esc_url_raw( (string) $raw['canonical'] ) : '';
		// The field shows the post's own URL; that is the default, not an
		// override worth storing.
		if ( (string) get_permalink( $post_id ) === $canonical ) {
			$canonical = '';
		}

		$meta = array(
			'page_type'    => $page_type,
			'article_type' => $art_type,
			'noindex'      => ! empty( $raw['noindex'] ),
			'nofollow'     => ! empty( $raw['nofollow'] ),
			'canonical'    => $canonical,
		);

		// All defaults? Keep the database clean.
		if ( '' === $meta['page_type'] && '' === $meta['article_type'] && ! $meta['noindex'] && ! $meta['nofollow'] && '' === $meta['canonical'] ) {
			delete_post_meta( $post_id, '_coywolf_seo' );
			return;
		}

		update_post_meta( $post_id, '_coywolf_seo', $meta );
	}
}
