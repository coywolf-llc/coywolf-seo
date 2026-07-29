<?php
/**
 * OKF bundle discovery / advertising — Labs (OKF sub-feature).
 *
 * OKF v0.2 defines NO discovery mechanism: there is no well-known path and no
 * consumer that probes for a bundle. So this is *advertising*, not a protocol —
 * it points agents and crawlers at the bundle from the places they already look
 * (llms.txt, a single <head> link) and keeps the bundle path unblocked in
 * robots.txt. None of it guarantees anything consumes the bundle; the UI copy
 * says so.
 *
 * Everything here is gated on BOTH the OKF feature being enabled AND its
 * "Advertise the bundle publicly" sub-setting AND the live read endpoint being
 * on (there is nothing to advertise without a reachable URL) AND the site being
 * public (blog_public). It never overwrites an llms.txt or robots.txt owned by
 * another plugin/theme/file — it detects and defers, surfacing the exact line
 * to add by hand instead.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovery hints for the OKF bundle.
 */
final class Coywolf_SEO_OKF_Advertiser extends Coywolf_SEO_Labs_Bundle_Advertiser {

	/**
	 * Query var for the proposed (non-standard) /.well-known/okf route.
	 */
	const WELLKNOWN_VAR = 'coywolf_seo_wk_okf';

	/**
	 * Stable id of the managed Robots.txt Manager rule that allows the bundle
	 * path, so it can be located for removal.
	 */
	const ROBOTS_RULE_ID = 'coywolf-okf-allow';

	/**
	 * The OKF feature (owns the settings and the canonical bundle URL).
	 *
	 * @var Coywolf_SEO_OKF
	 */
	private $okf;

	/**
	 * Construct with the owning OKF feature.
	 *
	 * @param Coywolf_SEO_OKF $okf OKF feature.
	 */
	public function __construct( Coywolf_SEO_OKF $okf ) {
		$this->okf = $okf;
	}

	/**
	 * The owning Labs feature (lets the shared base gates read its toggles).
	 *
	 * @return Coywolf_SEO_Labs_Feature
	 */
	protected function feature() {
		return $this->okf;
	}

