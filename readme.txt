=== Coywolf SEO ===
Contributors: jonhenshaw
Tags: seo
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.22
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An SEO plugin that has exactly what you need, and nothing more.

== Description ==

Coywolf SEO is built on a simple idea: an SEO plugin should give you exactly what you need, and nothing more. No upsells, no nags, no bloat, no features you have to turn off — just the essentials, done right, with the plugin doing the heavy lifting in the background.

Features:

* Site Details — one screen for your site's identity: the Site Name and Tagline (editable right there), whether titles append the site name (em dash separated), the default Open Graph image, whether the site represents an Organization (with a full Schema.org property picker whose inputs match each property — URL, email, date, image upload, structured address and contact point) or a Person, the homepage title and description, and schema type defaults for posts and pages.
* Titles — clean titles composed from your settings. The site name is appended only where you turn it on. A force-rewrite option handles themes that build their own title markup.
* Meta description — the homepage uses your description (default: the Tagline), posts and pages use their manual excerpt, terms use their description. Nothing is auto-generated, and one checkbox excludes meta descriptions entirely.
* Robots meta — index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1 by default, each directive toggleable.
* Canonical links — on the homepage, posts, pages, categories, and tags, with pagination handled and a per-post canonical override.
* SEO section on posts and pages — an SEO panel in the block editor's document sidebar (and a classic-editor meta box) to override the schema page/article type, set Noindex/Nofollow, or replace the canonical link.
* Schema markup — one JSON-LD graph per page: WebSite, your Organization (with every property you added) or Person as the publisher, the typed WebPage, the typed Article with its author, and CollectionPage on category and tag archives.
* Authors — pick a user and the plugin imports their account details as Schema.org Person properties; add anything from the full Person catalog. Used as the author in Article markup.
* Open Graph — og: tags on the homepage, posts, pages, categories, and tags, with the featured image falling back to your default Open Graph image. No Twitter/X tags.
* Access rights — choose whether Editors can manage the plugin alongside Administrators.
* Takes over from other SEO plugins — with Yoast SEO, Rank Math, All in One SEO, SEOPress, or The SEO Framework also active, their front-end output (titles, descriptions, schema, Open Graph, robots, canonical) and their edit-screen boxes and sidebars are suppressed through each plugin's own switches, so nothing is duplicated while you migrate. Their sitemaps and redirects are left running.
* Hide the category prefix — serve category archives at /news/ instead of /category/news/, with 301 redirects from the old URLs.
* IndexNow — ping Bing the moment a post or page is published, updated, or deleted. The site key is generated for you and served virtually; no file is written.
* Native sitemap exclusions — drop the Posts, Pages, Categories, or Users sitemaps from WordPress's own /wp-sitemap.xml; everything not excluded stays exactly as WordPress generates it.
* News sitemap — optionally serve /coywolf-news-sitemap.xml with articles from the last 48 hours; choose whether posts and/or pages are included and which categories are in or out.
* AI enrichment — bring your own Anthropic API key and Claude analyzes each post in the background as it is published: main subjects land in the Article schema's about property, passing references in mentions, each grounded to a real Wikidata item with its Wikipedia page in sameAs. Claude only ever extracts entity names — real candidates are looked up on Wikidata's public API, the model chooses among them, and the chosen item's type is verified — so identifiers are never invented. When new entities land, the page's cache is purged so the schema is served immediately.
* Redirects — a full redirect manager on one screen: quick-add a rule in seconds (exact or regex with capture groups, query strings ignored/passed/matched exactly, 301/302/307/308/410), test any URL against your rules before trusting the live site, and watch hit counts to see which rules still matter. The moment you delete a published post or page, the plugin asks right there on the list screen what to do with its URL — mark it gone (410), redirect it, or dismiss — and pending decisions also wait on the Redirects page.
* Import/Export — download the plugin settings, author properties, and redirect rules as JSON and import them on another site. API keys are never exported.

<!-- wporg-strip:start -->
Updates are delivered straight from the project's GitHub releases via the bundled self-updater, so new versions show up on Dashboard > Updates like any other plugin.
<!-- wporg-strip:end -->

== Installation ==

1. Upload the plugin to wp-content/plugins/coywolf-seo or install the zip from Plugins > Add New > Upload Plugin.
2. Activate it.

== Troubleshooting ==

* No new tags on the front end? Purge your page and CDN caches (including host-level edge caching) — cached pages keep serving pre-activation HTML. The plugin purges the common cache plugins on activation, but host and CDN caches are outside its reach.
* No Article schema on a page? Pages default to no Article type (Site Details > Pages); set a default there or override per page in the SEO panel.
* No meta description on a post? By design it comes only from a manual excerpt — nothing is auto-generated. Or exclude meta descriptions entirely in Settings.
* Nothing at all? The theme must call wp_head() — all output renders there.

== Privacy ==

Privacy-first: this plugin includes no analytics, no tracking, and no data gathering — nothing about you, your site, or your visitors is ever collected. Outbound connections exist only for features you turn on: with IndexNow enabled, the changed URL is sent to Bing's IndexNow endpoint on publish, update, and delete; with AI enrichment enabled, the published post's title and content are sent to Anthropic's API (https://www.anthropic.com/legal/privacy) using your own API key, entity names are looked up on Wikidata's public API (https://foundation.wikimedia.org/wiki/Policy:Privacy_policy). Nothing else, nowhere else.

== Changelog ==

= 1.0.22 =
* Rules table toolbar matches the Posts list table (#23).

= 1.0.21 =
* Import redirects from Redirection and Yoast SEO Premium (#22).

= 1.0.20 =
* AI description replaces the excerpt, settings reorder/rename, and term field refinements (#21).

= 1.0.19 =
* Redirects polish: green/red state dots and equal-width panels (#20).

= 1.0.18 =
* Per-term Page Title and Open Graph image for categories and tags (#19).

= 1.0.17 =
* AI meta descriptions: settings option with live exclude toggle, 200-char cap, regenerated on publish/update (#18).

= 1.0.16 =
* Rules table: search, filters, pagination, and bulk actions; slim the deletion notice (#17).

= 1.0.15 =
* Remove the Google Knowledge Graph functionality (#16).

= 1.0.14 =
* Align the deleted-URL decision actions on one centered line (#15).

= 1.0.13 =
* Send the site as referer on Knowledge Graph lookups, and append the site name to og:title (#14).

= 1.0.12 =
* Surface deleted-URL decisions on the list screens, and remove the 404 log (#13).

= 1.0.11 =
* Re-analyze on config change and on demand, surface Knowledge Graph errors, and add a key setup walkthrough (#12).

= 1.0.10 =
* Add the redirect manager: rules engine, deleted-content decisions, 404 log, and a single-screen UI (#11).

= 1.0.9 =
* Wikipedia + Google Knowledge Graph entity enrichment, cache bust on entities, and editor panel polish (#10).

= 1.0.8 =
* Raise the Anthropic request timeout past WordPress's 5-second default (#9).

= 1.0.7 =
* Fix AI enrichment on WP 7.0, double-quote robots meta, editable @id, and select-to-add property picker (#8).

= 1.0.6 =
* Editor SEO panel, SEO-plugin editor suppression, schema fixes, and Site Details/Settings UX (#7).

= 1.0.5 =
* Add AI Schema enrichment with Wikidata grounding, and settings Import/Export (#6).

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
