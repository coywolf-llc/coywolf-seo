<?php
/**
 * OKF bundle discovery / advertising — Labs (OKF sub-feature).
 *
 * OKF v0.1 defines NO discovery mechanism: there is no well-known path and no
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
final class Coywolf_SEO_OKF_Advertiser {

	/**
	 * Query var for the virtual llms.txt route.
	 */
	const LLMS_VAR = 'coywolf_seo_llms';

	/**
	 * Query var for the proposed (non-standard) /.well-known/okf route.
	 */
	const WELLKNOWN_VAR = 'coywolf_seo_wk_okf';

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
	 * Whether advertising is configured on: OKF enabled + advertise sub-setting
	 * + the live endpoint (no reachable URL to point at otherwise). Independent
	 * of blog_public, which gates the actual public output (so toggling site
	 * visibility needs no rewrite flush).
	 *
	 * @return bool
	 */
	public function is_configured() {
		return $this->okf->is_enabled() && $this->okf->advertise_enabled() && $this->okf->endpoint_enabled();
	}

	/**
	 * Whether public output should actually be emitted: configured AND the site
	 * is public.
	 *
	 * @return bool
	 */
	private function is_public() {
		return $this->is_configured() && (bool) get_option( 'blog_public', 1 );
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
		// PHP_INT_MAX so this runs after the Robots.txt Manager's own filter and
		// can see (and, only if needed, top up) the near-final output.
		add_filter( 'robots_txt', array( $this, 'ensure_bundle_allowed' ), PHP_INT_MAX, 2 );
	}

	// ---------------------------------------------------------------------
	// Routes: virtual llms.txt + proposed /.well-known/okf
	// ---------------------------------------------------------------------

	/**
	 * Register the discovery rewrite rules: a virtual llms.txt at the site root
	 * and a proposed (non-standard) /.well-known/okf that resolves to the
	 * bundle root.
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?' . self::LLMS_VAR . '=1', 'top' );
		// PROPOSED CONVENTION, NOT a standard: OKF v0.1 defines no well-known
		// location. This is a convenience alias that 302s to the canonical
		// bundle root; consumers must not rely on it existing.
		add_rewrite_rule( '^\.well-known/okf/?$', 'index.php?' . self::WELLKNOWN_VAR . '=1', 'top' );
	}

	/**
	 * Register the discovery query vars.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function register_query_vars( $vars ) {
		$vars[] = self::LLMS_VAR;
		$vars[] = self::WELLKNOWN_VAR;
		return $vars;
	}

	/**
	 * Serve the virtual llms.txt or resolve /.well-known/okf to the bundle root.
	 */
	public function maybe_serve() {
		if ( ! $this->is_public() ) {
			return;
		}

		if ( '1' === (string) get_query_var( self::WELLKNOWN_VAR ) ) {
			wp_safe_redirect( $this->bundle_root_url(), 302 );
			exit;
		}

		if ( '1' !== (string) get_query_var( self::LLMS_VAR ) ) {
			return;
		}

		// Never clobber a physical llms.txt owned by the site/another plugin —
		// if one exists, the web server serves it directly; should the request
		// still reach WordPress, defer rather than overwrite.
		if ( $this->physical_llms_txt_exists() ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: text/markdown; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text llms.txt body served as text/markdown, not HTML.
		echo $this->llms_txt_body();
		exit;
	}

	/**
	 * The minimal llms.txt body referencing the OKF bundle (absolute URL).
	 *
	 * @return string
	 */
	private function llms_txt_body() {
		$name = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$desc = wp_strip_all_tags( (string) get_bloginfo( 'description' ) );
		$out  = '# ' . $name . "\n\n";
		if ( '' !== trim( $desc ) ) {
			$out .= '> ' . $desc . "\n\n";
		}
		$out .= "## Open Knowledge Format\n\n";
		$out .= '- [' . $this->llms_reference_line() . "\n";
		return $out;
	}

	/**
	 * The exact llms.txt reference entry (without the leading "- ["), reused for
	 * both the served file and the manual-add guidance in the UI.
	 *
	 * @return string
	 */
	public function llms_reference_line() {
		return 'OKF bundle](' . $this->bundle_root_url() . '): Structured Markdown knowledge graph of this site\'s public content (Open Knowledge Format v0.1). Start at the root index and follow the cross-links.';
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

	/**
	 * Whether the current response is a public, indexable HTML page (mirrors the
	 * generator's visibility rules: no admin/feed/robots/404/search/preview, no
	 * password-protected or per-post noindex singular).
	 *
	 * @return bool
	 */
	private function is_indexable_response() {
		if ( is_admin() || is_feed() || is_robots() || is_404() || is_search() || is_preview() ) {
			return false;
		}
		if ( is_singular() ) {
			if ( post_password_required() ) {
				return false;
			}
			$meta = Coywolf_SEO_Options::post_meta( get_queried_object_id() );
			if ( ! empty( $meta['noindex'] ) ) {
				return false;
			}
		}
		return true;
	}

	// ---------------------------------------------------------------------
	// robots.txt allowance (additive only)
	// ---------------------------------------------------------------------

	/**
	 * Ensure the bundle path is not blocked in the virtual robots.txt. Adds an
	 * explicit `Allow: /okf/` ONLY when the near-final output would disallow the
	 * bundle for the wildcard agent. Never adds a Disallow. Physical robots.txt
	 * files bypass this filter entirely (the panel detects and defers for them).
	 *
	 * @param string $output      robots.txt body assembled so far.
	 * @param bool   $blog_public Whether the blog is public (unused; we re-check).
	 * @return string
	 */
	public function ensure_bundle_allowed( $output, $blog_public ) {
		unset( $blog_public );
		$output = (string) $output;
		if ( ! $this->is_public() ) {
			return $output;
		}
		if ( Coywolf_SEO_Robots_Rep::one_agent_allowed( $output, '*', $this->bundle_root_path() ) ) {
			return $output;
		}
		return rtrim( $output, "\n" ) . "\n\nUser-agent: *\nAllow: " . $this->bundle_root_path() . "\n";
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
	 * The virtual llms.txt URL.
	 *
	 * @return string
	 */
	public function llms_txt_url() {
		return home_url( '/llms.txt' );
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
	// Detection (panel)
	// ---------------------------------------------------------------------

	/**
	 * Whether a physical llms.txt exists at the site root (owned elsewhere).
	 *
	 * @return bool
	 */
	public function physical_llms_txt_exists() {
		$path = $this->site_root_file( 'llms.txt' );
		return '' !== $path && is_readable( $path );
	}

	/**
	 * Whether a physical robots.txt at the site root would block the bundle for
	 * the wildcard agent. Only physical files matter here — virtual output is
	 * kept allowed by ensure_bundle_allowed().
	 *
	 * @return bool
	 */
	public function robots_blocks_bundle() {
		$path = $this->site_root_file( 'robots.txt' );
		if ( '' === $path || ! is_readable( $path ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the site's own robots.txt to check whether it blocks the bundle path.
		$body = (string) file_get_contents( $path );
		return ! Coywolf_SEO_Robots_Rep::one_agent_allowed( $body, '*', $this->bundle_root_path() );
	}

	/**
	 * Absolute path to a site-root file (subdirectory-install safe), or '' when
	 * it can't be resolved. Loads the admin file API on the front end first.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private function site_root_file( $name ) {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$home = get_home_path();
		if ( ! $home || '/' === $home ) {
			return '';
		}
		return trailingslashit( $home ) . $name;
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
				if ( $this->physical_llms_txt_exists() ) {
					echo '<strong>' . esc_html__( 'llms.txt already exists at your site root', 'coywolf-seo' ) . '</strong> — ';
					esc_html_e( "we won't overwrite it. Add this line under a section in it by hand:", 'coywolf-seo' );
					echo '<br /><code>- ' . esc_html( $this->llms_reference_line() ) . '</code>';
				} else {
					esc_html_e( 'Serving a minimal llms.txt that references the bundle:', 'coywolf-seo' );
					echo ' <a href="' . esc_url( $this->llms_txt_url() ) . '" target="_blank" rel="noopener"><code>' . esc_html( $this->llms_txt_url() ) . '</code></a>';
				}
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
		</ul>

		<?php if ( $this->robots_blocks_bundle() ) : ?>
			<div class="notice notice-warning inline" style="margin:.5rem 0;">
				<p>
					<?php esc_html_e( 'Your robots.txt is a physical file that blocks the bundle path for crawlers. Add this line so agents can reach it (we cannot edit a file we do not manage):', 'coywolf-seo' ); ?>
					<br /><code>Allow: <?php echo esc_html( $this->bundle_root_path() ); ?></code>
				</p>
			</div>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'robots.txt allows the bundle path.', 'coywolf-seo' ); ?></p>
		<?php endif; ?>
		<?php
	}
}
