<?php
/**
 * AI Discovery — a single Labs feature that groups the three "make this site
 * discoverable to AI" outputs under one master switch.
 *
 * When AI Discovery is enabled it reveals three independent, off-by-default
 * outputs you activate individually:
 *   - Open Knowledge Format (OKF) — your content as a Markdown knowledge graph.
 *   - EntityMap — the Wikidata-grounded entities your pages are about.
 *   - Agentic Resource Discovery (ARD) — an ai-catalog.json of your site's
 *     machine-usable resources.
 * Turning the master switch off deactivates all three (their endpoints stop
 * serving and any advertising hints are withdrawn).
 *
 * The three outputs keep their own engines (generators, advertisers, endpoints,
 * cron, settings) — this class composes them, gates them behind the master
 * switch, and adds the shared AI-enrichment status panel (OKF and EntityMap are
 * built from the AI entity enrichment, so the panel surfaces whether that needs
 * running and what it costs).
 *
 * Stripped from the WordPress.org build with the rest of includes/labs/.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The AI Discovery Labs feature (master switch + three outputs + enrichment).
 */
final class Coywolf_SEO_AI_Discovery {

	/**
	 * Master on/off option.
	 */
	const OPTION = 'coywolf_seo_ai_discovery';

	/**
	 * Managed Robots.txt Manager rule id for the "block Google from crawling
	 * Markdown files" option.
	 */
	const ROBOTS_RULE_BLOCK_MD = 'coywolf-ai-discovery-block-md';

	/**
	 * Capability required to manage the feature (matches Settings).
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Settings page slug that hosts the "Enrich all content" runner.
	 */
	const ENRICH_PAGE = 'coywolf-seo-settings';

	/**
	 * Anchor of the "Enrich all content" section on that page.
	 */
	const ENRICH_ANCHOR = 'coywolf-seo-section-enrich';

	/**
	 * The OKF output engine.
	 *
	 * @var Coywolf_SEO_OKF
	 */
	private $okf;

	/**
	 * The EntityMap output engine.
	 *
	 * @var Coywolf_SEO_EntityMap
	 */
	private $entitymap;

	/**
	 * The ARD output engine.
	 *
	 * @var Coywolf_SEO_ARD
	 */
	private $ard;

	/**
	 * MCP integration (exposes the published outputs as MCP tools when the MCP
	 * Adapter plugin is present). Not an output engine.
	 *
	 * @var Coywolf_SEO_MCP
	 */
	private $mcp;

	/**
	 * Construct the three output engines and the MCP integration.
	 */
	public function __construct() {
		$this->okf       = new Coywolf_SEO_OKF();
		$this->entitymap = new Coywolf_SEO_EntityMap();
		$this->ard       = new Coywolf_SEO_ARD();
		$this->mcp       = new Coywolf_SEO_MCP( $this );
	}

	/**
	 * Feature id (used by the Labs registry / nonces).
	 *
	 * @return string
	 */
	public function id() {
		return 'ai-discovery';
	}

	/**
	 * Human title for the Labs panel.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'AI Discovery', 'coywolf-seo' );
	}

	/**
	 * OKF engine accessor (lets the llms.txt owner reach it through the Labs
	 * registry, as before the grouping).
	 *
	 * @return Coywolf_SEO_OKF
	 */
	public function okf() {
		return $this->okf;
	}

	/**
	 * EntityMap engine accessor.
	 *
	 * @return Coywolf_SEO_EntityMap
	 */
	public function entitymap() {
		return $this->entitymap;
	}

	/**
	 * ARD engine accessor.
	 *
	 * @return Coywolf_SEO_ARD
	 */
	public function ard() {
		return $this->ard;
	}

	/**
	 * The three output engines.
	 *
	 * @return Coywolf_SEO_Labs_Feature[]
	 */
	private function engines() {
		return array( $this->okf, $this->entitymap, $this->ard );
	}

