<img src=".wordpress-org/icon-256x256.png" alt="Coywolf SEO logo" width="128" />

# Coywolf SEO

An SEO plugin that has exactly what you need, and nothing more.

- **Version:** 1.0.47
- **Requires WordPress:** 6.0+
- **Requires PHP:** 7.4+
- **License:** GPL-2.0-or-later

## Description

Coywolf SEO is built on a simple idea: an SEO plugin should give you exactly what you need, and nothing more. No upsells, no nags, no bloat, no features you have to turn off — just the essentials, done right, with the plugin doing the heavy lifting in the background.

### Features

- **Site Details** — one screen for your site's identity: the Site Name and Tagline (editable right there), whether titles append the site name (em dash separated), the default Open Graph image, whether the site represents an Organization (with a full Schema.org property picker whose inputs match each property — URL, email, date, image upload, structured address and contact point) or a Person, the homepage title and description, and schema type defaults for posts and pages.
- **Titles** — clean titles composed from your settings. The site name is appended only where you turn it on. A force-rewrite option handles themes that build their own title markup.
- **Meta description** — the homepage uses your description (default: the Tagline), posts and pages use their manual excerpt, terms use their description. Nothing is auto-generated, and one checkbox excludes meta descriptions entirely — search engines generate snippets from content anyway.
- **Robots meta** — `index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1` by default, each directive toggleable.
- **Canonical links** — on the homepage, posts, pages, categories, and tags, with pagination handled and a per-post canonical override.
- **SEO section on posts and pages** — an SEO panel in the block editor's document sidebar (and a classic-editor meta box) to override the schema page/article type, set Noindex/Nofollow, or replace the canonical link.
- **Duplicate Post** — a Duplicate link under each post and page title (right next to Edit) copies it to a new draft: content, excerpt, categories and tags, featured image, page template, and custom fields included — your SEO settings among them. The draft belongs to whoever made it, WordPress assigns a fresh slug and date, and the original's old-URL redirect history stays where it belongs, so the copy never competes with the original.
- **Table of Contents block** — builds its list when the page is served, not when the post is saved, so it can never go stale: headings get clean anchor links automatically (hand-set anchors are kept, duplicate headings de-duplicated), headings added by shortcodes and synced patterns are included, and every heading block gets its own "Exclude from table of contents" toggle. Pick which heading levels are listed (H2–H6), edit the title and its heading level, choose plain, bulleted, or hierarchical numbering (1, 1.1, 1.2…), make the table collapsible (works without JavaScript), and turn on smooth scrolling (reduced-motion preferences are respected), current-section highlighting while reading, and a scroll offset so sticky headers never cover a jumped-to heading. A minimum-headings threshold leaves the table off short posts, the markup is a semantic `nav` with properly nested lists, pages with the block declare the `tableOfContents` accessibility feature in their schema, and a Yoast table-of-contents block converts to this one in one click.
- **Mobile alternative image** — the core Image block gains an *Add mobile alternative* control: pick a phone-specific image (a different crop, dimensions, or content) and the plugin serves it on small screens through a `<picture>` element with a `max-width: 768px` media query, while larger screens keep the desktop image. Because it's a real media query (art direction) rather than a `srcset` swap, the mobile image is guaranteed below the breakpoint instead of left to the browser's candidate guesswork. The desktop image and its lightbox are untouched, and the breakpoint is filterable.
- **Schema markup** — one JSON-LD graph per page: WebSite, your Organization (with every property you added) or Person as the publisher, the typed WebPage, the typed Article with its author, and CollectionPage on category and tag archives.
- **Authors** — pick a user and the plugin imports their account details as Schema.org Person properties; add anything from the full Person catalog. Used as the author in Article markup.
- **Open Graph** — og: tags on the homepage, posts, pages, categories, and tags, with the featured image falling back to your default Open Graph image. Categories and tags can carry their own Page Title (used for the page title and Open Graph title) and their own Open Graph image, set right on the term screens. No Twitter/X tags — X reads Open Graph.
- **Access rights** — choose whether Editors can manage the plugin alongside Administrators.
- **Takes over from other SEO plugins** — with Yoast SEO, Rank Math, All in One SEO, SEOPress, or The SEO Framework also active, their front-end output (titles, descriptions, schema, Open Graph, robots, canonical) and their edit-screen boxes and sidebars are suppressed through each plugin's own switches, so nothing is duplicated while you migrate. Their sitemaps and redirects are left running.
- **Hide the category prefix** — serve category archives at `/news/` instead of `/category/news/`, with 301 redirects from the old URLs.
- **IndexNow** — ping Bing the moment a post or page is published, updated, or deleted. The site key is generated for you and served virtually; no file is written.
- **Native sitemap exclusions** — drop the Posts, Pages, Categories, or Users sitemaps from WordPress's own `/wp-sitemap.xml`; everything not excluded stays exactly as WordPress generates it.
- **News sitemap** — optionally serve `/coywolf-news-sitemap.xml` with articles from the last 48 hours; choose whether posts and/or pages are included and which categories are in or out.
- **AI Schema enrichment** — bring your own Anthropic API key and Claude analyzes each post in the background as it is published: main subjects land in the Article schema's `about` property, passing references in `mentions`, each grounded to a real Wikidata item with its Wikipedia page in `sameAs`. Claude only ever extracts entity names — real candidates are looked up on Wikidata's public API, the model chooses among them, and the chosen item's type is verified — so identifiers are never invented. When new entities land, the page's cache is purged (Rocket.net edge cache and the common cache plugins) so the schema is served immediately. Enrich-all runs go through Anthropic's Batches API at half the standard token price, with live progress, pause/resume/cancel, and a lifetime average-cost-per-post readout. Turn on automatic meta descriptions and Claude also writes a faithful sub-200-character summary of each post as it is published or updated — it replaces the excerpt in the meta description, Open Graph, and Article schema, and the option steps aside whenever meta descriptions are excluded. Built on the WordPress PHP AI Client SDK with the Anthropic provider.
- **Redirects** — a full redirect manager on one screen: quick-add a rule in seconds (exact or regex with capture groups, query strings ignored/passed/matched exactly, 301/302/307/308/410), test any URL against your rules before trusting the live site, and watch hit counts to see which rules still matter. The moment you delete a published post or page, the plugin asks right there on the list screen what to do with its URL — mark it gone (410), redirect it, or dismiss — and pending decisions also wait on the Redirects page. Rules can be imported from the Redirection plugin (even when it's deactivated — its saved records are read straight from the database) and from Yoast SEO Premium, with duplicates skipped so re-running is safe; when either plugin is active, a banner offers the import — nothing is imported without your say-so. No log tables, no groups to configure, and a `COYWOLF_SEO_DISABLE_REDIRECTS` constant as a lockout escape hatch.
- **Import/Export** — download the plugin settings, author properties, and redirect rules as JSON and import them on another site. API keys are never exported.

<!-- wporg-strip:start -->
Updates are delivered straight from the project's GitHub releases via the bundled self-updater, so new versions show up on **Dashboard → Updates** like any other plugin.
<!-- wporg-strip:end -->

## Installation

1. Upload the plugin to `wp-content/plugins/coywolf-seo` or install the zip from **Plugins → Add New → Upload Plugin**.
2. Activate it.

## Troubleshooting

- **No new tags on the front end?** Purge your page and CDN caches (including host-level edge caching) — cached pages keep serving pre-activation HTML. The plugin purges the common cache plugins on activation, but host and CDN caches are outside its reach.
- **No Article schema on a page?** Pages default to no Article type (Site Details → Pages); set a default there or override per page in the SEO panel.
- **No meta description on a post?** By design it comes only from a manual excerpt — nothing is auto-generated. Or exclude meta descriptions entirely in Settings.
- **Nothing at all?** The theme must call `wp_head()` — all output renders there.

## Privacy

Privacy-first: this plugin includes no analytics, no tracking, and no data gathering — nothing about you, your site, or your visitors is ever collected. Outbound connections exist only for features you turn on: with IndexNow enabled, the changed URL is sent to Bing's IndexNow endpoint on publish, update, and delete; with AI enrichment enabled, the published post's title and content are sent to Anthropic's API using your own API key, entity names are looked up on Wikidata's public API. Nothing else, nowhere else.

## Changelog

### 1.0.47
- Add "Turn off features" toggles (AI enrichment, Schema.org, Sitemaps) and admin UX improvements (#48).

### 1.0.46
- Add Image Text: Claude-written alt text, titles, captions, and descriptions for the Media Library (#47).

### 1.0.45
- Harden JSON-LD XSS and object-injection, fix bulk-scan N+1, improve admin accessibility (#46).

### 1.0.44
- Mobile alternative image: serve via a <picture> media query so it displays on mobile (#45).

### 1.0.43
- Add a mobile alternative image option to the Image block (#44).

### 1.0.42
- Settings: detailed wp-config.php key instructions + API connection status indicator (#43).

### 1.0.41
- Move the Test API access button to the end of the AI enrichment settings (#42).

### 1.0.40
- Fix Settings page not saving (Save button orphaned by a nested form) (#41).

### 1.0.39
- Add a Duplicate Post feature (#40).

### 1.0.38
- Table of Contents: rename the title from block settings (#39).

### 1.0.37
- Table of Contents block for posts and pages (#38).

### 1.0.36
- Zero-stale estimate never claims a run is free (#37).

### 1.0.35
- Re-analyze all checkbox on bulk enrichment (#36).

### 1.0.34
- Bulk controls update in place — no page refresh (#35).

### 1.0.33
- Pre-run cost estimator with live model preview, and an API access test (#34).

### 1.0.32
- Right-size batch token allowances so the upfront credit check passes (#33).

### 1.0.31
- Bulk enrichment runs exclusively through the Message Batches API (50% token price) with usage/cost telemetry (#32).

### 1.0.30
- Bulk enrichment: surface failures and auto-pause on a failure streak (#31).

### 1.0.29
- Cancel is a real button beside Resume (#30).

### 1.0.28
- Bulk enrichment: Stop pauses with Resume and Cancel; Cancel truly kills the run (#29).

### 1.0.27
- Parallel bulk enrichment via loopback worker fan-out (#28).

### 1.0.26
- Coywolf logomark as the admin menu icon (#27).

### 1.0.25
- Bulk enrich-all with live progress, and gate schema entities on Entity detection (#26).

### 1.0.24
- Claude model picker in AI enrichment settings (#25).

### 1.0.23
- Native sitemap exclusions: Posts, Pages, Categories, and Users (#24).

### 1.0.22
- Rules table toolbar matches the Posts list table (#23).

### 1.0.21
- Import redirects from Redirection and Yoast SEO Premium (#22).

### 1.0.20
- AI description replaces the excerpt, settings reorder/rename, and term field refinements (#21).

### 1.0.19
- Redirects polish: green/red state dots and equal-width panels (#20).

### 1.0.18
- Per-term Page Title and Open Graph image for categories and tags (#19).

### 1.0.17
- AI meta descriptions: settings option with live exclude toggle, 200-char cap, regenerated on publish/update (#18).

### 1.0.16
- Rules table: search, filters, pagination, and bulk actions; slim the deletion notice (#17).

### 1.0.15
- Remove the Google Knowledge Graph functionality (#16).

### 1.0.14
- Align the deleted-URL decision actions on one centered line (#15).

### 1.0.13
- Send the site as referer on Knowledge Graph lookups, and append the site name to og:title (#14).

### 1.0.12
- Surface deleted-URL decisions on the list screens, and remove the 404 log (#13).

### 1.0.11
- Re-analyze on config change and on demand, surface Knowledge Graph errors, and add a key setup walkthrough (#12).

### 1.0.10
- Add the redirect manager: rules engine, deleted-content decisions, 404 log, and a single-screen UI (#11).

### 1.0.9
- Wikipedia + Google Knowledge Graph entity enrichment, cache bust on entities, and editor panel polish (#10).

### 1.0.8
- Raise the Anthropic request timeout past WordPress's 5-second default (#9).

### 1.0.7
- Fix AI enrichment on WP 7.0, double-quote robots meta, editable @id, and select-to-add property picker (#8).

### 1.0.6
- Editor SEO panel, SEO-plugin editor suppression, schema fixes, and Site Details/Settings UX (#7).

### 1.0.5
- Add AI Schema enrichment with Wikidata grounding, and settings Import/Export (#6).

### 1.0.4
- Add IndexNow pings to Bing and the optional News XML sitemap (#5).

### 1.0.3
- Suppress other SEO plugins' output and add category prefix removal (#4).

### 1.0.2
- Add schema markup, Open Graph metadata, and the Authors page (#3).

### 1.0.1
- Add Site Details, Settings, titles, meta description, robots, canonical, and the SEO post/page section (#2).

### 1.0.0
- Initial release: plugin foundation and scaffolding.
