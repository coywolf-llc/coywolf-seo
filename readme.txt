=== Coywolf SEO ===
Contributors: jonhenshaw
Tags: seo
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.4
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
* Takes over from other SEO plugins — with Yoast SEO, Rank Math, All in One SEO, or The SEO Framework also active, their titles, descriptions, schema, Open Graph, robots, and canonical output is suppressed through each plugin's own switches, so nothing is duplicated while you migrate. Their sitemaps and redirects are left running.
* Hide the category prefix — serve category archives at /news/ instead of /category/news/, with 301 redirects from the old URLs.
* IndexNow — ping Bing the moment a post or page is published, updated, or deleted. The site key is generated for you and served virtually; no file is written.
* News sitemap — optionally serve /coywolf-news-sitemap.xml with articles from the last 48 hours; choose whether posts and/or pages are included and which categories are in or out.

<!-- wporg-strip:start -->
Updates are delivered straight from the project's GitHub releases via the bundled self-updater, so new versions show up on Dashboard > Updates like any other plugin.
<!-- wporg-strip:end -->

== Installation ==

1. Upload the plugin to wp-content/plugins/coywolf-seo or install the zip from Plugins > Add New > Upload Plugin.
2. Activate it.

== Privacy ==

Privacy-first: this plugin includes no analytics, no tracking, and no data gathering — nothing about you, your site, or your visitors is ever collected. Its only outbound connection is one you turn on: with IndexNow enabled, the changed URL is sent to Bing's IndexNow endpoint on publish, update, and delete — nothing else, nowhere else.

== Changelog ==

= 1.0.4 =
* Add IndexNow pings to Bing and the optional News XML sitemap (#5).

= 1.0.3 =
* Suppress other SEO plugins' output and add category prefix removal (#4).

= 1.0.2 =
* Add schema markup, Open Graph metadata, and the Authors page (#3).

= 1.0.1 =
* Add Site Details, Settings, titles, meta description, robots, canonical, and the SEO post/page section (#2).

= 1.0.0 =
* Initial release: plugin foundation and scaffolding.
