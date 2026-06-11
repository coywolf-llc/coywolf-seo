<?php
/**
 * AI Schema enrichment for Coywolf SEO.
 *
 * When enabled (with the site owner's own Anthropic API key), published
 * posts and pages are analyzed in the background and the entities they
 * discuss are added to their Article schema — primary subjects as `about`,
 * passing references as `mentions`, each grounded to a real Wikidata item.
 *
 * Hallucinated identifiers are designed out with retrieve-and-verify:
 *
 * 1. Claude extracts entity MENTIONS only — surface form, normalized name,
 *    type (Person/Organization/Place/Thing), a one-line description, and a
 *    primary/secondary split. It is explicitly instructed not to produce
 *    QIDs or URLs.
 * 2. A deterministic lookup against Wikidata's public wbsearchentities API
 *    (no key required) returns real candidate items for each name.
 * 3. When several candidates exist, the model chooses among them — safe,
 *    because it is selecting from actual options (with their Wikidata
 *    descriptions) rather than generating an ID from memory. A chosen QID
 *    that is not in the candidate list is discarded.
 * 4. The chosen items' `instance of` (P31) claims are checked roughly
 *    against the expected type: disambiguation pages are dropped, a Person
 *    must be a human (Q5), and a non-Person must not be one.
 *
 * Calls go through the WordPress PHP AI Client SDK with the Anthropic
 * provider (both vendored via Composer), per the project requirements.
 *
 * @package CoywolfSEO
 */

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AnthropicAiProvider\Provider\AnthropicProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entity extraction, grounding, and storage.
 */
final class Coywolf_SEO_AI {

	/**
	 * Post meta holding the grounded entities.
	 */
	const META_KEY = '_coywolf_seo_entities';

	/**
	 * Single-event cron hook that analyzes one post.
	 */
	const CRON_HOOK = 'coywolf_seo_ai_analyze';

	/**
	 * Default Claude model. Filterable via coywolf_seo_ai_model.
	 */
	const DEFAULT_MODEL = 'claude-opus-4-8';

	/**
	 * Wikidata API endpoint.
	 */
	const WIKIDATA_API = 'https://www.wikidata.org/w/api.php';

