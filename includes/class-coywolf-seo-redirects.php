<?php
/**
 * Redirect manager for Coywolf SEO: storage, the front-end matching
 * engine, deleted-content tracking, and the 404 log.
 *
 * Three tables, created on activation:
 * - {prefix}coywolf_seo_redirects — the rules: exact rules match on a
 *   normalized lowercase path (indexed), regex rules are evaluated in
 *   order; each rule carries a query mode (ignore / exact / pass), an
 *   HTTP action (301, 302, 307, 308, 410), an enabled flag, and hit
 *   counts.
 * - {prefix}coywolf_seo_deleted — published posts/pages that were trashed
 *   or deleted, awaiting a decision: serve 410, redirect somewhere, or
 *   dismiss.
 *
 * Matching runs at template_redirect priority 0 — before any other
 * output. Slug changes
 * are deliberately not tracked: WordPress core already 301s old post
 * slugs via its own old-slug redirect.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirects: data, engine, capture.
 */
final class Coywolf_SEO_Redirects {

	/**
	 * Menu slug for the Redirects page.
	 */
	const SLUG = 'coywolf-seo-redirects';

	/**
	 * Supported HTTP actions.
	 *
	 * @return array Code => label.
	 */
	public static function types() {
		return array(
			301 => __( '301 — moved permanently', 'coywolf-seo' ),
			302 => __( '302 — found (temporary)', 'coywolf-seo' ),
			307 => __( '307 — temporary (method preserved)', 'coywolf-seo' ),
			308 => __( '308 — permanent (method preserved)', 'coywolf-seo' ),
			410 => __( '410 — gone', 'coywolf-seo' ),
		);
	}

	/**
	 * Current database schema version.
	 */
	const DB_VERSION = 2;

