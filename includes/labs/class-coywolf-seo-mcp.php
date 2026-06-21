<?php
/**
 * MCP integration for AI Discovery — exposes the already-public AI Discovery
 * outputs as read-only MCP tools through the official WordPress MCP Adapter
 * plugin (https://github.com/WordPress/mcp-adapter).
 *
 * This is NOT an output engine: it adds no endpoint of its own. It registers
 * one read-only Abilities-API ability per ENABLED output (mapping straight to
 * the data the generator already produces) and exposes the allow-listed
 * abilities as tools on a custom MCP server at /wp-json/coywolf-seo/v1/mcp.
 *
 * It does nothing unless ALL of these hold, and is a clean no-op otherwise (no
 * notices, no errors):
 *   - the MCP Adapter plugin is active (`\WP\MCP\Core\McpAdapter` exists),
 *   - the Abilities API is present (`wp_register_ability()` exists, core 6.9+),
 *   - the AI Discovery master switch is on (this class is only init()-ed then).
 * The adapter is detected at runtime and never vendored; every adapter class is
 * referenced by FQCN behind a `class_exists()` guard so a missing/renamed class
 * in another adapter version simply skips the server.
 *
 * Stripped from the WordPress.org build with the rest of includes/labs/.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers AI Discovery outputs as MCP tools when the MCP Adapter is present.
 */
final class Coywolf_SEO_MCP {

	/**
	 * Option holding the per-ability allow-list (map of ability id => bool).
	 * Absent / unset keys default to allowed.
	 */
	const ALLOW_OPTION = 'coywolf_seo_mcp_allowed_abilities';

	/**
	 * Capability required to manage the allow-list (matches the Labs page).
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'coywolf-seo';

	/**
	 * Custom MCP server identity.
	 */
	const SERVER_ID    = 'coywolf-seo';
	const SERVER_NS    = 'coywolf-seo/v1';
	const SERVER_ROUTE = 'mcp';

	/**
	 * The owning AI Discovery feature (reaches the three output engines).
	 *
	 * @var Coywolf_SEO_AI_Discovery
	 */
	private $ai;

	/**
	 * Construct with the owning AI Discovery feature.
	 *
	 * @param Coywolf_SEO_AI_Discovery $ai AI Discovery feature.
	 */
	public function __construct( Coywolf_SEO_AI_Discovery $ai ) {
		$this->ai = $ai;
	}

	// ---------------------------------------------------------------------
	// Detection + hooks
	// ---------------------------------------------------------------------

	/**
	 * Whether the MCP Adapter plugin and the Abilities API are both present.
	 *
	 * @return bool
	 */
	public function adapter_available() {
		return class_exists( '\WP\MCP\Core\McpAdapter' ) && function_exists( 'wp_register_ability' );
	}

