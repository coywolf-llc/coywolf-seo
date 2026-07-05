<?php
/**
 * EntityMap v1.0 file-set generator — Labs feature.
 *
 * Builds a spec-conformant EntityMap (https://entitymap.org/spec/v1.0) file set
 * from data already stored on the site: the Wikidata-grounded entities the
 * plugin's AI enrichment recorded for each page, plus extractive, publisher-
 * attributed evidence passages pulled from the pages those entities appear on.
 *
 * Two files are written under wp-content/uploads/coywolf-entitymap/:
 *   - entitymap.json — the machine file (the conformance target).
 *   - entitymap.html — a human/crawler-readable companion generated from the
 *     same model (per-entity JSON-LD + visible per-chunk cite attribution).
 *
 * They are served from the domain root (entitymap.json / entitymap.html) by the
 * feature's rewrite rules; the "served from the root" requirement is about the
 * URL, which the rewrite provides — storage stays on disk.
 *
 * This build targets the conformance floor + the entity/chunk layer only. Typed
 * `relations` are MAY in the spec and out of scope for v1; a clean extension
 * point is left (see build_model() and entity_object()) but none are generated.
 *
 * No outbound calls: everything is built from already-stored, already-grounded
 * data. It never calls an AI provider or Wikidata.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EntityMap file-set builder.
 */
final class Coywolf_SEO_EntityMap_Generator extends Coywolf_SEO_Labs_Bundle_Generator {

	/**
	 * Storage directory name, under the uploads basedir.
	 */
	const DIR = 'coywolf-entitymap';

	/**
	 * EntityMap specification version this generator targets.
	 */
	const SPEC_VERSION = '1.0';

	/**
	 * EntityMap specification URL (the root object's `schema` value).
	 */
	const SPEC_URL = 'https://entitymap.org/spec/v1.0';

	/**
	 * Max chunks emitted per entity (spec maximum).
	 */
	const MAX_CHUNKS = 5;

	/**
	 * Max characters per chunk `text` (spec maximum).
	 */
	const MAX_CHUNK_CHARS = 600;

	/**
	 * Per-rebuild cache of each post's plain text and its sentence split, keyed by
	 * post ID, so the same page is parsed/split once rather than once per
	 * (entity, candidate-post) pair. Reset at the top of each rebuild().
	 *
	 * @var array<int,array{plain:string,sentences:string[]}>
	 */
	private $text_cache = array();

	/**
	 * Absolute path to the generated JSON file.
	 *
	 * @return string
	 */
	public function json_path() {
		return $this->bundle_path() . '/entitymap.json';
	}

	/**
	 * Absolute path to the generated HTML companion.
	 *
	 * @return string
	 */
	public function html_path() {
		return $this->bundle_path() . '/entitymap.html';
	}

	/**
	 * Whether a generated file set is present on disk.
	 *
	 * @return bool
	 */
	public function bundle_exists() {
		return is_readable( $this->json_path() );
	}

	// ---------------------------------------------------------------------
	// Build
	// ---------------------------------------------------------------------

	/**
	 * Rebuild the entire file set from current site content.
	 *
	 * @return array|WP_Error Summary array { counts:{entities,chunks}, time } on
	 *                        success, WP_Error on failure.
	 */
	public function rebuild() {
		$this->text_cache = array(); // Fresh per-post memo for this rebuild.

		$fs = $this->filesystem();
		if ( null === $fs ) {
			return new WP_Error( 'entitymap_fs', __( 'The filesystem is not writable, so the EntityMap files could not be generated.', 'coywolf-seo' ) );
		}

		$base = $this->bundle_path();
		if ( ! wp_mkdir_p( $base ) ) {
			return new WP_Error( 'entitymap_mkdir', __( 'The EntityMap directory could not be created.', 'coywolf-seo' ) );
		}
		$this->write_file( $base . '/index.html', '' ); // Directory-listing guard.

		$publisher = $this->resolve_publisher();
		$model     = $this->build_model();
		$entities  = $this->build_entities( $model, $publisher );

		$chunk_count = 0;
		foreach ( $entities as $entity ) {
			$chunk_count += isset( $entity['hasChunks'] ) ? count( $entity['hasChunks'] ) : 0;
		}

		$json = $this->render_json( $publisher, $entities );
		if ( '' === $json ) {
			return new WP_Error( 'entitymap_encode', __( 'The EntityMap JSON could not be encoded.', 'coywolf-seo' ) );
		}
		$this->write_file( $this->json_path(), $json );
		$this->write_file( $this->html_path(), $this->render_html( $publisher, $entities ) );

		return array(
			'counts' => array(
				'entities' => count( $entities ),
				'chunks'   => $chunk_count,
			),
			'time'   => gmdate( 'c' ),
		);
	}

