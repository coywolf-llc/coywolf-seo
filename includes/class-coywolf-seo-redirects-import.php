<?php
/**
 * Import redirect records from other plugins into the redirect manager.
 *
 * Two sources:
 * - Redirection (johngodley): reads its wp_redirection_items table
 *   directly, so importing works whether the plugin is active or merely
 *   left its tables behind. Plain url-match rules, regex rules, and 410
 *   "error" actions come across; condition-based matches (login,
 *   referrer, IP…) and pass-through/random actions don't map and are
 *   skipped.
 * - Yoast SEO Premium: reads the wpseo-premium-redirects-base option
 *   (and the pre-3.1 legacy options), offered only while the plugin is
 *   active.
 *
 * Nothing imports automatically: when a source plugin is active, a
 * dismissible banner offers the import; the Import/Export page lists
 * whichever sources are detectable.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirect importers, their banner, and their handlers.
 */
final class Coywolf_SEO_Redirects_Import {

	/**
	 * Option holding which import banners were dismissed.
	 */
	const DISMISSED_OPTION = 'coywolf_seo_import_dismissed';

	/**
	 * The redirect engine.
	 *
	 * @var Coywolf_SEO_Redirects
	 */
	private $redirects;

	/**
	 * Wire up.
	 *
	 * @param Coywolf_SEO_Redirects $redirects Engine module.
	 */
	public function __construct( Coywolf_SEO_Redirects $redirects ) {
		$this->redirects = $redirects;
	}

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_action( 'admin_post_coywolf_seo_import_redirects', array( $this, 'handle_import' ) );
		add_action( 'admin_post_coywolf_seo_dismiss_import', array( $this, 'handle_dismiss' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_banner' ) );
	}

	/**
	 * Whether the Redirection plugin is active.
	 *
	 * @return bool
	 */
	public function redirection_active() {
		return class_exists( 'Red_Item' ) || ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'redirection/redirection.php' ) );
	}

	/**
	 * How many importable Redirection records exist (0 when its table
	 * doesn't, e.g. the plugin was never installed).
	 *
	 * @return int
	 */
	public function redirection_count() {
		global $wpdb;
		$table = $wpdb->prefix . 'redirection_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- another plugin's table, read directly by design.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix and a literal.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE match_type = 'url' AND ( action_type = 'url' OR ( action_type = 'error' AND action_code = 410 ) )" );
	}

	/**
	 * Whether Yoast SEO Premium is active.
	 *
	 * @return bool
	 */
	public function yoast_active() {
		return class_exists( 'WPSEO_Premium' ) || defined( 'WPSEO_PREMIUM_FILE' );
	}

	/**
	 * How many Yoast Premium redirect records exist.
	 *
	 * @return int
	 */
	public function yoast_count() {
		return count( $this->yoast_records() );
	}

	/**
	 * Yoast records normalized to one shape: origin/url/type/format.
	 * Handles both the current wpseo-premium-redirects-base list and the
	 * pre-3.1 origin-keyed legacy options.
	 *
	 * @return array[]
	 */
	private function yoast_records() {
		$records = array();

		$base = get_option( 'wpseo-premium-redirects-base', array() );
		if ( is_array( $base ) ) {
			foreach ( $base as $row ) {
				if ( is_array( $row ) && isset( $row['origin'] ) ) {
					$records[] = array(
						'origin' => (string) $row['origin'],
						'url'    => isset( $row['url'] ) ? (string) $row['url'] : '',
						'type'   => isset( $row['type'] ) ? (int) $row['type'] : 301,
						'format' => isset( $row['format'] ) ? (string) $row['format'] : 'plain',
					);
				}
			}
		}

		if ( empty( $records ) ) {
			// Pre-3.1 shape: origin-keyed, split across two options.
			foreach ( array( 'wpseo-premium-redirects' => 'plain', 'wpseo-premium-redirects-regex' => 'regex' ) as $option => $format ) {
				$legacy = get_option( $option, array() );
				if ( ! is_array( $legacy ) ) {
					continue;
				}
				foreach ( $legacy as $origin => $row ) {
					if ( is_array( $row ) ) {
						$records[] = array(
							'origin' => (string) $origin,
							'url'    => isset( $row['url'] ) ? (string) $row['url'] : '',
							'type'   => isset( $row['type'] ) ? (int) $row['type'] : 301,
							'format' => $format,
						);
					}
				}
			}
		}

		return $records;
	}

	/**
	 * Import from Redirection's table.
	 *
	 * @return array { imported: int, skipped: int }
	 */
	public function import_redirection() {
		global $wpdb;
		$table = $wpdb->prefix . 'redirection_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- another plugin's table, read directly by design.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array(
				'imported' => 0,
				'skipped'  => 0,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix and a literal.
		$rows = (array) $wpdb->get_results( "SELECT url, regex, action_type, action_code, action_data, status, title, match_data FROM {$table} WHERE match_type = 'url' ORDER BY id ASC" );

		$imported = 0;
		$skipped  = 0;
		$existing = $this->existing_sources();

		foreach ( $rows as $row ) {
			$is_410 = ( 'error' === $row->action_type && 410 === (int) $row->action_code );
			if ( 'url' !== $row->action_type && ! $is_410 ) {
				$skipped++; // pass/random/nothing or non-410 error pages.
				continue;
			}

			$target = '';
			if ( ! $is_410 ) {
				$raw = (string) $row->action_data;
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- allowed_classes=false blocks object injection.
				$target = is_serialized( $raw ) ? unserialize( $raw, array( 'allowed_classes' => false ) ) : $raw;
				if ( is_array( $target ) ) {
					$target = isset( $target['url'] ) ? (string) $target['url'] : '';
				}
				$target = (string) $target;
				if ( '' === $target ) {
					$skipped++;
					continue;
				}
			}

			$type = $is_410 ? 410 : (int) $row->action_code;
			if ( ! isset( Coywolf_SEO_Redirects::types()[ $type ] ) ) {
				$type = 301; // 303/304 and friends map to permanent.
			}

			// Redirection's per-rule query mode lives in its match_data
			// JSON; its own default is exact-in-any-order.
			$mode  = 'exact';
			$flags = json_decode( (string) $row->match_data, true );
			if ( isset( $flags['source']['flag_query'] ) && in_array( $flags['source']['flag_query'], array( 'exact', 'exactorder', 'ignore', 'pass' ), true ) ) {
				$mode = 'exactorder' === $flags['source']['flag_query'] ? 'exact' : $flags['source']['flag_query'];
			}

			$result = $this->import_rule(
				array(
					'source'     => (string) $row->url,
					'target'     => $target,
					'type'       => $type,
					'is_regex'   => (int) $row->regex,
					'query_mode' => $mode,
					'enabled'    => 'enabled' === $row->status ? 1 : 0,
					'note'       => '' !== (string) $row->title ? (string) $row->title : __( 'Imported from Redirection', 'coywolf-seo' ),
				),
				$existing
			);
			$result ? $imported++ : $skipped++;
		}

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Import from Yoast SEO Premium's option.
	 *
	 * @return array { imported: int, skipped: int }
	 */
	public function import_yoast() {
		$imported = 0;
		$skipped  = 0;
		$existing = $this->existing_sources();

		foreach ( $this->yoast_records() as $row ) {
			$type = (int) $row['type'];
			if ( 451 === $type ) {
				$type = 410; // Closest supported "gone" semantics.
			}
			if ( ! isset( Coywolf_SEO_Redirects::types()[ $type ] ) ) {
				$type = 301;
			}
			if ( 410 !== $type && '' === $row['url'] ) {
				$skipped++;
				continue;
			}

			$result = $this->import_rule(
				array(
					'source'     => $row['origin'],
					'target'     => 410 === $type ? '' : $row['url'],
					'type'       => $type,
					'is_regex'   => 'regex' === $row['format'] ? 1 : 0,
					'query_mode' => 'exact', // Yoast matches the URL as stored.
					'enabled'    => 1,
					'note'       => __( 'Imported from Yoast SEO Premium', 'coywolf-seo' ),
				),
				$existing
			);
			$result ? $imported++ : $skipped++;
		}

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Save one mapped rule unless an equivalent source already exists.
	 *
	 * @param array $data     Rule data for save_rule().
	 * @param array $existing Existing source keys, updated in place.
	 * @return bool Whether the rule was imported.
	 */
	private function import_rule( array $data, array &$existing ) {
		$key = $this->source_key( (string) $data['source'], ! empty( $data['is_regex'] ) );
		if ( isset( $existing[ $key ] ) ) {
			return false; // Already covered — safe to re-run imports.
		}
		$result = $this->redirects->save_rule( $data );
		if ( is_wp_error( $result ) ) {
			return false;
		}
		$existing[ $key ] = true;
		return true;
	}

	/**
	 * Source keys of all current rules, for duplicate detection.
	 *
	 * @return array
	 */
	private function existing_sources() {
		$keys = array();
		foreach ( $this->redirects->all_rules() as $rule ) {
			$keys[ $this->source_key( (string) $rule->source, (bool) $rule->is_regex ) ] = true;
		}
		return $keys;
	}

	/**
	 * Canonical comparison key for a source.
	 *
	 * @param string $source   Source as entered.
	 * @param bool   $is_regex Whether it is a pattern.
	 * @return string
	 */
	private function source_key( $source, $is_regex ) {
		if ( $is_regex ) {
			return 'r:' . $source;
		}
		$normalized = Coywolf_SEO_Redirects::normalize( $source );
		return 'p:' . $normalized['path'] . ( '' !== $normalized['query'] ? '?' . $normalized['query'] : '' );
	}

	/**
	 * Offer the import when a source plugin is active — never import
	 * automatically.
	 */
	public function maybe_show_banner() {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			return;
		}
		$dismissed = (array) get_option( self::DISMISSED_OPTION, array() );

		$sources = array();
		if ( $this->redirection_active() && empty( $dismissed['redirection'] ) ) {
			$count = $this->redirection_count();
			if ( $count > 0 ) {
				$sources['redirection'] = array(
					/* translators: %d: number of redirect records. */
					'text' => sprintf( _n( 'Redirection has %d redirect. Import it into Coywolf SEO?', 'Redirection has %d redirects. Import them into Coywolf SEO?', $count, 'coywolf-seo' ), $count ),
				);
			}
		}
		if ( $this->yoast_active() && empty( $dismissed['yoast'] ) ) {
			$count = $this->yoast_count();
			if ( $count > 0 ) {
				$sources['yoast'] = array(
					/* translators: %d: number of redirect records. */
					'text' => sprintf( _n( 'Yoast SEO Premium has %d redirect. Import it into Coywolf SEO?', 'Yoast SEO Premium has %d redirects. Import them into Coywolf SEO?', $count, 'coywolf-seo' ), $count ),
				);
			}
		}

		foreach ( $sources as $source => $banner ) {
			?>
			<div class="notice notice-info coywolf-seo-import-banner">
				<p>
					<strong><?php echo esc_html( $banner['text'] ); ?></strong>
				</p>
				<p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coywolf-seo-inline-form">
						<input type="hidden" name="action" value="coywolf_seo_import_redirects" />
						<?php wp_nonce_field( 'coywolf_seo_import_redirects' ); ?>
						<input type="hidden" name="source" value="<?php echo esc_attr( $source ); ?>" />
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'coywolf-seo' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coywolf-seo-inline-form">
						<input type="hidden" name="action" value="coywolf_seo_dismiss_import" />
						<?php wp_nonce_field( 'coywolf_seo_dismiss_import' ); ?>
						<input type="hidden" name="source" value="<?php echo esc_attr( $source ); ?>" />
						<button type="submit" class="button-link"><?php esc_html_e( 'Dismiss', 'coywolf-seo' ); ?></button>
					</form>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * The Import/Export page section: whichever sources are detectable.
	 */
	public function render_section() {
		$sources = array();
		if ( $this->redirection_count() > 0 ) {
			$sources['redirection'] = $this->redirection_active()
				? __( 'Redirection (active)', 'coywolf-seo' )
				: __( 'Redirection (inactive — its saved records are still in the database)', 'coywolf-seo' );
		}
		if ( $this->yoast_active() && $this->yoast_count() > 0 ) {
			$sources['yoast'] = __( 'Yoast SEO Premium (active)', 'coywolf-seo' );
		}
		if ( empty( $sources ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Import redirects', 'coywolf-seo' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Bring redirect rules over from another plugin. Records whose source URL already has a rule are skipped, so re-running an import is safe.', 'coywolf-seo' ); ?></p>
		<?php foreach ( $sources as $source => $label ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:8px">
				<input type="hidden" name="action" value="coywolf_seo_import_redirects" />
				<?php wp_nonce_field( 'coywolf_seo_import_redirects' ); ?>
				<input type="hidden" name="source" value="<?php echo esc_attr( $source ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Import from', 'coywolf-seo' ); ?> <?php echo esc_html( $label ); ?></button>
			</form>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Run an import (banner button or Import/Export page).
	 */
	public function handle_import() {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage redirects.', 'coywolf-seo' ) );
		}
		check_admin_referer( 'coywolf_seo_import_redirects' );

		$source = isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : '';
		$result = array(
			'imported' => 0,
			'skipped'  => 0,
		);
		if ( 'redirection' === $source ) {
			$result = $this->import_redirection();
		} elseif ( 'yoast' === $source && $this->yoast_active() ) {
			$result = $this->import_yoast();
		}

		// A completed import also clears the banner.
		$dismissed            = (array) get_option( self::DISMISSED_OPTION, array() );
		$dismissed[ $source ] = 1;
		update_option( self::DISMISSED_OPTION, $dismissed );

		wp_safe_redirect(
			add_query_arg(
				array(
					'redirect-imported' => (int) $result['imported'],
					'redirect-skipped'  => (int) $result['skipped'],
				),
				admin_url( 'admin.php?page=' . Coywolf_SEO_Redirects::SLUG )
			)
		);
		exit;
	}

	/**
	 * Dismiss a banner.
	 */
	public function handle_dismiss() {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage redirects.', 'coywolf-seo' ) );
		}
		check_admin_referer( 'coywolf_seo_dismiss_import' );

		$source                = isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : '';
		$dismissed             = (array) get_option( self::DISMISSED_OPTION, array() );
		$dismissed[ $source ]  = 1;
		update_option( self::DISMISSED_OPTION, $dismissed );

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url() );
		exit;
	}
}
