<?php
/**
 * Post ↔ Page type switcher.
 *
 * Changes a post's type between "post" and "page" from the Posts/Pages list
 * screens (a Bulk Actions item and a Quick Edit control) and from the post
 * editor (block and classic). Beyond a plain type change it can, in one step:
 *
 *   - assign a PARENT when a post becomes a page, or a CATEGORY when a page
 *     becomes a post (chosen in an accessible modal);
 *   - create a 301 redirect from the old URL to the new one when the permalink
 *     path changes, through the plugin's Redirect Manager;
 *   - be undone — a notice offers to revert the type, parent/category, and the
 *     auto-created redirect for the whole batch.
 *
 * The type change itself uses core set_post_type() (a direct, hook-free write),
 * so the parent, category, redirect, and undo record are all applied here
 * explicitly and in an order that keeps the new permalink correct.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The post ↔ page switcher.
 */
final class Coywolf_SEO_Post_Type_Switch {

	/**
	 * AJAX action and its nonce action.
	 */
	const AJAX_ACTION = 'coywolf_seo_switch_type';

	/**
	 * Undo admin action (nonce is UNDO_ACTION . '_' . $batch).
	 */
	const UNDO_ACTION = 'coywolf_seo_switch_undo';

	/**
	 * Per-post undo snapshot meta key.
	 */
	const UNDO_META = '_coywolf_seo_switch_undo';

	/**
	 * Query arg carrying a completed batch token to the result notice.
	 */
	const NOTICE_ARG = 'coywolf_seo_switched';

	/**
	 * Query arg carrying the count of reverted items to the undone notice.
	 */
	const UNDONE_ARG = 'coywolf_seo_unswitched';

	/**
	 * Transient prefix for a batch's stored result (keyed by token).
	 */
	const BATCH_PREFIX = 'coywolf_seo_switch_';

	/**
	 * Hidden list-table column whose presence makes quick_edit_custom_box fire.
	 */
	const COLUMN = 'coywolf_seo_type';

	/**
	 * Bulk Actions dropdown values.
	 */
	const BULK_TO_PAGE = 'coywolf_seo_to_page';
	const BULK_TO_POST = 'coywolf_seo_to_post';

	/**
	 * The Redirect Manager engine (injected). Its hooks may be inactive when the
	 * Redirects feature is off, but the object and its API are always present.
	 *
	 * @var Coywolf_SEO_Redirects
	 */
	private $redirects;

	/**
	 * Constructor.
	 *
	 * @param Coywolf_SEO_Redirects $redirects Redirect Manager engine.
	 */
	public function __construct( Coywolf_SEO_Redirects $redirects ) {
		$this->redirects = $redirects;
	}

