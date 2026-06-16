<?php
/**
 * The Redirects admin screen.
 *
 * One fully server-rendered page (it never depends on AJAX to render or
 * save — JavaScript only adds convenience): a pinned quick-add bar, a URL
 * tester, the deleted-content decisions panel, the rules table with
 * expandable inline edit forms, and the recent-404s panel with one-click
 * convert actions.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirects page rendering and form handling.
 */
final class Coywolf_SEO_Redirects_Admin {

	/**
	 * The data/engine module.
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
		add_action( 'admin_post_coywolf_seo_redirect_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_coywolf_seo_redirect_row', array( $this, 'handle_row_action' ) );
		add_action( 'admin_post_coywolf_seo_redirect_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_coywolf_seo_redirect_bulk', array( $this, 'handle_bulk' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_deletion_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_move_notice' ) );
	}

	/**
	 * Prompt to create a redirect when a published post/page's URL has changed.
	 * On the post editor it offers the decision for the post being edited; on the
	 * Posts/Pages list it surfaces any recent moves still awaiting a redirect.
	 */
	public function maybe_show_move_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			return;
		}

		$rows      = array();
		$return_to = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( 'post' === $screen->base ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection on the post editor.
			$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : (int) get_the_ID();
			$move    = $post_id ? $this->redirects->pending_move_for_post( $post_id ) : null;
			if ( $move ) {
				$rows[] = $move;
			}
		} elseif ( in_array( $screen->id, array( 'edit-post', 'edit-page' ), true ) ) {
			$rows = array_slice( $this->redirects->pending_moves(), 0, 5 );
		}

		if ( empty( $rows ) ) {
			return;
		}
		?>
		<div class="notice coywolf-seo-pending coywolf-seo-move-notice">
			<h2><?php esc_html_e( 'A URL changed — create a redirect?', 'coywolf-seo' ); ?></h2>
			<p class="description"><?php esc_html_e( 'The old address would now 404. Add a 301 redirect so existing links and search rankings carry over to the new URL.', 'coywolf-seo' ); ?></p>
			<table class="coywolf-seo-decision-table">
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td class="coywolf-seo-cell-grow">
								<strong><?php echo esc_html( $row->post_title ); ?></strong>
								<code><?php echo esc_html( $row->old_path ); ?></code>
								<span class="coywolf-seo-arrow" aria-hidden="true">&rarr;</span>
								<code><?php echo esc_html( $row->new_path ); ?></code>
							</td>
							<td class="coywolf-seo-cell-actions">
								<?php $this->render_move_actions( $row, $return_to ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * The two decision forms for a moved-page row: create the 301 old→new
	 * redirect, or dismiss. Used in the editor/list notice and on the Redirects
	 * page.
	 *
	 * @param object $row       Moved-page row (old_path, new_path, id).
	 * @param string $return_to Admin URL to return to ('' = Redirects page).
	 */
	public function render_move_actions( $row, $return_to = '' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coywolf-seo-inline-form">
			<input type="hidden" name="action" value="coywolf_seo_redirect_save" />
			<?php wp_nonce_field( 'coywolf_seo_redirect_save' ); ?>
			<input type="hidden" name="resolve_move" value="<?php echo esc_attr( (string) $row->id ); ?>" />
			<input type="hidden" name="redirect[source]" value="<?php echo esc_attr( $row->old_path ); ?>" />
			<input type="hidden" name="redirect[target]" value="<?php echo esc_attr( $row->new_path ); ?>" />
			<input type="hidden" name="redirect[type]" value="301" />
			<?php if ( '' !== $return_to ) : ?>
				<input type="hidden" name="return_to" value="<?php echo esc_attr( $return_to ); ?>" />
			<?php endif; ?>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Create 301 redirect', 'coywolf-seo' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coywolf-seo-inline-form">
			<input type="hidden" name="action" value="coywolf_seo_redirect_row" />
			<?php wp_nonce_field( 'coywolf_seo_redirect_row' ); ?>
			<input type="hidden" name="row_action" value="dismiss_move" />
			<input type="hidden" name="row_id" value="<?php echo esc_attr( (string) $row->id ); ?>" />
			<?php if ( '' !== $return_to ) : ?>
				<input type="hidden" name="return_to" value="<?php echo esc_attr( $return_to ); ?>" />
			<?php endif; ?>
			<button type="submit" class="button-link"><?php esc_html_e( 'Dismiss', 'coywolf-seo' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Right after a post or page is trashed or deleted from the list
	 * screen, present the URL decision there and then — no trip to the
	 * Redirects page needed.
	 */
	public function maybe_show_deletion_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-post', 'edit-page' ), true ) || ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only signals WordPress sets after its own trash/delete actions.
		if ( ! isset( $_GET['trashed'] ) && ! isset( $_GET['deleted'] ) ) {
			return;
		}
		$ids = isset( $_GET['ids'] ) ? array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$rows = ! empty( $ids )
			? $this->redirects->pending_for_posts( $ids )
			: array_slice( $this->redirects->pending_deletions(), 0, 5 );
		if ( empty( $rows ) ) {
			return;
		}

		$return_to = '';
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			// Strip the one-shot trashed/deleted flags so acting on the
			// notice doesn't re-trigger it after the redirect back.
			$return_to = remove_query_arg( array( 'trashed', 'deleted', 'ids' ), esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
		}
		?>
		<div class="notice coywolf-seo-pending coywolf-seo-deletion-notice">
			<h2><?php esc_html_e( 'What should happen to the deleted URL?', 'coywolf-seo' ); ?></h2>
			<table class="coywolf-seo-decision-table">
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td class="coywolf-seo-cell-grow">
								<strong><?php echo esc_html( $row->post_title ); ?></strong>
								<code><?php echo esc_html( $row->path ); ?></code>
							</td>
							<td class="coywolf-seo-cell-actions">
								<?php $this->render_decision_actions( $row, $return_to ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * The three decision forms for a deleted-content row: mark gone (410),
	 * redirect to a URL, or dismiss. Used on the Redirects page and in the
	 * post-deletion notice.
	 *
	 * @param object $row       Deleted-content row.
	 * @param string $return_to Admin URL to return to ('' = Redirects page).
	 */
	public function render_decision_actions( $row, $return_to = '' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coywolf-seo-inline-form">
			<input type="hidden" name="action" value="coywolf_seo_redirect_save" />
			<?php wp_nonce_field( 'coywolf_seo_redirect_save' ); ?>
			<input type="hidden" name="resolve_deleted" value="<?php echo esc_attr( (string) $row->id ); ?>" />
			<input type="hidden" name="redirect[source]" value="<?php echo esc_attr( $row->path ); ?>" />
			<input type="hidden" name="redirect[type]" value="410" />
			<?php if ( '' !== $return_to ) : ?>
				<input type="hidden" name="return_to" value="<?php echo esc_attr( $return_to ); ?>" />
			<?php endif; ?>
			<button type="submit" class="button"><?php esc_html_e( 'Mark gone (410)', 'coywolf-seo' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coywolf-seo-inline-form">
			<input type="hidden" name="action" value="coywolf_seo_redirect_save" />
			<?php wp_nonce_field( 'coywolf_seo_redirect_save' ); ?>
			<input type="hidden" name="resolve_deleted" value="<?php echo esc_attr( (string) $row->id ); ?>" />
			<input type="hidden" name="redirect[source]" value="<?php echo esc_attr( $row->path ); ?>" />
			<input type="hidden" name="redirect[type]" value="301" />
			<?php if ( '' !== $return_to ) : ?>
				<input type="hidden" name="return_to" value="<?php echo esc_attr( $return_to ); ?>" />
			<?php endif; ?>
			<input type="url" name="redirect[target]" class="regular-text" placeholder="<?php esc_attr_e( 'Redirect to…', 'coywolf-seo' ); ?>" required />
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Redirect', 'coywolf-seo' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coywolf-seo-inline-form">
			<input type="hidden" name="action" value="coywolf_seo_redirect_row" />
			<?php wp_nonce_field( 'coywolf_seo_redirect_row' ); ?>
			<input type="hidden" name="row_action" value="dismiss_deleted" />
			<input type="hidden" name="row_id" value="<?php echo esc_attr( (string) $row->id ); ?>" />
			<?php if ( '' !== $return_to ) : ?>
				<input type="hidden" name="return_to" value="<?php echo esc_attr( $return_to ); ?>" />
			<?php endif; ?>
			<button type="submit" class="button-link"><?php esc_html_e( 'Dismiss', 'coywolf-seo' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Add or update a rule (quick-add bar and inline edit forms).
	 */
	public function handle_save() {
		$this->guard( 'coywolf_seo_redirect_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in guard() above.

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field in save_rule().
		$raw    = isset( $_POST['redirect'] ) && is_array( $_POST['redirect'] ) ? wp_unslash( $_POST['redirect'] ) : array();
		$id     = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;
		$result = $this->redirects->save_rule( $raw, $id );

		if ( is_wp_error( $result ) ) {
			$this->back( array( 'redirect-error' => rawurlencode( $result->get_error_message() ) ) );
		}

		// A decided deleted-content row is resolved by the new rule.
		if ( isset( $_POST['resolve_deleted'] ) ) {
			$this->resolve_deleted( absint( $_POST['resolve_deleted'] ) );
		}
		// A "moved page" decision is resolved once its redirect is created.
		if ( isset( $_POST['resolve_move'] ) ) {
			$this->redirects->delete_move( absint( $_POST['resolve_move'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->back( array( 'redirect-saved' => $id > 0 ? 'updated' : (int) $result ) );
	}

	/**
	 * Row actions: enable, disable, delete, dismiss-deleted, dismiss-404.
	 */
	public function handle_row_action() {
		$this->guard( 'coywolf_seo_redirect_row' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in guard() above.

		global $wpdb;
		$action = isset( $_POST['row_action'] ) ? sanitize_key( $_POST['row_action'] ) : '';
		$id     = isset( $_POST['row_id'] ) ? absint( $_POST['row_id'] ) : 0;

		if ( $id > 0 ) {
			switch ( $action ) {
				case 'enable':
				case 'disable':
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- purpose-built table.
					$wpdb->update( Coywolf_SEO_Redirects::table( 'rules' ), array( 'enabled' => 'enable' === $action ? 1 : 0 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
					break;
				case 'delete':
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- purpose-built table.
					$wpdb->delete( Coywolf_SEO_Redirects::table( 'rules' ), array( 'id' => $id ), array( '%d' ) );
					break;
				case 'dismiss_deleted':
					$this->resolve_deleted( $id );
					break;
				case 'dismiss_move':
					$this->redirects->delete_move( $id );
					break;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->back( array( 'redirect-saved' => 'updated' ) );
	}

	/**
	 * Bulk actions on selected rules: enable, disable, delete.
	 */
	public function handle_bulk() {
		$this->guard( 'coywolf_seo_redirect_bulk' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in guard() above.
		$bulk = isset( $_POST['bulk_action'] ) ? sanitize_key( $_POST['bulk_action'] ) : '';
		$ids  = isset( $_POST['rule_ids'] ) && is_array( $_POST['rule_ids'] ) ? array_filter( array_map( 'absint', wp_unslash( $_POST['rule_ids'] ) ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! empty( $ids ) && in_array( $bulk, array( 'enable', 'disable', 'delete' ), true ) ) {
			global $wpdb;
			foreach ( $ids as $id ) {
				if ( 'delete' === $bulk ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- purpose-built table.
					$wpdb->delete( Coywolf_SEO_Redirects::table( 'rules' ), array( 'id' => $id ), array( '%d' ) );
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- purpose-built table.
					$wpdb->update( Coywolf_SEO_Redirects::table( 'rules' ), array( 'enabled' => 'enable' === $bulk ? 1 : 0 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
				}
			}
		}
		$this->back( array( 'redirect-saved' => 'updated' ) );
	}

	/**
	 * The URL tester (no-JS fallback; the AJAX path uses the same engine).
	 */
	public function handle_test() {
		$this->guard( 'coywolf_seo_redirect_test' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in guard(); value sanitized with esc_url_raw.
		$url = isset( $_POST['test_url'] ) ? esc_url_raw( wp_unslash( $_POST['test_url'] ) ) : '';
		$this->back( array( 'redirect-test' => rawurlencode( $url ) ) );
	}

	/**
	 * Capability + nonce guard for the admin-post handlers.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	private function guard( $nonce_action ) {
		if ( ! current_user_can( Coywolf_SEO_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage redirects.', 'coywolf-seo' ) );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Remove a decided/dismissed deleted-content row.
	 *
	 * @param int $id Row ID.
	 */
	private function resolve_deleted( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- purpose-built table.
		$wpdb->delete( Coywolf_SEO_Redirects::table( 'deleted' ), array( 'id' => $id ), array( '%d' ) );
	}


	/**
	 * Back to the Redirects page with state.
	 *
	 * @param array $args Query args.
	 */
	private function back( array $args ) {
		$destination = admin_url( 'admin.php?page=' . Coywolf_SEO_Redirects::SLUG );
		// Actions taken from the post-deletion notice return to the list
		// screen they came from (admin URLs only).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard() before any handler calls back().
		if ( isset( $_POST['return_to'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated against admin_url below.
			$requested = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['return_to'] ) ), '' );
			if ( '' !== $requested ) {
				$destination = $requested;
				unset( $args['redirect-saved'], $args['redirect-test'] );
			}
		}
		wp_safe_redirect( add_query_arg( $args, $destination ) );
		exit;
	}

	/**
	 * The item count and page links, in core list-table markup.
	 *
	 * @param int    $total       Total rules.
	 * @param int    $total_pages Total pages.
	 * @param int    $paged       Current page.
	 * @param string $list_url    Current list URL with filters.
	 */
	private function render_pagination( $total, $total_pages, $paged, $list_url ) {
		?>
		<div class="tablenav-pages<?php echo $total_pages <= 1 ? ' one-page' : ''; ?>">
			<span class="displaying-num">
				<?php
				printf(
					/* translators: %d: number of redirect rules. */
					esc_html( _n( '%d rule', '%d rules', $total, 'coywolf-seo' ) ),
					(int) $total
				);
				?>
			</span>
			<?php if ( $total_pages > 1 ) : ?>
				<span class="pagination-links">
					<?php
					echo wp_kses_post(
						(string) paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%', $list_url ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $total_pages,
								'prev_text' => __( '&lsaquo;', 'coywolf-seo' ),
								'next_text' => __( '&rsaquo;', 'coywolf-seo' ),
							)
						)
					);
					?>
				</span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the page.
	 */
	public function render() {
		$pending       = $this->redirects->pending_deletions();
		$pending_moves = $this->redirects->pending_moves();
		$types         = Coywolf_SEO_Redirects::types();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$f_type   = isset( $_GET['rtype'] ) ? absint( $_GET['rtype'] ) : 0;
		$f_status = isset( $_GET['rstatus'] ) ? sanitize_key( $_GET['rstatus'] ) : '';
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 20;
		$query    = $this->redirects->query_rules(
			array(
				's'        => $search,
				'type'     => $f_type,
				'status'   => $f_status,
				'paged'    => $paged,
				'per_page' => $per_page,
			)
		);
		$rules       = $query['items'];
		$total_rules = $query['total'];
		$total_pages = (int) ceil( $total_rules / $per_page );

		$base_url = admin_url( 'admin.php?page=' . Coywolf_SEO_Redirects::SLUG );
		$list_url = add_query_arg(
			array_filter(
				array(
					's'       => $search,
					'rtype'   => $f_type ? $f_type : null,
					'rstatus' => '' !== $f_status ? $f_status : null,
					'paged'   => $paged > 1 ? $paged : null,
				),
				static function ( $value ) {
					return null !== $value && '' !== $value;
				}
			),
			$base_url
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only display flags set by our own redirects, each sanitized on read.
		$saved_flag = isset( $_GET['redirect-saved'] ) ? sanitize_text_field( wp_unslash( $_GET['redirect-saved'] ) ) : '';
		$imported   = isset( $_GET['redirect-imported'] ) ? absint( $_GET['redirect-imported'] ) : -1;
		$import_skipped = isset( $_GET['redirect-skipped'] ) ? absint( $_GET['redirect-skipped'] ) : 0;
		$error_flag = isset( $_GET['redirect-error'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['redirect-error'] ) ) ) : '';
		$test_url   = isset( $_GET['redirect-test'] ) ? esc_url_raw( rawurldecode( wp_unslash( $_GET['redirect-test'] ) ) ) : '';
		$prefill    = isset( $_GET['prefill-source'] ) ? sanitize_text_field( wp_unslash( $_GET['prefill-source'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$test_result = '' !== $test_url ? $this->redirects->test_url( $test_url ) : null;
		?>
		<div class="wrap coywolf-seo-wrap coywolf-seo-redirects">
			<h1><?php esc_html_e( 'Redirects', 'coywolf-seo' ); ?></h1>

			<?php if ( '' !== $error_flag ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error_flag ); ?></p></div>
			<?php elseif ( $imported >= 0 ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %d: number of imported redirects. */
							esc_html( _n( 'Imported %d redirect.', 'Imported %d redirects.', $imported, 'coywolf-seo' ) ),
							(int) $imported
						);
						if ( $import_skipped > 0 ) {
							echo ' ';
							printf(
								/* translators: %d: number of skipped records. */
								esc_html( _n( '%d record was skipped (already covered or not importable).', '%d records were skipped (already covered or not importable).', $import_skipped, 'coywolf-seo' ) ),
								(int) $import_skipped
							);
						}
						?>
					</p>
				</div>
			<?php elseif ( '' !== $saved_flag ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Redirects updated.', 'coywolf-seo' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! empty( $pending ) ) : ?>
				<div class="coywolf-seo-panel coywolf-seo-pending">
					<h2>
						<?php
						printf(
							/* translators: %d: number of deleted posts/pages awaiting a decision. */
							esc_html( _n( '%d deleted page needs a decision', '%d deleted pages need a decision', count( $pending ), 'coywolf-seo' ) ),
							(int) count( $pending )
						);
						?>
					</h2>
					<p class="description"><?php esc_html_e( 'These published posts and pages were deleted. Tell search engines the content is gone (410), or send visitors somewhere useful.', 'coywolf-seo' ); ?></p>
					<table class="widefat striped">
						<tbody>
							<?php foreach ( $pending as $row ) : ?>
								<tr>
									<td class="coywolf-seo-cell-grow">
										<strong><?php echo esc_html( $row->post_title ); ?></strong><br />
										<code><?php echo esc_html( $row->path ); ?></code>
										<span class="description"><?php echo esc_html( gmdate( 'M j, Y', strtotime( $row->deleted ) ) ); ?></span>
									</td>
									<td class="coywolf-seo-cell-actions">
										<?php $this->render_decision_actions( $row ); ?>
										</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $pending_moves ) ) : ?>
				<div class="coywolf-seo-panel coywolf-seo-pending">
					<h2>
						<?php
						printf(
							/* translators: %d: number of moved posts/pages whose redirect hasn't been created. */
							esc_html( _n( '%d moved page can keep its old URL working', '%d moved pages can keep their old URLs working', count( $pending_moves ), 'coywolf-seo' ) ),
							(int) count( $pending_moves )
						);
						?>
					</h2>
					<p class="description"><?php esc_html_e( 'These published posts and pages changed URL. Create a 301 redirect so the old address still reaches the new one.', 'coywolf-seo' ); ?></p>
					<table class="widefat striped">
						<tbody>
							<?php foreach ( $pending_moves as $row ) : ?>
								<tr>
									<td class="coywolf-seo-cell-grow">
										<strong><?php echo esc_html( $row->post_title ); ?></strong><br />
										<code><?php echo esc_html( $row->old_path ); ?></code>
										<span class="coywolf-seo-arrow" aria-hidden="true">&rarr;</span>
										<code><?php echo esc_html( $row->new_path ); ?></code>
										<span class="description"><?php echo esc_html( gmdate( 'M j, Y', strtotime( $row->moved ) ) ); ?></span>
									</td>
									<td class="coywolf-seo-cell-actions">
										<?php $this->render_move_actions( $row ); ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<div class="coywolf-seo-panel">
				<h2><?php esc_html_e( 'Add a redirect', 'coywolf-seo' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coywolf-seo-quick-add">
					<input type="hidden" name="action" value="coywolf_seo_redirect_save" />
					<?php wp_nonce_field( 'coywolf_seo_redirect_save' ); ?>
					<div class="coywolf-seo-quick-row">
						<input type="text" name="redirect[source]" id="coywolf-seo-qa-source" class="regular-text" placeholder="<?php esc_attr_e( '/old-path/', 'coywolf-seo' ); ?>" value="<?php echo esc_attr( $prefill ); ?>" required />
						<span class="coywolf-seo-arrow" aria-hidden="true">&rarr;</span>
						<input type="text" name="redirect[target]" id="coywolf-seo-qa-target" class="regular-text" placeholder="<?php esc_attr_e( '/new-path/ or https://…', 'coywolf-seo' ); ?>" />
						<select name="redirect[type]" aria-label="<?php esc_attr_e( 'Redirect type', 'coywolf-seo' ); ?>">
							<?php foreach ( $types as $code => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $code ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Add', 'coywolf-seo' ); ?></button>
						<button type="button" class="button-link" id="coywolf-seo-qa-more" aria-expanded="false" aria-controls="coywolf-seo-qa-more-fields"><?php esc_html_e( 'More options', 'coywolf-seo' ); ?></button>
					</div>
					<div class="coywolf-seo-quick-more" id="coywolf-seo-qa-more-fields" style="display:none">
						<label>
							<input type="checkbox" name="redirect[is_regex]" value="1" />
							<?php esc_html_e( 'Regular expression (matches the whole path, capture groups as $1)', 'coywolf-seo' ); ?>
						</label>
						<label>
							<?php esc_html_e( 'Query strings:', 'coywolf-seo' ); ?>
							<select name="redirect[query_mode]">
								<option value="pass"><?php esc_html_e( 'Ignore when matching, pass to the target', 'coywolf-seo' ); ?></option>
								<option value="ignore"><?php esc_html_e( 'Ignore when matching, drop them', 'coywolf-seo' ); ?></option>
								<option value="exact"><?php esc_html_e( 'Must match exactly (any order)', 'coywolf-seo' ); ?></option>
							</select>
						</label>
						<label>
							<?php esc_html_e( 'Note:', 'coywolf-seo' ); ?>
							<input type="text" name="redirect[note]" class="regular-text" placeholder="<?php esc_attr_e( 'Why this redirect exists', 'coywolf-seo' ); ?>" />
						</label>
					</div>
					<p class="description"><?php esc_html_e( 'Sources match with or without a trailing slash, in any letter case. Paste a full URL and the domain is stripped automatically.', 'coywolf-seo' ); ?></p>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coywolf-seo-tester">
					<input type="hidden" name="action" value="coywolf_seo_redirect_test" />
					<?php wp_nonce_field( 'coywolf_seo_redirect_test' ); ?>
					<label for="coywolf-seo-test-url"><strong><?php esc_html_e( 'Test a URL:', 'coywolf-seo' ); ?></strong></label>
					<input type="text" name="test_url" id="coywolf-seo-test-url" class="regular-text" placeholder="<?php esc_attr_e( '/some-path/?with=query', 'coywolf-seo' ); ?>" value="<?php echo esc_attr( $test_url ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( 'Test', 'coywolf-seo' ); ?></button>
					<?php if ( null !== $test_result ) : ?>
						<span class="coywolf-seo-test-result" role="status" aria-live="polite">
							<?php if ( empty( $test_result['matched'] ) ) : ?>
								<?php esc_html_e( 'No rule matches — WordPress handles it normally.', 'coywolf-seo' ); ?>
							<?php elseif ( 410 === (int) $test_result['action'] ) : ?>
								<?php esc_html_e( 'Matches: responds 410 Gone.', 'coywolf-seo' ); ?>
							<?php else : ?>
								<?php
								printf(
									/* translators: 1: HTTP code, 2: target URL. */
									esc_html__( 'Matches: %1$d redirect to %2$s', 'coywolf-seo' ),
									(int) $test_result['action'],
									'<code>' . esc_html( $test_result['target'] ) . '</code>'
								);
								?>
							<?php endif; ?>
							<?php esc_html_e( '(If the live site behaves differently, a page cache is serving an old copy — purge it.)', 'coywolf-seo' ); ?>
						</span>
					<?php endif; ?>
				</form>
			</div>

			<div class="coywolf-seo-rules-list">

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( Coywolf_SEO_Redirects::SLUG ); ?>" />
				<?php if ( $f_type ) : ?>
					<input type="hidden" name="rtype" value="<?php echo esc_attr( (string) $f_type ); ?>" />
				<?php endif; ?>
				<?php if ( '' !== $f_status ) : ?>
					<input type="hidden" name="rstatus" value="<?php echo esc_attr( $f_status ); ?>" />
				<?php endif; ?>
				<p class="search-box">
					<label class="screen-reader-text" for="coywolf-seo-rule-search"><?php esc_html_e( 'Search rules', 'coywolf-seo' ); ?></label>
					<input type="search" id="coywolf-seo-rule-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Source, target, note…', 'coywolf-seo' ); ?>" />
					<input type="submit" class="button" value="<?php esc_attr_e( 'Search rules', 'coywolf-seo' ); ?>" />
				</p>
			</form>

			<div class="tablenav top">
				<div class="alignleft actions bulkactions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="coywolf-seo-bulk">
						<input type="hidden" name="action" value="coywolf_seo_redirect_bulk" />
						<?php wp_nonce_field( 'coywolf_seo_redirect_bulk' ); ?>
						<input type="hidden" name="return_to" value="<?php echo esc_attr( $list_url ); ?>" />
						<select name="bulk_action" aria-label="<?php esc_attr_e( 'Bulk action', 'coywolf-seo' ); ?>">
							<option value=""><?php esc_html_e( 'Bulk actions', 'coywolf-seo' ); ?></option>
							<option value="enable"><?php esc_html_e( 'Enable', 'coywolf-seo' ); ?></option>
							<option value="disable"><?php esc_html_e( 'Disable', 'coywolf-seo' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Delete', 'coywolf-seo' ); ?></option>
						</select>
						<button type="submit" class="button action"><?php esc_html_e( 'Apply', 'coywolf-seo' ); ?></button>
					</form>
				</div>
				<div class="alignleft actions">
					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
						<input type="hidden" name="page" value="<?php echo esc_attr( Coywolf_SEO_Redirects::SLUG ); ?>" />
						<?php if ( '' !== $search ) : ?>
							<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>" />
						<?php endif; ?>
						<select name="rtype" aria-label="<?php esc_attr_e( 'Filter by type', 'coywolf-seo' ); ?>">
							<option value=""><?php esc_html_e( 'All types', 'coywolf-seo' ); ?></option>
							<?php foreach ( $types as $code => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $code ); ?>" <?php selected( $f_type, $code ); ?>><?php echo esc_html( (string) $code ); ?></option>
							<?php endforeach; ?>
						</select>
						<select name="rstatus" aria-label="<?php esc_attr_e( 'Filter by status', 'coywolf-seo' ); ?>">
							<option value=""><?php esc_html_e( 'All statuses', 'coywolf-seo' ); ?></option>
							<option value="enabled" <?php selected( $f_status, 'enabled' ); ?>><?php esc_html_e( 'Enabled', 'coywolf-seo' ); ?></option>
							<option value="disabled" <?php selected( $f_status, 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'coywolf-seo' ); ?></option>
						</select>
						<button type="submit" class="button"><?php esc_html_e( 'Filter', 'coywolf-seo' ); ?></button>
						<?php if ( '' !== $search || $f_type || '' !== $f_status ) : ?>
							<a class="button-link" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Clear', 'coywolf-seo' ); ?></a>
						<?php endif; ?>
					</form>
				</div>
				<?php $this->render_pagination( $total_rules, $total_pages, $paged, $list_url ); ?>
				<br class="clear" />
			</div>

			<table class="widefat striped coywolf-seo-rules">
				<thead>
					<tr>
						<th class="coywolf-seo-col-cb"><input type="checkbox" id="coywolf-seo-cb-all" aria-label="<?php esc_attr_e( 'Select all rules', 'coywolf-seo' ); ?>" /></th>
						<th class="coywolf-seo-col-on"><?php esc_html_e( 'On', 'coywolf-seo' ); ?></th>
						<th><?php esc_html_e( 'Source', 'coywolf-seo' ); ?></th>
						<th><?php esc_html_e( 'Target', 'coywolf-seo' ); ?></th>
						<th><?php esc_html_e( 'Type', 'coywolf-seo' ); ?></th>
						<th><?php esc_html_e( 'Hits', 'coywolf-seo' ); ?></th>
						<th><?php esc_html_e( 'Last hit', 'coywolf-seo' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'coywolf-seo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rules ) ) : ?>
						<tr><td colspan="8"><?php echo ( '' !== $search || $f_type || '' !== $f_status ) ? esc_html__( 'No rules match the current search or filters.', 'coywolf-seo' ) : esc_html__( 'No redirects yet — add the first one above.', 'coywolf-seo' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rules as $rule ) : ?>
						<tr class="<?php echo $rule->enabled ? '' : 'coywolf-seo-disabled'; ?>" id="coywolf-seo-rule-<?php echo esc_attr( (string) $rule->id ); ?>">
							<td><input type="checkbox" class="coywolf-seo-cb" name="rule_ids[]" value="<?php echo esc_attr( (string) $rule->id ); ?>" form="coywolf-seo-bulk" aria-label="<?php esc_attr_e( 'Select rule', 'coywolf-seo' ); ?>" /></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coywolf-seo-inline-form">
									<input type="hidden" name="action" value="coywolf_seo_redirect_row" />
									<?php wp_nonce_field( 'coywolf_seo_redirect_row' ); ?>
									<input type="hidden" name="row_action" value="<?php echo $rule->enabled ? 'disable' : 'enable'; ?>" />
									<input type="hidden" name="row_id" value="<?php echo esc_attr( (string) $rule->id ); ?>" />
									<button type="submit" class="button-link coywolf-seo-toggle coywolf-seo-toggle-<?php echo $rule->enabled ? 'on' : 'off'; ?>" title="<?php echo esc_attr( $rule->enabled ? __( 'Disable', 'coywolf-seo' ) : __( 'Enable', 'coywolf-seo' ) ); ?>"><?php echo $rule->enabled ? '&#9679;' : '&#9675;'; ?></button>
								</form>
							</td>
							<td>
								<code><?php echo esc_html( $rule->source ); ?></code>
								<?php if ( $rule->is_regex ) : ?>
									<span class="coywolf-seo-badge coywolf-seo-badge-regex"><?php esc_html_e( 'regex', 'coywolf-seo' ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $rule->note ) : ?>
									<br /><span class="description"><?php echo esc_html( $rule->note ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo 410 === (int) $rule->type ? '—' : '<code>' . esc_html( $rule->target ) . '</code>'; ?></td>
							<td><span class="coywolf-seo-badge coywolf-seo-badge-<?php echo esc_attr( (string) $rule->type ); ?>"><?php echo esc_html( (string) $rule->type ); ?></span></td>
							<td><?php echo esc_html( number_format_i18n( (int) $rule->hits ) ); ?></td>
							<td><?php echo $rule->last_hit ? esc_html( gmdate( 'M j, Y', strtotime( $rule->last_hit ) ) ) : '—'; ?></td>
							<td class="coywolf-seo-cell-actions">
								<button type="button" class="button-link coywolf-seo-edit-toggle" data-rule="<?php echo esc_attr( (string) $rule->id ); ?>" aria-expanded="false" aria-controls="coywolf-seo-edit-<?php echo esc_attr( (string) $rule->id ); ?>"><?php esc_html_e( 'Edit', 'coywolf-seo' ); ?></button>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coywolf-seo-inline-form coywolf-seo-delete-form">
									<input type="hidden" name="action" value="coywolf_seo_redirect_row" />
									<?php wp_nonce_field( 'coywolf_seo_redirect_row' ); ?>
									<input type="hidden" name="row_action" value="delete" />
									<input type="hidden" name="row_id" value="<?php echo esc_attr( (string) $rule->id ); ?>" />
									<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete', 'coywolf-seo' ); ?></button>
								</form>
							</td>
						</tr>
						<tr class="coywolf-seo-edit-row" id="coywolf-seo-edit-<?php echo esc_attr( (string) $rule->id ); ?>" style="display:none">
							<td colspan="8">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coywolf-seo-edit-form">
									<input type="hidden" name="action" value="coywolf_seo_redirect_save" />
									<?php wp_nonce_field( 'coywolf_seo_redirect_save' ); ?>
									<input type="hidden" name="rule_id" value="<?php echo esc_attr( (string) $rule->id ); ?>" />
									<input type="hidden" name="redirect[enabled]" value="<?php echo esc_attr( (string) (int) $rule->enabled ); ?>" />
									<label><?php esc_html_e( 'Source', 'coywolf-seo' ); ?> <input type="text" name="redirect[source]" class="regular-text" value="<?php echo esc_attr( $rule->source ); ?>" required /></label>
									<label><?php esc_html_e( 'Target', 'coywolf-seo' ); ?> <input type="text" name="redirect[target]" class="regular-text" value="<?php echo esc_attr( $rule->target ); ?>" /></label>
									<label><?php esc_html_e( 'Type', 'coywolf-seo' ); ?>
										<select name="redirect[type]">
											<?php foreach ( $types as $code => $label ) : ?>
												<option value="<?php echo esc_attr( (string) $code ); ?>" <?php selected( (int) $rule->type, $code ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
									<label><input type="checkbox" name="redirect[is_regex]" value="1" <?php checked( (bool) $rule->is_regex ); ?> /> <?php esc_html_e( 'Regex', 'coywolf-seo' ); ?></label>
									<label><?php esc_html_e( 'Query strings', 'coywolf-seo' ); ?>
										<select name="redirect[query_mode]">
											<option value="pass" <?php selected( $rule->query_mode, 'pass' ); ?>><?php esc_html_e( 'Ignore, pass to target', 'coywolf-seo' ); ?></option>
											<option value="ignore" <?php selected( $rule->query_mode, 'ignore' ); ?>><?php esc_html_e( 'Ignore, drop', 'coywolf-seo' ); ?></option>
											<option value="exact" <?php selected( $rule->query_mode, 'exact' ); ?>><?php esc_html_e( 'Match exactly', 'coywolf-seo' ); ?></option>
										</select>
									</label>
									<label><?php esc_html_e( 'Note', 'coywolf-seo' ); ?> <input type="text" name="redirect[note]" class="regular-text" value="<?php echo esc_attr( $rule->note ); ?>" /></label>
									<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'coywolf-seo' ); ?></button>
									<button type="button" class="button coywolf-seo-edit-toggle" data-rule="<?php echo esc_attr( (string) $rule->id ); ?>"><?php esc_html_e( 'Cancel', 'coywolf-seo' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div class="tablenav bottom">
				<?php $this->render_pagination( $total_rules, $total_pages, $paged, $list_url ); ?>
				<br class="clear" />
			</div>

			</div><!-- .coywolf-seo-rules-list -->

		</div>
		<?php
	}
}
