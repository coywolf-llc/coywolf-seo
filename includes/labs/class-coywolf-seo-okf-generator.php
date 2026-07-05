<?php
/**
 * Open Knowledge Format (OKF) bundle generator — Labs feature.
 *
 * Builds an OKF v0.1 conformant bundle of UTF-8 Markdown files from the site's
 * public, published content under wp-content/uploads/coywolf-okf/. The bundle's
 * value is the cross-link graph between articles, topics, authors, and the
 * AI-enriched about/mentions entities — not a copy of the page text.
 *
 * OKF v0.1 conformance rules honored here:
 *   - Every non-reserved .md carries parseable YAML frontmatter with a
 *     non-empty `type`.
 *   - `index.md` (directory listing) and `log.md` (change history) are
 *     reserved; only the bundle-root index.md carries frontmatter, and it
 *     declares okf_version: "0.1".
 *   - Cross-links are absolute URLs to the served bundle root (the site's
 *     preference over the spec's suggested bundle-relative links), e.g.
 *     https://example.com/okf/articles/foo.md.
 *
 * No outbound calls: the bundle is built entirely from data already stored on
 * the site (posts, terms, users, and the persisted entity meta).
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OKF bundle builder.
 */
final class Coywolf_SEO_OKF_Generator extends Coywolf_SEO_Labs_Bundle_Generator {

	/**
	 * Bundle directory name, under the uploads basedir.
	 */
	const DIR = 'coywolf-okf';

	/**
	 * OKF specification version this generator targets.
	 */
	const OKF_VERSION = '0.1';

	/**
	 * Absolute URL of the served bundle root, without a trailing slash, used to
	 * make every cross-link absolute (e.g. https://example.com/okf). Set per
	 * rebuild() so the generator stays decoupled from the endpoint's route.
	 *
	 * @var string
	 */
	private $base_url = '';

	/**
	 * Whether a generated bundle is present on disk.
	 *
	 * @return bool
	 */
	public function bundle_exists() {
		return is_readable( $this->bundle_path() . '/index.md' );
	}

	/**
	 * Rebuild the entire bundle from current site content.
	 *
	 * The directory is rebuilt from scratch (so deleted content disappears),
	 * but the reserved log.md history is preserved and prepended to.
	 *
	 * @param string $base_url Absolute URL of the served bundle root (e.g.
	 *                         https://example.com/okf/). Cross-links are made
	 *                         absolute against it; falls back to /okf/.
	 * @return array|WP_Error Summary array on success, WP_Error on failure.
	 */
	public function rebuild( $base_url = '' ) {
		$base_url       = '' !== (string) $base_url ? (string) $base_url : home_url( '/okf/' );
		$this->base_url = untrailingslashit( $base_url );

		$fs = $this->filesystem();
		if ( null === $fs ) {
			return new WP_Error( 'okf_fs', __( 'The filesystem is not writable, so the OKF bundle could not be generated.', 'coywolf-seo' ) );
		}

		$final = $this->bundle_path();

		// Preserve the change history across rebuilds (log.md is reserved).
		$prior_log = '';
		if ( is_readable( $final . '/log.md' ) ) {
			$prior_log = (string) $fs->get_contents( $final . '/log.md' );
		}

		// Build into a sibling temp tree and swap it in only after a fully
		// successful build, so a mid-build OOM/timeout leaves the previous bundle
		// serving instead of an empty directory that 404s every /okf/ request.
		$base = $final . '-new';
		if ( is_dir( $base ) ) {
			$fs->delete( $base, true ); // Clean up a prior aborted build.
		}
		if ( ! wp_mkdir_p( $base ) ) {
			return new WP_Error( 'okf_mkdir', __( 'The OKF bundle directory could not be created.', 'coywolf-seo' ) );
		}
		$this->write_file( $base . '/index.html', '' ); // Directory-listing guard.

		$model = $this->build_model();

		$counts = array(
			'posts'    => 0,
			'topics'   => count( $model['terms'] ),
			'authors'  => count( $model['authors'] ),
			'entities' => count( $model['entities'] ),
		);

		// Concept files, grouped per directory for the listings.
		$listings = array();

		foreach ( $model['posts'] as $post ) {
			$this->write_file( $base . '/' . $post['path'], $this->render_post( $post, $model ) );
			$listings[ $post['dir'] ][] = array(
				'title' => $post['title'],
				'path'  => $post['path'],
			);
			++$counts['posts'];
		}
		foreach ( $model['terms'] as $term ) {
			$this->write_file( $base . '/' . $term['path'], $this->render_term( $term, $model ) );
			$listings[ $term['dir'] ][] = array(
				'title' => $term['name'],
				'path'  => $term['path'],
			);
		}
		foreach ( $model['authors'] as $author ) {
			$this->write_file( $base . '/' . $author['path'], $this->render_author( $author, $model ) );
			$listings[ $author['dir'] ][] = array(
				'title' => $author['name'],
				'path'  => $author['path'],
			);
		}
		foreach ( $model['entities'] as $entity ) {
			$this->write_file( $base . '/' . $entity['path'], $this->render_entity( $entity, $model ) );
			$listings[ $entity['dir'] ][] = array(
				'title' => $entity['name'],
				'path'  => $entity['path'],
			);
		}

		// Per-directory index.md listings (reserved name: no frontmatter).
		foreach ( $listings as $dir => $items ) {
			$this->write_file( $base . '/' . $dir . '/index.md', $this->render_dir_index( $dir, $items ) );
		}

		// Root index.md (the only index.md permitted frontmatter; declares okf_version).
		$this->write_file( $base . '/index.md', $this->render_root_index( $model, $listings ) );

		// log.md change history (reserved): prepend today's rebuild entry.
		$this->write_file( $base . '/log.md', $this->render_log( $prior_log, $counts ) );

		// The new tree is complete — atomically swap it in: drop the old bundle,
		// then move the temp tree into place. Only now does bundle_exists() see the
		// fresh index.md.
		if ( is_dir( $final ) ) {
			$fs->delete( $final, true );
		}
		if ( ! $fs->move( $base, $final, true ) ) {
			$fs->delete( $base, true );
			return new WP_Error( 'okf_swap', __( 'The OKF bundle could not be finalised.', 'coywolf-seo' ) );
		}

		return array(
			'counts' => $counts,
			'time'   => gmdate( 'c' ),
		);
	}

