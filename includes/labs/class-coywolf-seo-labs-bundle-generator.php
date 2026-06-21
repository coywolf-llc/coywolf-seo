<?php
/**
 * Shared base for Labs bundle / file-set generators.
 *
 * Holds the filesystem and visibility plumbing every Labs generator uses
 * identically: the uploads-dir storage path (keyed by the subclass DIR const),
 * the WP_Filesystem initialiser, recursive cleanup, the public-post visibility
 * rule (shared with the sitemap/llms.txt surfaces), and the parent-dir-creating
 * file writer. The build logic itself (what files are produced and in what
 * format) and bundle_exists()/build_zip() live in the concrete subclass.
 *
 * Stripped from the WordPress.org build with the rest of includes/labs/.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract generator with shared filesystem + visibility helpers.
 */
abstract class Coywolf_SEO_Labs_Bundle_Generator {

	/**
	 * Absolute path to the storage directory (no trailing slash). The subclass
	 * supplies the directory name via its DIR const.
	 *
	 * @return string
	 */
	public function bundle_path() {
		$uploads = wp_upload_dir();
		return untrailingslashit( $uploads['basedir'] ) . '/' . static::DIR;
	}

	/**
	 * Initialise WP_Filesystem and return it, loading the admin file API on
	 * front-end / cron requests.
	 *
	 * @return WP_Filesystem_Base|null
	 */
	protected function filesystem() {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem() ) {
			return null;
		}
		return $wp_filesystem;
	}

	/**
	 * Delete the entire generated directory.
	 *
	 * @return bool True when the directory is gone (or never existed).
	 */
	public function cleanup() {
		$base = $this->bundle_path();
		if ( ! is_dir( $base ) ) {
			return true;
		}
		$fs = $this->filesystem();
		if ( null === $fs ) {
			return false;
		}
		return (bool) $fs->delete( $base, true );
	}

	/**
	 * Whether a post belongs in the public surface (and thus the output). Mirrors
	 * the sitemap/llms.txt visibility so every machine-readable surface agrees:
	 * published, not password protected, a viewable public type, not per-post
	 * noindex.
	 *
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	protected function is_post_visible( $post ) {
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		if ( '' !== (string) $post->post_password ) {
			return false;
		}
		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return false;
		}
		$meta = Coywolf_SEO_Options::post_meta( $post->ID );
		if ( ! empty( $meta['noindex'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Write a file, creating its parent directory first.
	 *
	 * @param string $abs     Absolute file path.
	 * @param string $content File content.
	 * @return void
	 */
	protected function write_file( $abs, $content ) {
		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			$wp_filesystem->put_contents( $abs, $content, FS_CHMOD_FILE );
		}
	}

	/**
	 * Whether generated output is present on disk.
	 *
	 * @return bool
	 */
	abstract public function bundle_exists();
}