	/**
	 * Build a downloadable zip of the current file set (json + html) at the
	 * given path.
	 *
	 * @param string $dest_path Absolute path the archive is written to.
	 * @return bool|WP_Error True on success, WP_Error otherwise.
	 */
	public function build_zip( $dest_path ) {
		if ( ! $this->bundle_exists() ) {
			return new WP_Error( 'entitymap_no_bundle', __( 'There are no generated EntityMap files to download yet. Rebuild them first.', 'coywolf-seo' ) );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'entitymap_no_zip', __( 'The PHP Zip extension is not available on this server.', 'coywolf-seo' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $dest_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'entitymap_zip_open', __( 'The download archive could not be created.', 'coywolf-seo' ) );
		}
		$zip->addFile( $this->json_path(), 'entitymap.json' );
		if ( is_readable( $this->html_path() ) ) {
			$zip->addFile( $this->html_path(), 'entitymap.html' );
		}
		$zip->close();
		return true;
	}

	// ---------------------------------------------------------------------
	// Model
	// ---------------------------------------------------------------------

	/**
	 * The published, public posts and the entities they record, deduped by
	 * Wikidata QID. Only entities with a non-empty description survive (the spec
	 * floor requires `description`).
	 *
	 * @return array { posts: array<int,WP_Post>, entities: array<string,array> }
	 */
	private function build_model() {
		$posts    = array();
		$entities = array();

		$post_types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $post_types as $post_type ) {
			if ( 'attachment' === $post_type || ! is_post_type_viewable( $post_type ) ) {
				continue;
			}
			// Only AI-enriched posts can contribute entities, and the query is
			// paged, so peak memory does not scale with total site size (a large
			// enriched site would otherwise time out / OOM the cron rebuild).
			$paged = 1;
			do {
				$found = get_posts(
					array(
						'post_type'        => $post_type,
						'post_status'      => 'publish',
						'has_password'     => false,
						'orderby'          => 'modified',
						'order'            => 'DESC',
						'suppress_filters' => true,
						'meta_key'         => Coywolf_SEO_AI::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- restrict the rebuild to AI-enriched posts; non-enriched posts contribute nothing.
						'posts_per_page'   => 200,
						'paged'            => $paged,
					)
				);
				foreach ( $found as $post ) {
					if ( ! $this->is_post_visible( $post ) ) {
						continue;
					}
					$rows = Coywolf_SEO_AI::stored_entities( $post->ID );
					if ( empty( $rows ) ) {
						continue;
					}
					$posts[ $post->ID ] = $post;

					foreach ( $rows as $row ) {
						$qid  = (string) $row['qid'];
						$desc = trim( (string) $row['description'] );
						if ( '' === $qid || '' === $desc ) {
							continue; // Floor requires a description.
						}
						if ( ! isset( $entities[ $qid ] ) ) {
							$entities[ $qid ] = array(
								'qid'              => $qid,
								'name'             => (string) $row['name'],
								'type'             => (string) $row['type'],
								'description'      => $desc,
								'same_as'          => isset( $row['same_as'] ) && is_array( $row['same_as'] ) ? $row['same_as'] : array(),
								'about_post_ids'   => array(),
								'mention_post_ids' => array(),
								// v1 generates no typed relations (MAY in the spec); the
								// key is reserved here as the extension point.
								'relations'        => array(),
							);
						}
						if ( 'about' === $row['bucket'] ) {
							$entities[ $qid ]['about_post_ids'][] = (int) $post->ID;
						} else {
							$entities[ $qid ]['mention_post_ids'][] = (int) $post->ID;
						}
					}
				}
				$batch = count( $found );
				++$paged;
			} while ( 200 === $batch );
		}

