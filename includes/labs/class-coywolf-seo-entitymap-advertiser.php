<?php
/**
 * EntityMap discovery / advertising — Labs (EntityMap sub-feature).
 *
 * The EntityMap spec lists several discovery channels for the JSON file: a
 * <head> link, a robots.txt line, a sitemap entry, and an llms.txt reference.
 * This points agents and crawlers at the file set from the places they already
 * look, and keeps the file paths unblocked in robots.txt. It never overwrites a
 * robots.txt owned by another plugin/theme/file — it contributes a managed rule
 * to the Robots.txt Manager and otherwise defers.
 *
 * Everything here is gated on the EntityMap feature being enabled AND its
 * "Advertise" sub-setting AND the live endpoint being on (no reachable URL to
 * point at otherwise) AND the site being public (blog_public).
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovery hints for the EntityMap file set.
 */
final class Coywolf_SEO_EntityMap_Advertiser extends Coywolf_SEO_Labs_Bundle_Advertiser {

	/**
	 * Stable ids of the managed Robots.txt Manager Allow rules (one per file),
	 * so they can be located for removal.
	 */
	const ROBOTS_RULE_JSON = 'coywolf-entitymap-allow-json';
	const ROBOTS_RULE_HTML = 'coywolf-entitymap-allow-html';

	/**
	 * The EntityMap feature (owns the settings and the canonical URLs).
	 *
	 * @var Coywolf_SEO_EntityMap
	 */
	private $entitymap;

	/**
	 * Construct with the owning EntityMap feature.
	 *
	 * @param Coywolf_SEO_EntityMap $entitymap EntityMap feature.
	 */
	public function __construct( Coywolf_SEO_EntityMap $entitymap ) {
		$this->entitymap = $entitymap;
	}

	/**
	 * The owning Labs feature (lets the shared base gates read its toggles).
	 *
	 * @return Coywolf_SEO_Labs_Feature
	 */
	protected function feature() {
		return $this->entitymap;
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
		// (added/removed in the EntityMap save handler), not a runtime filter.
	}

	// ---------------------------------------------------------------------
	// <head> hint
	// ---------------------------------------------------------------------

	/**
	 * Emit a single site-level <link rel="entitymap"> pointing at the JSON file,
	 * on public, indexable front-end pages only.
	 */
	public function output_link() {
		if ( ! $this->is_public() || ! $this->is_indexable_response() ) {
			return;
		}
		printf(
			'<link rel="entitymap" type="application/json" href="%1$s" />' . "\n",
			esc_url( $this->json_url() )
		);
	}

	// ---------------------------------------------------------------------
	// llms.txt reference
	// ---------------------------------------------------------------------

	/**
	 * The exact llms.txt reference entry (without the leading "- ["), reused by
	 * the llms.txt owner and the manual-add guidance in the UI.
	 *
	 * @return string
	 */
	public function llms_reference_line() {
		return 'EntityMap](' . $this->json_url() . '): Spec-conformant EntityMap v1.0 index of the Wikidata-grounded entities this site is about or mentions, with extractive, publisher-attributed evidence passages. A human-readable companion is at ' . $this->html_url() . '.';
	}

	// ---------------------------------------------------------------------
	// robots.txt allowance (managed Robots.txt Manager rules)
	// ---------------------------------------------------------------------

	/**
	 * Add (or refresh) the managed Allow rules for the JSON + HTML paths in the
	 * Robots.txt Manager. Called from the EntityMap save handler when advertising
	 * is configured.
	 */
	public function add_robots_rule() {
		$robots = $this->robots_manager();
		if ( ! $robots ) {
			return;
		}
		foreach ( $this->robots_rules() as $rule ) {
			$robots->add_managed_rule( $rule );
		}
	}

	/**
	 * Remove the managed Allow rules. Called from the EntityMap save handler when
	 * advertising is turned off (or EntityMap is disabled).
	 */
	public function remove_robots_rule() {
		$robots = $this->robots_manager();
		if ( ! $robots ) {
			return;
		}
		$robots->remove_managed_rule( self::ROBOTS_RULE_JSON );
		$robots->remove_managed_rule( self::ROBOTS_RULE_HTML );
	}