	/**
	 * Register hooks. Called by AI Discovery only while the master switch is on,
	 * and a no-op unless the adapter + Abilities API are present.
	 */
	public function init() {
		if ( ! $this->adapter_available() ) {
			return;
		}
		add_action( 'admin_post_coywolf_seo_mcp_save', array( $this, 'handle_save' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'mcp_adapter_init', array( $this, 'register_server' ) );
	}

	// ---------------------------------------------------------------------
	// Ability catalog
	// ---------------------------------------------------------------------

	/**
	 * The full ability catalog: id => definition. `engine` is the AI Discovery
	 * output the ability draws from ('okf' | 'entitymap' | 'ard'), or '' for the
	 * always-available status summary.
	 *
	 * @return array<string,array>
	 */
	private function ability_catalog() {
		return array(
			'coywolf-seo/get-okf-index'             => array(
				'engine'        => 'okf',
				'label'         => __( 'Get OKF index', 'coywolf-seo' ),
				'description'   => __( 'Return the Open Knowledge Format bundle index (the root list of concepts) as Markdown, plus the public /okf/ base URL.', 'coywolf-seo' ),
				'input_schema'  => array(
					'type'                 => 'object',
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'endpoint' => array( 'type' => 'string' ),
						'markdown' => array( 'type' => 'string' ),
					),
				),
				'callback'      => array( $this, 'cb_okf_index' ),
			),
			'coywolf-seo/get-okf-concept'           => array(
				'engine'        => 'okf',
				'label'         => __( 'Get OKF concept', 'coywolf-seo' ),
				'description'   => __( 'Return one OKF concept\'s Markdown by its bundle path (as listed by get-okf-index, e.g. "articles/my-post.md").', 'coywolf-seo' ),
				'input_schema'  => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'path' ),
					'properties'           => array(
						'path' => array(
							'type'        => 'string',
							'description' => __( 'Relative path of the concept within the OKF bundle, e.g. "articles/my-post.md".', 'coywolf-seo' ),
						),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'path'     => array( 'type' => 'string' ),
						'markdown' => array( 'type' => 'string' ),
					),
				),
				'callback'      => array( $this, 'cb_okf_concept' ),
			),
			'coywolf-seo/get-entitymap'             => array(
				'engine'        => 'entitymap',
				'label'         => __( 'Get EntityMap', 'coywolf-seo' ),
				'description'   => __( 'Return the EntityMap (entitymap.json) — the Wikidata-grounded entities this site is about or mentions, with evidence passages. Optional input: limit.', 'coywolf-seo' ),
				'input_schema'  => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'limit' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Optional: cap the number of entities returned.', 'coywolf-seo' ),
						),
					),
				),
				'output_schema' => array(
					'type' => 'object',
				),
				'callback'      => array( $this, 'cb_entitymap' ),
			),
			'coywolf-seo/get-ai-catalog'            => array(
				'engine'        => 'ard',
				'label'         => __( 'Get ai-catalog.json', 'coywolf-seo' ),
				'description'   => __( 'Return the Agentic Resource Discovery manifest (ai-catalog.json) — this site\'s machine-usable resources.', 'coywolf-seo' ),
				'input_schema'  => array(
					'type'                 => 'object',
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
				),
				'callback'      => array( $this, 'cb_ai_catalog' ),
			),
			'coywolf-seo/list-ai-discovery-outputs' => array(
				'engine'        => '',
				'label'         => __( 'List AI Discovery outputs', 'coywolf-seo' ),
				'description'   => __( 'Summarise which AI Discovery outputs are published, their public URLs, and when each was last built.', 'coywolf-seo' ),
				'input_schema'  => array(
					'type'                 => 'object',
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'outputs' => array( 'type' => 'array' ),
					),
				),
				'callback'      => array( $this, 'cb_list_outputs' ),
			),
		);
	}

	/**
	 * The output engine for an ability definition, or null for the status ability.
	 *
	 * @param string $engine_key Engine key ('okf'|'entitymap'|'ard'|'').
	 * @return Coywolf_SEO_Labs_Feature|null
	 */
	private function engine_for( $engine_key ) {
		switch ( $engine_key ) {
			case 'okf':
				return $this->ai->okf();
			case 'entitymap':
				return $this->ai->entitymap();
			case 'ard':
				return $this->ai->ard();
		}
		return null;
	}

	/**
	 * Whether an output's data is public (the feature enabled AND its live
	 * endpoint on) — the precondition for exposing it over MCP.
	 *
	 * @param Coywolf_SEO_Labs_Feature|null $engine Engine.
	 * @return bool
	 */
	private function engine_public( $engine ) {
		return $engine && $engine->is_enabled() && $engine->endpoint_enabled();
	}

	/**
	 * Ability ids that COULD be registered given the currently-published outputs
	 * (ignoring the allow-list). The status ability is always available.
	 *
	 * @return string[]
	 */
	private function registrable_ability_ids() {
		$ids = array();
		foreach ( $this->ability_catalog() as $id => $def ) {
			if ( '' === $def['engine'] || $this->engine_public( $this->engine_for( $def['engine'] ) ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Ability ids that are both registrable AND allowed on the Labs panel.
	 *
	 * @return string[]
	 */
	private function active_ability_ids() {
		return array_values( array_filter( $this->registrable_ability_ids(), array( $this, 'is_allowed' ) ) );
	}

	// ---------------------------------------------------------------------
	// Allow-list
	// ---------------------------------------------------------------------

	/**
	 * The stored allow-list map (ability id => bool).
	 *
	 * @return array<string,bool>
	 */
	private function allowed_map() {
		$map = get_option( self::ALLOW_OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Whether an ability is allowed. Unset keys default to allowed.
	 *
	 * @param string $id Ability id.
	 * @return bool
	 */
	public function is_allowed( $id ) {
		$map = $this->allowed_map();
		return ! array_key_exists( $id, $map ) || ! empty( $map[ $id ] );
	}

	// ---------------------------------------------------------------------
	// Registration
	// ---------------------------------------------------------------------

	/**
	 * Register the allow-listed, published abilities on `wp_abilities_api_init`.
	 */
	public function register_abilities() {
		if ( ! $this->ai->is_enabled() ) {
			return;
		}
		$catalog = $this->ability_catalog();
		foreach ( $this->active_ability_ids() as $id ) {
			if ( ! isset( $catalog[ $id ] ) ) {
				continue;
			}
			$def = $catalog[ $id ];
			wp_register_ability(
				$id,
				array(
					'label'               => $def['label'],
					'description'         => $def['description'],
					'category'            => self::CATEGORY,
					'input_schema'        => $def['input_schema'],
					'output_schema'       => $def['output_schema'],
					'execute_callback'    => $def['callback'],
					'permission_callback' => array( $this, 'permission_read' ),
				)
			);
		}
	}

	/**
	 * Permission callback for every (read-only) ability. The connecting MCP user
	 * must be able to read the site; the data exposed is already public.
	 *
	 * @return bool
	 */
	public function permission_read() {
		return current_user_can( 'read' );
	}

	/**
	 * Create the custom MCP server on `mcp_adapter_init`, exposing the active
	 * abilities as tools. Bails cleanly if the transport class is missing or
	 * create_server fails / has a different shape in this adapter version.
	 *
	 * @param object $adapter The MCP adapter (passed by the action).
	 */
	public function register_server( $adapter ) {
		if ( ! $this->ai->is_enabled() ) {
			return;
		}
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}
		if ( ! class_exists( '\WP\MCP\Transport\HttpTransport' ) ) {
			return; // No usable transport in this adapter version.
		}

		$tools = $this->active_ability_ids();
		if ( empty( $tools ) ) {
			return; // Nothing published to expose.
		}

		$error_handler = class_exists( '\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler' )
			? '\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler'
			: null;
		$version       = ( class_exists( 'Coywolf_SEO' ) && defined( 'Coywolf_SEO::VERSION' ) ) ? Coywolf_SEO::VERSION : '1.0';

		try {
			$result = $adapter->create_server(
				self::SERVER_ID,
				self::SERVER_NS,
				self::SERVER_ROUTE,
				'Coywolf SEO',
				__( 'Read-only access to this site\'s published AI Discovery outputs (OKF, EntityMap, ai-catalog) for MCP clients.', 'coywolf-seo' ),
				$version,
				array( '\WP\MCP\Transport\HttpTransport' ),
				$error_handler,
				null,
				$tools,
				array(),
				array()
			);
		} catch ( \Throwable $e ) {
			return; // Adapter shape differs — skip the server cleanly.
		}

		unset( $result ); // create_server may return the server or a WP_Error; either way we registered best-effort.
	}

	// ---------------------------------------------------------------------
	// Ability callbacks (read-only; never trigger a rebuild)
	// ---------------------------------------------------------------------

	/**
	 * Ability get-okf-index: the OKF root index Markdown + the public base URL.
	 *
	 * @param array $input Unused.
	 * @return array|WP_Error
	 */
	public function cb_okf_index( $input = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the Abilities API passes input to every execute_callback; this tool takes none.
		$okf = $this->ai->okf();
		if ( ! $this->engine_public( $okf ) ) {
			return new WP_Error( 'coywolf_seo_okf_off', __( 'The OKF output is not published.', 'coywolf-seo' ) );
		}
		$gen = $okf->generator();
		if ( ! $gen->bundle_exists() ) {
			return new WP_Error( 'coywolf_seo_okf_unbuilt', __( 'The OKF bundle has not been generated yet.', 'coywolf-seo' ) );
		}
		$md = $this->read_file( $gen->bundle_path() . '/index.md' );
		if ( null === $md ) {
			return new WP_Error( 'coywolf_seo_okf_unbuilt', __( 'The OKF bundle index could not be read.', 'coywolf-seo' ) );
		}
		return array(
			'endpoint' => $okf->endpoint_base_url(),
			'markdown' => $md,
		);
	}

	/**
	 * Ability get-okf-concept: one concept's Markdown by its bundle path, with the same
	 * path-traversal guard as the public /okf/ endpoint.
	 *
	 * @param array $input { path: string }.
	 * @return array|WP_Error
	 */
	public function cb_okf_concept( $input = array() ) {
		$okf = $this->ai->okf();
		if ( ! $this->engine_public( $okf ) ) {
			return new WP_Error( 'coywolf_seo_okf_off', __( 'The OKF output is not published.', 'coywolf-seo' ) );
		}
		$rel = isset( $input['path'] ) ? ltrim( (string) $input['path'], '/' ) : '';
		if ( '' === $rel || false !== strpos( $rel, '..' ) || false !== strpos( $rel, "\0" ) || ! preg_match( '/\.md$/', $rel ) ) {
			return new WP_Error( 'coywolf_seo_okf_bad_path', __( 'Provide a valid concept path ending in .md (see get-okf-index).', 'coywolf-seo' ) );
		}
		$gen  = $okf->generator();
		$base = $gen->bundle_path();
		if ( ! $gen->bundle_exists() ) {
			return new WP_Error( 'coywolf_seo_okf_unbuilt', __( 'The OKF bundle has not been generated yet.', 'coywolf-seo' ) );
		}
		$real_base = realpath( $base );
		$real_file = realpath( $base . '/' . $rel );
		if ( false === $real_base || false === $real_file
			|| 0 !== strpos( $real_file, $real_base . DIRECTORY_SEPARATOR )
			|| ! is_file( $real_file ) ) {
			return new WP_Error( 'coywolf_seo_okf_not_found', __( 'No such OKF concept.', 'coywolf-seo' ) );
		}
		$md = $this->read_file( $real_file );
		if ( null === $md ) {
			return new WP_Error( 'coywolf_seo_okf_not_found', __( 'The concept could not be read.', 'coywolf-seo' ) );
		}
		return array(
			'path'     => $rel,
			'markdown' => $md,
		);
	}

	/**
	 * Ability get-entitymap: the entitymap.json contents (optionally capped to `limit`
	 * entities).
	 *
	 * @param array $input { limit?: int }.
	 * @return array|WP_Error
	 */
	public function cb_entitymap( $input = array() ) {
		$em = $this->ai->entitymap();
		if ( ! $this->engine_public( $em ) ) {
			return new WP_Error( 'coywolf_seo_entitymap_off', __( 'The EntityMap output is not published.', 'coywolf-seo' ) );
		}
		$gen = $em->generator();
		if ( ! $gen->bundle_exists() ) {
			return new WP_Error( 'coywolf_seo_entitymap_unbuilt', __( 'The EntityMap has not been generated yet.', 'coywolf-seo' ) );
		}
		$json = $this->read_file( $gen->json_path() );
		$data = ( null !== $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'coywolf_seo_entitymap_unbuilt', __( 'The EntityMap could not be read.', 'coywolf-seo' ) );
		}
		$limit = isset( $input['limit'] ) ? max( 0, (int) $input['limit'] ) : 0;
		if ( $limit > 0 && isset( $data['entities'] ) && is_array( $data['entities'] ) ) {
			$data['entities'] = array_slice( $data['entities'], 0, $limit );
		}
		return $data;
	}

	/**
	 * Ability get-ai-catalog: the ai-catalog.json manifest contents.
	 *
	 * @param array $input Unused.
	 * @return array|WP_Error
	 */
	public function cb_ai_catalog( $input = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the Abilities API passes input to every execute_callback; this tool takes none.
		$ard = $this->ai->ard();
		if ( ! $this->engine_public( $ard ) ) {
			return new WP_Error( 'coywolf_seo_ard_off', __( 'The ai-catalog.json output is not published.', 'coywolf-seo' ) );
		}
		$json = $ard->generator()->catalog_json();
		$data = ( '' !== $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'coywolf_seo_ard_unbuilt', __( 'The ai-catalog.json manifest is not available yet.', 'coywolf-seo' ) );
		}
		return $data;
	}

	/**
	 * Ability list-ai-discovery-outputs: status summary of the three outputs.
	 *
	 * @param array $input Unused.
	 * @return array
	 */
	public function cb_list_outputs( $input = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the Abilities API passes input to every execute_callback; this tool takes none.
		$rows = array();
		$map  = array(
			'okf'       => $this->ai->okf(),
			'entitymap' => $this->ai->entitymap(),
			'ard'       => $this->ai->ard(),
		);
		foreach ( $map as $key => $engine ) {
			$last   = $engine->last_build();
			$rows[] = array(
				'id'               => $key,
				'enabled'          => (bool) $engine->is_enabled(),
				'endpoint_enabled' => (bool) $engine->endpoint_enabled(),
				'url'              => $this->output_url( $key, $engine ),
				'last_build'       => ( is_array( $last ) && isset( $last['time'] ) ) ? (string) $last['time'] : null,
			);
		}
		return array( 'outputs' => $rows );
	}

	/**
	 * The public URL for an output.
	 *
	 * @param string                   $key    Output key.
	 * @param Coywolf_SEO_Labs_Feature $engine Engine.
	 * @return string
	 */
	private function output_url( $key, $engine ) {
		if ( 'okf' === $key && method_exists( $engine, 'endpoint_base_url' ) ) {
			return $engine->endpoint_base_url();
		}
		if ( 'entitymap' === $key && method_exists( $engine->generator(), 'public_json_url' ) ) {
			return $engine->generator()->public_json_url();
		}
		if ( 'ard' === $key && method_exists( $engine, 'catalog_url' ) ) {
			return $engine->catalog_url();
		}
		return '';
	}

	/**
	 * Read a file via WP_Filesystem; null on failure.
	 *
	 * @param string $abs Absolute path.
	 * @return string|null
	 */
	private function read_file( $abs ) {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		if ( ! $wp_filesystem || ! $wp_filesystem->exists( $abs ) ) {
			return null;
		}
		$contents = $wp_filesystem->get_contents( $abs );
		return is_string( $contents ) ? $contents : null;
	}

	// ---------------------------------------------------------------------
	// Labs panel (rendered inside AI Discovery's panel, master-on only)
	// ---------------------------------------------------------------------

	/**
	 * The public MCP endpoint URL.
	 *
	 * @return string
	 */
	public function endpoint_url() {
		return rest_url( self::SERVER_NS . '/' . self::SERVER_ROUTE );
	}

	/**
	 * Render the MCP sub-panel. With no adapter, a single muted note; with the
	 * adapter present, the endpoint plus a per-ability allow-list.
	 */
	public function render_panel() {
		if ( ! $this->adapter_available() ) {
			?>
			<div class="coywolf-seo-labs-feature coywolf-seo-panel">
				<p class="description">
					<?php esc_html_e( 'Install the WordPress MCP Adapter plugin to additionally expose these outputs to AI agents over MCP (Claude Desktop, Claude Code, Cursor).', 'coywolf-seo' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		$catalog = $this->ability_catalog();
		?>
		<div class="coywolf-seo-labs-feature coywolf-seo-panel">
			<h3><?php esc_html_e( 'MCP server', 'coywolf-seo' ); ?></h3>
			<p class="description">
				<?php
				printf(
					/* translators: %s: the MCP endpoint URL */
					esc_html__( 'The MCP Adapter plugin is active, so the outputs you publish above can also be exposed as read-only MCP tools at %s — for MCP clients like Claude Desktop, Claude Code, and Cursor, scoped to the connecting user\'s capabilities. Choose which tools to expose:', 'coywolf-seo' ),
					'<code>' . esc_html( $this->endpoint_url() ) . '</code>'
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="coywolf_seo_mcp_save" />
				<?php wp_nonce_field( 'coywolf_seo_mcp_save' ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( $catalog as $id => $def ) : ?>
						<?php
						$engine    = $this->engine_for( $def['engine'] );
						$available = ( '' === $def['engine'] ) || $this->engine_public( $engine );
						$checked   = $available && $this->is_allowed( $id );
						?>
						<tr>
							<th scope="row" style="font-weight:400;">
								<label>
									<input type="checkbox" name="coywolf_seo_mcp_abilities[<?php echo esc_attr( $id ); ?>]" value="1" <?php checked( $checked ); ?> <?php disabled( ! $available ); ?> />
									<?php echo esc_html( $def['label'] ); ?>
								</label>
							</th>
							<td>
								<p style="margin-top:0;"><code><?php echo esc_html( $id ); ?></code></p>
								<p class="description" style="margin-top:0;"><?php echo esc_html( $def['description'] ); ?></p>
								<?php if ( ! $available ) : ?>
									<p class="description" style="margin-top:0;">
										<em><?php echo esc_html( $this->disabled_hint( $def['engine'] ) ); ?></em>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button( __( 'Save MCP tools', 'coywolf-seo' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * The hint shown for an ability whose output is not published.
	 *
	 * @param string $engine_key Engine key.
	 * @return string
	 */
	private function disabled_hint( $engine_key ) {
		switch ( $engine_key ) {
			case 'okf':
				return __( 'Enable the OKF output (with its live endpoint) above to expose this tool.', 'coywolf-seo' );
			case 'entitymap':
				return __( 'Enable the EntityMap output (with its live endpoint) above to expose this tool.', 'coywolf-seo' );
			case 'ard':
				return __( 'Enable the ARD output (with its live endpoint) above to expose this tool.', 'coywolf-seo' );
		}
		return '';
	}

	/**
	 * Save the allow-list. Only currently-publishable abilities are on the form;
	 * others keep their stored value.
	 */
	public function handle_save() {
		$this->guard( 'coywolf_seo_mcp_save' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in guard(); only known ability ids are read as booleans below.
		$raw = isset( $_POST['coywolf_seo_mcp_abilities'] ) && is_array( $_POST['coywolf_seo_mcp_abilities'] ) ? wp_unslash( $_POST['coywolf_seo_mcp_abilities'] ) : array();
		$map = $this->allowed_map();
		foreach ( $this->registrable_ability_ids() as $id ) {
			$map[ $id ] = ! empty( $raw[ $id ] );
		}
		update_option( self::ALLOW_OPTION, $map );

		$this->redirect( 'mcp-saved' );
	}

	/**
	 * Status messages this sub-feature can surface on the Labs page.
	 *
	 * @return array key => array( type, text )
	 */
	public function notices() {
		if ( ! $this->adapter_available() ) {
			return array();
		}
		return array(
			'mcp-saved' => array( 'success', __( 'MCP tool settings saved.', 'coywolf-seo' ) ),
		);
	}

	/**
	 * Capability + nonce guard.
	 *
	 * @param string $nonce_action Nonce action.
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
}