	/**
	 * Register hooks. The master save handler always registers; the output engines
	 * only wire up their endpoints/cron/advertising while the master switch is on.
	 */
	public function init() {
		$this->maybe_migrate();

		add_action( 'admin_post_coywolf_seo_ai_discovery_save', array( $this, 'handle_save' ) );

		if ( ! $this->is_enabled() ) {
			return;
		}
		foreach ( $this->engines() as $engine ) {
			$engine->init();
		}
		// MCP is not an output engine (no endpoint); it exposes the published
		// outputs as MCP tools when the MCP Adapter plugin is present.
		$this->mcp->init();
	}

	/**
	 * One-shot migration for installs that ran OKF / EntityMap / ARD as separate
	 * Labs features before they were grouped here: if the master option does not
	 * exist yet, seed it on (so already-active outputs keep serving) when any
	 * output was enabled, otherwise off. Writes the option exactly once, so it is
	 * a no-op on every later request.
	 *
	 * @return void
	 */
	private function maybe_migrate() {
		if ( false !== get_option( self::OPTION, false ) ) {
			return;
		}
		$any = false;
		foreach ( $this->engines() as $engine ) {
			if ( $engine->is_enabled() ) {
				$any = true;
				break;
			}
		}
		update_option( self::OPTION, array( 'enabled' => $any ) );
	}

	// ---------------------------------------------------------------------
	// Master switch
	// ---------------------------------------------------------------------

	/**
	 * Whether AI Discovery is enabled (off by default).
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) && ! empty( $saved['enabled'] );
	}

	/**
	 * Save the master switch. Turning it off deactivates every output (each
	 * engine's own apply_settings handles stopping its endpoint and withdrawing
	 * its advertising), so a disabled master can never leave a dangling endpoint
	 * or robots/llms reference.
	 */
	public function handle_save() {
		$this->guard( 'coywolf_seo_ai_discovery_save' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in guard(); only the boolean checkboxes below are read.
		$raw     = isset( $_POST[ self::OPTION ] ) && is_array( $_POST[ self::OPTION ] ) ? wp_unslash( $_POST[ self::OPTION ] ) : array();
		$enabled = ! empty( $raw['enabled'] );
		$was     = $this->is_enabled();

		// The "Block Google from crawling Markdown files" checkbox only renders
		// while AI Discovery is on AND the Robots.txt Manager feature is enabled.
		// Read it only when it was shown; preserve the stored choice when it was
		// hidden (Robots.txt Manager off) so toggling that feature doesn't drop
		// it, and clear it whenever AI Discovery itself is turned off.
		if ( ! $enabled ) {
			$block_md = false;
		} elseif ( Coywolf_SEO_Options::feature_enabled( 'robots' ) ) {
			$block_md = ! empty( $raw['block_md'] );
		} else {
			$block_md = $this->block_md_enabled();
		}

		update_option(
			self::OPTION,
			array(
				'enabled'  => $enabled,
				'block_md' => $block_md,
			)
		);

		if ( ! $enabled && $was ) {
			foreach ( $this->engines() as $engine ) {
				if ( $engine->is_enabled() ) {
					$engine->apply_settings( false, $engine->endpoint_enabled(), $engine->advertise_enabled() );
				}
			}
		}

		$this->apply_block_md_rule( $block_md );

		$msg = 'ai-discovery-saved';
		if ( $enabled && ! $was ) {
			$msg = 'ai-discovery-enabled';
		} elseif ( ! $enabled && $was ) {
			$msg = 'ai-discovery-disabled';
		}
		$this->redirect( $msg );
	}

	/**
	 * Whether the "Block Google from crawling Markdown files" option is stored on
	 * (off by default).
	 *
	 * @return bool
	 */
	public function block_md_enabled() {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) && ! empty( $saved['block_md'] );
	}

	/**
	 * Add or remove the managed Robots.txt Manager rule that blocks Googlebot from
	 * crawling Markdown (.md) files, to match the stored checkbox state. The rule
	 * renders as:  User-agent: Googlebot  /  Disallow: /*.md$
	 *
	 * @param bool $on Whether the rule should be present.
	 * @return void
	 */
	private function apply_block_md_rule( $on ) {
		$robots = $this->robots_manager();
		if ( ! $robots ) {
			return;
		}
		if ( $on ) {
			$robots->add_managed_rule( $this->block_md_rule() );
		} else {
			$robots->remove_managed_rule( self::ROBOTS_RULE_BLOCK_MD );
		}
	}