	/**
	 * Post types that get analyzed.
	 *
	 * @var string[]
	 */
	private $post_types = array( 'post', 'page' );

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_action( self::CRON_HOOK, array( $this, 'analyze_post' ) );
		add_action( 'transition_post_status', array( $this, 'maybe_queue' ), 10, 3 );
		add_filter( 'http_request_args', array( $this, 'extend_anthropic_timeout' ), 10, 2 );
	}

	/**
	 * The timeout for Anthropic generation calls, in seconds. Model
	 * generation takes far longer than WordPress's 5-second HTTP default.
	 *
	 * @return float
	 */
	private function timeout() {
		/**
		 * Filters the Anthropic request timeout (seconds).
		 *
		 * @param int $timeout Timeout in seconds.
		 */
		return (float) apply_filters( 'coywolf_seo_ai_timeout', 180 );
	}

	/**
	 * Floor the WordPress HTTP timeout for Anthropic API requests.
	 *
	 * WordPress 7.0's bundled AI transport sends through the WordPress HTTP
	 * API with its 5-second default — every generation call times out. The
	 * SDK request options set the timeout too, but this filter guarantees
	 * it for any call the SDK makes on its own (model discovery included).
	 *
	 * @param array  $args Request arguments.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public function extend_anthropic_timeout( $args, $url ) {
		if ( is_string( $url ) && false !== strpos( $url, 'api.anthropic.com' ) ) {
			$timeout         = $this->timeout();
			$args['timeout'] = isset( $args['timeout'] ) ? max( (float) $args['timeout'], $timeout ) : $timeout;
		}
		return $args;
	}

	/**
	 * Whether enrichment is enabled and an API key is available.
	 *
	 * @return bool
	 */
	public function enabled() {
		return (bool) Coywolf_SEO_Options::get( 'ai_enabled' ) && '' !== $this->api_key();
	}

	/**
	 * The Anthropic API key: the saved setting, or the ANTHROPIC_API_KEY
	 * constant/environment as a wp-config-style fallback.
	 *
	 * @return string
	 */
	private function api_key() {
		$key = (string) Coywolf_SEO_Options::get( 'ai_api_key' );
		if ( '' !== $key ) {
			return $key;
		}
		if ( defined( 'ANTHROPIC_API_KEY' ) ) {
			return (string) ANTHROPIC_API_KEY;
		}
		$env = getenv( 'ANTHROPIC_API_KEY' );
		return $env ? (string) $env : '';
	}

	/**
	 * Queue analysis when a post is published or updated.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status (unused).
	 * @param WP_Post $post       Post.
	 */
	public function maybe_queue( $new_status, $old_status, $post ) {
		unset( $old_status );
		if ( 'publish' !== $new_status || ! $this->enabled() ) {
			return;
		}
		if ( ! in_array( $post->post_type, $this->post_types, true ) || wp_is_post_revision( $post->ID ) ) {
			return;
		}
		$args = array( (int) $post->ID );
		if ( ! wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK, $args );
			// Kick cron now so the analysis starts within seconds even on
			// low-traffic sites, instead of waiting for the next visit.
			if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
				spawn_cron();
			}
		}
	}

	/**
	 * Analyze one post: extract, ground, verify, store.
	 *
	 * Runs on WP-Cron, so latency is invisible to editors and visitors.
	 *
	 * @param int $post_id Post ID.
	 */
	public function analyze_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ! $this->enabled() ) {
			return;
		}

		$title   = get_the_title( $post );
		$content = $this->plain_content( $post );
		if ( '' === trim( $content ) ) {
			return;
		}

		// Unchanged since the last successful run? Done.
		$hash  = md5( $title . "\n" . $content );
		$saved = get_post_meta( $post_id, self::META_KEY, true );
		if ( is_array( $saved ) && isset( $saved['hash'] ) && $saved['hash'] === $hash ) {
			return;
		}

		try {
			$mentions = $this->extract_mentions( $title, $content );
			$entities = empty( $mentions ) ? array() : $this->ground_mentions( $mentions, $title, $content );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-mode only.
				error_log( 'Coywolf SEO AI enrichment failed for post ' . $post_id . ': ' . $e->getMessage() );
			}
			// Record the failure (with an empty hash so the next save
			// retries) and surface it in the editor's SEO panel.
			update_post_meta(
				$post_id,
				self::META_KEY,
				array(
					'hash'     => '',
					'time'     => gmdate( 'c' ),
					'status'   => 'error',
					'error'    => $e->getMessage(),
					'entities' => array(),
				)
			);
			return;
		}

		update_post_meta(
			$post_id,
			self::META_KEY,
			array(
				'hash'     => $hash,
				'time'     => gmdate( 'c' ),
				'status'   => 'ok',
				'error'    => '',
				'entities' => $entities,
			)
		);
	}

	/**
	 * Human-readable analysis status for a post, shown in the editor's SEO
	 * panel so a silent background failure is never invisible.
	 *
	 * @param int $post_id Post ID.
	 * @return string '' when enrichment is disabled.
	 */
	public function status_text( $post_id ) {
		if ( ! $this->enabled() ) {
			return '';
		}
		$saved = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $saved ) ) {
			return __( 'Entities: analyzed in the background after publishing.', 'coywolf-seo' );
		}
		if ( isset( $saved['status'] ) && 'error' === $saved['status'] ) {
			/* translators: %s: error message. */
			return sprintf( __( 'Entity analysis failed: %s — retried on the next save.', 'coywolf-seo' ), (string) $saved['error'] );
		}
		$about    = 0;
		$mentions = 0;
		if ( ! empty( $saved['entities'] ) && is_array( $saved['entities'] ) ) {
			foreach ( $saved['entities'] as $entity ) {
				if ( ! empty( $entity['primary'] ) ) {
					$about++;
				} else {
					$mentions++;
				}
			}
		}
		/* translators: 1: number of about entities, 2: number of mention entities. */
		return sprintf( __( 'Entities in schema: %1$d about, %2$d mentions.', 'coywolf-seo' ), $about, $mentions );
	}

	/**
	 * Schema nodes for a post's stored entities.
	 *
	 * @param int $post_id Post ID.
	 * @return array { about: array[], mentions: array[] }
	 */
	public static function schema_nodes( $post_id ) {
		$out   = array(
			'about'    => array(),
			'mentions' => array(),
		);
		$saved = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $saved ) || empty( $saved['entities'] ) || ! is_array( $saved['entities'] ) ) {
			return $out;
		}
		foreach ( $saved['entities'] as $entity ) {
			if ( ! is_array( $entity ) || empty( $entity['name'] ) || empty( $entity['qid'] ) ) {
				continue;
			}
			$node = array(
				'@type'  => isset( $entity['type'] ) && in_array( $entity['type'], array( 'Person', 'Organization', 'Place' ), true ) ? $entity['type'] : 'Thing',
				'name'   => (string) $entity['name'],
				'sameAs' => 'https://www.wikidata.org/wiki/' . $entity['qid'],
			);
			if ( ! empty( $entity['description'] ) ) {
				$node['description'] = (string) $entity['description'];
			}
			if ( ! empty( $entity['primary'] ) ) {
				$out['about'][] = $node;
			} else {
				$out['mentions'][] = $node;
			}
		}
		return $out;
	}

	/**
	 * The post's content as plain text, bounded for the prompt.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private function plain_content( $post ) {
		$content = (string) $post->post_content;
		$content = strip_shortcodes( $content );
		$content = excerpt_remove_blocks( $content );
		$content = wp_strip_all_tags( $content, true );
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $content, 0, 24000 );
		}
		return substr( $content, 0, 24000 );
	}

	/**
	 * Stage 1 — Claude extracts entity mentions (no identifiers).
	 *
	 * @param string $title   Post title.
	 * @param string $content Plain post content.
	 * @return array Rows of surface/name/type/description/primary.
	 */
	private function extract_mentions( $title, $content ) {
		$system = 'You extract named entities from an article for SEO schema markup. '
			. 'Respond with ONLY a JSON array — no prose, no code fences. Each element is an object with exactly these keys: '
			. '"surface" (the entity exactly as written in the article), '
			. '"name" (the canonical, normalized name), '
			. '"type" (one of "Person", "Organization", "Place", "Thing"), '
			. '"description" (one short line stating what the entity is), '
			. '"primary" (true when the entity is a main subject of the article, false when it is mentioned in passing). '
			. 'Rules: never output identifiers, QIDs, database IDs, or URLs of any kind. '
			. 'Only include real-world entities a reader could look up — people, organizations, places, products, published works, well-defined concepts. '
			. 'Skip the article author and the publishing website itself. At most 12 entities. If there are none, return [].';

		$text = $this->generate( $system, 'Title: ' . $title . "\n\n" . $content );
		$rows = $this->decode_json( $text );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$clean = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) || empty( $row['type'] ) ) {
				continue;
			}
			$type = (string) $row['type'];
			if ( ! in_array( $type, array( 'Person', 'Organization', 'Place', 'Thing' ), true ) ) {
				$type = 'Thing';
			}
			$clean[] = array(
				'surface'     => isset( $row['surface'] ) ? sanitize_text_field( (string) $row['surface'] ) : '',
				'name'        => sanitize_text_field( (string) $row['name'] ),
				'type'        => $type,
				'description' => isset( $row['description'] ) ? sanitize_text_field( (string) $row['description'] ) : '',
				'primary'     => ! empty( $row['primary'] ),
			);
			if ( count( $clean ) >= 12 ) {
				break;
			}
		}
		return $clean;
	}

	/**
	 * Stages 2–4 — resolve each mention to a verified Wikidata item.
	 *
	 * @param array  $mentions Extracted mentions.
	 * @param string $title    Post title (disambiguation context).
	 * @param string $content  Plain content (disambiguation context).
	 * @return array Grounded entities (name/type/description/qid/primary).
	 */
	private function ground_mentions( array $mentions, $title, $content ) {
		// Stage 2: deterministic candidate lookup per mention.
		$ambiguous = array();
		foreach ( $mentions as $i => $mention ) {
			$candidates                  = $this->wikidata_search( $mention['name'] );
			$mentions[ $i ]['qid']       = '';
			$mentions[ $i ]['candidates'] = $candidates;
			if ( empty( $candidates ) ) {
				continue; // No real item: the mention is dropped, never invented.
			}
			if ( 1 === count( $candidates ) ) {
				$mentions[ $i ]['qid'] = $candidates[0]['id'];
			} else {
				$ambiguous[ $i ] = $mentions[ $i ];
			}
		}

		// Stage 3: the model chooses among the real candidates.
		if ( ! empty( $ambiguous ) ) {
			$choices = $this->disambiguate( $ambiguous, $title, $content );
			foreach ( $ambiguous as $i => $mention ) {
				$name = $mention['name'];
				if ( ! isset( $choices[ $name ] ) || ! is_string( $choices[ $name ] ) ) {
					continue;
				}
				$chosen = strtoupper( trim( $choices[ $name ] ) );
				// Trust the choice only if it is one of the real candidates.
				foreach ( $mention['candidates'] as $candidate ) {
					if ( $candidate['id'] === $chosen ) {
						$mentions[ $i ]['qid'] = $chosen;
						break;
					}
				}
			}
		}

		$resolved = array();
		foreach ( $mentions as $mention ) {
			if ( '' !== $mention['qid'] ) {
				$resolved[] = $mention;
			}
		}
		if ( empty( $resolved ) ) {
			return array();
		}

		// Stage 4: rough P31 (instance of) type verification.
		$claims = $this->wikidata_instance_of( wp_list_pluck( $resolved, 'qid' ) );
		$final  = array();
		foreach ( $resolved as $mention ) {
			$p31 = isset( $claims[ $mention['qid'] ] ) ? $claims[ $mention['qid'] ] : array();
			if ( in_array( 'Q4167410', $p31, true ) ) {
				continue; // Wikimedia disambiguation page — never a real entity.
			}
			$is_human = in_array( 'Q5', $p31, true );
			if ( 'Person' === $mention['type'] && ! empty( $p31 ) && ! $is_human ) {
				continue; // Expected a person; the item is something else.
			}
			if ( 'Person' !== $mention['type'] && $is_human ) {
				continue; // Expected a non-person; the item is a human.
			}
			$final[] = array(
				'name'        => $mention['name'],
				'type'        => $mention['type'],
				'description' => $mention['description'],
				'qid'         => $mention['qid'],
				'primary'     => $mention['primary'],
			);
		}
		return $final;
	}

	/**
	 * Stage 3 prompt — choose among real Wikidata candidates.
	 *
	 * @param array  $ambiguous Mentions with their candidate lists.
	 * @param string $title     Post title.
	 * @param string $content   Plain content.
	 * @return array Map of entity name => QID string or null.
	 */
	private function disambiguate( array $ambiguous, $title, $content ) {
		$system = 'You match entities from an article to Wikidata items. '
			. 'Respond with ONLY a JSON object — no prose, no code fences — mapping each entity name to the QID string of the best-matching candidate, or null when no candidate clearly matches. '
			. 'Choose strictly among the provided candidates. Never produce a QID that is not listed. '
			. 'Use the article context, the entity type, and each candidate\'s Wikidata description to decide. If the choice is unclear, use null.';

		$context = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 600 ) : substr( $content, 0, 600 );
		$lines   = array( 'Article: ' . $title . ' — ' . $context, '', 'Entities and their candidates:' );
		foreach ( $ambiguous as $mention ) {
			$lines[] = $mention['name'] . ' (' . $mention['type'] . ( '' !== $mention['description'] ? ' — ' . $mention['description'] : '' ) . '):';
			foreach ( $mention['candidates'] as $candidate ) {
				$lines[] = '  ' . $candidate['id'] . ': ' . $candidate['label'] . ( '' !== $candidate['description'] ? ' — ' . $candidate['description'] : '' );
			}
		}

		$text    = $this->generate( $system, implode( "\n", $lines ) );
		$decoded = $this->decode_json( $text );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Whether the SDK has been bootstrapped this request, and which copy of
	 * the PHP AI Client is in play.
	 *
	 * @var bool
	 */
	private static $sdk_loaded = false;

	/**
	 * True when the site itself provides the PHP AI Client (WordPress 7.0+
	 * bundles it in core; the php-ai-client plugin provides it earlier).
	 *
	 * @var bool
	 */
	private static $site_provides_client = false;

	/**
	 * Bootstrap the SDK without ever shadowing a copy the site already has.
	 *
	 * WordPress 7.0 bundles the PHP AI Client in core (wp-settings.php
	 * registers its autoloader), with its third-party internals scoped.
	 * Loading our vendored copy of the same classes alongside it mixes the
	 * two builds (Composer's autoloader prepends) and fatals deep inside
	 * the SDK. So: when the client already exists, use it and only
	 * autoload the Anthropic provider from our vendor directory; the full
	 * vendored stack loads only on sites with no client at all (WP < 7.0).
	 */
	private function load_sdk() {
		if ( self::$sdk_loaded ) {
			return;
		}
		self::$sdk_loaded = true;

		if ( class_exists( 'WordPress\\AiClient\\AiClient' ) ) {
			self::$site_provides_client = true;
			if ( ! class_exists( 'WordPress\\AnthropicAiProvider\\Provider\\AnthropicProvider' ) ) {
				spl_autoload_register(
					static function ( $class_name ) {
						$prefix = 'WordPress\\AnthropicAiProvider\\';
						if ( 0 !== strpos( $class_name, $prefix ) ) {
							return;
						}
						$file = COYWOLF_SEO_PATH . 'vendor/wordpress/ai-provider-for-anthropic/src/'
							. str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
						if ( file_exists( $file ) ) {
							require $file;
						}
					}
				);
			}
			return;
		}

		self::$site_provides_client = false;
		require_once COYWOLF_SEO_PATH . 'vendor/autoload.php';
	}

	/**
	 * Call Claude through the PHP AI Client with the Anthropic provider.
	 *
	 * @param string $system System instruction.
	 * @param string $prompt User prompt.
	 * @return string Generated text.
	 */
	private function generate( $system, $prompt ) {
		$this->load_sdk();

		$registry = AiClient::defaultRegistry();
		if ( ! self::$site_provides_client ) {
			// Our vendored stack ships no PSR-18 client for the SDK's
			// discovery to find: route it through the WordPress HTTP API.
			// (A site-provided client comes with its own transport wiring.)
			require_once COYWOLF_SEO_PATH . 'includes/class-coywolf-seo-http-transporter.php';
			$registry->setHttpTransporter( new Coywolf_SEO_Http_Transporter() );
		}
		if ( ! $registry->hasProvider( 'anthropic' ) ) {
			$registry->registerProvider( AnthropicProvider::class );
		}
		$key = (string) Coywolf_SEO_Options::get( 'ai_api_key' );
		if ( '' !== $key ) {
			$registry->setProviderRequestAuthentication( 'anthropic', new ApiKeyRequestAuthentication( $key ) );
		}

		/**
		 * Filters the Claude model used for schema enrichment.
		 *
		 * @param string $model Model ID.
		 */
		$model = apply_filters( 'coywolf_seo_ai_model', self::DEFAULT_MODEL );

		$options = new RequestOptions();
		$options->setTimeout( $this->timeout() );

		try {
			return AiClient::prompt( $prompt, $registry )
				->usingProvider( 'anthropic' )
				->usingModelPreference( $model )
				->usingSystemInstruction( $system )
				->usingMaxTokens( 4000 )
				->usingRequestOptions( $options )
				->generateText();
		} catch ( \Throwable $e ) {
			// The pinned model may not be available to this key yet; let the
			// provider pick its preferred Claude model instead.
			return AiClient::prompt( $prompt, $registry )
				->usingProvider( 'anthropic' )
				->usingSystemInstruction( $system )
				->usingMaxTokens( 4000 )
				->usingRequestOptions( $options )
				->generateText();
		}
	}

	/**
	 * Decode a model response that should be bare JSON, tolerating fences.
	 *
	 * @param string $text Model output.
	 * @return mixed Decoded value, or null.
	 */
	private function decode_json( $text ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', (string) $text );
		$start = strpbrk( $text, '[{' );
		if ( false === $start ) {
			return null;
		}
		return json_decode( $start, true );
	}

	/**
	 * Wikidata wbsearchentities lookup — real candidates only.
	 *
	 * @param string $name Entity name.
	 * @return array Candidates as array( id, label, description ).
	 */
	private function wikidata_search( $name ) {
		$language = substr( (string) get_locale(), 0, 2 );
		$language = $language ? $language : 'en';
		$url      = add_query_arg(
			array(
				'action'   => 'wbsearchentities',
				'format'   => 'json',
				'language' => $language,
				'uselang'  => $language,
				'type'     => 'item',
				'limit'    => 5,
				'search'   => rawurlencode( $name ),
			),
			self::WIKIDATA_API
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => $this->user_agent(),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['search'] ) || ! is_array( $body['search'] ) ) {
			return array();
		}

		$candidates = array();
		foreach ( $body['search'] as $hit ) {
			if ( empty( $hit['id'] ) ) {
				continue;
			}
			$candidates[] = array(
				'id'          => (string) $hit['id'],
				'label'       => isset( $hit['label'] ) ? (string) $hit['label'] : '',
				'description' => isset( $hit['description'] ) ? (string) $hit['description'] : '',
			);
		}
		return $candidates;
	}

	/**
	 * Batched P31 (instance of) claims for a set of items.
	 *
	 * @param string[] $qids Item IDs.
	 * @return array Map of QID => array of P31 value QIDs.
	 */
	private function wikidata_instance_of( array $qids ) {
		$qids = array_values( array_unique( array_filter( $qids ) ) );
		if ( empty( $qids ) ) {
			return array();
		}
		$url = add_query_arg(
			array(
				'action' => 'wbgetentities',
				'format' => 'json',
				'props'  => 'claims',
				'ids'    => implode( '|', array_slice( $qids, 0, 50 ) ),
			),
			self::WIKIDATA_API
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => $this->user_agent(),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['entities'] ) || ! is_array( $body['entities'] ) ) {
			return array();
		}

		$map = array();
		foreach ( $body['entities'] as $qid => $entity ) {
			$values = array();
			if ( isset( $entity['claims']['P31'] ) && is_array( $entity['claims']['P31'] ) ) {
				foreach ( $entity['claims']['P31'] as $claim ) {
					if ( isset( $claim['mainsnak']['datavalue']['value']['id'] ) ) {
						$values[] = (string) $claim['mainsnak']['datavalue']['value']['id'];
					}
				}
			}
			$map[ (string) $qid ] = $values;
		}
		return $map;
	}

	/**
	 * Descriptive user agent per the Wikimedia API etiquette.
	 *
	 * @return string
	 */
	private function user_agent() {
		return 'CoywolfSEO/' . Coywolf_SEO::VERSION . ' (WordPress plugin; ' . home_url( '/' ) . ')';
	}
}
