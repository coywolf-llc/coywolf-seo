<?php
/**
 * ARD discovery / advertising — Labs (ARD sub-feature).
 *
 * The ARD spec defines real discovery channels for the ai-catalog.json manifest:
 * the well-known path itself (the primary mechanism, owned by the feature's
 * endpoint), a `<link rel="ai-catalog">` in the page <head>, a robots.txt
 * `Agentmap:` directive, and optional DNS records. This advertiser emits the
 * <head> link and contributes a managed Allow rule keeping the catalog path
 * crawlable; the `Agentmap:` robots line and the DNS records are surfaced as
 * exact copy-paste guidance on the panel rather than written automatically (the
 * Robots.txt Manager models path Allow/Disallow rules, not raw directives, and
 * this feature never overwrites a robots.txt it does not own).
 *
 * Gated on the feature being enabled + advertise + the live endpoint, then
 * blog_public for actual public output. The shared gates + robots accessor live
 * in Coywolf_SEO_Labs_Bundle_Advertiser.
 *
 * Stripped from the WordPress.org build with the rest of includes/labs/.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovery hints for the ARD ai-catalog.json manifest.
 */
final class Coywolf_SEO_ARD_Advertiser extends Coywolf_SEO_Labs_Bundle_Advertiser {

	/**
	 * Stable id of the managed Robots.txt Manager Allow rule for the catalog path.
	 */
	const ROBOTS_RULE_ID = 'coywolf-ard-allow';

	/**
	 * The ARD feature (owns the settings and the canonical catalog URL).
	 *
	 * @var Coywolf_SEO_ARD
	 */
	private $ard;

	/**
	 * Construct with the owning ARD feature.
	 *
	 * @param Coywolf_SEO_ARD $ard ARD feature.
	 */
	public function __construct( Coywolf_SEO_ARD $ard ) {
		$this->ard = $ard;
	}

	/**
	 * The owning Labs feature (lets the shared base gates read its toggles).
	 *
	 * @return Coywolf_SEO_Labs_Feature
	 */
	protected function feature() {
		return $this->ard;
	}

	/**
	 * Register hooks. No-op unless advertising is configured; the output path
	 * additionally re-checks blog_public at request time.
	 */
	public function init() {
		if ( ! $this->is_configured() ) {
			return;
		}
		add_action( 'wp_head', array( $this, 'output_link' ), 2 );
		// The robots.txt allowance is a managed rule in the Robots.txt Manager
		// (added/removed in the ARD save handler), not a runtime filter.
	}

	// ---------------------------------------------------------------------
	// <head> hint
	// ---------------------------------------------------------------------

	/**
	 * Emit a single site-level <link rel="ai-catalog"> pointing at the manifest,
	 * on public, indexable front-end pages only.
	 */
	public function output_link() {
		if ( ! $this->is_public() || ! $this->is_indexable_response() ) {
			return;
		}
		printf(
			'<link rel="ai-catalog" href="%1$s" />' . "\n",
			esc_url( $this->catalog_url() )
		);
	}

	/**
	 * The exact llms.txt reference entry (without the leading "- ["). ARD is a
	 * capability-discovery manifest, not an llms.txt content channel, so this is
	 * not wired into the llms.txt owner; it exists only so the inherited owner
	 * accessor has something to return if ever called.
	 *
	 * @return string
	 */
	public function llms_reference_line() {
		return 'AI Catalog (ARD)](' . $this->catalog_url() . '): An Agentic Resource Discovery manifest of this site\'s machine-usable resources (ai-catalog.json).';
	}

	// ---------------------------------------------------------------------
	// robots.txt allowance (a managed Robots.txt Manager rule)
	// ---------------------------------------------------------------------

	/**
	 * Add (or refresh) the managed Allow rule for the catalog path.
	 */
	public function add_robots_rule() {
		$robots = $this->robots_manager();
		if ( $robots ) {
			$robots->add_managed_rule( $this->robots_rule() );
		}
	}