	/**
	 * Build a downloadable zip of the current bundle at the given path.
	 *
	 * @param string $dest_path Absolute path the archive is written to.
	 * @return bool|WP_Error True on success, WP_Error otherwise.
	 */
	public function build_zip( $dest_path ) {
		if ( ! $this->bundle_exists() ) {
			return new WP_Error( 'okf_no_bundle', __( 'There is no generated bundle to download yet. Rebuild it first.', 'coywolf-seo' ) );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'okf_no_zip', __( 'The PHP Zip extension is not available on this server.', 'coywolf-seo' ) );
		}
		$base = $this->bundle_path();
		$zip  = new ZipArchive();
		if ( true !== $zip->open( $dest_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'okf_zip_open', __( 'The download archive could not be created.', 'coywolf-seo' ) );
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $iterator as $file ) {
			$abs = $file->getPathname();
			$rel = ltrim( substr( $abs, strlen( $base ) ), '/\\' );
			if ( 'index.html' === $rel ) {
				continue; // The listing guard does not belong in the distribution.
			}
			$zip->addFile( $abs, self::DIR . '/' . str_replace( '\\', '/', $rel ) );
		}
		$zip->close();
		return true;
	}

	/**
	 * Build a downloadable gzipped tarball (.tar.gz) of the current bundle — the
	 * archive form the OKF spec names for distribution ("tarballs or zip
	 * archives of the directory"). The single index.html listing guard is
	 * excluded, exactly as in build_zip().
	 *
	 * @param string $dest_path Absolute path the .tar.gz is written to.
	 * @return bool|WP_Error True on success, WP_Error otherwise.
	 */
	public function build_tar_gz( $dest_path ) {
		if ( ! $this->bundle_exists() ) {
			return new WP_Error( 'okf_no_bundle', __( 'There is no generated bundle to download yet. Rebuild it first.', 'coywolf-seo' ) );
		}
		if ( ! class_exists( 'PharData' ) ) {
			return new WP_Error( 'okf_no_phar', __( 'The PHP Phar extension is not available on this server, so .tar.gz downloads are disabled.', 'coywolf-seo' ) );
		}

		$base = $this->bundle_path();
		// PharData writes a .tar and compress() emits a sibling .tar.gz; work
		// alongside the requested destination, then move the result onto it.
		$tar_path = $dest_path . '.tar';
		$gz_path  = $tar_path . '.gz';
		if ( file_exists( $tar_path ) ) {
			wp_delete_file( $tar_path );
		}
		if ( file_exists( $gz_path ) ) {
			wp_delete_file( $gz_path );
		}

		try {
			$phar     = new PharData( $tar_path, 0, null, Phar::TAR );
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iterator as $file ) {
				$abs = $file->getPathname();
				$rel = ltrim( substr( $abs, strlen( $base ) ), '/\\' );
				if ( 'index.html' === $rel ) {
					continue; // The listing guard does not belong in the distribution.
				}
				$phar->addFile( $abs, self::DIR . '/' . str_replace( '\\', '/', $rel ) );
			}
			$phar->compress( Phar::GZ ); // Emits $gz_path.
			unset( $phar ); // Release the handle before moving / deleting.
		} catch ( Exception $e ) {
			if ( file_exists( $tar_path ) ) {
				wp_delete_file( $tar_path );
			}
			if ( file_exists( $gz_path ) ) {
				wp_delete_file( $gz_path );
			}
			return new WP_Error( 'okf_tar_open', __( 'The download archive could not be created.', 'coywolf-seo' ) );
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		$ok = $wp_filesystem && $wp_filesystem->exists( $gz_path )
			? (bool) $wp_filesystem->move( $gz_path, $dest_path, true )
			: false;

		if ( file_exists( $tar_path ) ) {
			wp_delete_file( $tar_path );
		}
		if ( ! $ok && file_exists( $gz_path ) ) {
			wp_delete_file( $gz_path );
		}

		return $ok ? true : new WP_Error( 'okf_tar_move', __( 'The download archive could not be finalised.', 'coywolf-seo' ) );
	}

	// ---------------------------------------------------------------------
	// Model
	// ---------------------------------------------------------------------

	/**
	 * Gather every concept and assign each its bundle path, so the second pass
	 * (rendering) can resolve cross-links. Only public, published content is
	 * included; entities, topics, and authors are derived from the included
	 * posts so the graph stays internally consistent.
	 *
	 * @return array Model: site, posts, terms, authors, entities.
	 */
	private function build_model() {
		$model = array(
			'site'     => array(
				'title' => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			),
			'posts'    => array(),
			'terms'    => array(),
			'authors'  => array(),
			'entities' => array(),
		);

		$used = array(); // Per-directory slug uniqueness: $used[ dir ][ slug ] = true.

		$post_types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $post_types as $post_type ) {
			if ( 'attachment' === $post_type || ! is_post_type_viewable( $post_type ) ) {
				continue;
			}
			list( $dir, $type_label ) = $this->post_type_dir_and_label( $post_type );

			// Page the query so peak memory does not scale with total site size:
			// hydrate one batch of full posts at a time and drop the object caches
			// each batch so a large site cannot OOM the cron rebuild.
			$paged = 1;
			do {
				$posts = get_posts(
					array(
						'post_type'              => $post_type,
						'post_status'            => 'publish',
						'has_password'           => false,
						'orderby'                => 'date',
						'order'                  => 'DESC',
						'suppress_filters'       => true,
						'posts_per_page'         => 200,
						'paged'                  => $paged,
						'no_found_rows'          => true,
						'update_post_term_cache' => false,
					)
				);

				foreach ( $posts as $post ) {
					if ( ! $this->is_post_visible( $post ) ) {
						continue;
					}
					$slug = $this->unique_slug( $used, $dir, $post->post_name ? $post->post_name : sanitize_title( $post->post_title ), (string) $post->ID );

					$entities = Coywolf_SEO_AI::stored_entities( $post->ID );

					$model['posts'][ $post->ID ] = array(
						'id'          => $post->ID,
						'title'       => get_the_title( $post ),
						'description' => $this->post_description( $post ),
						'resource'    => (string) get_permalink( $post ),
						'timestamp'   => (string) get_post_modified_time( 'c', true, $post ),
						'type_label'  => $type_label,
						'dir'         => $dir,
						'slug'        => $slug,
						'path'        => $dir . '/' . $slug . '.md',
						'author_id'   => (int) $post->post_author,
						'term_keys'   => array(),
						'entities'    => $entities,
						'tags'        => array(),
					);

					// Authors derived from included posts (users with published content).
					$this->register_author( $model, $used, (int) $post->post_author );

					// Topics: the post's terms in public taxonomies.
					$this->register_post_terms( $model, $used, $post );

					// Entities: dedupe by Wikidata QID, record about/mentions back-links.
					foreach ( $entities as $entity ) {
						$this->register_entity( $model, $used, $entity, $post->ID );
					}
				}
				$batch = count( $posts );
				// Release this batch's full WP_Post objects + primed caches so the
				// non-persistent object cache does not accumulate every post.
				foreach ( $posts as $post ) {
					clean_post_cache( $post->ID );
				}
				++$paged;
			} while ( 200 === $batch );
		}

		return $model;
	}

	/**
	 * Resolve a public post type to its bundle directory and OKF `type` label.
	 *
	 * @param string $post_type Post type name.
	 * @return array { string $dir, string $type_label }
	 */
	private function post_type_dir_and_label( $post_type ) {
		if ( 'post' === $post_type ) {
			return array( 'articles', 'Article' );
		}
		if ( 'page' === $post_type ) {
			return array( 'pages', 'Page' );
		}
		$obj   = get_post_type_object( $post_type );
		$label = ( $obj && ! empty( $obj->labels->singular_name ) ) ? $obj->labels->singular_name : $post_type;
		return array( sanitize_title( $post_type ), $label );
	}

	/**
	 * A non-fabricated description: the manual excerpt only. Never the trimmed
	 * post body (the bundle is a link graph, not a content copy), so posts
	 * without a manual excerpt simply omit the field.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function post_description( $post ) {
		return trim( (string) $post->post_excerpt );
	}

	/**
	 * Register an author concept (once) from a post's author id.
	 *
	 * @param array $model Model (by reference).
	 * @param array $used  Slug-uniqueness map (by reference).
	 * @param int   $user_id User id.
	 * @return void
	 */
	private function register_author( array &$model, array &$used, $user_id ) {
		if ( $user_id <= 0 || isset( $model['authors'][ $user_id ] ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$slug = $this->unique_slug( $used, 'authors', $user->user_nicename ? $user->user_nicename : sanitize_title( $user->display_name ), (string) $user_id );

		$model['authors'][ $user_id ] = array(
			'id'       => $user_id,
			'name'     => $user->display_name,
			'resource' => (string) get_author_posts_url( $user_id ),
			'dir'      => 'authors',
			'slug'     => $slug,
			'path'     => 'authors/' . $slug . '.md',
			'same_as'  => $this->author_same_as( $user_id ),
		);
	}

	/**
	 * The sameAs URLs saved for an author (Authors page Person `sameAs` rows),
	 * read through the plugin's own options accessor.
	 *
	 * @param int $user_id User id.
	 * @return string[]
	 */
	private function author_same_as( $user_id ) {
		$rows = Coywolf_SEO_Options::author_rows( $user_id );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['prop'], $row['value'] ) && 'sameAs' === $row['prop'] && ! is_array( $row['value'] ) && '' !== $row['value'] ) {
				$out[] = (string) $row['value'];
			}
		}
		return $out;
	}

	/**
	 * Register a post's public-taxonomy terms as topic concepts and link the
	 * post into each.
	 *
	 * @param array   $model Model (by reference).
	 * @param array   $used  Slug-uniqueness map (by reference).
	 * @param WP_Post $post  Post object.
	 * @return void
	 */
	private function register_post_terms( array &$model, array &$used, $post ) {
		$taxes = get_object_taxonomies( $post->post_type, 'objects' );
		foreach ( $taxes as $tax ) {
			if ( empty( $tax->public ) || ! is_taxonomy_viewable( $tax ) ) {
				continue;
			}
			$terms = get_the_terms( $post->ID, $tax->name );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			$type_label = ! empty( $tax->labels->singular_name ) ? $tax->labels->singular_name : 'Topic';
			foreach ( $terms as $term ) {
				$key = $tax->name . ':' . $term->term_id;
				if ( ! isset( $model['terms'][ $key ] ) ) {
					$slug                   = $this->unique_slug( $used, 'topics', $term->slug ? $term->slug : sanitize_title( $term->name ), (string) $term->term_id );
					$model['terms'][ $key ] = array(
						'id'         => (int) $term->term_id,
						'taxonomy'   => $tax->name,
						'name'       => $term->name,
						'resource'   => (string) get_term_link( $term ),
						'type_label' => $type_label,
						'dir'        => 'topics',
						'slug'       => $slug,
						'path'       => 'topics/' . $slug . '.md',
						'post_ids'   => array(),
					);
				}
				$model['terms'][ $key ]['post_ids'][]       = (int) $post->ID;
				$model['posts'][ $post->ID ]['term_keys'][] = $key;
				$model['posts'][ $post->ID ]['tags'][]      = $term->name;
			}
		}
	}

	/**
	 * Register an enriched entity (deduped by QID) and record its about /
	 * mentions back-link to the given post.
	 *
	 * @param array $model   Model (by reference).
	 * @param array $used    Slug-uniqueness map (by reference).
	 * @param array $entity  Normalized entity from Coywolf_SEO_AI::stored_entities().
	 * @param int   $post_id Post the entity was found on.
	 * @return void
	 */
	private function register_entity( array &$model, array &$used, array $entity, $post_id ) {
		$qid = $entity['qid'];
		if ( '' === $qid ) {
			return;
		}
		if ( ! isset( $model['entities'][ $qid ] ) ) {
			$slug                      = $this->unique_slug( $used, 'entities', sanitize_title( $entity['name'] ), strtolower( $qid ) );
			$model['entities'][ $qid ] = array(
				'qid'              => $qid,
				'name'             => $entity['name'],
				'type_label'       => '' !== $entity['type'] ? $entity['type'] : 'Entity',
				'description'      => $entity['description'],
				'same_as'          => $entity['same_as'],
				'dir'              => 'entities',
				'slug'             => $slug,
				'path'             => 'entities/' . $slug . '.md',
				'about_post_ids'   => array(),
				'mention_post_ids' => array(),
			);
		}
		if ( 'about' === $entity['bucket'] ) {
			$model['entities'][ $qid ]['about_post_ids'][] = (int) $post_id;
		} else {
			$model['entities'][ $qid ]['mention_post_ids'][] = (int) $post_id;
		}
	}

	/**
	 * Reserve a unique slug within a bundle directory, appending a stable
	 * suffix (the source id) on collision so links never clash.
	 *
	 * @param array  $used   Slug-uniqueness map (by reference).
	 * @param string $dir    Directory key.
	 * @param string $slug   Candidate slug.
	 * @param string $suffix Stable disambiguator (post/term/user id, or QID).
	 * @return string
	 */
	private function unique_slug( array &$used, $dir, $slug, $suffix ) {
		$slug = $slug ? $slug : 'item';
		if ( ! isset( $used[ $dir ] ) ) {
			$used[ $dir ] = array();
		}
		if ( isset( $used[ $dir ][ $slug ] ) ) {
			$slug .= '-' . sanitize_title( $suffix );
		}
		// Final guard against an empty/duplicate suffixed slug.
		$candidate = $slug;
		$i         = 2;
		while ( isset( $used[ $dir ][ $candidate ] ) ) {
			$candidate = $slug . '-' . $i;
			++$i;
		}
		$used[ $dir ][ $candidate ] = true;
		return $candidate;
	}

	// ---------------------------------------------------------------------
	// Rendering
	// ---------------------------------------------------------------------

	/**
	 * Render an article/page/CPT concept file.
	 *
	 * @param array $post  Post model entry.
	 * @param array $model Full model.
	 * @return string
	 */
	private function render_post( array $post, array $model ) {
		$front = array(
			'type'        => $post['type_label'],
			'title'       => $post['title'],
			'description' => $post['description'],
			'resource'    => $post['resource'],
			'tags'        => array_values( array_unique( $post['tags'] ) ),
			'timestamp'   => $post['timestamp'],
		);

		$body = '# ' . $this->md_text( $post['title'] ) . "\n";

		if ( isset( $model['authors'][ $post['author_id'] ] ) ) {
			$author = $model['authors'][ $post['author_id'] ];
			$body  .= "\n## Author\n\n" . $this->link_line( $author['path'], $author['name'] );
		}

		if ( ! empty( $post['term_keys'] ) ) {
			$lines = '';
			foreach ( $post['term_keys'] as $key ) {
				if ( isset( $model['terms'][ $key ] ) ) {
					$lines .= $this->link_line( $model['terms'][ $key ]['path'], $model['terms'][ $key ]['name'] );
				}
			}
			if ( '' !== $lines ) {
				$body .= "\n## Topics\n\n" . $lines;
			}
		}

		// about / mentions distinction expressed through section headings.
		$about    = '';
		$mentions = '';
		foreach ( $post['entities'] as $entity ) {
			if ( ! isset( $model['entities'][ $entity['qid'] ] ) ) {
				continue;
			}
			$node = $model['entities'][ $entity['qid'] ];
			$line = $this->link_line( $node['path'], $node['name'], $entity['description'] );
			if ( 'about' === $entity['bucket'] ) {
				$about .= $line;
			} else {
				$mentions .= $line;
			}
		}
		if ( '' !== $about ) {
			$body .= "\n## About\n\nPrimary subjects of this article:\n\n" . $about;
		}
		if ( '' !== $mentions ) {
			$body .= "\n## Also mentions\n\nEntities referenced in passing:\n\n" . $mentions;
		}

		return $this->frontmatter( $front ) . "\n" . $body;
	}

	/**
	 * Render a topic (taxonomy term) concept file.
	 *
	 * @param array $term  Term model entry.
	 * @param array $model Full model.
	 * @return string
	 */
	private function render_term( array $term, array $model ) {
		$front = array(
			'type'     => $term['type_label'],
			'title'    => $term['name'],
			'resource' => $term['resource'],
		);
		$body  = '# ' . $this->md_text( $term['name'] ) . "\n\n## Articles\n\n";
		$body .= $this->post_links( $term['post_ids'], $model );
		return $this->frontmatter( $front ) . "\n" . $body;
	}

	/**
	 * Render an author concept file.
	 *
	 * @param array $author Author model entry.
	 * @param array $model  Full model.
	 * @return string
	 */
	private function render_author( array $author, array $model ) {
		$front = array(
			'type'     => 'Author',
			'title'    => $author['name'],
			'resource' => $author['resource'],
		);
		$body  = '# ' . $this->md_text( $author['name'] ) . "\n\n## Articles\n\n";

		$post_ids = array();
		foreach ( $model['posts'] as $post ) {
			if ( $post['author_id'] === $author['id'] ) {
				$post_ids[] = $post['id'];
			}
		}
		$body .= $this->post_links( $post_ids, $model );

		if ( ! empty( $author['same_as'] ) ) {
			$body .= "\n## Citations\n\n" . $this->citation_lines( $author['same_as'] );
		}
		return $this->frontmatter( $front ) . "\n" . $body;
	}

	/**
	 * Render an enriched-entity concept file: the hub node that lists every
	 * article it is the subject of or mentioned in, plus its stable external
	 * identifiers (Wikidata QID / Wikipedia) as citations.
	 *
	 * @param array $entity Entity model entry.
	 * @param array $model  Full model.
	 * @return string
	 */
	private function render_entity( array $entity, array $model ) {
		$front = array(
			'type'        => $entity['type_label'],
			'title'       => $entity['name'],
			'description' => $entity['description'],
			// The primary stable external identifier (Wikidata QID URL).
			'resource'    => isset( $entity['same_as'][0] ) ? $entity['same_as'][0] : '',
		);

		$body = '# ' . $this->md_text( $entity['name'] ) . "\n";
		if ( '' !== $entity['description'] ) {
			$body .= "\n" . $this->md_text( $entity['description'] ) . "\n";
		}
		if ( ! empty( $entity['about_post_ids'] ) ) {
			$body .= "\n## Subject of\n\n" . $this->post_links( $entity['about_post_ids'], $model );
		}
		if ( ! empty( $entity['mention_post_ids'] ) ) {
			$body .= "\n## Mentioned in\n\n" . $this->post_links( $entity['mention_post_ids'], $model );
		}
		$body .= "\n## Citations\n\n" . $this->citation_lines( $entity['same_as'] );

		return $this->frontmatter( $front ) . "\n" . $body;
	}

	/**
	 * Render a per-directory index.md listing (reserved name: no frontmatter).
	 *
	 * @param string $dir   Directory key.
	 * @param array  $items Each: array( title, path ).
	 * @return string
	 */
	private function render_dir_index( $dir, array $items ) {
		$heading = ucfirst( $dir );
		usort(
			$items,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a['title'], (string) $b['title'] );
			}
		);
		$body = '# ' . $this->md_text( $heading ) . "\n\n";
		foreach ( $items as $item ) {
			$body .= $this->link_line( $item['path'], $item['title'] );
		}
		return $body;
	}

	/**
	 * Render the bundle-root index.md — the only index.md that carries
	 * frontmatter, and the one that declares okf_version.
	 *
	 * @param array $model    Full model.
	 * @param array $listings Per-directory listings (dir => items).
	 * @return string
	 */
	private function render_root_index( array $model, array $listings ) {
		$front = array(
			'okf_version' => self::OKF_VERSION,
			'title'       => $model['site']['title'],
			'resource'    => $model['site']['url'],
			'timestamp'   => gmdate( 'c' ),
		);

		$body  = '# ' . $this->md_text( $model['site']['title'] ) . " — Knowledge Bundle\n\n";
		$body .= "An [Open Knowledge Format](https://github.com/GoogleCloudPlatform/knowledge-catalog/tree/main/okf) bundle of this site's public content, generated by Coywolf SEO. Concepts cross-link into a navigable graph; entities are grounded to stable external identifiers.\n\n";
		$body .= "## Contents\n\n";

		$labels = array(
			'articles' => 'Articles',
			'pages'    => 'Pages',
			'topics'   => 'Topics',
			'authors'  => 'Authors',
			'entities' => 'Entities',
		);
		// Known directories first, then any custom-post-type directories.
		$dirs = array_keys( $listings );
		usort(
			$dirs,
			static function ( $a, $b ) use ( $labels ) {
				$order = array_keys( $labels );
				$ia    = array_search( $a, $order, true );
				$ib    = array_search( $b, $order, true );
				$ia    = false === $ia ? 99 : $ia;
				$ib    = false === $ib ? 99 : $ib;
				if ( $ia === $ib ) {
					return strcmp( $a, $b );
				}
				return $ia - $ib;
			}
		);
		foreach ( $dirs as $dir ) {
			$label = isset( $labels[ $dir ] ) ? $labels[ $dir ] : ucfirst( $dir );
			$count = count( $listings[ $dir ] );
			$body .= '- [' . $this->md_text( $label ) . '](' . $this->abs( $dir . '/index.md' ) . ') (' . (int) $count . ")\n";
		}
		$body .= "\nSee [the change log](" . $this->abs( 'log.md' ) . ") for generation history.\n";

		return $this->frontmatter( $front ) . "\n" . $body;
	}

	/**
	 * Render (and prepend to) the reserved log.md change history.
	 *
	 * @param string $prior  Existing log.md content (may be empty).
	 * @param array  $counts Rebuild counts.
	 * @return string
	 */
	private function render_log( $prior, array $counts ) {
		$today = gmdate( 'Y-m-d' );
		$entry = sprintf(
			'- Rebuilt bundle: %1$d articles/pages, %2$d topics, %3$d authors, %4$d entities.',
			(int) $counts['posts'],
			(int) $counts['topics'],
			(int) $counts['authors'],
			(int) $counts['entities']
		);

		// Strip the title from prior content; keep only the dated history body.
		$history = trim( (string) preg_replace( '/^#\s+Change Log\s*\n+/', '', (string) $prior ) );

		if ( 0 === strpos( $history, '## ' . $today ) ) {
			// Append today's bullet under the existing most-recent heading.
			$history = preg_replace( '/^(## ' . preg_quote( $today, '/' ) . "\\n)/", '$1' . $entry . "\n", $history, 1 );
		} else {
			$history = '## ' . $today . "\n" . $entry . "\n" . ( '' !== $history ? "\n" . $history : '' );
		}

		// Cap the history so it cannot grow without bound.
		$lines = explode( "\n", $history );
		if ( count( $lines ) > 200 ) {
			$lines   = array_slice( $lines, 0, 200 );
			$history = rtrim( implode( "\n", $lines ) );
		}

		return "# Change Log\n\n" . rtrim( $history ) . "\n";
	}

	// ---------------------------------------------------------------------
	// Rendering helpers
	// ---------------------------------------------------------------------

	/**
	 * Markdown bullet list of links to the given posts.
	 *
	 * @param int[] $post_ids Post ids.
	 * @param array $model    Full model.
	 * @return string
	 */
	private function post_links( array $post_ids, array $model ) {
		$out  = '';
		$seen = array();
		foreach ( $post_ids as $id ) {
			if ( isset( $seen[ $id ] ) || ! isset( $model['posts'][ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out        .= $this->link_line( $model['posts'][ $id ]['path'], $model['posts'][ $id ]['title'] );
		}
		return '' !== $out ? $out : "_None._\n";
	}

	/**
	 * Markdown bullet list of external citation links, labeled by source.
	 *
	 * @param string[] $urls Citation URLs.
	 * @return string
	 */
	private function citation_lines( array $urls ) {
		$out = '';
		foreach ( $urls as $url ) {
			$url = esc_url_raw( (string) $url );
			if ( '' === $url ) {
				continue;
			}
			if ( false !== strpos( $url, 'wikidata.org' ) ) {
				$label = 'Wikidata';
			} elseif ( false !== strpos( $url, 'wikipedia.org' ) ) {
				$label = 'Wikipedia';
			} else {
				$host  = wp_parse_url( $url, PHP_URL_HOST );
				$label = $host ? $host : $url;
			}
			$out .= '- [' . $this->md_text( $label ) . '](' . $url . ")\n";
		}
		return '' !== $out ? $out : "_None._\n";
	}

	/**
	 * A single Markdown link bullet to a concept, optionally with a trailing
	 * description. The target is an absolute URL to the served bundle.
	 *
	 * @param string $path Bundle-relative concept path (e.g. articles/foo.md).
	 * @param string $text Link text.
	 * @param string $desc Optional trailing description.
	 * @return string
	 */
	private function link_line( $path, $text, $desc = '' ) {
		$line = '- [' . $this->md_text( $text ) . '](' . $this->abs( $path ) . ')';
		$desc = $this->md_text( $desc );
		if ( '' !== $desc ) {
			$line .= ' — ' . $desc;
		}
		return $line . "\n";
	}

	/**
	 * Absolute URL for a bundle-relative concept path (e.g. articles/foo.md ->
	 * https://example.com/okf/articles/foo.md).
	 *
	 * @param string $path Bundle-relative path.
	 * @return string
	 */
	private function abs( $path ) {
		return $this->base_url . '/' . ltrim( (string) $path, '/' );
	}

	/**
	 * Emit a YAML frontmatter block. String values are double-quoted and
	 * escaped; empty values are omitted; array values become YAML sequences.
	 *
	 * @param array $fields Ordered field => value.
	 * @return string
	 */
	private function frontmatter( array $fields ) {
		$lines = array( '---' );
		foreach ( $fields as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = array_values(
					array_filter(
						array_map( 'strval', $value ),
						static function ( $v ) {
							return '' !== trim( $v );
						}
					)
				);
				if ( empty( $value ) ) {
					continue;
				}
				$lines[] = $key . ':';
				foreach ( $value as $item ) {
					$lines[] = '  - ' . $this->yaml_scalar( $item );
				}
				continue;
			}
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			$lines[] = $key . ': ' . $this->yaml_scalar( $value );
		}
		$lines[] = '---';
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Quote and escape a scalar for a YAML double-quoted string.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function yaml_scalar( $value ) {
		$value = (string) $value;
		$value = str_replace( array( "\r\n", "\r", "\n" ), ' ', $value );
		$value = str_replace( '\\', '\\\\', $value );
		$value = str_replace( '"', '\\"', $value );
		return '"' . $value . '"';
	}

	/**
	 * Flatten text to a single line and escape Markdown link-breaking
	 * characters, for safe use as link text or a heading.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function md_text( $text ) {
		$text = (string) $text;
		$text = str_replace( array( "\r\n", "\r", "\n" ), ' ', $text );
		$text = str_replace( array( '[', ']' ), array( '\[', '\]' ), $text );
		return trim( $text );
	}
}
