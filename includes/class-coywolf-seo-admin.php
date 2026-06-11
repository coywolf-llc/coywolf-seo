<?php
/**
 * Admin screens for Coywolf SEO: the Coywolf SEO menu with the Site Details
 * and Settings pages, their save handlers, and the access-rights capability
 * sync.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin pages and form handling.
 */
final class Coywolf_SEO_Admin {

	/**
	 * Capability gating the plugin's admin pages (granted per Access Rights).
	 */
	const CAPABILITY = 'coywolf_seo_manage';

	/**
	 * Menu slugs.
	 */
	const SLUG_SITE     = 'coywolf-seo';
	const SLUG_SETTINGS = 'coywolf-seo-settings';

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_coywolf_seo_save_site', array( $this, 'save_site_details' ) );
		add_action( 'admin_post_coywolf_seo_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_saved_notice' ) );
	}

	/**
	 * Grant the plugin capability per the Access Rights setting. Runs on
	 * activation and whenever the setting changes.
	 *
	 * @param string $access_role 'administrator' or 'editor' (the minimum role).
	 * @return void
	 */
	public static function sync_capability( $access_role ) {
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::CAPABILITY ) ) {
			$admin->add_cap( self::CAPABILITY );
		}
		$editor = get_role( 'editor' );
		if ( $editor ) {
			if ( 'editor' === $access_role && ! $editor->has_cap( self::CAPABILITY ) ) {
				$editor->add_cap( self::CAPABILITY );
			}
			if ( 'editor' !== $access_role && $editor->has_cap( self::CAPABILITY ) ) {
				$editor->remove_cap( self::CAPABILITY );
			}
		}
	}

	/**
	 * Remove the plugin capability from every role (uninstall cleanup).
	 *
	 * @return void
	 */
	public static function remove_capability() {
		foreach ( wp_roles()->role_objects as $role ) {
			if ( $role->has_cap( self::CAPABILITY ) ) {
				$role->remove_cap( self::CAPABILITY );
			}
		}
	}

	/**
	 * Register the Coywolf SEO menu and its pages.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Coywolf SEO', 'coywolf-seo' ),
			__( 'Coywolf SEO', 'coywolf-seo' ),
			self::CAPABILITY,
			self::SLUG_SITE,
			array( $this, 'render_site_details' ),
			'dashicons-search',
			81
		);
		add_submenu_page(
			self::SLUG_SITE,
			__( 'Site Details', 'coywolf-seo' ),
			__( 'Site Details', 'coywolf-seo' ),
			self::CAPABILITY,
			self::SLUG_SITE,
			array( $this, 'render_site_details' )
		);
		add_submenu_page(
			self::SLUG_SITE,
			__( 'Authors', 'coywolf-seo' ),
			__( 'Authors', 'coywolf-seo' ),
			self::CAPABILITY,
			Coywolf_SEO_Authors::SLUG,
			array( Coywolf_SEO::instance()->authors(), 'render' )
		);
		add_submenu_page(
			self::SLUG_SITE,
			__( 'Settings', 'coywolf-seo' ),
			__( 'Settings', 'coywolf-seo' ),
			'manage_options',
			self::SLUG_SETTINGS,
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Enqueue admin CSS/JS on the plugin's own screens only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, self::SLUG_SITE ) === false ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style(
			'coywolf-seo-admin',
			COYWOLF_SEO_URL . 'css/admin.css',
			array(),
			Coywolf_SEO::VERSION
		);
		wp_enqueue_script(
			'coywolf-seo-admin',
			COYWOLF_SEO_URL . 'js/admin.js',
			array( 'jquery' ),
			Coywolf_SEO::VERSION,
			true
		);
	}

	/**
	 * Success notice after a save redirect.
	 */
	public function maybe_show_saved_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag set by our own redirect.
		$saved  = isset( $_GET['coywolf-seo-saved'] ) ? sanitize_key( $_GET['coywolf-seo-saved'] ) : '';
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $saved || ! $screen || strpos( (string) $screen->id, self::SLUG_SITE ) === false ) {
			return;
		}
		if ( 'settings' === $saved ) {
			$message = __( 'Settings saved.', 'coywolf-seo' );
		} elseif ( 'author' === $saved ) {
			$message = __( 'The author details have been saved.', 'coywolf-seo' );
		} else {
			$message = __( 'Site details saved.', 'coywolf-seo' );
		}
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * Redirect back to a plugin page with the saved flag.
	 *
	 * @param string $slug Page slug.
	 * @param string $what Which form was saved.
	 */
	private function redirect_back( $slug, $what ) {
		wp_safe_redirect( add_query_arg( 'coywolf-seo-saved', $what, admin_url( 'admin.php?page=' . $slug ) ) );
		exit;
	}

	/**
	 * Handle the Site Details form.
	 */
	public function save_site_details() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Coywolf SEO.', 'coywolf-seo' ) );
		}
		check_admin_referer( 'coywolf_seo_site' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field in Coywolf_SEO_Options::sanitize().
		$raw = isset( $_POST['coywolf_seo'] ) && is_array( $_POST['coywolf_seo'] ) ? wp_unslash( $_POST['coywolf_seo'] ) : array();

		// Checkboxes are absent when unchecked: force their presence so
		// sanitize() records the off state instead of skipping the key.
		foreach ( array( 'post_append_site_name', 'page_append_site_name', 'cat_append_site_name', 'cat_hide_prefix', 'tag_append_site_name' ) as $key ) {
			$raw[ $key ] = ! empty( $raw[ $key ] );
		}
		// Rebuild the property repeater from its parallel arrays.
		$raw['org_properties'] = $this->zip_properties( $raw );

		$prefix_before = (bool) Coywolf_SEO_Options::get( 'cat_hide_prefix' );
		Coywolf_SEO_Options::update( Coywolf_SEO_Options::sanitize( $raw ) );
		if ( (bool) Coywolf_SEO_Options::get( 'cat_hide_prefix' ) !== $prefix_before ) {
			// Category URLs just changed shape; regenerate the rewrite rules.
			flush_rewrite_rules();
		}
		$this->redirect_back( self::SLUG_SITE, 'site' );
	}

	/**
	 * Handle the Settings form.
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Coywolf SEO settings.', 'coywolf-seo' ) );
		}
		check_admin_referer( 'coywolf_seo_settings' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field in Coywolf_SEO_Options::sanitize().
		$raw = isset( $_POST['coywolf_seo'] ) && is_array( $_POST['coywolf_seo'] ) ? wp_unslash( $_POST['coywolf_seo'] ) : array();

		foreach ( array( 'force_rewrite_titles', 'exclude_meta_desc', 'robots_index', 'robots_follow', 'robots_max_image', 'robots_max_snippet', 'robots_max_video', 'indexnow_enabled', 'news_enabled', 'news_include_posts', 'news_include_pages' ) as $key ) {
			$raw[ $key ] = ! empty( $raw[ $key ] );
		}
		if ( empty( $raw['news_cats'] ) ) {
			$raw['news_cats'] = array();
		}

		$news_before = (bool) Coywolf_SEO_Options::get( 'news_enabled' );
		$clean       = Coywolf_SEO_Options::sanitize( $raw );
		Coywolf_SEO_Options::update( $clean );
		if ( isset( $clean['access_role'] ) ) {
			self::sync_capability( $clean['access_role'] );
		}
		if ( ! empty( $clean['indexnow_enabled'] ) ) {
			Coywolf_SEO_IndexNow::ensure_key();
		}
		if ( (bool) Coywolf_SEO_Options::get( 'news_enabled' ) !== $news_before ) {
			// The sitemap URL just appeared or disappeared.
			flush_rewrite_rules();
		}
		$this->redirect_back( self::SLUG_SETTINGS, 'settings' );
	}

	/**
	 * Zip the repeater's parallel prop/value arrays into rows.
	 *
	 * @param array $raw Raw form input.
	 * @return array
	 */
	private function zip_properties( array $raw ) {
		$props  = isset( $raw['org_prop'] ) && is_array( $raw['org_prop'] ) ? array_values( $raw['org_prop'] ) : array();
		$values = isset( $raw['org_value'] ) && is_array( $raw['org_value'] ) ? array_values( $raw['org_value'] ) : array();
		$rows   = array();
		foreach ( $props as $i => $prop ) {
			$rows[] = array(
				'prop'  => (string) $prop,
				'value' => isset( $values[ $i ] ) ? (string) $values[ $i ] : '',
			);
		}
		return $rows;
	}

	/**
	 * Render the Site Details page.
	 */
	public function render_site_details() {
		$o          = Coywolf_SEO_Options::all();
		$page_types = Coywolf_SEO_Options::page_types();
		$art_types  = Coywolf_SEO_Options::article_types();
		$org_props  = Coywolf_SEO_Options::organization_properties();

		$rows = $o['org_properties'];
		if ( empty( $rows ) ) {
			// First run: seed the typical Organization defaults.
			$rows = array(
				array(
					'prop'  => 'name',
					'value' => get_bloginfo( 'name' ),
				),
				array(
					'prop'  => 'url',
					'value' => home_url( '/' ),
				),
				array(
					'prop'  => 'logo',
					'value' => '',
				),
				array(
					'prop'  => 'sameAs',
					'value' => '',
				),
			);
		}

		$og_image_url = $o['og_image_id'] ? wp_get_attachment_image_url( (int) $o['og_image_id'], 'medium' ) : '';
		?>
		<div class="wrap coywolf-seo-wrap">
			<h1><?php esc_html_e( 'Site Details', 'coywolf-seo' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="coywolf_seo_save_site" />
				<?php wp_nonce_field( 'coywolf_seo_site' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Site Name', 'coywolf-seo' ); ?></th>
						<td>
							<code><?php echo esc_html( get_bloginfo( 'name' ) ); ?></code>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to the WordPress General Settings screen. */
									esc_html__( 'Uses the WordPress Site Name. Change it in %s.', 'coywolf-seo' ),
									'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'General Settings', 'coywolf-seo' ) . '</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tagline', 'coywolf-seo' ); ?></th>
						<td>
							<code><?php echo esc_html( get_bloginfo( 'description' ) ); ?></code>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to the WordPress General Settings screen. */
									esc_html__( 'Uses the WordPress Tagline. Change it in %s.', 'coywolf-seo' ),
									'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'General Settings', 'coywolf-seo' ) . '</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="coywolf-seo-og-image"><?php esc_html_e( 'Open Graph Image', 'coywolf-seo' ); ?></label></th>
						<td>
							<div class="coywolf-seo-og-preview" id="coywolf-seo-og-preview">
								<?php if ( $og_image_url ) : ?>
									<img src="<?php echo esc_url( $og_image_url ); ?>" alt="" />
								<?php endif; ?>
							</div>
							<input type="hidden" id="coywolf-seo-og-image" name="coywolf_seo[og_image_id]" value="<?php echo esc_attr( (string) $o['og_image_id'] ); ?>" />
							<button type="button" class="button" id="coywolf-seo-og-select"><?php esc_html_e( 'Select image', 'coywolf-seo' ); ?></button>
							<button type="button" class="button" id="coywolf-seo-og-remove" <?php echo $o['og_image_id'] ? '' : 'style="display:none"'; ?>><?php esc_html_e( 'Remove', 'coywolf-seo' ); ?></button>
							<p class="description"><?php esc_html_e( 'Default Open Graph image, used on any page that does not have one of its own.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Organization or Person', 'coywolf-seo' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="coywolf_seo[entity_type]" value="organization" <?php checked( $o['entity_type'], 'organization' ); ?> class="coywolf-seo-entity-toggle" />
									<?php esc_html_e( 'Organization', 'coywolf-seo' ); ?>
								</label>
								&nbsp;&nbsp;
								<label>
									<input type="radio" name="coywolf_seo[entity_type]" value="person" <?php checked( $o['entity_type'], 'person' ); ?> class="coywolf-seo-entity-toggle" />
									<?php esc_html_e( 'Person', 'coywolf-seo' ); ?>
								</label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Whether this site represents an organization or a person. Used as the publisher in schema markup.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr class="coywolf-seo-person-row" <?php echo ( 'person' === $o['entity_type'] ) ? '' : 'style="display:none"'; ?>>
						<th scope="row"><label for="coywolf-seo-person"><?php esc_html_e( 'Person', 'coywolf-seo' ); ?></label></th>
						<td>
							<select id="coywolf-seo-person" name="coywolf_seo[person_user_id]">
								<option value="0"><?php esc_html_e( '— Select a user —', 'coywolf-seo' ); ?></option>
								<?php foreach ( get_users( array( 'fields' => array( 'ID', 'display_name' ) ) ) as $user ) : ?>
									<option value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php selected( (int) $o['person_user_id'], (int) $user->ID ); ?>>
										<?php echo esc_html( $user->display_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr class="coywolf-seo-org-row" <?php echo ( 'organization' === $o['entity_type'] ) ? '' : 'style="display:none"'; ?>>
						<th scope="row"><?php esc_html_e( 'Organization properties', 'coywolf-seo' ); ?></th>
						<td>
							<table class="coywolf-seo-props" id="coywolf-seo-org-props">
								<tbody>
									<?php foreach ( $rows as $row ) : ?>
										<tr>
											<td>
												<select name="coywolf_seo[org_prop][]">
													<?php foreach ( $org_props as $prop => $label ) : ?>
														<option value="<?php echo esc_attr( $prop ); ?>" <?php selected( $row['prop'], $prop ); ?>><?php echo esc_html( $label ); ?></option>
													<?php endforeach; ?>
												</select>
											</td>
											<td><input type="text" class="regular-text" name="coywolf_seo[org_value][]" value="<?php echo esc_attr( $row['value'] ); ?>" /></td>
											<td><button type="button" class="button-link coywolf-seo-remove-row" aria-label="<?php esc_attr_e( 'Remove property', 'coywolf-seo' ); ?>">&times;</button></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<button type="button" class="button coywolf-seo-add-row" data-target="coywolf-seo-org-props"><?php esc_html_e( 'Add property', 'coywolf-seo' ); ?></button>
							<p class="description"><?php esc_html_e( 'Schema.org Organization properties. Add a property more than once (sameAs, for example) to output multiple values. Empty rows are not saved.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Homepage', 'coywolf-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="coywolf-seo-home-title"><?php esc_html_e( 'Title', 'coywolf-seo' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="coywolf-seo-home-title" name="coywolf_seo[homepage_title]" value="<?php echo esc_attr( $o['homepage_title'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Used for the homepage page title and Open Graph title. Defaults to the Site Name.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="coywolf-seo-home-desc"><?php esc_html_e( 'Description', 'coywolf-seo' ); ?></label></th>
						<td>
							<input type="text" class="large-text" id="coywolf-seo-home-desc" name="coywolf_seo[homepage_description]" value="<?php echo esc_attr( $o['homepage_description'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'description' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Used for the homepage meta description and Open Graph description. Defaults to the Tagline.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Posts', 'coywolf-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Append site name', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[post_append_site_name]" value="1" <?php checked( $o['post_append_site_name'] ); ?> />
								<?php esc_html_e( 'Append the site name to post titles, separated with an em dash', 'coywolf-seo' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="coywolf-seo-post-page-type"><?php esc_html_e( 'Page', 'coywolf-seo' ); ?></label></th>
						<td>
							<select id="coywolf-seo-post-page-type" name="coywolf_seo[post_page_type]">
								<?php foreach ( $page_types as $type => $label ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $o['post_page_type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Default schema page type for posts.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="coywolf-seo-post-article-type"><?php esc_html_e( 'Article', 'coywolf-seo' ); ?></label></th>
						<td>
							<select id="coywolf-seo-post-article-type" name="coywolf_seo[post_article_type]">
								<option value="none" <?php selected( $o['post_article_type'], 'none' ); ?>><?php esc_html_e( 'None', 'coywolf-seo' ); ?></option>
								<?php foreach ( $art_types as $type => $label ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $o['post_article_type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Default schema article type for posts.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Pages', 'coywolf-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Append site name', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[page_append_site_name]" value="1" <?php checked( $o['page_append_site_name'] ); ?> />
								<?php esc_html_e( 'Append the site name to page titles, separated with an em dash', 'coywolf-seo' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="coywolf-seo-page-page-type"><?php esc_html_e( 'Page', 'coywolf-seo' ); ?></label></th>
						<td>
							<select id="coywolf-seo-page-page-type" name="coywolf_seo[page_page_type]">
								<?php foreach ( $page_types as $type => $label ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $o['page_page_type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Default schema page type for pages.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="coywolf-seo-page-article-type"><?php esc_html_e( 'Article', 'coywolf-seo' ); ?></label></th>
						<td>
							<select id="coywolf-seo-page-article-type" name="coywolf_seo[page_article_type]">
								<option value="none" <?php selected( $o['page_article_type'], 'none' ); ?>><?php esc_html_e( 'None', 'coywolf-seo' ); ?></option>
								<?php foreach ( $art_types as $type => $label ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $o['page_article_type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Default schema article type for pages. Pages usually do not need one.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Categories', 'coywolf-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Append site name', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[cat_append_site_name]" value="1" <?php checked( $o['cat_append_site_name'] ); ?> />
								<?php esc_html_e( 'Append the site name to category titles, separated with an em dash', 'coywolf-seo' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Hide category prefix in slug', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[cat_hide_prefix]" value="1" <?php checked( $o['cat_hide_prefix'] ); ?> />
								<?php esc_html_e( 'Remove the category prefix (usually /category/) from category URLs', 'coywolf-seo' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Old prefixed URLs are 301 redirected to the clean ones. Requires pretty permalinks. A category sharing a slug with a page will take precedence over that page.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Tags', 'coywolf-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Append site name', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[tag_append_site_name]" value="1" <?php checked( $o['tag_append_site_name'] ); ?> />
								<?php esc_html_e( 'Append the site name to tag titles, separated with an em dash', 'coywolf-seo' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Site Details', 'coywolf-seo' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the Settings page.
	 */
	public function render_settings() {
		$o = Coywolf_SEO_Options::all();
		?>
		<div class="wrap coywolf-seo-wrap">
			<h1><?php esc_html_e( 'Settings', 'coywolf-seo' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="coywolf_seo_save_settings" />
				<?php wp_nonce_field( 'coywolf_seo_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="coywolf-seo-access"><?php esc_html_e( 'Access Rights', 'coywolf-seo' ); ?></label></th>
						<td>
							<select id="coywolf-seo-access" name="coywolf_seo[access_role]">
								<option value="administrator" <?php selected( $o['access_role'], 'administrator' ); ?>><?php esc_html_e( 'Administrators', 'coywolf-seo' ); ?></option>
								<option value="editor" <?php selected( $o['access_role'], 'editor' ); ?>><?php esc_html_e( 'Administrators and Editors', 'coywolf-seo' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Who can manage Site Details and Authors. This Settings page is always administrator-only.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Force rewrite titles', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[force_rewrite_titles]" value="1" <?php checked( $o['force_rewrite_titles'] ); ?> />
								<?php esc_html_e( 'Force rewrite the page title with this plugin’s settings', 'coywolf-seo' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Only needed when the theme builds its own title tag and ignores the WordPress document title. Leaves the markup untouched otherwise.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Exclude meta description', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[exclude_meta_desc]" value="1" <?php checked( $o['exclude_meta_desc'] ); ?> />
								<?php esc_html_e( 'Do not output a meta description on any page', 'coywolf-seo' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Google usually generates a snippet from the content, so a meta description is no longer necessary.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Robots', 'coywolf-seo' ); ?></th>
						<td>
							<fieldset>
								<label><input type="checkbox" name="coywolf_seo[robots_index]" value="1" <?php checked( $o['robots_index'] ); ?> /> <code>index</code></label><br />
								<label><input type="checkbox" name="coywolf_seo[robots_follow]" value="1" <?php checked( $o['robots_follow'] ); ?> /> <code>follow</code></label><br />
								<label><input type="checkbox" name="coywolf_seo[robots_max_image]" value="1" <?php checked( $o['robots_max_image'] ); ?> /> <code>max-image-preview:large</code></label><br />
								<label><input type="checkbox" name="coywolf_seo[robots_max_snippet]" value="1" <?php checked( $o['robots_max_snippet'] ); ?> /> <code>max-snippet:-1</code></label><br />
								<label><input type="checkbox" name="coywolf_seo[robots_max_video]" value="1" <?php checked( $o['robots_max_video'] ); ?> /> <code>max-video-preview:-1</code></label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Directives included in the robots meta tag. Per-post Noindex and Nofollow override index and follow.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'IndexNow', 'coywolf-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'IndexNow', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[indexnow_enabled]" value="1" <?php checked( $o['indexnow_enabled'] ); ?> />
								<?php esc_html_e( 'Ping Bing via IndexNow whenever a post or page is published, updated, or deleted', 'coywolf-seo' ); ?>
							</label>
							<?php if ( '' !== (string) $o['indexnow_key'] ) : ?>
								<p class="description">
									<?php esc_html_e( 'Key file:', 'coywolf-seo' ); ?>
									<a href="<?php echo esc_url( home_url( '/' . $o['indexnow_key'] . '.txt' ) ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( '/' . $o['indexnow_key'] . '.txt' ); ?></code></a>
									<?php esc_html_e( '(served by the plugin — no file is written).', 'coywolf-seo' ); ?>
								</p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'A site key is generated automatically when you enable this.', 'coywolf-seo' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Sitemaps', 'coywolf-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'News', 'coywolf-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="coywolf_seo[news_enabled]" value="1" <?php checked( $o['news_enabled'] ); ?> />
								<?php
								printf(
									/* translators: %s: the sitemap file name. */
									esc_html__( 'Serve a News XML sitemap at %s with articles from the last 48 hours', 'coywolf-seo' ),
									'<code>/' . esc_html( Coywolf_SEO_News_Sitemap::FILENAME ) . '</code>'
								);
								?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Include', 'coywolf-seo' ); ?></th>
						<td>
							<label><input type="checkbox" name="coywolf_seo[news_include_posts]" value="1" <?php checked( $o['news_include_posts'] ); ?> /> <?php esc_html_e( 'Posts', 'coywolf-seo' ); ?></label>
							&nbsp;&nbsp;
							<label><input type="checkbox" name="coywolf_seo[news_include_pages]" value="1" <?php checked( $o['news_include_pages'] ); ?> /> <?php esc_html_e( 'Pages', 'coywolf-seo' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="coywolf-seo-news-cat-mode"><?php esc_html_e( 'Categories', 'coywolf-seo' ); ?></label></th>
						<td>
							<select id="coywolf-seo-news-cat-mode" name="coywolf_seo[news_cat_mode]">
								<option value="all" <?php selected( $o['news_cat_mode'], 'all' ); ?>><?php esc_html_e( 'All categories', 'coywolf-seo' ); ?></option>
								<option value="include" <?php selected( $o['news_cat_mode'], 'include' ); ?>><?php esc_html_e( 'Only these categories', 'coywolf-seo' ); ?></option>
								<option value="exclude" <?php selected( $o['news_cat_mode'], 'exclude' ); ?>><?php esc_html_e( 'All except these categories', 'coywolf-seo' ); ?></option>
							</select>
							<div id="coywolf-seo-news-cats" class="coywolf-seo-cat-list" <?php echo ( 'all' === $o['news_cat_mode'] ) ? 'style="display:none"' : ''; ?>>
								<?php foreach ( get_categories( array( 'hide_empty' => false ) ) as $cat ) : ?>
									<label>
										<input type="checkbox" name="coywolf_seo[news_cats][]" value="<?php echo esc_attr( (string) $cat->term_id ); ?>" <?php checked( in_array( $cat->term_id, array_map( 'intval', (array) $o['news_cats'] ), true ) ); ?> />
										<?php echo esc_html( $cat->name ); ?>
									</label><br />
								<?php endforeach; ?>
							</div>
							<p class="description"><?php esc_html_e( 'Applies to posts; pages have no categories.', 'coywolf-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'coywolf-seo' ) ); ?>
			</form>
		</div>
		<?php
	}
}