		return array(
			'posts'    => $posts,
			'entities' => $entities,
		);
	}

	// ---------------------------------------------------------------------
	// Publisher
	// ---------------------------------------------------------------------

	/**
	 * Resolve the publisher ONCE, decode its name a single time, and reuse the
	 * exact string everywhere (every chunk's `publisher` must equal the root
	 * `publisher.name` byte-for-byte, per the spec). Falls back to the blog name
	 * when the schema accessor is unavailable.
	 *
	 * @return array{name:string,url:string,same_as:string[]}
	 */
	private function resolve_publisher() {
		$name    = '';
		$url     = home_url( '/' );
		$same_as = array();

		$schema = method_exists( 'Coywolf_SEO', 'instance' ) ? Coywolf_SEO::instance() : null;
		if ( $schema && method_exists( $schema, 'schema' ) ) {
			$obj = $schema->schema();
			if ( $obj && method_exists( $obj, 'publisher_identity' ) ) {
				$identity = $obj->publisher_identity();
				$name     = isset( $identity['name'] ) ? (string) $identity['name'] : '';
				$url      = ( isset( $identity['url'] ) && '' !== (string) $identity['url'] ) ? (string) $identity['url'] : $url;
				$same_as  = isset( $identity['same_as'] ) && is_array( $identity['same_as'] ) ? $identity['same_as'] : array();
			}
		}
		if ( '' === $name ) {
			$name = (string) get_bloginfo( 'name' );
		}

		return array(
			'name'    => Coywolf_SEO_Markdown_Source::decode_text( $name ),
			'url'     => $url,
			'same_as' => array_values( array_filter( array_map( 'strval', $same_as ), 'strlen' ) ),
		);
	}

	// ---------------------------------------------------------------------
	// Entities + chunks
	// ---------------------------------------------------------------------

	/**
	 * Turn the deduped entity map into ordered, conformant entity objects, each
	 * with 1–5 extractive chunks. Entities that end up with zero chunks are
	 * dropped (the floor requires ≥1 chunk).
	 *
	 * @param array $model     Model from build_model().
	 * @param array $publisher Resolved publisher (name reused verbatim per chunk).
	 * @return array[] Entity objects ready for serialization.
	 */
	private function build_entities( array $model, array $publisher ) {
		$out = array();
		foreach ( $model['entities'] as $entity ) {
			$chunks = $this->build_chunks( $entity, $model['posts'], $publisher['name'] );
			if ( empty( $chunks ) ) {
				continue; // No evidence passage — cannot satisfy the floor.
			}
			$out[] = $this->entity_object( $entity, $chunks );
		}
		return $out;
	}

	/**
	 * Shape one conformant entity object.
	 *
	 * @param array $entity Deduped entity model entry.
	 * @param array $chunks Already-built chunk objects (≥1).
	 * @return array
	 */
	private function entity_object( array $entity, array $chunks ) {
		$object = array(
			'entityId'    => $this->entity_id( $entity['qid'] ),
			'@type'       => $this->type_for( $entity['type'] ),
			'name'        => Coywolf_SEO_Markdown_Source::decode_text( $entity['name'] ),
			'description' => $this->trim_sentences( Coywolf_SEO_Markdown_Source::decode_text( $entity['description'] ), 3 ),
		);

		// Wikidata grounding: SHOULD for Concept, and present for every entity
		// because they are grounded. same_as[0] is the wikidata.org/wiki/Q… URI.
		if ( isset( $entity['same_as'][0] ) && '' !== (string) $entity['same_as'][0] ) {
			$object['sameAs'] = (string) $entity['same_as'][0];
		}

		$object['hasChunks'] = $chunks;

		// v1 emits no typed relations (MAY in the spec). The key is intentionally
		// omitted; serialize $entity['relations'] here once they are generated.

		return $object;
	}

	/**
	 * A stable entityId derived from the QID. Never reuse a retired id.
	 *
	 * @param string $qid Wikidata QID (e.g. Q42).
	 * @return string
	 */
	private function entity_id( $qid ) {
		return 'e_' . strtolower( (string) $qid );
	}

	/**
	 * Map the stored Schema.org type to an EntityMap core @type. Person /
	 * Organization / Place carry through; everything else (Thing) becomes the
	 * general Wikidata-grounded knowledge type, Concept.
	 *
	 * @param string $type Stored type.
	 * @return string
	 */
	private function type_for( $type ) {
		switch ( (string) $type ) {
			case 'Person':
				return 'Person';
			case 'Organization':
				return 'Organization';
			case 'Place':
				return 'Place';
			default:
				return 'Concept';
		}
	}

	/**
	 * Build 1–5 extractive chunks for an entity, deterministically (no AI call):
	 * its `about` posts first, then `mentions`, most-recently-modified first,
	 * capped at five. Each chunk's text is the first sentence on the page that
	 * names the entity, trimmed to ≤600 chars; failing that, the page's meta
	 * description / excerpt. Posts that yield no passage are skipped.
	 *
	 * @param array  $entity         Entity model entry.
	 * @param array  $posts          id => WP_Post map.
	 * @param string $publisher_name The verbatim root publisher name.
	 * @return array[] Chunk objects.
	 */
	private function build_chunks( array $entity, array $posts, $publisher_name ) {
		$candidates = array();
		foreach ( array_merge( $entity['about_post_ids'], $entity['mention_post_ids'] ) as $post_id ) {
			$post_id = (int) $post_id;
			if ( ! isset( $candidates[ $post_id ] ) && isset( $posts[ $post_id ] ) ) {
				$candidates[ $post_id ] = $posts[ $post_id ];
			}
		}
		// Most-recently-modified first for a stable, useful order.
		uasort(
			$candidates,
			static function ( $a, $b ) {
				return strcmp(
					(string) get_post_modified_time( 'U', true, $b ),
					(string) get_post_modified_time( 'U', true, $a )
				);
			}
		);

		$chunks = array();
		foreach ( $candidates as $post ) {
			if ( count( $chunks ) >= self::MAX_CHUNKS ) {
				break;
			}
			$chunk = $this->build_chunk( $entity, $post, $publisher_name );
			if ( null !== $chunk ) {
				$chunks[] = $chunk;
			}
		}
		return $chunks;
	}

	/**
	 * Build a single chunk for an entity from one post, or null when no passage
	 * can be extracted.
	 *
	 * @param array   $entity         Entity model entry.
	 * @param WP_Post $post           Source post.
	 * @param string  $publisher_name The verbatim root publisher name.
	 * @return array|null
	 */
	private function build_chunk( array $entity, $post, $publisher_name ) {
		$sentence = $this->first_sentence_with( $this->post_sentences( $post ), $entity['name'] );

		$content_type = 'evidence';
		if ( '' === $sentence ) {
			$sentence     = $this->fallback_passage( $post );
			$content_type = 'definition';
		}
		if ( '' === $sentence ) {
			return null;
		}

		return array(
			'chunkId'     => $this->entity_id( $entity['qid'] ) . '_' . (int) $post->ID,
			'text'        => $sentence,
			'sourceUrl'   => (string) get_permalink( $post ),
			'pageTitle'   => Coywolf_SEO_Markdown_Source::decode_text( get_the_title( $post ) ),
			'publisher'   => $publisher_name,
			'retrieved'   => (string) get_post_modified_time( 'c', true, $post ),
			'contentType' => $content_type,
		);
	}

	/**
	 * The plain text and its sentence split for a post, computed once per rebuild
	 * and memoized by post ID (the same page is a chunk candidate for every entity
	 * it records, so without this the full-content parse + split runs once per
	 * (entity, post) pair).
	 *
	 * @param WP_Post $post Source post.
	 * @return string[] Sentences of the post's plain text.
	 */
	private function post_sentences( $post ) {
		$id = (int) $post->ID;
		if ( ! isset( $this->text_cache[ $id ] ) ) {
			$plain                   = Coywolf_SEO_AI::plain_text( $post );
			$this->text_cache[ $id ] = array(
				'plain'     => $plain,
				'sentences' => $this->sentences( $plain ),
			);
		}
		return $this->text_cache[ $id ]['sentences'];
	}

	/**
	 * The first sentence (from a pre-split sentence list) that contains the entity
	 * name (case-insensitive), trimmed to ≤600 chars on a word boundary. '' when
	 * none matches.
	 *
	 * @param string[] $sentences Pre-split sentences of the page text.
	 * @param string   $name      Entity name.
	 * @return string
	 */
	private function first_sentence_with( array $sentences, $name ) {
		$name = Coywolf_SEO_Markdown_Source::decode_text( $name );
		if ( '' === $name ) {
			return '';
		}
		foreach ( $sentences as $sentence ) {
			if ( $this->contains_ci( $sentence, $name ) ) {
				return $this->trim_passage( $sentence );
			}
		}
		return '';
	}

	/**
	 * Fallback chunk text when no sentence names the entity: the page's saved
	 * meta description, else its manual excerpt, trimmed to ≤600 chars.
	 *
	 * @param WP_Post $post Source post.
	 * @return string
	 */
	private function fallback_passage( $post ) {
		$text = '';
		$seo  = method_exists( 'Coywolf_SEO', 'instance' ) ? Coywolf_SEO::instance() : null;
		if ( $seo && method_exists( $seo, 'ai' ) && $seo->ai() && method_exists( $seo->ai(), 'description_for' ) ) {
			$text = (string) $seo->ai()->description_for( $post->ID );
		}
		if ( '' === trim( $text ) ) {
			$text = (string) $post->post_excerpt;
		}
		$text = Coywolf_SEO_Markdown_Source::decode_text( $text );
		return '' !== $text ? $this->trim_passage( $text ) : '';
	}

	/**
	 * Split text into sentences (best-effort; whitespace collapsed first).
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private function sentences( $text ) {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( '' === $text ) {
			return array();
		}
		$parts = preg_split( '/(?<=[.!?])\s+/u', $text );
		if ( ! is_array( $parts ) ) {
			return array( $text );
		}
		return array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) );
	}

	/**
	 * Case-insensitive substring test (multibyte-aware).
	 *
	 * @param string $haystack Subject.
	 * @param string $needle   Search term.
	 * @return bool
	 */
	private function contains_ci( $haystack, $needle ) {
		if ( '' === $needle ) {
			return false;
		}
		if ( function_exists( 'mb_stripos' ) ) {
			return false !== mb_stripos( $haystack, $needle );
		}
		return false !== stripos( $haystack, $needle );
	}

	/**
	 * Collapse whitespace and trim a passage to at most MAX_CHUNK_CHARS,
	 * backing off to the last word boundary and appending an ellipsis when cut.
	 *
	 * @param string $text Passage.
	 * @return string
	 */
	private function trim_passage( $text ) {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		if ( $len <= self::MAX_CHUNK_CHARS ) {
			return $text;
		}
		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_CHUNK_CHARS - 1 ) : substr( $text, 0, self::MAX_CHUNK_CHARS - 1 );
		$sp  = function_exists( 'mb_strrpos' ) ? mb_strrpos( $cut, ' ' ) : strrpos( $cut, ' ' );
		if ( false !== $sp && $sp > 0 ) {
			$cut = function_exists( 'mb_substr' ) ? mb_substr( $cut, 0, $sp ) : substr( $cut, 0, $sp );
		}
		return rtrim( $cut, " \t\n\r\0\x0B.,;:" ) . '…';
	}

	/**
	 * Keep at most the first N sentences of a description (1–3 per the spec).
	 *
	 * @param string $text          Description text.
	 * @param int    $max_sentences Max sentences.
	 * @return string
	 */
	private function trim_sentences( $text, $max_sentences ) {
		$sentences = $this->sentences( $text );
		if ( empty( $sentences ) ) {
			return trim( (string) $text );
		}
		return trim( implode( ' ', array_slice( $sentences, 0, max( 1, (int) $max_sentences ) ) ) );
	}

	// ---------------------------------------------------------------------
	// Serialization — JSON
	// ---------------------------------------------------------------------

	/**
	 * Serialize the root EntityMap JSON object.
	 *
	 * @param array $publisher Resolved publisher.
	 * @param array $entities  Entity objects.
	 * @return string JSON, or '' on encode failure.
	 */
	private function render_json( array $publisher, array $entities ) {
		$publisher_node = array(
			'name' => $publisher['name'],
			'url'  => $publisher['url'],
		);
		if ( ! empty( $publisher['same_as'] ) ) {
			// Spec publisher.sameAs is a single URI (MAY); use the first.
			$publisher_node['sameAs'] = $publisher['same_as'][0];
		}

		$data = array(
			'version'            => self::SPEC_VERSION,
			'schema'             => self::SPEC_URL,
			'publisher'          => $publisher_node,
			'generated'          => gmdate( 'c' ),
			// Automated output without human review MUST be generator-draft.
			'verificationStatus' => 'generator-draft',
			'entities'           => array_values( $entities ),
		);

		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		return is_string( $json ) ? $json . "\n" : '';
	}

	// ---------------------------------------------------------------------
	// Serialization — HTML companion
	// ---------------------------------------------------------------------

	/**
	 * Render the human/crawler-readable entitymap.html from the same model. It
	 * references the JSON, embeds per-entity JSON-LD, and renders each chunk with
	 * visible cite attribution. It carries NO noindex (it must be indexable).
	 *
	 * @param array $publisher Resolved publisher.
	 * @param array $entities  Entity objects.
	 * @return string
	 */
	private function render_html( array $publisher, array $entities ) {
		$json_url = $this->public_json_url();
		$title    = $publisher['name'] . ' — EntityMap';

		$h  = "<!DOCTYPE html>\n";
		$h .= '<html lang="' . esc_attr( $this->html_lang() ) . "\">\n";
		$h .= "<head>\n";
		$h .= "<meta charset=\"UTF-8\">\n";
		$h .= "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
		$h .= '<title>' . esc_html( $title ) . "</title>\n";
		$h .= '<link rel="alternate" type="application/json" href="' . esc_url( $json_url ) . "\">\n";
		$h .= "</head>\n";
		$h .= "<body>\n";
		$h .= '<h1>' . esc_html( $title ) . "</h1>\n";
		$h .= '<p>' . sprintf(
			/* translators: %s: the entitymap.json URL */
			esc_html__( 'An EntityMap v1.0 entity index for %1$s. Machine-readable source: %2$s', 'coywolf-seo' ),
			esc_html( $publisher['name'] ),
			'<a href="' . esc_url( $json_url ) . '">' . esc_html( $json_url ) . '</a>'
		) . "</p>\n";

		foreach ( $entities as $entity ) {
			$h .= $this->render_html_entity( $entity, $publisher['name'] );
		}

		$h .= "</body>\n</html>\n";
		return $h;
	}

	/**
	 * Render one entity section: heading, JSON-LD, and its cited chunks.
	 *
	 * @param array  $entity         Entity object.
	 * @param string $publisher_name Verbatim publisher name.
	 * @return string
	 */
	private function render_html_entity( array $entity, $publisher_name ) {
		$h  = '<section id="' . esc_attr( $entity['entityId'] ) . "\">\n";
		$h .= '<h2>' . esc_html( $entity['name'] ) . ' <small>(' . esc_html( $entity['@type'] ) . ")</small></h2>\n";
		$h .= '<p>' . esc_html( $entity['description'] ) . "</p>\n";
		if ( ! empty( $entity['sameAs'] ) ) {
			$h .= '<p><a href="' . esc_url( $entity['sameAs'] ) . '" rel="nofollow">' . esc_html( $entity['sameAs'] ) . "</a></p>\n";
		}

		// Per-entity JSON-LD. Encode WITHOUT unescaped slashes so a "</script>"
		// in any value cannot break out of the script element.
		$ld   = $this->entity_jsonld( $entity );
		$json = wp_json_encode( $ld, JSON_UNESCAPED_UNICODE );
		if ( is_string( $json ) ) {
			$h .= '<script type="application/ld+json">' . $json . "</script>\n";
		}

		foreach ( $entity['hasChunks'] as $chunk ) {
			$h .= $this->render_html_chunk( $chunk, $publisher_name );
		}

		// Relations render as internal anchors where targets exist; with no
		// relations generated in v1 this is intentionally a no-op.

		$h .= "</section>\n";
		return $h;
	}

	/**
	 * Render one chunk as a cited blockquote. The visible cite text is the
	 * attribution that survives plain-text ingestion, so it is required.
	 *
	 * @param array  $chunk          Chunk object.
	 * @param string $publisher_name Verbatim publisher name.
	 * @return string
	 */
	private function render_html_chunk( array $chunk, $publisher_name ) {
		// Separate the passage from its attribution with block children so the
		// passage's last word does not fuse to the page title in the browser or on
		// plain-text ingestion (e.g. "…search engine.My Page Title").
		return '<blockquote data-publisher="' . esc_attr( $publisher_name ) . '">'
			. '<p>' . esc_html( $chunk['text'] ) . '</p>'
			. '<footer><cite><a href="' . esc_url( $chunk['sourceUrl'] ) . '">' . esc_html( $chunk['pageTitle'] ) . '</a> — '
			. sprintf(
				/* translators: %s: the publisher / brand name */
				esc_html__( 'published by %s', 'coywolf-seo' ),
				esc_html( $publisher_name )
			)
			. "</cite></footer></blockquote>\n";
	}

	/**
	 * The JSON-LD node for an entity (mirrors the JSON entity, mapped to
	 * Schema.org-style keys for the embedded block).
	 *
	 * @param array $entity Entity object.
	 * @return array
	 */
	private function entity_jsonld( array $entity ) {
		$ld = array(
			'@context'    => 'https://schema.org',
			'@type'       => $this->schema_type_for( $entity['@type'] ),
			'@id'         => $entity['entityId'],
			'name'        => $entity['name'],
			'description' => $entity['description'],
		);
		if ( ! empty( $entity['sameAs'] ) ) {
			$ld['sameAs'] = $entity['sameAs'];
		}
		return $ld;
	}

	/**
	 * Map an EntityMap @type to the closest Schema.org type for the JSON-LD
	 * block (Concept has no Schema.org equivalent, so it becomes Thing).
	 *
	 * @param string $type EntityMap @type.
	 * @return string
	 */
	private function schema_type_for( $type ) {
		switch ( (string) $type ) {
			case 'Person':
				return 'Person';
			case 'Organization':
				return 'Organization';
			case 'Place':
				return 'Place';
			default:
				return 'Thing';
		}
	}

	/**
	 * The document language for the HTML companion.
	 *
	 * @return string
	 */
	private function html_lang() {
		$lang = (string) get_bloginfo( 'language' );
		return '' !== $lang ? $lang : 'en';
	}

	/**
	 * The public root URL of entitymap.json (subdirectory-install safe).
	 *
	 * @return string
	 */
	public function public_json_url() {
		return home_url( '/entitymap.json' );
	}

	/**
	 * The public root URL of entitymap.html.
	 *
	 * @return string
	 */
	public function public_html_url() {
		return home_url( '/entitymap.html' );
	}

	// ---------------------------------------------------------------------
	// Filesystem
	// ---------------------------------------------------------------------
}
