<?php
/**
 * IndexNow for Coywolf SEO.
 *
 * When enabled, Bing's IndexNow endpoint is pinged whenever a post or page
 * is published, updated, or removed, so the change is discovered in
 * seconds instead of on the next crawl. Bing is the IndexNow node used —
 * participating engines share submissions with one another.
 *
 * The required site key is generated once and served virtually at
 * /<key>.txt (no file is written); the ping passes keyLocation so
 * subdirectory installs verify too.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * IndexNow pings.
 */
final class Coywolf_SEO_IndexNow {

	/**
	 * Bing's IndexNow endpoint.
	 */
	const ENDPOINT = 'https://www.bing.com/indexnow';

	/**
	 * Post types whose changes are submitted.
	 *
	 * @var string[]
	 */
	private $post_types = array( 'post', 'page' );

	/**
	 * Permalinks captured before trash/delete rewrites or removes them,
	 * keyed by post ID.
	 *
	 * @var array
	 */
	private $pre_removal_urls = array();

	/**
	 * URLs already pinged in this request (a save can fire several
	 * transitions).
	 *
	 * @var array
	 */
	private $pinged = array();

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_action( 'template_redirect', array( $this, 'maybe_serve_key_file' ), 0 );
		add_action( 'wp_trash_post', array( $this, 'capture_permalink' ) );
		add_action( 'before_delete_post', array( $this, 'capture_permalink' ) );
		add_action( 'transition_post_status', array( $this, 'on_status_change' ), 10, 3 );
	}

	/**
	 * Whether pinging is enabled and configured.
	 *
	 * @return bool
	 */
	private function enabled() {
		return (bool) Coywolf_SEO_Options::get( 'indexnow_enabled' )
			&& '' !== $this->key()
			// Respect Settings → Reading → "Discourage search engines": a hidden
			// site must not submit URLs to a search engine's IndexNow endpoint.
			&& (bool) get_option( 'blog_public', 1 );
	}

	/**
	 * The IndexNow key.
	 *
	 * @return string
	 */
	private function key() {
		return (string) Coywolf_SEO_Options::get( 'indexnow_key' );
	}

	/**
	 * Generate (and store) a key when none exists yet.
	 *
	 * @return string The key in use afterwards.
	 */
	public static function ensure_key() {
		$key = (string) Coywolf_SEO_Options::get( 'indexnow_key' );
		if ( '' === $key ) {
			$key = strtolower( wp_generate_password( 32, false, false ) );
			$key = preg_replace( '/[^a-z0-9]/', '0', $key );
			Coywolf_SEO_Options::update( array( 'indexnow_key' => $key ) );
		}
		return $key;
	}

	/**
	 * Serve the verification key file virtually at /<key>.txt.
	 */
	public function maybe_serve_key_file() {
		$key = $this->key();
		if ( '' === $key || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$request = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$home    = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( untrailingslashit( (string) $request ) !== untrailingslashit( $home ) . '/' . $key . '.txt' ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text key file body, [a-z0-9] only.
		echo $key;
		exit;
	}

	/**
	 * Remember a post's permalink before trashing/deleting mangles it.
	 *
	 * @param int $post_id Post ID.
	 */
	public function capture_permalink( $post_id ) {
		$post = get_post( $post_id );
		if ( $post && in_array( $post->post_type, $this->post_types, true ) && 'publish' === $post->post_status ) {
			$this->pre_removal_urls[ $post_id ] = get_permalink( $post );
		}
	}

	/**
	 * Submit on publish, update, and removal transitions.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_status_change( $new_status, $old_status, $post ) {
		if ( ! $this->enabled() || ! in_array( $post->post_type, $this->post_types, true ) ) {
			return;
		}
		if ( 'auto-draft' === $new_status || wp_is_post_revision( $post->ID ) ) {
			return;
		}

		if ( 'publish' === $new_status ) {
			// New publish or update of published content.
			$this->ping( get_permalink( $post ) );
			return;
		}

		if ( 'publish' === $old_status && 'publish' !== $new_status ) {
			// Removed from the public site (trash, draft, private, delete).
			$url = isset( $this->pre_removal_urls[ $post->ID ] )
				? $this->pre_removal_urls[ $post->ID ]
				: get_permalink( $post );
			$this->ping( $url );
		}
	}

	/**
	 * Fire-and-forget GET to Bing's IndexNow endpoint.
	 *
	 * @param string $url The changed URL.
	 */
	private function ping( $url ) {
		$url = (string) $url;
		if ( '' === $url || isset( $this->pinged[ $url ] ) ) {
			return;
		}
		$this->pinged[ $url ] = true;

		$endpoint = add_query_arg(
			array(
				'url'         => rawurlencode( $url ),
				'key'         => $this->key(),
				'keyLocation' => rawurlencode( home_url( '/' . $this->key() . '.txt' ) ),
			),
			self::ENDPOINT
		);
		wp_remote_get(
			$endpoint,
			array(
				'timeout'  => 3,
				'blocking' => false,
				'user-agent' => 'Coywolf SEO/' . Coywolf_SEO::VERSION . '; ' . home_url( '/' ),
			)
		);
	}
}