	/**
	 * Whether the managed Allow rules are currently stored.
	 *
	 * @return bool
	 */
	public function robots_rule_present() {
		$robots = $this->robots_manager();
		if ( ! $robots ) {
			return false;
		}
		foreach ( $robots->get_rules() as $rule ) {
			if ( isset( $rule['id'] ) && self::ROBOTS_RULE_JSON === (string) $rule['id'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The managed Allow rules: one per served file, for all robots.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function robots_rules() {
		$json = array_merge(
			Coywolf_SEO_Robots_Rules::blank(),
			array(
				'id'          => self::ROBOTS_RULE_JSON,
				'type'        => 'single_page',
				'directive'   => 'allow',
				'strict'      => true,
				'name'        => __( 'EntityMap (JSON)', 'coywolf-seo' ),
				'description' => __( 'Lets crawlers reach entitymap.json (added by the Labs EntityMap feature).', 'coywolf-seo' ),
				'path'        => $this->json_path(),
				'agents'      => array( '*' ),
			)
		);
		$html = array_merge(
			Coywolf_SEO_Robots_Rules::blank(),
			array(
				'id'          => self::ROBOTS_RULE_HTML,
				'type'        => 'single_page',
				'directive'   => 'allow',
				'strict'      => true,
				'name'        => __( 'EntityMap (HTML)', 'coywolf-seo' ),
				'description' => __( 'Lets crawlers reach entitymap.html (added by the Labs EntityMap feature).', 'coywolf-seo' ),
				'path'        => $this->html_path(),
				'agents'      => array( '*' ),
			)
		);
		return array( $json, $html );
	}

	// ---------------------------------------------------------------------
	// URLs / paths
	// ---------------------------------------------------------------------

	/**
	 * The public entitymap.json URL.
	 *
	 * @return string
	 */
	public function json_url() {
		return $this->entitymap->generator()->public_json_url();
	}

	/**
	 * The public entitymap.html URL.
	 *
	 * @return string
	 */
	public function html_url() {
		return $this->entitymap->generator()->public_html_url();
	}

	/**
	 * The entitymap.json path component (subdirectory-install safe).
	 *
	 * @return string
	 */
	public function json_path() {
		$path = (string) wp_parse_url( $this->json_url(), PHP_URL_PATH );
		return '' !== $path ? $path : '/entitymap.json';
	}

	/**
	 * The entitymap.html path component (subdirectory-install safe).
	 *
	 * @return string
	 */
	public function html_path() {
		$path = (string) wp_parse_url( $this->html_url(), PHP_URL_PATH );
		return '' !== $path ? $path : '/entitymap.html';
	}

	// ---------------------------------------------------------------------
	// Panel
	// ---------------------------------------------------------------------

	/**
	 * Render the advertising status sub-section on the EntityMap panel.
	 */
	public function render_status() {
		?>
		<hr />
		<h3><?php esc_html_e( 'Discovery (advertising)', 'coywolf-seo' ); ?></h3>
		<?php if ( ! $this->entitymap->endpoint_enabled() ) : ?>
			<p class="description"><?php esc_html_e( 'Advertising needs the live root endpoint — enable it above. There is no public URL to advertise otherwise.', 'coywolf-seo' ); ?></p>
			<?php
			return;
		endif;
		if ( ! (bool) get_option( 'blog_public', 1 ) ) :
			?>
			<p class="description"><?php esc_html_e( 'Your site is set to discourage search engines (Settings → Reading), so no public discovery hints are emitted while that is on.', 'coywolf-seo' ); ?></p>
			<?php endif; ?>

		<p class="description"><?php esc_html_e( 'These hints point agents at the EntityMap from the channels the spec lists.', 'coywolf-seo' ); ?></p>
		<ul class="ul-disc" style="margin-left:1.5rem;">
			<li>
				<?php esc_html_e( 'EntityMap JSON:', 'coywolf-seo' ); ?>
				<a href="<?php echo esc_url( $this->json_url() ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $this->json_url() ); ?></code></a>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: the /llms.txt URL */
					esc_html__( 'The EntityMap is referenced from %s. With the LLMs.txt feature on, the reference is folded into its full file; with it off, a minimal llms.txt is served there.', 'coywolf-seo' ),
					'<a href="' . esc_url( home_url( '/llms.txt' ) ) . '" target="_blank" rel="noopener"><code>' . esc_html( home_url( '/llms.txt' ) ) . '</code></a>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'A single <link rel="entitymap" type="application/json"> in the <head> of public, indexable pages.', 'coywolf-seo' ); ?></li>
			<li>
				<?php
				if ( $this->robots_rule_present() ) {
					printf(
						/* translators: 1: the JSON path, 2: the HTML path */
						esc_html__( 'Allow rules for %1$s and %2$s were added to your Robots.txt Manager so crawlers are not blocked from the files.', 'coywolf-seo' ),
						'<code>' . esc_html( $this->json_path() ) . '</code>',
						'<code>' . esc_html( $this->html_path() ) . '</code>'
					);
					if ( ! Coywolf_SEO_Options::feature_enabled( 'robots' ) ) {
						echo ' <em>' . esc_html__( '(The Robots.txt Manager is currently off, so they are stored but not served until you turn that feature on.)', 'coywolf-seo' ) . '</em>';
					}
				} else {
					printf(
						/* translators: 1: the JSON path, 2: the HTML path */
						esc_html__( 'Make sure %1$s and %2$s are not blocked in your robots.txt so crawlers can reach the files.', 'coywolf-seo' ),
						'<code>' . esc_html( $this->json_path() ) . '</code>',
						'<code>' . esc_html( $this->html_path() ) . '</code>'
					);
				}
				?>
			</li>
			<li><?php esc_html_e( 'The spec also lists a sitewide footer link and a sitemap entry; this feature does not modify your theme or core sitemaps, so add those by hand if you want them.', 'coywolf-seo' ); ?></li>
		</ul>
		<?php
	}
}