	/**
	 * Hook everything up.
	 */
	public function init() {
		// Plugin updates don't re-run activation: create the tables when
		// an existing install first loads this version.
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );

		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 0 );

		add_action( 'wp_trash_post', array( $this, 'capture_removed_post' ) );
		add_action( 'before_delete_post', array( $this, 'capture_removed_post' ) );
		add_action( 'untrashed_post', array( $this, 'forget_removed_post' ) );

	}

	/**
	 * Table names.
	 *
	 * @param string $which rules | deleted.
	 * @return string
	 */
	public static function table( $which ) {
		global $wpdb;
		$map = array(
			'rules'   => $wpdb->prefix . 'coywolf_seo_redirects',
			'deleted' => $wpdb->prefix . 'coywolf_seo_deleted',
		);
		return $map[ $which ];
	}

	/**
	 * Create the tables on update if activation never ran for this version.
	 */
	public function maybe_upgrade() {
		$installed = (int) get_option( 'coywolf_seo_db_version', 0 );
		if ( $installed >= self::DB_VERSION ) {
			return;
		}
		if ( 1 === $installed ) {
			// v2 removed the 404 log: drop its table and stop its cron.
			global $wpdb;
			$log_table = $wpdb->prefix . 'coywolf_seo_404s';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name built from $wpdb->prefix; feature removal.
			$wpdb->query( "DROP TABLE IF EXISTS `{$log_table}`" );
			wp_unschedule_hook( 'coywolf_seo_redirects_prune' );
		}
		self::install();
	}

	/**
	 * Create/upgrade the tables (activation and version upgrades).
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::table( 'rules' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source varchar(2000) NOT NULL,
				match_path varchar(191) NOT NULL DEFAULT '',
				target text NOT NULL,
				type smallint unsigned NOT NULL DEFAULT 301,
				is_regex tinyint(1) NOT NULL DEFAULT 0,
				query_mode varchar(10) NOT NULL DEFAULT 'pass',
				enabled tinyint(1) NOT NULL DEFAULT 1,
				hits bigint(20) unsigned NOT NULL DEFAULT 0,
				last_hit datetime DEFAULT NULL,
				note varchar(255) NOT NULL DEFAULT '',
				created datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY match_path (match_path),
				KEY enabled (enabled)
			) $charset;"
		);


		dbDelta(
			'CREATE TABLE ' . self::table( 'deleted' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				post_title varchar(255) NOT NULL DEFAULT '',
				path varchar(191) NOT NULL,
				deleted datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY path (path)
			) $charset;"
		);

		update_option( 'coywolf_seo_db_version', self::DB_VERSION );
	}

	/**
	 * How many deleted pages await a decision (the menu bubble).
	 *
	 * @return int
	 */
	public function pending_count() {
		if ( (int) get_option( 'coywolf_seo_db_version', 0 ) < self::DB_VERSION ) {
			return 0; // Tables not created yet.
		}
		global $wpdb;
		$deleted_table = self::table( 'deleted' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- tiny count for the admin menu.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$deleted_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name built from $wpdb->prefix, no user input.
	}

	/**
	 * Normalize a URL or path into the canonical matching form:
	 * site-relative path, URL-decoded, lowercased, no trailing slash
	 * (the root stays '/'), query string split off.
	 *
	 * @param string $url URL or path, with or without host.
	 * @return array { path: string, query: string }
	 */
	public static function normalize( $url ) {
		$url   = trim( (string) $url );
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? $parts['query'] : '';

		// Make site-relative for subdirectory installs.
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '/' !== $home_path && 0 === stripos( $path, $home_path ) ) {
			$path = '/' . ltrim( substr( $path, strlen( $home_path ) ), '/' );
		}

		$path = rawurldecode( $path );
		$path = strtolower( $path );
		if ( '' === $path ) {
			$path = '/';
		}
		if ( '/' !== $path ) {
			$path = '/' . trim( $path, '/' );
		}

		// Query-parameter order should not affect matching.
		if ( '' !== $query ) {
			parse_str( $query, $params );
			ksort( $params );
			$query = http_build_query( $params );
		}

		return array(
			'path'  => $path,
			'query' => $query,
		);
	}

	/**
	 * The current request, normalized.
	 *
	 * @return array { path, query, raw }
	 */
	private function current_request() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- raw URI is parsed and normalized, never output.
		$uri        = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$normalized = self::normalize( $uri );

		$normalized['raw'] = $uri;
		return $normalized;
	}

	/**
	 * Match and perform a redirect/410 for the current request.
	 */
	public function maybe_redirect() {
		if ( is_admin() ) {
			return;
		}
		// When the Redirects feature is turned off, the engine does nothing so
		// another redirect plugin can handle the request.
		if ( ! Coywolf_SEO_Options::feature_enabled( 'redirects' ) ) {
			return;
		}
		// Never die() inside CLI/cron bootstraps, and leave a lockout
		// escape hatch: define COYWOLF_SEO_DISABLE_REDIRECTS in
		// wp-config.php to switch the engine off entirely.
		if ( 'cli' === PHP_SAPI || ( defined( 'COYWOLF_SEO_DISABLE_REDIRECTS' ) && COYWOLF_SEO_DISABLE_REDIRECTS ) ) {
			return;
		}
		$request = $this->current_request();
		$rule    = $this->match( $request['path'], $request['query'] );
		if ( ! $rule ) {
			return;
		}

		$this->record_hit( (int) $rule->id );

		if ( 410 === (int) $rule->type ) {
			$this->serve_410();
		}

		$target = $this->build_target( $rule, $request );
		if ( '' === $target || $this->is_current_url( $target, $request ) ) {
			return; // Never loop onto ourselves.
		}

		nocache_headers();
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- targets are admin-defined and validated on save; external hosts are the point.
		wp_redirect( $target, (int) $rule->type, 'Coywolf SEO' );
		exit;
	}

	/**
	 * Find the first matching enabled rule: exact path matches first
	 * (exact-query rules before query-agnostic ones), then regex rules in
	 * creation order.
	 *
	 * @param string $path  Normalized request path.
	 * @param string $query Normalized request query.
	 * @return object|null Rule row.
	 */
	public function match( $path, $query ) {
		global $wpdb;
		$rules_table = self::table( 'rules' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- purpose-built lookup table with its own index.
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$rules_table} WHERE enabled = 1 AND is_regex = 0 AND match_path = %s ORDER BY (query_mode = 'exact') DESC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from $wpdb->prefix.
				$path
			)
		);
		foreach ( (array) $candidates as $rule ) {
			if ( 'exact' === $rule->query_mode ) {
				$source = self::normalize( $rule->source );
				if ( $source['query'] === $query ) {
					return $rule;
				}
				continue;
			}
			return $rule; // ignore / pass match on path alone.
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- regex rules must be evaluated in PHP.
		$regex_rules = $wpdb->get_results(
			"SELECT * FROM {$rules_table} WHERE enabled = 1 AND is_regex = 1 ORDER BY id ASC" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name built from $wpdb->prefix, no user input.
		);
		$subject     = $path . ( '' !== $query ? '?' . $query : '' );
		foreach ( (array) $regex_rules as $rule ) {
			if ( @preg_match( self::regex_pattern( $rule->source ), $subject ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a bad admin-entered pattern must not raise warnings on the front end.
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Wrap an admin-entered pattern in delimiters. Patterns are written
	 * without delimiters; matching is case-insensitive (the subject is
	 * normalized to lowercase) and dot-matches-all.
	 *
	 * @param string $source Pattern as entered.
	 * @return string
	 */
	public static function regex_pattern( $source ) {
		return '#' . str_replace( '#', '\#', (string) $source ) . '#is';
	}

	/**
	 * Build the destination URL for a matched rule.
	 *
	 * @param object $rule    Rule row.
	 * @param array  $request Normalized request.
	 * @return string Absolute or site-relative URL; '' to abort.
	 */
	private function build_target( $rule, array $request ) {
		$target = (string) $rule->target;

		if ( $rule->is_regex ) {
			// The regex sees the whole path+query; query modes don't apply.
			$subject = $request['path'] . ( '' !== $request['query'] ? '?' . $request['query'] : '' );
			$built   = @preg_replace( self::regex_pattern( $rule->source ), $target, $subject, 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- bad patterns abort quietly.
			if ( null === $built ) {
				return '';
			}
			$target = $built;
		} elseif ( 'pass' === $rule->query_mode && '' !== $request['query'] ) {
			// Merge the request's parameters into the target. Parameters
			// the target already defines win and cannot be overridden.
			$target_parts  = explode( '?', $target, 2 );
			$target_params = array();
			if ( isset( $target_parts[1] ) ) {
				parse_str( $target_parts[1], $target_params );
			}
			parse_str( $request['query'], $request_params );
			$merged = array_merge( $request_params, $target_params );
			$target = $target_parts[0] . ( empty( $merged ) ? '' : '?' . http_build_query( $merged ) );
		}

		if ( 0 === strpos( $target, '/' ) ) {
			$target = home_url( $target );
		}
		return $target;
	}

	/**
	 * Whether a built target points back at the current request.
	 *
	 * @param string $target  Destination URL.
	 * @param array  $request Normalized request.
	 * @return bool
	 */
	private function is_current_url( $target, array $request ) {
		$normalized = self::normalize( $target );
		return $normalized['path'] === $request['path'] && $normalized['query'] === $request['query'];
	}

	/**
	 * Serve a 410 Gone response.
	 */
	private function serve_410() {
		status_header( 410 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html><head><title>410 Gone</title></head><body><h1>410 Gone</h1><p>This content has been permanently removed.</p></body></html>';
		exit;
	}

	/**
	 * Count a hit on a rule.
	 *
	 * @param int $rule_id Rule ID.
	 */
	private function record_hit( $rule_id ) {
		global $wpdb;
		$rules_table = self::table( 'rules' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- atomic counter update.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$rules_table} SET hits = hits + 1, last_hit = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from $wpdb->prefix.
				gmdate( 'Y-m-d H:i:s' ),
				$rule_id
			)
		);
	}


	/**
	 * Record a published post/page heading for the trash or deletion, so
	 * a 410-or-redirect decision can be offered. Skips URLs already
	 * covered by a rule.
	 *
	 * @param int $post_id Post ID.
	 */
	public function capture_removed_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || 'publish' !== $post->post_status ) {
			return;
		}
		$permalink  = get_permalink( $post );
		$normalized = self::normalize( $permalink );
		if ( '/' === $normalized['path'] ) {
			return;
		}
		if ( $this->match( $normalized['path'], $normalized['query'] ) ) {
			return; // Already handled by a rule.
		}

		global $wpdb;
		$deleted_table = self::table( 'deleted' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- purpose-built table, deduped by path.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$deleted_table} (post_id, post_title, path, deleted) VALUES (%d, %s, %s, %s) ON DUPLICATE KEY UPDATE post_id = %d, post_title = %s, deleted = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from $wpdb->prefix.
				$post->ID,
				$post->post_title,
				substr( $normalized['path'], 0, 191 ),
				gmdate( 'Y-m-d H:i:s' ),
				$post->ID,
				$post->post_title,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * A restored post no longer needs a decision.
	 *
	 * @param int $post_id Post ID.
	 */
	public function forget_removed_post( $post_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- purpose-built table.
		$wpdb->delete( self::table( 'deleted' ), array( 'post_id' => (int) $post_id ), array( '%d' ) );
	}


	/**
	 * Create or update a rule from sanitized input.
	 *
	 * @param array $data  source, target, type, is_regex, query_mode, note, enabled.
	 * @param int   $id    Rule ID to update, 0 to insert.
	 * @return int|WP_Error New/updated rule ID.
	 */
	public function save_rule( array $data, $id = 0 ) {
		global $wpdb;

		$source = trim( (string) ( $data['source'] ?? '' ) );
		$target = trim( (string) ( $data['target'] ?? '' ) );
		$type   = (int) ( $data['type'] ?? 301 );
		$regex  = ! empty( $data['is_regex'] );
		$mode   = (string) ( $data['query_mode'] ?? 'pass' );
		if ( ! in_array( $mode, array( 'ignore', 'exact', 'pass' ), true ) ) {
			$mode = 'pass';
		}
		if ( $regex ) {
			$mode = 'ignore'; // The regex sees the whole URL; query modes don't apply.
		}

		if ( '' === $source ) {
			return new WP_Error( 'coywolf_seo_redirect_source', __( 'A source URL is required.', 'coywolf-seo' ) );
		}
		if ( ! isset( self::types()[ $type ] ) ) {
			$type = 301;
		}
		if ( 410 !== $type && '' === $target ) {
			return new WP_Error( 'coywolf_seo_redirect_target', __( 'A target URL is required (unless the action is 410 Gone).', 'coywolf-seo' ) );
		}
		if ( $regex ) {
			if ( false === @preg_match( self::regex_pattern( $source ), '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- validating an admin-entered pattern.
				return new WP_Error( 'coywolf_seo_redirect_regex', __( 'The regular expression is not valid.', 'coywolf-seo' ) );
			}
			$match_path = '';
		} else {
			$normalized = self::normalize( $source );
			$match_path = substr( $normalized['path'], 0, 191 );

			// A redirect onto itself would loop.
			if ( 410 !== $type ) {
				$target_normalized = self::normalize( $target );
				if ( $target_normalized['path'] === $normalized['path'] && $target_normalized['query'] === $normalized['query'] ) {
					return new WP_Error( 'coywolf_seo_redirect_loop', __( 'The source and target are the same URL — that would redirect to itself.', 'coywolf-seo' ) );
				}
			}
		}

		$row     = array(
			'source'     => substr( $source, 0, 2000 ),
			'match_path' => $match_path,
			'target'     => ( 410 === $type ) ? '' : $target,
			'type'       => $type,
			'is_regex'   => $regex ? 1 : 0,
			'query_mode' => $mode,
			'enabled'    => isset( $data['enabled'] ) ? (int) (bool) $data['enabled'] : 1,
			'note'       => substr( sanitize_text_field( (string) ( $data['note'] ?? '' ) ), 0, 255 ),
		);
		$formats = array( '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s' );

		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- purpose-built table.
			$wpdb->update( self::table( 'rules' ), $row, array( 'id' => $id ), $formats, array( '%d' ) );
			return $id;
		}

		$row['created'] = gmdate( 'Y-m-d H:i:s' );
		$formats[]      = '%s';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- purpose-built table.
		$wpdb->insert( self::table( 'rules' ), $row, $formats );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Query rules for the admin table: search, filters, pagination.
	 *
	 * @param array $args s (search), type (HTTP code), status
	 *                    (enabled|disabled|''), paged, per_page.
	 * @return array { items: array, total: int }
	 */
	public function query_rules( array $args ) {
		global $wpdb;
		$rules_table = self::table( 'rules' );

		$where  = array( '1=1' );
		$values = array();

		$search = trim( (string) ( $args['s'] ?? '' ) );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(source LIKE %s OR target LIKE %s OR note LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}
		$type = (int) ( $args['type'] ?? 0 );
		if ( isset( self::types()[ $type ] ) ) {
			$where[]  = 'type = %d';
			$values[] = $type;
		}
		$status = (string) ( $args['status'] ?? '' );
		if ( in_array( $status, array( 'enabled', 'disabled' ), true ) ) {
			$where[]  = 'enabled = %d';
			$values[] = 'enabled' === $status ? 1 : 0;
		}

		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$offset   = ( $paged - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- purpose-built table; clauses are placeholder-built, table name from $wpdb->prefix.
		if ( ! empty( $values ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rules_table} WHERE {$where_sql}", $values ) );
			$items = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$rules_table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $values, array( $per_page, $offset ) ) ) );
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rules_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$items = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$rules_table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * All rules, for the admin screen and export.
	 *
	 * @return array
	 */
	public function all_rules() {
		global $wpdb;
		$rules_table = self::table( 'rules' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- purpose-built table; admin screen.
		return (array) $wpdb->get_results( "SELECT * FROM {$rules_table} ORDER BY id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name built from $wpdb->prefix, no user input.
	}

	/**
	 * Pending decisions for specific posts (the All Posts/Pages notice).
	 *
	 * @param int[] $post_ids Post IDs just removed.
	 * @return array
	 */
	public function pending_for_posts( array $post_ids ) {
		$post_ids = array_values( array_filter( array_map( 'absint', $post_ids ) ) );
		if ( empty( $post_ids ) ) {
			return array();
		}
		global $wpdb;
		$deleted_table = self::table( 'deleted' );
		$placeholders  = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name from $wpdb->prefix; placeholders built to count.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$deleted_table} WHERE post_id IN ({$placeholders}) ORDER BY deleted DESC", $post_ids ) );
	}

	/**
	 * Pending deleted-content decisions.
	 *
	 * @return array
	 */
	public function pending_deletions() {
		global $wpdb;
		$deleted_table = self::table( 'deleted' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- purpose-built table; admin screen.
		return (array) $wpdb->get_results( "SELECT * FROM {$deleted_table} ORDER BY deleted DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name built from $wpdb->prefix, no user input.
	}


	/**
	 * Test a URL against the rules without redirecting — the admin
	 * screen's tester.
	 *
	 * @param string $url URL or path to test.
	 * @return array { matched: bool, rule_id, action, target }
	 */
	public function test_url( $url ) {
		$request = self::normalize( $url );
		$rule    = $this->match( $request['path'], $request['query'] );
		if ( ! $rule ) {
			return array( 'matched' => false );
		}
		$request['raw'] = $url;
		return array(
			'matched' => true,
			'rule_id' => (int) $rule->id,
			'action'  => (int) $rule->type,
			'target'  => 410 === (int) $rule->type ? '' : $this->build_target( $rule, $request ),
		);
	}
}
