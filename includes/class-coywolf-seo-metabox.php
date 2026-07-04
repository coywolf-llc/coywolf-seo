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
				'title'        => array( 'type' => 'string' ),
				'description'  => array( 'type' => 'string' ),
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
			'title'        => '',
			'description'  => '',
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
					'type'              => 'object',
					'single'            => true,
					'default'           => $default,
					'auth_callback'     => array( $this, 'can_edit_meta' ),
					// The REST (block editor) write path stores whatever the
					// client sends; sanitize on save so it matches the classic
					// path field for field.
					'sanitize_callback' => array( $this, 'sanitize_meta' ),
					'show_in_rest'      => array( 'schema' => $schema ),
				)
			);
		}
	}

	/**
	 * Sanitize the meta object on every write (REST and update_post_meta
	 * alike), mirroring the classic save() sanitizers per field.
	 *
	 * @param mixed $value Raw meta value.
	 * @return array Clean meta object.
	 */
	public function sanitize_meta( $value ) {
		$value      = is_array( $value ) ? $value : array();
		$page_types = Coywolf_SEO_Options::page_types();
		$art_types  = Coywolf_SEO_Options::article_types();

		$page_type = isset( $value['page_type'] ) && isset( $page_types[ $value['page_type'] ] ) ? (string) $value['page_type'] : '';
		$art_type  = '';
		if ( isset( $value['article_type'] ) && ( 'none' === $value['article_type'] || isset( $art_types[ $value['article_type'] ] ) ) ) {
			$art_type = (string) $value['article_type'];
		}

		return array(
			'title'        => isset( $value['title'] ) ? sanitize_text_field( (string) $value['title'] ) : '',
			'description'  => isset( $value['description'] ) ? sanitize_textarea_field( (string) $value['description'] ) : '',
			'page_type'    => $page_type,
			'article_type' => $art_type,
			'noindex'      => ! empty( $value['noindex'] ),
			'nofollow'     => ! empty( $value['nofollow'] ),
			'canonical'    => isset( $value['canonical'] ) ? esc_url_raw( (string) $value['canonical'] ) : '',
		);
	}

	/**
	 * Whether this post is the static front page or the posts page. Their
	 * title/description come from Site Details (homepage_title/description,
	 * or the posts page's own name), so per-post Title/Description overrides
	 * would be silently ignored — the fields are hidden there instead.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return bool
	 */
	private function is_special_page( $post ) {
		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type || 'page' !== get_option( 'show_on_front' ) ) {
			return false;
		}
		return (int) get_option( 'page_on_front' ) === (int) $post->ID
			|| (int) get_option( 'page_for_posts' ) === (int) $post->ID;
	}

	/**
	 * Help text for the Title field, reflecting the append-site-name setting.
	 *
	 * @return string
	 */
	private function title_help() {
		return Coywolf_SEO_Options::get( 'append_site_name' )
			? __( 'The page title (title tag) and Open Graph title. Defaults to the post title; the site name is still appended.', 'coywolf-seo' )
			: __( 'The page title (title tag) and Open Graph title. Defaults to the post title.', 'coywolf-seo' );
	}

	/**
	 * Help text for the Description field, reflecting the actual default
	 * source (AI-written description vs the excerpt).
	 *
	 * @return string
	 */
	private function description_help() {
		return Coywolf_SEO::instance()->ai()->descriptions_on()
			? __( 'The meta description and Open Graph description. Defaults to the AI-written description, or the excerpt.', 'coywolf-seo' )
			: __( 'The meta description and Open Graph description. Defaults to the excerpt.', 'coywolf-seo' );
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
		// The AI-written meta description, if the feature is on and one is
		// stored. The panel falls back to the live excerpt when this is empty —
		// mirroring how source_description() resolves the front-end default.
		$ai_description = ( $post instanceof WP_Post ) ? Coywolf_SEO::instance()->ai()->description_for( $post->ID ) : '';

		wp_localize_script(
			'coywolf-seo-editor',
			'coywolf_seo_editor',
			array(
				'pageTypeOptions'    => $page_options,
				'articleTypeOptions' => $article_options,
				'entityStatus'       => $entity_status,
				'permalink'          => $permalink,
				'aiDescription'      => $ai_description,
				// With "Exclude meta description" on, the panel hides the
				// Description field (a stored override is kept, just not shown).
				'excludeDescription' => (bool) Coywolf_SEO_Options::get( 'exclude_meta_desc' ),
				// The front/posts pages take title+description from Site
				// Details, so the panel hides both fields there.
				'specialPage'        => ( $post instanceof WP_Post ) && $this->is_special_page( $post ),
				'aiEnabled'          => Coywolf_SEO::instance()->ai()->enabled(),
				'postId'             => ( $post instanceof WP_Post ) ? (int) $post->ID : 0,
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'reanalyzeNonce'     => wp_create_nonce( 'coywolf_seo_reanalyze' ),
				'i18n'               => array(
					'panelTitle'      => __( 'SEO', 'coywolf-seo' ),
					'title'           => __( 'Title', 'coywolf-seo' ),
					'titleHelp'       => $this->title_help(),
					'description'     => __( 'Description', 'coywolf-seo' ),
					'descriptionHelp' => $this->description_help(),
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

		// Default Description shown when there is no override: the AI summary if
		// present, otherwise the manual excerpt (mirrors source_description()).
		$default_desc = Coywolf_SEO::instance()->ai()->description_for( $post->ID );
		if ( '' === $default_desc ) {
			$default_desc = (string) $post->post_excerpt;
		}

		// The static front page and the posts page take their title/description
		// from Site Details, so per-post overrides would be silently ignored
		// there — hide the fields instead (see self::is_special_page()).
		$is_special = $this->is_special_page( $post );

		$default_page_type = Coywolf_SEO_Options::get( $is_page ? 'page_page_type' : 'post_page_type' );
		$default_art_type  = Coywolf_SEO_Options::get( $is_page ? 'page_article_type' : 'post_article_type' );
		$default_art_label = ( 'none' === $default_art_type )
			? __( 'None', 'coywolf-seo' )
			: ( isset( $art_types[ $default_art_type ] ) ? $art_types[ $default_art_type ] : $default_art_type );

		wp_nonce_field( 'coywolf_seo_meta_' . $post->ID, 'coywolf_seo_meta_nonce' );
		?>
		<table class="form-table" role="presentation">
			<?php if ( ! $is_special ) : ?>
			<tr>
				<th scope="row"><label for="coywolf-seo-title"><?php esc_html_e( 'Title', 'coywolf-seo' ); ?></label></th>
				<td>
					<input type="text" class="large-text" id="coywolf-seo-title" name="coywolf_seo_meta[title]" value="<?php echo esc_attr( '' !== $meta['title'] ? $meta['title'] : $post->post_title ); ?>" />
					<?php // The default the field was rendered with, so save() can tell "untouched" apart from "edited" even when the post title changes in the same update. ?>
					<input type="hidden" name="coywolf_seo_meta[title_default]" value="<?php echo esc_attr( $post->post_title ); ?>" />
					<p class="description"><?php echo esc_html( $this->title_help() ); ?></p>
				</td>
			</tr>
			<?php if ( ! Coywolf_SEO_Options::get( 'exclude_meta_desc' ) ) : ?>
			<tr>
				<th scope="row"><label for="coywolf-seo-description"><?php esc_html_e( 'Description', 'coywolf-seo' ); ?></label></th>
				<td>
					<textarea class="large-text" rows="3" id="coywolf-seo-description" name="coywolf_seo_meta[description]"><?php echo esc_textarea( '' !== $meta['description'] ? $meta['description'] : $default_desc ); ?></textarea>
					<input type="hidden" name="coywolf_seo_meta[description_default]" value="<?php echo esc_attr( $default_desc ); ?>" />
					<p class="description"><?php echo esc_html( $this->description_help() ); ?></p>
				</td>
			</tr>
			<?php endif; ?>
			<?php endif; ?>
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

		// Title/Description fields are pre-filled with the defaults (post
		// title; AI summary or excerpt); a value matching its default is not
		// an override worth storing. Each field's render-time default rides
		// along in a hidden *_default field: comparing against it (as well as
		// the current default) keeps an UNTOUCHED field from being stored as
		// an override when its default changed in the same update — e.g. the
		// user renames the post, edits the excerpt, or an AI summary landed
		// between render and save. All comparisons are sanitized-vs-sanitized
		// so kses/whitespace normalization can't fabricate an override either.
		if ( isset( $raw['title'] ) ) {
			$title          = sanitize_text_field( (string) $raw['title'] );
			$render_default = isset( $raw['title_default'] ) ? sanitize_text_field( (string) $raw['title_default'] ) : null;
			if ( sanitize_text_field( $post->post_title ) === $title || ( null !== $render_default && $render_default === $title ) ) {
				$title = '';
			}
		} else {
			// Field not submitted (hidden on the front/posts pages): keep any
			// stored override rather than wiping it.
			$title = (string) Coywolf_SEO_Options::post_meta( $post_id )['title'];
		}

		if ( isset( $raw['description'] ) ) {
			$description  = sanitize_textarea_field( (string) $raw['description'] );
			$default_desc = Coywolf_SEO::instance()->ai()->description_for( $post_id );
			if ( '' === $default_desc ) {
				$default_desc = (string) $post->post_excerpt;
			}
			$render_default = isset( $raw['description_default'] ) ? sanitize_textarea_field( (string) $raw['description_default'] ) : null;
			if ( sanitize_textarea_field( $default_desc ) === $description || ( null !== $render_default && $render_default === $description ) ) {
				$description = '';
			}
		} else {
			// Field not submitted — hidden by "Exclude meta description" or on
			// the front/posts pages. Keep any stored override.
			$description = (string) Coywolf_SEO_Options::post_meta( $post_id )['description'];
		}

		$canonical = isset( $raw['canonical'] ) ? esc_url_raw( (string) $raw['canonical'] ) : '';
		// The field shows the post's own URL; that is the default, not an
		// override worth storing.
		if ( (string) get_permalink( $post_id ) === $canonical ) {
			$canonical = '';
		}

		$meta = array(
			'title'        => $title,
			'description'  => $description,
			'page_type'    => $page_type,
			'article_type' => $art_type,
			'noindex'      => ! empty( $raw['noindex'] ),
			'nofollow'     => ! empty( $raw['nofollow'] ),
			'canonical'    => $canonical,
		);

		// All defaults? Keep the database clean.
		if ( '' === $meta['title'] && '' === $meta['description'] && '' === $meta['page_type'] && '' === $meta['article_type'] && ! $meta['noindex'] && ! $meta['nofollow'] && '' === $meta['canonical'] ) {
			delete_post_meta( $post_id, '_coywolf_seo' );
			return;
		}

		update_post_meta( $post_id, '_coywolf_seo', $meta );
	}
}