	/**
	 * Remove the managed Allow rule for the catalog path.
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
	 * The managed rule that allows crawlers to reach the manifest.
	 *
	 * @return array<string,mixed>
	 */
	private function robots_rule() {
		return array_merge(
			Coywolf_SEO_Robots_Rules::blank(),
			array(
				'id'          => self::ROBOTS_RULE_ID,
				'type'        => 'single_page',
				'directive'   => 'allow',
				'strict'      => true,
				'name'        => __( 'AI Catalog (ARD)', 'coywolf-seo' ),
				'description' => __( 'Lets crawlers reach /.well-known/ai-catalog.json (added by the Labs ARD feature).', 'coywolf-seo' ),
				'path'        => $this->catalog_path(),
				'agents'      => array( '*' ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// URLs / directives
	// ---------------------------------------------------------------------

	/**
	 * Canonical manifest URL.
	 *
	 * @return string
	 */
	public function catalog_url() {
		return $this->ard->catalog_url();
	}

	/**
	 * The manifest path component (subdirectory-install safe).
	 *
	 * @return string
	 */
	public function catalog_path() {
		$path = (string) wp_parse_url( $this->catalog_url(), PHP_URL_PATH );
		return '' !== $path ? $path : '/.well-known/ai-catalog.json';
	}

	/**
	 * The exact robots.txt `Agentmap:` directive line the spec defines, for the
	 * copy-paste guidance on the panel.
	 *
	 * @return string
	 */
	public function agentmap_line() {
		return 'Agentmap: ' . $this->catalog_url();
	}

	// ---------------------------------------------------------------------
	// Panel
	// ---------------------------------------------------------------------

	/**
	 * Render the advertising status sub-section on the ARD panel.
	 */
	public function render_status() {
		?>
		<hr />
		<h3><?php esc_html_e( 'Discovery (advertising)', 'coywolf-seo' ); ?></h3>
		<?php if ( ! $this->ard->endpoint_enabled() ) : ?>
			<p class="description"><?php esc_html_e( 'Advertising needs the live /.well-known/ai-catalog.json endpoint — enable it above. There is no manifest URL to advertise otherwise.', 'coywolf-seo' ); ?></p>
			<?php
			return;
		endif;
		if ( ! (bool) get_option( 'blog_public', 1 ) ) :
			?>
			<p class="description"><?php esc_html_e( 'Your site is set to discourage search engines (Settings → Reading), so no public discovery hints are emitted while that is on.', 'coywolf-seo' ); ?></p>
			<?php endif; ?>

		<p class="description"><?php esc_html_e( 'These point AI clients and registries at the manifest where the ARD spec says to look. Adoption is early — no major registry consumes ai-catalog.json widely yet — so treat this as honest, forward-looking advertising.', 'coywolf-seo' ); ?></p>
		<ul class="ul-disc" style="margin-left:1.5rem;">
			<li>
				<?php esc_html_e( 'Manifest (well-known path):', 'coywolf-seo' ); ?>
				<a href="<?php echo esc_url( $this->catalog_url() ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $this->catalog_url() ); ?></code></a>
			</li>
			<li><?php esc_html_e( 'A single <link rel="ai-catalog"> in the <head> of public, indexable pages.', 'coywolf-seo' ); ?></li>
			<li>
				<?php
				if ( $this->robots_rule_present() ) {
					printf(
						/* translators: %s: the manifest path */
						esc_html__( 'An %s Allow rule was added to your Robots.txt Manager so crawlers are not blocked from the manifest.', 'coywolf-seo' ),
						'<code>' . esc_html( $this->catalog_path() ) . '</code>'
					);
					if ( ! Coywolf_SEO_Options::feature_enabled( 'robots' ) ) {
						echo ' <em>' . esc_html__( '(The Robots.txt Manager is currently off, so it is stored but not served until you turn that feature on.)', 'coywolf-seo' ) . '</em>';
					}
				} else {
					printf(
						/* translators: %s: the manifest path */
						esc_html__( 'Make sure %s is not blocked in your robots.txt so crawlers can reach the manifest.', 'coywolf-seo' ),
						'<code>' . esc_html( $this->catalog_path() ) . '</code>'
					);
				}
				?>
			</li>
			<li>
				<?php esc_html_e( 'The spec also defines a robots.txt directive. The Robots.txt Manager models path rules, not raw directives, so add this line to your robots.txt by hand if you want it:', 'coywolf-seo' ); ?>
				<br />
				<code><?php echo esc_html( $this->agentmap_line() ); ?></code>
			</li>
			<li><?php esc_html_e( 'The spec also lists optional DNS Service Binding records (a _catalog._agents TXT record); add those at your DNS host if you want them.', 'coywolf-seo' ); ?></li>
		</ul>
		<?php
	}
}
