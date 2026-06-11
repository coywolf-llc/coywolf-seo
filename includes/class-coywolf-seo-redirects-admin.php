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
		add_action( 'admin_notices', array( $this, 'maybe_show_deletion_notice' ) );
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
			<h3><?php esc_html_e( 'What should happen to the deleted URL?', 'coywolf-seo' ); ?></h3>
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
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
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
	 * Render the page.
	 */
	public function render() {
		$rules    = $this->redirects->all_rules();
		$pending  = $this->redirects->pending_deletions();
		$types    = Coywolf_SEO_Redirects::types();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only display flags set by our own redirects, each sanitized on read.
		$saved_flag = isset( $_GET['redirect-saved'] ) ? sanitize_text_field( wp_unslash( $_GET['redirect-saved'] ) ) : '';
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
						<button type="button" class="button-link" id="coywolf-seo-qa-more"><?php esc_html_e( 'More options', 'coywolf-seo' ); ?></button>
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
						<span class="coywolf-seo-test-result">
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

			<h2 class="coywolf-seo-rules-heading">
				<?php
				printf(
					/* translators: %d: number of redirect rules. */
					esc_html( _n( '%d rule', '%d rules', count( $rules ), 'coywolf-seo' ) ),
					(int) count( $rules )
				);
				?>
			</h2>
			<table class="widefat striped coywolf-seo-rules">
				<thead>
					<tr>
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
						<tr><td colspan="7"><?php esc_html_e( 'No redirects yet — add the first one above.', 'coywolf-seo' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rules as $rule ) : ?>
						<tr class="<?php echo $rule->enabled ? '' : 'coywolf-seo-disabled'; ?>" id="coywolf-seo-rule-<?php echo esc_attr( (string) $rule->id ); ?>">
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="coywolf-seo-inline-form">
									<input type="hidden" name="action" value="coywolf_seo_redirect_row" />
									<?php wp_nonce_field( 'coywolf_seo_redirect_row' ); ?>
									<input type="hidden" name="row_action" value="<?php echo $rule->enabled ? 'disable' : 'enable'; ?>" />
									<input type="hidden" name="row_id" value="<?php echo esc_attr( (string) $rule->id ); ?>" />
									<button type="submit" class="button-link coywolf-seo-toggle" title="<?php echo esc_attr( $rule->enabled ? __( 'Disable', 'coywolf-seo' ) : __( 'Enable', 'coywolf-seo' ) ); ?>"><?php echo $rule->enabled ? '&#9679;' : '&#9675;'; ?></button>
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
								<button type="button" class="button-link coywolf-seo-edit-toggle" data-rule="<?php echo esc_attr( (string) $rule->id ); ?>"><?php esc_html_e( 'Edit', 'coywolf-seo' ); ?></button>
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
							<td colspan="7">
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

		</div>
		<?php
	}
}
