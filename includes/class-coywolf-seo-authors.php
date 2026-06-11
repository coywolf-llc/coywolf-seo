<?php
/**
 * The Authors page for Coywolf SEO.
 *
 * Pick a user and the page imports their account details (display name,
 * website or archive URL, bio, avatar) as Schema.org Person properties,
 * alongside the typical author defaults. Properties can then be edited,
 * removed, or extended from the full Person catalog. Saved sets are used as
 * the author in Article schema markup.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authors admin page and storage glue.
 */
final class Coywolf_SEO_Authors {

	/**
	 * Menu slug.
	 */
	const SLUG = 'coywolf-seo-authors';

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_action( 'admin_post_coywolf_seo_save_author', array( $this, 'save' ) );
	}

	/**
	 * The default property rows imported from a user's account.
	 *
	 * @param WP_User $user User.
	 * @return array
	 */
	public function imported_rows( $user ) {
		$url  = $user->user_url ? $user->user_url : get_author_posts_url( $user->ID );
		$rows = array(
			array(
				'prop'  => '@id',
				'value' => get_author_posts_url( $user->ID ) . '#person',
			),
			array(
				'prop'  => 'name',
				'value' => $user->display_name,
			),
			array(
				'prop'  => 'url',
				'value' => $url,
			),
		);
		$bio = get_the_author_meta( 'description', $user->ID );
		if ( '' !== trim( (string) $bio ) ) {
			$rows[] = array(
				'prop'  => 'description',
				'value' => sanitize_text_field( $bio ),
			);
		}
		$avatar = get_avatar_url( $user->ID, array( 'size' => 512 ) );
		if ( $avatar ) {
			$rows[] = array(
				'prop'  => 'image',
				'value' => $avatar,
			);
		}
		$rows[] = array(
			'prop'  => 'jobTitle',
			'value' => '',
		);
		$rows[] = array(
			'prop'  => 'sameAs',
			'value' => '',
		);
		return $rows;
	}

	/**
	 * Handle the Authors form.
	 */
	public function save() {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Coywolf SEO.', 'coywolf-seo' ) );
		}
		check_admin_referer( 'coywolf_seo_author' );

		$user_id = isset( $_POST['coywolf_seo_author_id'] ) ? absint( $_POST['coywolf_seo_author_id'] ) : 0;
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			wp_die( esc_html__( 'Unknown user.', 'coywolf-seo' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized row-by-row below.
		$raw  = isset( $_POST['coywolf_seo'] ) && is_array( $_POST['coywolf_seo'] ) ? wp_unslash( $_POST['coywolf_seo'] ) : array();
		$rows = isset( $raw['author_rows'] ) && is_array( $raw['author_rows'] ) ? array_values( $raw['author_rows'] ) : array();
		$rows = Coywolf_SEO_Options::sanitize_properties( $rows, Coywolf_SEO_Options::person_properties() );

		Coywolf_SEO_Options::save_author_rows( $user_id, $rows );

		wp_safe_redirect(
			add_query_arg(
				array(
					'coywolf-seo-saved' => 'author',
					'user'              => $user_id,
				),
				admin_url( 'admin.php?page=' . self::SLUG )
			)
		);
		exit;
	}

	/**
	 * Render the Authors page.
	 */
	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selection of which author to edit.
		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		$user    = $user_id ? get_userdata( $user_id ) : false;

		$person_props = Coywolf_SEO_Options::person_properties();
		$saved_all    = Coywolf_SEO_Options::authors_all();
		?>
		<div class="wrap coywolf-seo-wrap">
			<h1><?php esc_html_e( 'Authors', 'coywolf-seo' ); ?></h1>
			<p><?php esc_html_e( 'Author details are used for the author in Article schema markup. Selecting a user imports their account details; add, edit, or remove any Person property, then save.', 'coywolf-seo' ); ?></p>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<label for="coywolf-seo-author-select"><strong><?php esc_html_e( 'Select user', 'coywolf-seo' ); ?></strong></label>
				&nbsp;
				<select id="coywolf-seo-author-select" name="user">
					<option value="0"><?php esc_html_e( '— Select a user —', 'coywolf-seo' ); ?></option>
					<?php foreach ( get_users( array( 'fields' => array( 'ID', 'display_name' ) ) ) as $u ) : ?>
						<option value="<?php echo esc_attr( (string) $u->ID ); ?>" <?php selected( $user_id, (int) $u->ID ); ?>>
							<?php
							echo esc_html( $u->display_name );
							if ( isset( $saved_all[ $u->ID ] ) ) {
								echo ' ✓';
							}
							?>
						</option>
					<?php endforeach; ?>
				</select>
				<noscript><?php submit_button( __( 'Load', 'coywolf-seo' ), 'secondary', '', false ); ?></noscript>
			</form>

			<?php if ( $user ) : ?>
				<?php
				$rows = Coywolf_SEO_Options::author_rows( $user->ID );
				if ( null === $rows || empty( $rows ) ) {
					$rows = $this->imported_rows( $user );
				} else {
					// Always show the @id used in the output so it can be
					// edited (removing it falls back to the default anchor).
					$has_id = false;
					foreach ( $rows as $row ) {
						if ( isset( $row['prop'] ) && '@id' === $row['prop'] ) {
							$has_id = true;
							break;
						}
					}
					if ( ! $has_id ) {
						array_unshift(
							$rows,
							array(
								'prop'  => '@id',
								'value' => get_author_posts_url( $user->ID ) . '#person',
							)
						);
					}
				}
				?>
				<h2><?php echo esc_html( $user->display_name ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="coywolf_seo_save_author" />
					<input type="hidden" name="coywolf_seo_author_id" value="<?php echo esc_attr( (string) $user->ID ); ?>" />
					<?php wp_nonce_field( 'coywolf_seo_author' ); ?>

					<table class="coywolf-seo-props" id="coywolf-seo-author-props" data-field="author_rows" data-next-index="<?php echo esc_attr( (string) count( $rows ) ); ?>">
						<tbody>
							<?php Coywolf_SEO_Admin::render_property_rows( 'author_rows', $rows, $person_props ); ?>
						</tbody>
					</table>
					<select class="coywolf-seo-add-select" data-target="coywolf-seo-author-props" aria-label="<?php esc_attr_e( 'Add a property', 'coywolf-seo' ); ?>">
						<option value=""><?php esc_html_e( '— select property —', 'coywolf-seo' ); ?></option>
						<?php foreach ( $person_props as $prop => $label ) : ?>
							<option value="<?php echo esc_attr( $prop ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Schema.org Person properties — selecting a property adds it, and each value input matches the property type. Add a property more than once (sameAs, for example) to output multiple values. Empty rows are not saved; removing @id falls back to the default.', 'coywolf-seo' ); ?></p>

					<?php submit_button( __( 'Save Author', 'coywolf-seo' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