	/**
	 * Hook everything up (admin + admin-ajax only).
	 */
	public function init() {
		add_filter( 'bulk_actions-edit-post', array( $this, 'add_bulk_action_posts' ) );
		add_filter( 'bulk_actions-edit-page', array( $this, 'add_bulk_action_pages' ) );
		add_filter( 'handle_bulk_actions-edit-post', array( $this, 'handle_bulk_fallback' ), 10, 3 );
		add_filter( 'handle_bulk_actions-edit-page', array( $this, 'handle_bulk_fallback' ), 10, 3 );

		add_filter( 'manage_post_posts_columns', array( $this, 'add_type_column' ) );
		add_filter( 'manage_page_posts_columns', array( $this, 'add_type_column' ) );
		add_action( 'manage_post_posts_custom_column', array( $this, 'render_type_column' ), 10, 2 );
		add_action( 'manage_page_posts_custom_column', array( $this, 'render_type_column' ), 10, 2 );
		add_filter( 'default_hidden_columns', array( $this, 'default_hidden_columns' ), 10, 2 );

		add_action( 'quick_edit_custom_box', array( $this, 'render_quick_edit_control' ), 10, 2 );
		add_action( 'post_submitbox_misc_actions', array( $this, 'render_submitbox_control' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_switch' ) );
		add_action( 'admin_footer-edit.php', array( $this, 'print_modal_template' ) );

		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_switch_type' ) );
		add_action( 'admin_action_' . self::UNDO_ACTION, array( $this, 'handle_undo' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_result_notice' ) );

		add_filter( 'coywolf_seo_duplicate_excluded_meta_keys', array( $this, 'exclude_undo_meta_from_duplicates' ) );
	}

	// Pure helpers (no WordPress calls) — unit-tested.

	/**
	 * Why a post cannot be switched, or '' when it can. Kept pure so the
	 * eligibility rules live in one testable place.
	 *
	 * @param string $current_type  Current post type.
	 * @param string $target_type   Desired post type.
	 * @param string $post_status   Post status.
	 * @param int    $post_id       Post ID.
	 * @param int    $front_page_id page_on_front (0 if none).
	 * @param int    $posts_page_id page_for_posts (0 if none).
	 * @return string Reason code, or '' if eligible.
	 */
	public static function switch_blocker( $current_type, $target_type, $post_status, $post_id, $front_page_id, $posts_page_id ) {
		$switchable = array( 'post', 'page' );
		if ( ! in_array( $current_type, $switchable, true ) || ! in_array( $target_type, $switchable, true ) ) {
			return 'not_switchable';
		}
		if ( $current_type === $target_type ) {
			return 'same_type';
		}
		if ( 'trash' === $post_status ) {
			return 'trash';
		}
		if ( 'auto-draft' === $post_status ) {
			return 'not_saved'; // A brand-new post has no real URL to switch yet.
		}
		if ( $front_page_id > 0 && (int) $post_id === (int) $front_page_id ) {
			return 'front_page';
		}
		if ( $posts_page_id > 0 && (int) $post_id === (int) $posts_page_id ) {
			return 'posts_page';
		}
		return '';
	}

	/**
	 * Whether a type switch should create a 301 from old to new.
	 *
	 * @param string $old_path        Old normalized path.
	 * @param string $new_path        New normalized path.
	 * @param bool   $is_public       Old post was publicly viewable.
	 * @param bool   $redirects_on    Redirects feature enabled.
	 * @param bool   $already_covered A rule already matches the old path.
	 * @return bool
	 */
	public static function should_create_redirect( $old_path, $new_path, $is_public, $redirects_on, $already_covered ) {
		return $redirects_on
			&& $is_public
			&& ! $already_covered
			&& '' !== $old_path
			&& '/' !== $old_path
			&& $old_path !== $new_path;
	}

	/**
	 * Build the per-post undo snapshot.
	 *
	 * @param string $from_type    Type before the switch.
	 * @param int    $old_parent   Parent before the switch.
	 * @param int[]  $old_cat_ids  Category ids before the switch.
	 * @param int    $redirect_id  Auto-created rule id (0 if none).
	 * @param int[]  $children     Child page ids reparented away.
	 * @param string $batch        Batch token.
	 * @param int    $time         Timestamp.
	 * @return array
	 */
	public static function build_undo_record( $from_type, $old_parent, array $old_cat_ids, $redirect_id, array $children, $batch, $time ) {
		return array(
			'from'        => (string) $from_type,
			'parent'      => (int) $old_parent,
			'cats'        => array_values( array_map( 'intval', $old_cat_ids ) ),
			'redirect_id' => (int) $redirect_id,
			'children'    => array_values( array_map( 'intval', $children ) ),
			'batch'       => (string) $batch,
			'time'        => (int) $time,
		);
	}

	/**
	 * Human-readable skip reasons.
	 *
	 * @return array<string,string>
	 */
	private static function skip_labels() {
		return array(
			'same_type'      => __( 'already that type', 'coywolf-seo' ),
			'not_switchable' => __( 'not a post or page', 'coywolf-seo' ),
			'trash'          => __( 'in the trash', 'coywolf-seo' ),
			'not_saved'      => __( 'not saved yet', 'coywolf-seo' ),
			'front_page'     => __( 'front page', 'coywolf-seo' ),
			'posts_page'     => __( 'posts page', 'coywolf-seo' ),
			'no_permission'  => __( 'no permission', 'coywolf-seo' ),
			'db_error'       => __( 'could not be changed', 'coywolf-seo' ),
		);
	}

	/**
	 * The result-notice summary line.
	 *
	 * @param array  $counts      { converted:int, redirects:int, skipped:array }.
	 * @param string $target_type The type converted to.
	 * @return string
	 */
	public static function summary_message( array $counts, $target_type ) {
		$converted = (int) ( $counts['converted'] ?? 0 );
		$redirects = (int) ( $counts['redirects'] ?? 0 );
		$skipped   = ( isset( $counts['skipped'] ) && is_array( $counts['skipped'] ) ) ? $counts['skipped'] : array();

		if ( 'page' === $target_type ) {
			/* translators: %d: number of posts changed to pages. */
			$msg = sprintf( _n( 'Changed %d post to a page.', 'Changed %d posts to pages.', max( 1, $converted ), 'coywolf-seo' ), $converted );
		} else {
			/* translators: %d: number of pages changed to posts. */
			$msg = sprintf( _n( 'Changed %d page to a post.', 'Changed %d pages to posts.', max( 1, $converted ), 'coywolf-seo' ), $converted );
		}

		if ( $redirects > 0 ) {
			/* translators: %d: number of redirects created. */
			$msg .= ' ' . sprintf( _n( '%d redirect created.', '%d redirects created.', $redirects, 'coywolf-seo' ), $redirects );
		}

		if ( ! empty( $skipped ) ) {
			$by_reason = array();
			foreach ( $skipped as $s ) {
				$reason               = is_array( $s ) ? (string) ( $s['reason'] ?? '' ) : (string) $s;
				$by_reason[ $reason ] = ( $by_reason[ $reason ] ?? 0 ) + 1;
			}
			$labels = self::skip_labels();
			$parts  = array();
			foreach ( array_keys( $by_reason ) as $reason ) {
				$parts[] = isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason;
			}
			$total = array_sum( $by_reason );
			/* translators: 1: number skipped, 2: comma-separated reasons. */
			$msg .= ' ' . sprintf( _n( 'Skipped %1$d (%2$s).', 'Skipped %1$d (%2$s).', $total, 'coywolf-seo' ), $total, implode( ', ', $parts ) );
		}

		return $msg;
	}

	// List-table surfaces.

	/**
	 * Add "Change to Page" to the Posts bulk actions.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function add_bulk_action_posts( $actions ) {
		if ( current_user_can( $this->target_cap( 'page' ) ) ) {
			$actions[ self::BULK_TO_PAGE ] = __( 'Change to Page', 'coywolf-seo' );
		}
		return $actions;
	}

	/**
	 * Add "Change to Post" to the Pages bulk actions.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function add_bulk_action_pages( $actions ) {
		if ( current_user_can( $this->target_cap( 'post' ) ) ) {
			$actions[ self::BULK_TO_POST ] = __( 'Change to Post', 'coywolf-seo' );
		}
		return $actions;
	}

	/**
	 * No-JS fallback: WordPress submitted our bulk action normally (the JS modal
	 * did not intercept). Convert with default parent/category. The bulk nonce
	 * was already verified by core before this filter runs.
	 *
	 * @param string $sendback  Redirect URL.
	 * @param string $doaction  The chosen action.
	 * @param int[]  $post_ids  Selected post ids.
	 * @return string
	 */
	public function handle_bulk_fallback( $sendback, $doaction, $post_ids ) {
		if ( self::BULK_TO_PAGE !== $doaction && self::BULK_TO_POST !== $doaction ) {
			return $sendback;
		}
		$target = ( self::BULK_TO_PAGE === $doaction ) ? 'page' : 'post';
		if ( ! current_user_can( $this->target_cap( $target ) ) ) {
			return $sendback;
		}
		$batch = $this->run_batch( array_map( 'absint', (array) $post_ids ), $target, 0, 0 );
		return add_query_arg( self::NOTICE_ARG, $batch, $sendback );
	}

	/**
	 * Register the hidden "Type" column so quick_edit_custom_box fires.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_type_column( $columns ) {
		$columns[ self::COLUMN ] = __( 'Type', 'coywolf-seo' );
		return $columns;
	}

	/**
	 * Render the (normally hidden) Type column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_type_column( $column, $post_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$obj = get_post_type_object( get_post_type( $post_id ) );
		echo esc_html( $obj ? $obj->labels->singular_name : get_post_type( $post_id ) );
	}

	/**
	 * Hide the Type column by default on the two list screens.
	 *
	 * @param array     $hidden Hidden columns.
	 * @param WP_Screen $screen Current screen.
	 * @return array
	 */
	public function default_hidden_columns( $hidden, $screen ) {
		if ( $screen && in_array( $screen->id, array( 'edit-post', 'edit-page' ), true ) && ! in_array( self::COLUMN, (array) $hidden, true ) ) {
			$hidden[] = self::COLUMN;
		}
		return $hidden;
	}

	/**
	 * Output the Quick Edit "Change to…" trigger (JS relocates it beneath the
	 * Password field). Rendered once per screen as the inline-edit template.
	 *
	 * @param string $column_name Custom column being rendered.
	 * @param string $post_type   Post type of the list screen.
	 */
	public function render_quick_edit_control( $column_name, $post_type ) {
		if ( self::COLUMN !== $column_name || ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
			return;
		}
		$target = ( 'post' === $post_type ) ? 'page' : 'post';
		if ( ! current_user_can( $this->target_cap( $target ) ) ) {
			return;
		}
		$label = ( 'page' === $target ) ? __( 'Change to Page', 'coywolf-seo' ) : __( 'Change to Post', 'coywolf-seo' );
		?>
		<div class="coywolf-seo-switch-qe inline-edit-group wp-clearfix" data-target="<?php echo esc_attr( $target ); ?>">
			<button type="button" class="button coywolf-seo-switch-trigger" data-context="quick"><?php echo esc_html( $label ); ?></button>
		</div>
		<?php
	}

	/**
	 * Output the classic-editor "Change to…" control in the Publish box.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_submitbox_control( $post ) {
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}
		$target  = ( 'post' === $post->post_type ) ? 'page' : 'post';
		$blocker = self::switch_blocker( $post->post_type, $target, $post->post_status, $post->ID, (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) );
		if ( '' !== $blocker || ! current_user_can( $this->target_cap( $target ) ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		$current = get_post_type_object( $post->post_type );
		$label   = ( 'page' === $target ) ? __( 'Change to Page', 'coywolf-seo' ) : __( 'Change to Post', 'coywolf-seo' );
		?>
		<div class="misc-pub-section coywolf-seo-switch-submitbox" data-target="<?php echo esc_attr( $target ); ?>" data-post="<?php echo esc_attr( (string) $post->ID ); ?>">
			<span class="dashicons dashicons-randomize" aria-hidden="true"></span>
			<?php
			printf(
				/* translators: %s: current post type singular name. */
				esc_html__( 'Type: %s', 'coywolf-seo' ),
				'<strong>' . esc_html( $current ? $current->labels->singular_name : $post->post_type ) . '</strong>'
			);
			?>
			<button type="button" class="button-link coywolf-seo-switch-trigger" data-context="classic" style="margin-left:6px;"><?php echo esc_html( $label ); ?></button>
		</div>
		<?php
	}

	// Assets.

	/**
	 * Enqueue the list-screen / classic-editor script + styles, gated to the
	 * post/page list and non-block post/page editors.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		$context = '';
		if ( 'edit.php' === $hook ) {
			$context = 'list';
		} elseif ( 'post.php' === $hook && ! $screen->is_block_editor() ) {
			$context = 'classic';
		}
		if ( '' === $context ) {
			return; // Block editor is handled by enqueue_editor_switch().
		}

		wp_enqueue_style( 'coywolf-seo-type-switch', COYWOLF_SEO_URL . 'css/type-switch.css', array(), Coywolf_SEO::VERSION );
		wp_enqueue_script(
			'coywolf-seo-type-switch',
			COYWOLF_SEO_URL . 'js/type-switch.js',
			array( 'jquery', 'wp-a11y' ),
			Coywolf_SEO::VERSION,
			true
		);

		$target = ( 'post' === $screen->post_type ) ? 'page' : 'post';
		wp_localize_script(
			'coywolf-seo-type-switch',
			'coywolf_seo_type_switch',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::AJAX_ACTION ),
				'action'     => self::AJAX_ACTION,
				'context'    => $context,
				'screenType' => $screen->post_type,
				'target'     => $target,
				'chooser'    => ( 'page' === $target ) ? 'parent' : 'category',
				'bulkAction' => ( 'page' === $target ) ? self::BULK_TO_PAGE : self::BULK_TO_POST,
				'i18n'       => $this->i18n_strings(),
			)
		);
	}

	/**
	 * Enqueue the block-editor "Post Type" control.
	 */
	public function enqueue_editor_switch() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! in_array( (string) $screen->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}
		global $post;
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$target  = ( 'post' === $post->post_type ) ? 'page' : 'post';
		$blocker = self::switch_blocker( $post->post_type, $target, $post->post_status, $post->ID, (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) );
		$can     = '' === $blocker && current_user_can( $this->target_cap( $target ) ) && current_user_can( 'edit_post', $post->ID );

		wp_enqueue_script(
			'coywolf-seo-type-switch-editor',
			COYWOLF_SEO_URL . 'js/type-switch-editor.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n' ),
			Coywolf_SEO::VERSION,
			true
		);
		wp_set_script_translations( 'coywolf-seo-type-switch-editor', 'coywolf-seo' );

		$current = get_post_type_object( $post->post_type );
		$to      = get_post_type_object( $target );
		wp_localize_script(
			'coywolf-seo-type-switch-editor',
			'coywolf_seo_type_switch_editor',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::AJAX_ACTION ),
				'action'       => self::AJAX_ACTION,
				'postId'       => (int) $post->ID,
				'currentType'  => $post->post_type,
				'currentLabel' => $current ? $current->labels->singular_name : $post->post_type,
				'target'       => $target,
				'targetLabel'  => $to ? $to->labels->singular_name : $target,
				'canSwitch'    => (bool) $can,
				'i18n'         => $this->i18n_strings(),
			)
		);
	}

	/**
	 * Print the modal body template for the list screens: a parent-page chooser
	 * (posts list) or a category chooser (pages list). Server-rendered so it is
	 * hierarchical and translated natively.
	 */
	public function print_modal_template() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}
		$target = ( 'post' === $screen->post_type ) ? 'page' : 'post';
		if ( ! current_user_can( $this->target_cap( $target ) ) ) {
			return;
		}
		?>
		<template id="coywolf-seo-switch-chooser-tpl">
			<?php if ( 'page' === $target ) : ?>
				<p>
					<label for="coywolf-seo-switch-parent"><?php esc_html_e( 'Parent page (optional)', 'coywolf-seo' ); ?></label><br />
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- core wp_dropdown_pages() prints escaped markup; the option labels are translator strings.
					wp_dropdown_pages(
						array(
							'id'                => 'coywolf-seo-switch-parent',
							'name'              => 'parent',
							'show_option_none'  => __( '— No parent —', 'coywolf-seo' ),
							'option_none_value' => '0',
						)
					);
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</p>
			<?php else : ?>
				<p>
					<label for="coywolf-seo-switch-category"><?php esc_html_e( 'Category (optional)', 'coywolf-seo' ); ?></label><br />
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- core wp_dropdown_categories() prints escaped markup; the option labels are translator strings.
					wp_dropdown_categories(
						array(
							'id'                => 'coywolf-seo-switch-category',
							'name'              => 'category',
							'hide_empty'        => false,
							'hierarchical'      => true,
							'show_option_none'  => __( '— Default category —', 'coywolf-seo' ),
							'option_none_value' => '0',
						)
					);
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</p>
			<?php endif; ?>
		</template>
		<?php
	}

	/**
	 * Shared UI strings for the JS.
	 *
	 * @return array<string,string>
	 */
	private function i18n_strings() {
		return array(
			'changeToPage'  => __( 'Change to Page', 'coywolf-seo' ),
			'changeToPost'  => __( 'Change to Post', 'coywolf-seo' ),
			'typeLabel'     => __( 'Type:', 'coywolf-seo' ),
			'convert'       => __( 'Convert', 'coywolf-seo' ),
			'cancel'        => __( 'Cancel', 'coywolf-seo' ),
			'noneSelected'  => __( 'Select at least one item first.', 'coywolf-seo' ),
			'redirectNote'  => __( 'A 301 redirect is created automatically if the URL changes. You can undo this afterwards.', 'coywolf-seo' ),
			'editorNote'    => __( 'The editor will reload as the new type. Set the parent or category in the sidebar afterwards. A 301 redirect is created if the URL changes.', 'coywolf-seo' ),
			'saveFirst'     => __( 'Please save your changes before switching the type.', 'coywolf-seo' ),
			'working'       => __( 'Changing…', 'coywolf-seo' ),
			'failed'        => __( 'The change could not be completed.', 'coywolf-seo' ),
			/* translators: %d: number of selected items. */
			'confirmToPage' => __( 'Change %d selected item(s) to pages?', 'coywolf-seo' ),
			/* translators: %d: number of selected items. */
			'confirmToPost' => __( 'Change %d selected item(s) to posts?', 'coywolf-seo' ),
		);
	}

	// AJAX + conversion.

	/**
	 * Convert the posted ids to the target type (with optional parent/category).
	 */
	public function ajax_switch_type() {
		check_ajax_referer( self::AJAX_ACTION );

		$ids       = isset( $_POST['ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) ) ) ) : array();
		$target    = isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : '';
		$parent_id = isset( $_POST['parent'] ) ? absint( wp_unslash( $_POST['parent'] ) ) : 0;
		$cat       = isset( $_POST['category'] ) ? absint( wp_unslash( $_POST['category'] ) ) : 0;
		$context   = isset( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : 'list';

		if ( empty( $ids ) || ! in_array( $target, array( 'post', 'page' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to change.', 'coywolf-seo' ) ), 400 );
		}
		if ( ! current_user_can( $this->target_cap( $target ) ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to create that type.', 'coywolf-seo' ) ), 403 );
		}

		// Validate the chosen parent / category up front.
		if ( 'page' === $target && $parent_id > 0 ) {
			$parent_post = get_post( $parent_id );
			if ( ! $parent_post
					|| 'page' !== $parent_post->post_type
					|| in_array( $parent_post->post_status, array( 'trash', 'auto-draft' ), true )
					|| in_array( $parent_id, $ids, true )
					|| ! current_user_can( 'edit_post', $parent_id ) ) {
				$parent_id = 0;
			}
		} else {
			$parent_id = 0;
		}
		if ( 'post' === $target && $cat > 0 ) {
			if ( ! term_exists( $cat, 'category' ) ) {
				$cat = 0;
			}
		} else {
			$cat = 0;
		}

		$batch = $this->run_batch( $ids, $target, $parent_id, $cat );
		$data  = $this->read_batch( $batch );
		$count = $data ? (int) $data['counts']['converted'] : 0;

		$redirect_to = ( 'editor' === $context && 1 === count( $ids ) )
			? add_query_arg( self::NOTICE_ARG, $batch, get_edit_post_link( $ids[0], 'raw' ) )
			: add_query_arg( self::NOTICE_ARG, $batch, $this->safe_referer() );

		wp_send_json_success(
			array(
				'batch'      => $batch,
				'converted'  => $count,
				'message'    => $data ? $data['message'] : '',
				'redirectTo' => $redirect_to,
			)
		);
	}

	/**
	 * Convert a set of ids and store the batch result. Returns the batch token.
	 *
	 * Two phases: every eligible post is snapshotted BEFORE any mutation, so
	 * converting one post can never corrupt another's captured URL/parent (a page
	 * and one of its descendants selected together). The result is written
	 * incrementally, so a timeout mid-run still leaves a recoverable batch.
	 *
	 * @param int[]  $ids       Post ids.
	 * @param string $target    Target type.
	 * @param int    $parent_id Parent page id (to page).
	 * @param int    $cat_id    Category id (to post).
	 * @return string Batch token.
	 */
	private function run_batch( array $ids, $target, $parent_id, $cat_id ) {
		// A lowercase token so sanitize_key() round-trips it unchanged in the
		// notice/undo URLs (wp_generate_password() is otherwise mixed-case, and
		// sanitize_key() would lowercase it and miss the stored transient).
		$batch  = self::BATCH_PREFIX . strtolower( wp_generate_password( 20, false, false ) );
		$result = array(
			'converted' => array(),
			'redirects' => 0,
			'skipped'   => array(),
		);

		$front = (int) get_option( 'page_on_front' );
		$posts = (int) get_option( 'page_for_posts' );

		// Phase 1: validate + snapshot every eligible post, before any mutation.
		$plan = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				$result['skipped'][] = array(
					'id'     => $id,
					'title'  => get_the_title( $post ),
					'reason' => 'no_permission',
				);
				continue;
			}
			$blocker = self::switch_blocker( $post->post_type, $target, $post->post_status, $post->ID, $front, $posts );
			if ( '' !== $blocker ) {
				$result['skipped'][] = array(
					'id'     => $id,
					'title'  => get_the_title( $post ),
					'reason' => $blocker,
				);
				continue;
			}
			$plan[] = array(
				'post'       => $post,
				'from'       => $post->post_type,
				'old'        => Coywolf_SEO_Redirects::normalize( (string) get_permalink( $post ) ),
				'is_public'  => is_post_publicly_viewable( $post ),
				'old_parent' => (int) $post->post_parent,
				'old_cats'   => wp_get_post_categories( $post->ID ),
			);
		}

		// Phase 2: convert. Persist every few posts so a mid-run timeout still
		// leaves an undoable batch.
		$done = 0;
		foreach ( $plan as $snap ) {
			$this->convert_planned( $snap, $target, $parent_id, $cat_id, $batch, $result );
			++$done;
			if ( 0 === ( $done % 20 ) ) {
				$this->store_result( $batch, $target, $result );
			}
		}
		$this->store_result( $batch, $target, $result );
		return $batch;
	}

	/**
	 * Store the batch result (counts + message) for the notice/undo.
	 *
	 * @param string $batch  Token.
	 * @param string $target Target type.
	 * @param array  $result Accumulator.
	 */
	private function store_result( $batch, $target, array $result ) {
		$counts = array(
			'converted' => count( $result['converted'] ),
			'redirects' => $result['redirects'],
			'skipped'   => $result['skipped'],
		);
		$this->store_batch(
			$batch,
			array(
				'converted' => $result['converted'],
				'target'    => $target,
				'counts'    => $counts,
				'message'   => self::summary_message( $counts, $target ),
			)
		);
	}

	/**
	 * Convert one snapshotted post. Appends to $result; a skip never throws.
	 *
	 * @param array  $snap      Snapshot: post, from, old, is_public, old_parent, old_cats.
	 * @param string $target    Target type.
	 * @param int    $parent_id Parent page id (to page).
	 * @param int    $cat_id    Category id (to post).
	 * @param string $batch     Batch token.
	 * @param array  $result    Accumulator (by reference).
	 */
	private function convert_planned( array $snap, $target, $parent_id, $cat_id, $batch, array &$result ) {
		$post = $snap['post'];
		$id   = (int) $post->ID;
		$from = (string) $snap['from'];
		$old  = $snap['old'];

		if ( ! set_post_type( $id, $target ) ) {
			$result['skipped'][] = array(
				'id'     => $id,
				'title'  => get_the_title( $post ),
				'reason' => 'db_error',
			);
			return;
		}

		$children = array();
		if ( 'page' === $target ) {
			if ( $parent_id > 0 ) {
				$this->set_parent_raw( $id, $parent_id );
			}
			unstick_post( $id ); // A page is never sticky.
		} else {
			$children = $this->reparent_children( $id, (int) $snap['old_parent'] );
			$this->set_parent_raw( $id, 0 );
			wp_set_post_categories( $id, ( $cat_id > 0 ) ? array( $cat_id ) : array() );
			delete_post_meta( $id, '_wp_page_template' ); // A page template is meaningless on a post.
		}

		$new = Coywolf_SEO_Redirects::normalize( (string) get_permalink( $id ) );

		$rule_id = 0;
		// Gated on the feature so a site with Redirects off never touches the
		// redirect tables (which may predate a schema upgrade while off).
		if ( Coywolf_SEO_Options::feature_enabled( 'redirects' ) ) {
			// The NEW url now serves this content, so a rule that redirects it
			// away (e.g. an auto-301 left by an earlier conversion of this URL)
			// would hijack it — drop any such rule before adding the new one.
			if ( '' !== $new['path'] && '/' !== $new['path'] ) {
				$this->redirects->delete_rules_by_source_path( $new['path'] );
			}
			if ( self::should_create_redirect( $old['path'], $new['path'], (bool) $snap['is_public'], true, (bool) $this->redirects->match( $old['path'], $old['query'] ) ) ) {
				$saved = $this->redirects->save_rule(
					array(
						'source' => $old['path'],
						'target' => $new['path'],
						'type'   => 301,
						'note'   => __( 'Automatic: post type changed', 'coywolf-seo' ),
					)
				);
				if ( ! is_wp_error( $saved ) ) {
					$rule_id             = (int) $saved;
					$result['redirects'] = (int) $result['redirects'] + 1;
				}
			}
			// The auto 301 supersedes any pending "URL changed" prompt.
			$move = $this->redirects->pending_move_for_post( $id );
			if ( $move ) {
				$this->redirects->delete_move( (int) $move->id );
			}
		}

		update_post_meta(
			$id,
			self::UNDO_META,
			self::build_undo_record( $from, (int) $snap['old_parent'], (array) $snap['old_cats'], $rule_id, $children, $batch, time() )
		);

		/**
		 * Fires after a post's type is switched between post and page.
		 *
		 * @param int    $post_id The post ID.
		 * @param string $from    The previous post type.
		 * @param string $target  The new post type.
		 */
		do_action( 'coywolf_seo_post_type_switched', $id, $from, $target );

		$result['converted'][] = $id;
	}

	/**
	 * Reparent a page's child pages to a new parent (their grandparent), so
	 * they are not orphaned when the page becomes a post.
	 *
	 * @param int $page_id    The page becoming a post.
	 * @param int $new_parent Where the children move to.
	 * @return int[] Reparented child ids.
	 */
	private function reparent_children( $page_id, $new_parent ) {
		$children = get_posts(
			array(
				'post_type'        => 'page',
				'post_parent'      => $page_id,
				'post_status'      => 'any',
				'numberposts'      => -1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- bounded to one page's direct children.
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		foreach ( $children as $child_id ) {
			$this->set_parent_raw( (int) $child_id, (int) $new_parent );
		}
		return array_map( 'intval', $children );
	}

	/**
	 * Set post_parent without firing update hooks (which would pollute the
	 * redirect move-capture with an intermediate permalink).
	 *
	 * @param int $post_id   Post ID.
	 * @param int $parent_id New parent (0 = none).
	 */
	private function set_parent_raw( $post_id, $parent_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- hook-free parent write; wp_update_post() would trigger the redirect move-capture mid-conversion.
		$wpdb->update( $wpdb->posts, array( 'post_parent' => (int) $parent_id ), array( 'ID' => (int) $post_id ), array( '%d' ), array( '%d' ) );
		clean_post_cache( $post_id );
	}

	// Undo.

	/**
	 * Revert a whole batch: type, parent, categories, children, and the auto
	 * redirect for each post that still carries this batch's undo snapshot.
	 */
	public function handle_undo() {
		$batch = isset( $_GET['batch'] ) ? sanitize_key( wp_unslash( $_GET['batch'] ) ) : '';
		check_admin_referer( self::UNDO_ACTION . '_' . $batch );

		$data = $this->read_batch( $batch );
		if ( ! $data ) {
			wp_die( esc_html__( 'This undo has expired.', 'coywolf-seo' ) );
		}

		$front = (int) get_option( 'page_on_front' );
		$posts = (int) get_option( 'page_for_posts' );

		// Replay in REVERSE conversion order so an ancestor reverted after its
		// descendant restores the hierarchy correctly (mirror of the two-phase
		// convert order).
		$undone = 0;
		foreach ( array_reverse( (array) $data['converted'] ) as $id ) {
			$id   = (int) $id;
			$undo = get_post_meta( $id, self::UNDO_META, true );
			if ( ! is_array( $undo ) || ( $undo['batch'] ?? '' ) !== $batch ) {
				continue;
			}
			if ( ! current_user_can( 'edit_post', $id ) || ! current_user_can( $this->target_cap( (string) $undo['from'] ) ) ) {
				continue;
			}
			// Don't revert a type change that would make the static front page /
			// posts page a post (it must stay a page).
			if ( '' !== self::switch_blocker( get_post_type( $id ), (string) $undo['from'], (string) get_post_status( $id ), $id, $front, $posts ) ) {
				continue;
			}
			$this->revert_one( $id, $undo );
			++$undone;
		}

		delete_transient( $batch );
		$sendback = add_query_arg( self::UNDONE_ARG, $undone, $this->safe_referer() );
		wp_safe_redirect( $sendback );
		exit;
	}

	/**
	 * Revert one post from its undo snapshot.
	 *
	 * @param int   $id   Post ID.
	 * @param array $undo Undo snapshot.
	 */
	private function revert_one( $id, array $undo ) {
		set_post_type( $id, (string) $undo['from'] );
		$this->set_parent_raw( $id, (int) $undo['parent'] );
		foreach ( (array) ( $undo['children'] ?? array() ) as $child_id ) {
			$this->set_parent_raw( (int) $child_id, $id );
		}
		wp_set_object_terms( $id, array_map( 'intval', (array) ( $undo['cats'] ?? array() ) ), 'category' );
		if ( ! empty( $undo['redirect_id'] ) ) {
			$this->redirects->delete_rule( (int) $undo['redirect_id'] );
		}
		delete_post_meta( $id, self::UNDO_META );
	}

	// Notices + batch storage.

	/**
	 * Show the "changed / undo" notice and the "restored" notice on the list and
	 * post-edit screens.
	 */
	public function maybe_show_result_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->base, array( 'edit', 'post' ), true ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notices keyed by a per-user transient token; no state change.
		if ( isset( $_GET[ self::UNDONE_ARG ] ) ) {
			$count = absint( wp_unslash( $_GET[ self::UNDONE_ARG ] ) );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of items restored. */
						_n( '%d item restored to its previous type.', '%d items restored to their previous type.', $count, 'coywolf-seo' ),
						$count
					)
				)
			);
			return;
		}

		$batch = isset( $_GET[ self::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' === $batch ) {
			return;
		}
		$data = $this->read_batch( $batch );
		if ( ! $data ) {
			return;
		}

		$undo_url = wp_nonce_url(
			admin_url( 'admin.php?action=' . self::UNDO_ACTION . '&batch=' . rawurlencode( $batch ) ),
			self::UNDO_ACTION . '_' . $batch
		);
		$can_undo = ! empty( $data['converted'] );
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php echo esc_html( (string) $data['message'] ); ?>
				<?php if ( $can_undo ) : ?>
					&nbsp;<a href="<?php echo esc_url( $undo_url ); ?>" class="button button-small"><?php esc_html_e( 'Undo', 'coywolf-seo' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Store a batch result for the notice/undo, scoped to the current user.
	 *
	 * @param string $batch Token (already prefixed).
	 * @param array  $data  Result data.
	 */
	private function store_batch( $batch, array $data ) {
		$data['user_id'] = get_current_user_id();
		set_transient( $batch, $data, HOUR_IN_SECONDS );
	}

	/**
	 * Read a batch result, only for the user who created it.
	 *
	 * @param string $batch Token.
	 * @return array|null
	 */
	private function read_batch( $batch ) {
		if ( '' === $batch || 0 !== strpos( $batch, self::BATCH_PREFIX ) ) {
			return null;
		}
		$data = get_transient( $batch );
		if ( ! is_array( $data ) || (int) ( $data['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return null;
		}
		return $data;
	}

	// Misc.

	/**
	 * Keep the undo snapshot off duplicated posts.
	 *
	 * @param array $keys Excluded meta keys.
	 * @return array
	 */
	public function exclude_undo_meta_from_duplicates( $keys ) {
		$keys   = (array) $keys;
		$keys[] = self::UNDO_META;
		return $keys;
	}

	/**
	 * The capability required to create a post of the given type.
	 *
	 * @param string $type Post type.
	 * @return string Capability.
	 */
	private function target_cap( $type ) {
		$obj = get_post_type_object( $type );
		return ( $obj && isset( $obj->cap->publish_posts ) ) ? $obj->cap->publish_posts : 'publish_posts';
	}

	/**
	 * A safe same-site referer to send the user back to, falling back to the
	 * Posts list.
	 *
	 * @return string
	 */
	private function safe_referer() {
		$referer = wp_get_referer();
		if ( ! $referer ) {
			$referer = admin_url( 'edit.php' );
		}
		return $referer;
	}
}
