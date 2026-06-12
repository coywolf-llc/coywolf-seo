<?php
/**
 * Duplicate Post.
 *
 * Adds a "Duplicate" row action to the Posts and Pages list screens that
 * copies the post — content, excerpt, taxonomies, featured image, template,
 * and custom fields — into a new draft owned by the current user, then
 * returns to the list with a link to the new draft.
 *
 * The copy is deliberately a fresh start where it should be: the status is
 * always draft, the slug and dates are left for WordPress to assign, and
 * housekeeping meta (edit locks, old-slug redirect history) stays behind so
 * the duplicate never competes with the original's URLs.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Duplicate" row action and the copy it performs.
 */
final class Coywolf_SEO_Duplicate {

	/**
	 * Admin action name (also the nonce action, suffixed with the post ID).
	 */
	const ACTION = 'coywolf_seo_duplicate';

	/**
	 * Meta key on the copy recording the original post's ID.
	 */
	const ORIGINAL_META = '_coywolf_seo_duplicate_of';

	/**
	 * Query arg carrying the new draft's ID back to the list screen.
	 */
	const NOTICE_ARG = 'coywolf_seo_duplicated';

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_filter( 'post_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_action( 'admin_action_' . self::ACTION, array( $this, 'handle_duplicate' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_duplicated_notice' ) );
	}

	/**
	 * Add the "Duplicate" link to a list-table row, after Edit and before
	 * Quick Edit.
	 *
	 * @param array   $actions Row action links, keyed by action.
	 * @param WP_Post $post    The row's post.
	 * @return array
	 */
	public function add_row_action( $actions, $post ) {
		if ( ! is_array( $actions ) || ! $post instanceof WP_Post || ! $this->can_duplicate( $post ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'post'   => $post->ID,
				),
				admin_url( 'admin.php' )
			),
			self::ACTION . '_' . $post->ID
		);

		$link = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( $url ),
			/* translators: %s: post title. */
			esc_attr( sprintf( __( 'Duplicate &#8220;%s&#8221; as a new draft', 'coywolf-seo' ), _draft_or_post_title( $post ) ) ),
			esc_html__( 'Duplicate', 'coywolf-seo' )
		);

		// Rebuild the array to place Duplicate directly after Edit (and so
		// before Quick Edit). Edit is always present here — can_duplicate()
		// requires edit_post — but append as a fallback just in case another
		// plugin removed it.
		$reordered = array();
		foreach ( $actions as $key => $html ) {
			$reordered[ $key ] = $html;
			if ( 'edit' === $key ) {
				$reordered[ self::ACTION ] = $link;
			}
		}
		if ( ! isset( $reordered[ self::ACTION ] ) ) {
			$reordered[ self::ACTION ] = $link;
		}

		return $reordered;
	}

	/**
	 * Whether the current user may duplicate this post.
	 *
	 * Requires edit rights on the original and create rights for the post
	 * type — no plugin-specific capability or setting.
	 *
	 * @param WP_Post $post The post to copy.
	 * @return bool
	 */
	private function can_duplicate( WP_Post $post ) {
		if ( 'trash' === $post->post_status || wp_is_post_revision( $post ) ) {
			return false;
		}

		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->show_ui || 'attachment' === $post->post_type ) {
			return false;
		}

		return current_user_can( 'edit_post', $post->ID )
			&& current_user_can( $type->cap->create_posts );
	}

	/**
	 * Handle admin.php?action=coywolf_seo_duplicate: verify, copy, and
	 * bounce back to the list screen the click came from.
	 */
	public function handle_duplicate() {
		$post_id = isset( $_REQUEST['post'] ) ? absint( $_REQUEST['post'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $post_id );

		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			wp_die( esc_html__( 'No post supplied to duplicate.', 'coywolf-seo' ) );
		}
		if ( ! $this->can_duplicate( $post ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to duplicate this item.', 'coywolf-seo' ), 403 );
		}

		$new_id = $this->create_duplicate( $post );
		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		$sendback = wp_get_referer();
		if ( ! $sendback || false === strpos( $sendback, 'edit.php' ) ) {
			$sendback = add_query_arg( 'post_type', $post->post_type, admin_url( 'edit.php' ) );
		}

		wp_safe_redirect( add_query_arg( self::NOTICE_ARG, $new_id, remove_query_arg( self::NOTICE_ARG, $sendback ) ) );
		exit;
	}

	/**
	 * Copy a post into a new draft and return the new ID.
	 *
	 * Copied: title, content, excerpt, parent, menu order, comment/ping
	 * status, password, taxonomy terms, and custom fields (which carry the
	 * featured image, page template, and this plugin's SEO settings).
	 * Not copied: status (always draft), slug and dates (WordPress assigns
	 * fresh ones), author (the duplicate belongs to whoever made it), and
	 * stickiness.
	 *
	 * @param WP_Post $post The original post.
	 * @return int|WP_Error The new post's ID, or the wp_insert_post() error.
	 */
	private function create_duplicate( WP_Post $post ) {
		$new_post = array(
			'post_title'            => $post->post_title,
			'post_content'          => $post->post_content,
			'post_content_filtered' => $post->post_content_filtered,
			'post_excerpt'          => $post->post_excerpt,
			'post_type'             => $post->post_type,
			'post_status'           => 'draft',
			'post_parent'           => $post->post_parent,
			'menu_order'            => $post->menu_order,
			'comment_status'        => $post->comment_status,
			'ping_status'           => $post->ping_status,
			'post_password'         => $post->post_password,
			'post_author'           => get_current_user_id(),
		);

		// wp_insert_post() unslashes its input.
		$new_id = wp_insert_post( wp_slash( $new_post ), true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		$this->copy_taxonomies( $new_id, $post );
		$this->copy_meta( $new_id, $post );
		add_post_meta( $new_id, self::ORIGINAL_META, $post->ID );

		return $new_id;
	}

	/**
	 * Mirror the original's term assignments onto the copy.
	 *
	 * Terms are read and written as IDs — no slug round-trips. Empty term
	 * lists are written too, so the default category wp_insert_post() adds
	 * never survives on a copy whose original has no categories.
	 *
	 * @param int     $new_id The copy's post ID.
	 * @param WP_Post $post   The original post.
	 */
	private function copy_taxonomies( $new_id, WP_Post $post ) {
		$taxonomies = get_object_taxonomies( $post->post_type );
		// Some plugins add post-formats support without registering the
		// taxonomy for the type.
		if ( post_type_supports( $post->post_type, 'post-formats' ) && ! in_array( 'post_format', $taxonomies, true ) ) {
			$taxonomies[] = 'post_format';
		}

		foreach ( $taxonomies as $taxonomy ) {
			$term_ids = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $term_ids ) ) {
				continue;
			}
			wp_set_object_terms( $new_id, array_map( 'intval', $term_ids ), $taxonomy );
		}
	}

	/**
	 * Copy the original's custom fields onto the copy, minus housekeeping
	 * keys.
	 *
	 * All meta is fetched in one call (a single primed-cache read) and the
	 * exclude list is flipped into a map so each key is one isset() check.
	 *
	 * @param int     $new_id The copy's post ID.
	 * @param WP_Post $post   The original post.
	 */
	private function copy_meta( $new_id, WP_Post $post ) {
		$all_meta = get_post_meta( $post->ID );
		if ( ! is_array( $all_meta ) || array() === $all_meta ) {
			return;
		}

		$excluded = array(
			'_edit_lock',
			'_edit_last',
			// Old-slug/date redirect history must not move to the copy, or
			// the copy would capture redirects meant for the original.
			'_wp_old_slug',
			'_wp_old_date',
			// Trash bookkeeping, in case it lingers on a restored post.
			'_wp_trash_meta_status',
			'_wp_trash_meta_time',
			'_wp_desired_post_slug',
			self::ORIGINAL_META,
		);

		/**
		 * Filters the meta keys that are never copied to a duplicate.
		 *
		 * @param string[] $excluded Meta keys to skip.
		 * @param WP_Post  $post     The post being duplicated.
		 */
		$excluded = apply_filters( 'coywolf_seo_duplicate_excluded_meta_keys', $excluded, $post );
		$excluded = array_fill_keys( (array) $excluded, true );

		foreach ( $all_meta as $key => $values ) {
			if ( isset( $excluded[ $key ] ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				// Raw values arrive serialized; unserialize so add_post_meta()
				// stores a value, not a double-serialized string. wp_slash()
				// recurses arrays and compensates add_post_meta()'s unslash.
				add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
	}

	/**
	 * After the redirect, confirm the duplication and link to the new draft.
	 */
	public function maybe_show_duplicated_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only signal set by the duplicate redirect.
		$new_id = isset( $_GET[ self::NOTICE_ARG ] ) ? absint( $_GET[ self::NOTICE_ARG ] ) : 0;
		if ( ! $new_id ) {
			return;
		}

		// Only acknowledge posts this feature actually created, for users
		// who may edit them — the query arg is attacker-supplied.
		if ( ! get_post_meta( $new_id, self::ORIGINAL_META, true ) || ! current_user_can( 'edit_post', $new_id ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Duplicate created as a draft.', 'coywolf-seo' ),
			esc_url( get_edit_post_link( $new_id ) ),
			esc_html__( 'Edit the duplicate', 'coywolf-seo' )
		);
	}
}
