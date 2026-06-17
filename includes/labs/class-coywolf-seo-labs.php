<?php
/**
 * Labs — a container for experimental Coywolf SEO features.
 *
 * Each Labs feature is fully self-contained: it is enabled AND managed (its
 * settings and actions) here on the Labs page, not on the main Settings page.
 * The container only renders the page shell and the shared "experimental"
 * notice; every feature owns its own option, panel, hooks, and actions.
 *
 * A Labs feature implements: id(), title(), init(), render_panel(), and
 * (optionally) notices() returning [ msg_key => [ type, text ] ].
 *
 * The whole includes/labs/ tree — and the marked require of it in the main
 * plugin file — is stripped from the WordPress.org build (see
 * .github/build-wporg.sh), so Labs ships only in the GitHub distribution.
 * Callers reference this class through class_exists() guards so its absence is
 * a clean no-op.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Labs feature registry and page.
 */
final class Coywolf_SEO_Labs {

	/**
	 * Labs admin page slug.
	 */
	const SLUG = 'coywolf-seo-labs';

	/**
	 * Capability required to view/manage Labs (matches Settings).
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Registered feature objects.
	 *
	 * @var object[]
	 */
	private $features = array();

	/**
	 * Build the feature registry.
	 */
	public function __construct() {
		$this->features[] = new Coywolf_SEO_OKF();
	}

	/**
	 * Initialise every feature's hooks.
	 */
	public function init() {
		foreach ( $this->features as $feature ) {
			$feature->init();
		}
	}

	/**
	 * Render the Labs page: experimental notice, any status message, then each
	 * feature's self-managed panel.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		?>
		<div class="wrap coywolf-seo-wrap">
			<h1><?php esc_html_e( 'Labs', 'coywolf-seo' ); ?></h1>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'Labs features are experimental. They are off by default, may change between releases, and could be removed. Enable only what you intend to use.', 'coywolf-seo' ); ?></p>
			</div>
			<?php
			$this->render_status_notice();
			foreach ( $this->features as $feature ) {
				$feature->render_panel();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the status notice for a just-completed feature action, looked up
	 * across every feature's notices().
	 */
	private function render_status_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag set by our own post-action redirect.
		$key = isset( $_GET['coywolf-seo-labs-msg'] ) ? sanitize_key( wp_unslash( $_GET['coywolf-seo-labs-msg'] ) ) : '';
		if ( '' === $key ) {
			return;
		}
		foreach ( $this->features as $feature ) {
			if ( ! method_exists( $feature, 'notices' ) ) {
				continue;
			}
			$notices = $feature->notices();
			if ( isset( $notices[ $key ] ) ) {
				$type  = 'error' === $notices[ $key ][0] ? 'error' : 'success';
				$class = 'notice notice-' . $type . ' is-dismissible';
				printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $notices[ $key ][1] ) );
				return;
			}
		}
	}
}