	/**
	 * The Robots.txt Manager instance, or null when its class is unavailable.
	 *
	 * @return Coywolf_SEO_Robots|null
	 */
	private function robots_manager() {
		if ( ! class_exists( 'Coywolf_SEO_Robots' ) ) {
			return null;
		}
		$inst = Coywolf_SEO::instance();
		return method_exists( $inst, 'robots' ) ? $inst->robots() : null;
	}

	/**
	 * The managed rule blocking Googlebot from all Markdown files. A `filetype`
	 * Disallow on `md` for the Googlebot agent renders as the two lines above.
	 *
	 * @return array<string,mixed>
	 */
	private function block_md_rule() {
		return array_merge(
			Coywolf_SEO_Robots_Rules::blank(),
			array(
				'id'          => self::ROBOTS_RULE_BLOCK_MD,
				'type'        => 'filetype',
				'directive'   => 'disallow',
				'name'        => __( 'Block Markdown', 'coywolf-seo' ),
				'description' => __( 'Block crawling for all Markdown files', 'coywolf-seo' ),
				'ext'         => 'md',
				'agents'      => array( 'Googlebot' ),
			)
		);
	}

	/**
	 * Capability + nonce guard.
	 *
	 * @param string $nonce_action Nonce action name.
	 * @return void
	 */
	private function guard( $nonce_action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Coywolf SEO Labs features.', 'coywolf-seo' ) );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Redirect back to the Labs page with a status message key.
	 *
	 * @param string $msg Message key.
	 * @return void
	 */
	private function redirect( $msg ) {
		$url = add_query_arg(
			array(
				'page'                 => Coywolf_SEO_Labs::SLUG,
				'coywolf-seo-labs-msg' => $msg,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Status messages: this feature's own plus every output engine's (so the Labs
	 * page can resolve a message key raised by any of them).
	 *
	 * @return array key => array( type, text )
	 */
	public function notices() {
		$notices = array(
			'ai-discovery-saved'    => array( 'success', __( 'AI Discovery settings saved.', 'coywolf-seo' ) ),
			'ai-discovery-enabled'  => array( 'success', __( 'AI Discovery enabled. Choose which outputs to publish below.', 'coywolf-seo' ) ),
			'ai-discovery-disabled' => array( 'success', __( 'AI Discovery disabled. All three outputs were turned off.', 'coywolf-seo' ) ),
		);
		foreach ( $this->engines() as $engine ) {
			$notices += $engine->notices();
		}
		$notices += $this->mcp->notices();
		return $notices;
	}

	// ---------------------------------------------------------------------
	// Panel
	// ---------------------------------------------------------------------

	/**
	 * Render the AI Discovery panel: the master switch, then (when on) the
	 * AI-enrichment status and the three output sub-panels.
	 */
	public function render_panel() {
		$enabled = $this->is_enabled();
		?>
		<div class="coywolf-seo-labs-feature coywolf-seo-panel">
			<h2><?php echo esc_html( $this->title() ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Make this site discoverable to AI agents. Turn AI Discovery on, then choose which outputs to publish — each is off until you enable it. They are built from data already on your site; no content is sent anywhere by these outputs themselves. If the WordPress MCP Adapter plugin is installed, the outputs you publish can additionally be exposed as read-only MCP tools for AI agents.', 'coywolf-seo' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="coywolf_seo_ai_discovery_save" />
				<?php wp_nonce_field( 'coywolf_seo_ai_discovery_save' ); ?>
				<p>
					<label>
						<input type="checkbox" name="coywolf_seo_ai_discovery[enabled]" value="1" <?php checked( $enabled ); ?> />
						<?php esc_html_e( 'Enable AI Discovery', 'coywolf-seo' ); ?>
					</label>
				</p>
				<?php if ( $enabled && Coywolf_SEO_Options::feature_enabled( 'robots' ) ) : ?>
					<p>
						<label>
							<input type="checkbox" name="coywolf_seo_ai_discovery[block_md]" value="1" <?php checked( $this->block_md_enabled() ); ?> />
							<?php esc_html_e( 'Block Google from crawling Markdown files', 'coywolf-seo' ); ?>
						</label>
					</p>
				<?php endif; ?>
				<?php submit_button( $enabled ? __( 'Save AI Discovery', 'coywolf-seo' ) : __( 'Enable AI Discovery', 'coywolf-seo' ) ); ?>
			</form>
		</div>

		<?php if ( $enabled ) : ?>
			<?php $this->render_enrichment_status(); ?>
			<?php
			foreach ( $this->engines() as $engine ) {
				$engine->render_panel();
			}
			?>
			<?php $this->mcp->render_panel(); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the AI-enrichment status panel. OKF and EntityMap are built from the
	 * AI entity enrichment, so when either is active this surfaces whether the
	 * enrichment needs running, its one-time nature, and the cost — linking to the
	 * existing "Enrich all content" runner rather than duplicating it.
	 */
	private function render_enrichment_status() {
		// Only OKF and EntityMap consume entity enrichment; ARD does not.
		if ( ! $this->okf->is_enabled() && ! $this->entitymap->is_enabled() ) {
			return;
		}
		?>
		<div class="coywolf-seo-labs-feature coywolf-seo-panel">
			<h3><?php esc_html_e( 'AI enrichment', 'coywolf-seo' ); ?></h3>
			<?php
			$ai = ( class_exists( 'Coywolf_SEO' ) && method_exists( 'Coywolf_SEO', 'instance' ) ) ? Coywolf_SEO::instance()->ai() : null;

			if ( ! $ai || ! $ai->enabled() ) {
				printf(
					'<p class="description">%1$s <a href="%2$s">%3$s</a></p>',
					esc_html__( 'The Open Knowledge Format and EntityMap outputs describe the entities your pages cover, which the AI enrichment detects and grounds to Wikidata. AI enrichment is currently off or has no API key, so those outputs will be sparse until you turn it on and add a key.', 'coywolf-seo' ),
					esc_url( admin_url( 'admin.php?page=' . self::ENRICH_PAGE ) ),
					esc_html__( 'Open AI settings', 'coywolf-seo' )
				);
				echo '</div>';
				return;
			}

			$est        = $ai->bulk_estimate();
			$posts      = isset( $est['posts'] ) ? (int) $est['posts'] : 0;
			$enrich_url = admin_url( 'admin.php?page=' . self::ENRICH_PAGE ) . '#' . self::ENRICH_ANCHOR;

			if ( $posts < 1 ) {
				echo '<p class="description">';
				esc_html_e( 'Your published content is enriched. You only need to run this once — new publishes and updates to posts and pages are enriched automatically and keep every output current.', 'coywolf-seo' );
				echo '</p>';
				echo '</div>';
				return;
			}

			$cost_phrase = '';
			if ( ! empty( $est['priced'] ) && isset( $est['est_cost'] ) && (float) $est['est_cost'] > 0 ) {
				$cost_phrase = sprintf(
					/* translators: 1: dollar cost, 2: model id */
					__( ' Estimated cost: about $%1$s with %2$s.', 'coywolf-seo' ),
					number_format_i18n( (float) $est['est_cost'], 2 ),
					isset( $est['model'] ) ? (string) $est['model'] : ''
				);
			} else {
				$cost_phrase = __( ' A cost estimate is unavailable for the current model.', 'coywolf-seo' );
			}
			?>
			<p class="description">
				<?php
				printf(
					/* translators: %d: number of posts/pages needing enrichment */
					esc_html( _n( '%d published post or page still needs AI enrichment before these outputs are complete.', '%d published posts and pages still need AI enrichment before these outputs are complete.', $posts, 'coywolf-seo' ) ),
					(int) $posts
				);
				echo esc_html( $cost_phrase );
				?>
			</p>
			<p class="description">
				<?php esc_html_e( 'This only needs to be run once. Afterwards, every new publish and update to a post or page runs the AI enrichment automatically and updates the outputs.', 'coywolf-seo' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $enrich_url ); ?>"><?php esc_html_e( 'Run AI enrichment', 'coywolf-seo' ); ?></a>
			</p>
		</div>
		<?php
	}
}
