<?php
/**
 * Plugin Name:       Coywolf SEO
 * Plugin URI:        https://coywolf.com/notes/coywolf-seo/
 * Description:       An SEO plugin that has exactly what you need, and nothing more.
 * Version:           1.0.49
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Coywolf
 * Author URI:        https://coywolf.com/jon-henshaw/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       coywolf-seo
 * Update URI:        https://github.com/coywolf-llc/coywolf-seo
 *
 * @package CoywolfSEO
 *
 * Coywolf SEO
 * Copyright (C) 2026 Coywolf LLC
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, version 2, as published
 * by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, see https://www.gnu.org/licenses/gpl-2.0.html.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COYWOLF_SEO_FILE', __FILE__ );
define( 'COYWOLF_SEO_PATH', plugin_dir_path( __FILE__ ) );
define( 'COYWOLF_SEO_URL', plugin_dir_url( __FILE__ ) );

/* wporg-strip:start — GitHub self-updater (removed from the WordPress.org build) */
require_once __DIR__ . '/includes/class-github-updater.php';
// Flags this as the GitHub distribution (stripped from the WordPress.org build).
define( 'COYWOLF_SEO_GITHUB_BUILD', true );
/* wporg-strip:end */
require_once __DIR__ . '/includes/class-coywolf-seo-options.php';
require_once __DIR__ . '/includes/class-coywolf-seo-markdown.php';
require_once __DIR__ . '/includes/class-coywolf-seo-admin.php';
require_once __DIR__ . '/includes/class-coywolf-seo-authors.php';
require_once __DIR__ . '/includes/class-coywolf-seo-terms.php';
require_once __DIR__ . '/includes/class-coywolf-seo-metabox.php';
require_once __DIR__ . '/includes/class-coywolf-seo-titles.php';
require_once __DIR__ . '/includes/class-coywolf-seo-head.php';
require_once __DIR__ . '/includes/class-coywolf-seo-schema.php';
require_once __DIR__ . '/includes/class-coywolf-seo-opengraph.php';
require_once __DIR__ . '/includes/class-coywolf-seo-compat.php';
require_once __DIR__ . '/includes/class-coywolf-seo-category-base.php';
require_once __DIR__ . '/includes/class-coywolf-seo-indexnow.php';
require_once __DIR__ . '/includes/class-coywolf-seo-news-sitemap.php';
require_once __DIR__ . '/includes/class-coywolf-seo-sitemaps.php';
require_once __DIR__ . '/includes/class-coywolf-seo-ai-batch.php';
require_once __DIR__ . '/includes/class-coywolf-seo-ai.php';
require_once __DIR__ . '/includes/class-coywolf-seo-image-ai.php';
require_once __DIR__ . '/includes/class-coywolf-seo-image-text.php';
require_once __DIR__ . '/includes/class-coywolf-seo-link-manager.php';
require_once __DIR__ . '/includes/class-coywolf-seo-import-export.php';
require_once __DIR__ . '/includes/class-coywolf-seo-redirects.php';
require_once __DIR__ . '/includes/class-coywolf-seo-redirects-admin.php';
require_once __DIR__ . '/includes/class-coywolf-seo-redirects-import.php';
require_once __DIR__ . '/includes/class-coywolf-seo-toc.php';
require_once __DIR__ . '/includes/class-coywolf-seo-mobile-image.php';
require_once __DIR__ . '/includes/class-coywolf-seo-duplicate.php';
require_once __DIR__ . '/includes/class-coywolf-seo.php';

register_activation_hook( __FILE__, array( 'Coywolf_SEO', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'Coywolf_SEO', 'on_deactivate' ) );

Coywolf_SEO::instance();

/* wporg-strip:start — GitHub self-updater (removed from the WordPress.org build) */
// Wire in the GitHub self-updater so releases show up on Dashboard → Updates.
( new Coywolf_SEO_GitHub_Updater( __FILE__, Coywolf_SEO::VERSION ) )->init();
/* wporg-strip:end */
