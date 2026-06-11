=== Coywolf SEO ===
Contributors: jonhenshaw
Tags: seo
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An SEO plugin that has exactly what you need, and nothing more.

== Description ==

Coywolf SEO is built on a simple idea: an SEO plugin should give you exactly what you need, and nothing more. No upsells, no nags, no bloat, no features you have to turn off — just the essentials, done right, with the plugin doing the heavy lifting in the background.

Features:

* Site Details — one screen for your site's identity: the default Open Graph image, whether the site represents an Organization (with a full Schema.org property picker) or a Person, the homepage title and description, and per-content defaults for posts, pages, categories, and tags (schema page/article types, and whether titles append the site name, em dash separated).
* Titles — clean titles composed from your settings. The site name is appended only where you turn it on. A force-rewrite option handles themes that build their own title markup.
* Meta description — the homepage uses your description (default: the Tagline), posts and pages use their manual excerpt, terms use their description. Nothing is auto-generated, and one checkbox excludes meta descriptions entirely.
* Robots meta — index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1 by default, each directive toggleable.
* Canonical links — on the homepage, posts, pages, categories, and tags, with pagination handled and a per-post canonical override.
* SEO section on posts and pages — override the schema page/article type, set Noindex/Nofollow, or replace the canonical link.
* Schema markup — one JSON-LD graph per page: WebSite, your Organization (with every property you added) or Person as the publisher, the typed WebPage, the typed Article with its author, and CollectionPage on category and tag archives.
* Authors — pick a user and the plugin imports their account details as Schema.org Person properties; add anything from the full Person catalog. Used as the author in Article markup.
* Open Graph — og: tags on the homepage, posts, pages, categories, and tags, with the featured image falling back to your default Open Graph image. No Twitter/X tags.
* Access rights — choose whether Editors can manage the plugin alongside Administrators.

<!-- wporg-strip:start -->
Updates are delivered straight from the project's GitHub releases via the bundled self-updater, so new versions show up on Dashboard > Updates like any other plugin.
<!-- wporg-strip:end -->

== Installation ==

1. Upload the plugin to wp-content/plugins/coywolf-seo or install the zip from Plugins > Add New > Upload Plugin.
2. Activate it.

== Privacy ==

Privacy-first: this plugin includes no analytics, no tracking, and no data gathering — nothing about you, your site, or your visitors is ever collected.

== Changelog ==

= 1.0.1 =
* Add Site Details, Settings, titles, meta description, robots, canonical, and the SEO post/page section (#2).

= 1.0.0 =
* Initial release: plugin foundation and scaffolding.