	/**
	 * Register hooks. No-op unless advertising is configured; each output path
	 * additionally re-checks blog_public at request time.
	 */
	public function init() {
		if ( ! $this->is_configured() ) {
			return;
		}
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ), 0 );
		add_action( 'wp_head', array( $this, 'output_link' ), 2 );
		// The robots.txt allowance is a managed rule in the Robots.txt Manager
		// (added/removed in the OKF save handler), not a runtime filter — so it
		// is visible and editable in the Rules table and written to a physical
		// robots.txt too.
	}

	// ---------------------------------------------------------------------
	// Route: proposed /.well-known/okf  (llms.txt is owned by Coywolf_SEO_Llms_Txt)
	// ---------------------------------------------------------------------

	/**
	 * Register the proposed (non-standard) /.well-known/okf rewrite that
	 * resolves to the bundle root. llms.txt is no longer registered here — the
	 * core Coywolf_SEO_Llms_Txt owns it and integrates the OKF reference.
	 */
	public function add_rewrite_rules() {
		// PROPOSED CONVENTION, NOT a standard: OKF v0.2 defines no well-known
		// location. This is a convenience alias that 302s to the canonical
		// bundle root; consumers must not rely on it existing.
		add_rewrite_rule( '^\.well-known/okf/?$', 'index.php?' . self::WELLKNOWN_VAR . '=1', 'top' );
	}

	/**
	 * The regex patterns add_rewrite_rules() registers, so a disable transition
	 * can drop them from the in-memory rule set before flushing (otherwise the
	 * flush re-persists the /.well-known/okf alias).
	 *
	 * @return string[]
	 */
	public function rewrite_patterns() {
		return array( '^\.well-known/okf/?$' );
	}

	/**
	 * Register the discovery query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function register_query_vars( $vars ) {
		$vars[] = self::WELLKNOWN_VAR;
		return $vars;
	}

	/**
	 * Resolve /.well-known/okf to the bundle root.
	 */
	public function maybe_serve() {
		if ( ! $this->is_public() ) {
			return;
		}
		if ( '1' === (string) get_query_var( self::WELLKNOWN_VAR ) ) {
			wp_safe_redirect( $this->bundle_root_url(), 302 );
			exit;
		}
	}

	/**
	 * The exact llms.txt reference entry (without the leading "- ["), reused by
	 * the llms.txt owner and the manual-add guidance in the UI.
	 *
	 * @return string
	 */
	public function llms_reference_line() {
		return 'OKF bundle](' . $this->bundle_root_url() . '): Structured Markdown knowledge graph of this site\'s public content (Open Knowledge Format v' . Coywolf_SEO_OKF_Generator::OKF_VERSION . '). Start at the root index and follow the cross-links.';
	}

	// ---------------------------------------------------------------------
	// <head> hint
	// ---------------------------------------------------------------------

	/**
	 * Emit a single site-level <link rel="alternate"> pointing at the bundle
	 * root, on public, indexable front-end pages only.
	 */
	public function output_link() {
		if ( ! $this->is_public() || ! $this->is_indexable_response() ) {
			return;
		}
		printf(
			'<link rel="alternate" type="text/markdown" title="%1$s" href="%2$s" />' . "\n",
			esc_attr__( 'Open Knowledge Format bundle', 'coywolf-seo' ),
			esc_url( $this->bundle_root_url() )
		);
	}

	// ---------------------------------------------------------------------
	// robots.txt allowance (a managed Robots.txt Manager rule)
	// ---------------------------------------------------------------------

	/**
	 * Add (or refresh) the managed Allow rule for the bundle path in the
	 * Robots.txt Manager. It is stored even when that feature is off, and is
	 * served once it is on (virtual or physical). Called from the OKF save
	 * handler when advertising is configured.
	 */
	public function add_robots_rule() {
		$robots = $this->robots_manager();
		if ( $robots ) {
			$robots->add_managed_rule( $this->robots_rule() );
		}
	}

	/**
	 * Remove the managed Allow rule for the bundle path. Called from the OKF
	 * save handler when advertising is turned off (or OKF is disabled).
	 */
	public function remove_robots_rule() {
		$robots = $this->robots_manager();
		if ( $robots ) {
			$robots->remove_managed_rule( self::ROBOTS_RULE_ID );
		}
	}

	/**
	 * Whether the managed Allow rule is currently stored.
	 *
	 * @return bool
	 */
	public function robots_rule_present() {
		$robots = $this->robots_manager();
		if ( ! $robots ) {
			return false;
		}
		foreach ( $robots->get_rules() as $rule ) {
			if ( isset( $rule['id'] ) && self::ROBOTS_RULE_ID === (string) $rule['id'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The managed rule that allows crawlers to reach the bundle: an Allow on the
	 * bundle folder for all robots.
	 *
	 * @return array<string,mixed>
	 */
	private function robots_rule() {
		return array_merge(
			Coywolf_SEO_Robots_Rules::blank(),
			array(
				'id'          => self::ROBOTS_RULE_ID,
				'type'        => 'folder',
				'directive'   => 'allow',
				'name'        => __( 'Open Knowledge Format (OKF) bundle', 'coywolf-seo' ),
				'description' => __( 'Lets crawlers reach the OKF bundle (added by the Labs OKF feature).', 'coywolf-seo' ),
				'path'        => $this->bundle_root_path(),
				'agents'      => array( '*' ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// URLs / paths
	// ---------------------------------------------------------------------

	/**
	 * Canonical bundle root URL (the entry point every hint points at).
	 *
	 * @return string
	 */
	public function bundle_root_url() {
		return $this->okf->endpoint_base_url();
	}

	/**
	 * Bundle root path component (subdirectory-install safe), e.g. /okf/.
	 *
	 * @return string
	 */
	public function bundle_root_path() {
		$path = (string) wp_parse_url( $this->bundle_root_url(), PHP_URL_PATH );
		return '' !== $path ? $path : '/okf/';
	}

	/**
	 * The proposed /.well-known/okf alias URL.
	 *
	 * @return string
	 */
	public function wellknown_url() {
		return home_url( '/.well-known/okf' );
	}

	// ---------------------------------------------------------------------
	// Panel
	// ---------------------------------------------------------------------

	/**
	 * Render the advertising status sub-section on the OKF panel: which hints
	 * are active, the canonical URL, and any deferral guidance (a foreign
	 * llms.txt, or a robots.txt that blocks the bundle).
	 */
	public function render_status() {
		?>
		<hr />
		<h3><?php esc_html_e( 'Discovery (advertising)', 'coywolf-seo' ); ?></h3>
		<?php if ( ! $this->okf->endpoint_enabled() ) : ?>
			<p class="description"><?php esc_html_e( 'Advertising needs the live /okf/ read endpoint — enable it above. There is no public URL to advertise otherwise.', 'coywolf-seo' ); ?></p>
			<?php
			return;
		endif;
		if ( ! (bool) get_option( 'blog_public', 1 ) ) :
			?>
			<p class="description"><?php esc_html_e( 'Your site is set to discourage search engines (Settings → Reading), so no public discovery hints are emitted while that is on.', 'coywolf-seo' ); ?></p>
			<?php endif; ?>

		<p class="description"><?php esc_html_e( 'OKF has no automatic discovery, so these hints point agents at the bundle where they already look — without guaranteeing anything consumes it.', 'coywolf-seo' ); ?></p>
		<ul class="ul-disc" style="margin-left:1.5rem;">
			<li>
				<?php esc_html_e( 'Canonical bundle root:', 'coywolf-seo' ); ?>
				<a href="<?php echo esc_url( $this->bundle_root_url() ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $this->bundle_root_url() ); ?></code></a>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: the /llms.txt URL */
					esc_html__( 'The bundle is referenced from %s. With the LLMs.txt feature on, the reference is folded into its full file; with it off, a minimal llms.txt is served here.', 'coywolf-seo' ),
					'<a href="' . esc_url( home_url( '/llms.txt' ) ) . '" target="_blank" rel="noopener"><code>' . esc_html( home_url( '/llms.txt' ) ) . '</code></a>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'A single <link rel="alternate" type="text/markdown"> in the <head> of public, indexable pages.', 'coywolf-seo' ); ?></li>
			<li>
				<?php
				printf(
					/* translators: %s: the /.well-known/okf URL */
					esc_html__( 'A proposed (non-standard) %s alias that redirects to the bundle root.', 'coywolf-seo' ),
					'<code>' . esc_html( $this->wellknown_url() ) . '</code>'
				);
				?>
			</li>
			<li>
				<?php
				if ( $this->robots_rule_present() ) {
					printf(
						/* translators: %s: the bundle path, e.g. /okf/ */
						esc_html__( 'An %s Allow rule was added to your Robots.txt Manager so crawlers are not blocked from the bundle.', 'coywolf-seo' ),
						'<code>' . esc_html( $this->bundle_root_path() ) . '</code>'
					);
					if ( ! Coywolf_SEO_Options::feature_enabled( 'robots' ) ) {
						echo ' <em>' . esc_html__( '(The Robots.txt Manager is currently off, so it is stored but not served until you turn that feature on.)', 'coywolf-seo' ) . '</em>';
					}
				} else {
					printf(
						/* translators: %s: the bundle path, e.g. /okf/ */
						esc_html__( 'Make sure %s is not blocked in your robots.txt so crawlers can reach the bundle.', 'coywolf-seo' ),
						'<code>' . esc_html( $this->bundle_root_path() ) . '</code>'
					);
				}
				?>
			</li>
		</ul>
		<?php
	}
}
