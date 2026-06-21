<?php
/**
 * Agentic Resource Discovery (ARD) catalog generator — Labs (ARD sub-feature).
 *
 * Builds the ARD `ai-catalog.json` manifest (spec at
 * https://agenticresourcediscovery.org/): a host object plus an entries[] array
 * cataloguing this site's machine-usable resources — the WP REST API, the
 * plugin's own agent-readable outputs (OKF bundle, EntityMap, llms.txt) when
 * those Labs/core features are enabled and serving, an optional MCP server card,
 * and any custom entries the owner adds. Unlike OKF/EntityMap this is NOT a file
 * bundle on disk: the manifest is a single small JSON document stored in an
 * option and served dynamically from /.well-known/ai-catalog.json (no writable
 * site-root file, which keeps it WordPress.org-safe and spec-compatible).
 *
 * Stripped from the WordPress.org build with the rest of includes/labs/.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds + stores the ARD ai-catalog.json manifest.
 */
final class Coywolf_SEO_ARD_Generator {

	/**
	 * ARD manifest schema version. The published spec document is v0.9 Draft, but
	 * the value clients check (and the published example manifests use) is "1.0".
	 */
	const SPEC_VERSION = '1.0';

	/**
	 * Option holding the last-built manifest JSON (served verbatim). autoload=off.
	 */
	const CATALOG_OPTION = 'coywolf_seo_ard_catalog';

	/**
	 * The owning ARD feature (settings + host/source/entry config).
	 *
	 * @var Coywolf_SEO_ARD
	 */
	private $ard;

	/**
	 * Construct with the owning ARD feature.
	 *
	 * @param Coywolf_SEO_ARD $ard ARD feature.
	 */
	public function __construct( Coywolf_SEO_ARD $ard ) {
		$this->ard = $ard;
	}

	// ---------------------------------------------------------------------
	// Build / store
	// ---------------------------------------------------------------------

	/**
	 * Rebuild the manifest from current config + available features and store it.
	 *
	 * @return array|WP_Error Summary { counts:{entries}, time } or WP_Error.
	 */
	public function rebuild() {
		$catalog = $this->build_catalog();
		$json    = $this->encode( $catalog );
		if ( '' === $json ) {
			return new WP_Error( 'ard_encode', __( 'The ai-catalog.json manifest could not be encoded.', 'coywolf-seo' ) );
		}
		update_option( self::CATALOG_OPTION, $json, false );
		return array(
			'counts' => array( 'entries' => count( $catalog['entries'] ) ),
			'time'   => gmdate( 'c' ),
		);
	}

	/**
	 * The manifest JSON to serve: the stored copy when present, otherwise a fresh
	 * build (so the endpoint is never empty once the feature is enabled).
	 *
	 * @return string
	 */
	public function catalog_json() {
		$stored = get_option( self::CATALOG_OPTION, '' );
		if ( is_string( $stored ) && '' !== trim( $stored ) ) {
			return $stored;
		}
		return $this->encode( $this->build_catalog() );
	}

	/**
	 * Whether a manifest has been built and stored.
	 *
	 * @return bool
	 */
	public function bundle_exists() {
		$stored = get_option( self::CATALOG_OPTION, '' );
		return is_string( $stored ) && '' !== trim( $stored );
	}

	/**
	 * Remove the stored manifest (explicit cleanup / disable handoff).
	 *
	 * @return bool
	 */
	public function cleanup() {
		delete_option( self::CATALOG_OPTION );
		return true;
	}

	/**
	 * The current entry count without (re)storing — for the panel preview.
	 *
	 * @return int
	 */
	public function entry_count() {
		$catalog = $this->build_catalog();
		return count( $catalog['entries'] );
	}

	// ---------------------------------------------------------------------
	// Catalog model
	// ---------------------------------------------------------------------

	/**
	 * Assemble the ARD catalog: { specVersion, host, entries }.
	 *
	 * @return array
	 */
	private function build_catalog() {
		$config = $this->ard->config();

		$host_name = '' !== trim( (string) $config['host_name'] ) ? trim( (string) $config['host_name'] ) : (string) get_bloginfo( 'name' );
		$host      = array(
			'displayName' => $host_name,
			'identifier'  => $this->publisher(),
		);
		if ( '' !== trim( (string) $config['doc_url'] ) ) {
			$host['documentationUrl'] = esc_url_raw( (string) $config['doc_url'] );
		}
		if ( '' !== trim( (string) $config['logo_url'] ) ) {
			$host['logoUrl'] = esc_url_raw( (string) $config['logo_url'] );
		}

		return array(
			'specVersion' => self::SPEC_VERSION,
			'host'        => $host,
			'entries'     => $this->build_entries( $config ),
		);
	}

	/**
	 * Build the entries[] array from the owner-selected built-in sources (only
	 * those actually enabled + serving), the optional MCP card, and custom
	 * entries.
	 *
	 * @param array $config ARD config.
	 * @return array<int,array>
	 */
	private function build_entries( array $config ) {
		$entries = array();

		// The WP REST API: always present on a WordPress site.
		if ( ! empty( $config['include_rest'] ) ) {
			$entries[] = $this->entry(
				'wp-rest-api',
				__( 'WordPress REST API', 'coywolf-seo' ),
				'application/json',
				rest_url(),
				__( 'The site\'s self-describing WordPress REST API. An agent can read posts, pages, taxonomies, and search from the linked root.', 'coywolf-seo' ),
				array(
					__( 'list recent posts from this site', 'coywolf-seo' ),
					__( 'search this site\'s content through its API', 'coywolf-seo' ),
				)
			);
		}

		// OKF bundle (Labs) — only when enabled with a live endpoint.
		if ( ! empty( $config['include_okf'] ) && $this->labs_feature_url( 'coywolf_seo_okf', '/okf/' ) ) {
			$entries[] = $this->entry(
				'okf-bundle',
				__( 'Open Knowledge Format bundle', 'coywolf-seo' ),
				'text/markdown',
				$this->labs_feature_url( 'coywolf_seo_okf', '/okf/' ),
				__( 'A navigable Markdown knowledge graph of this site\'s public content (Open Knowledge Format v0.1). Start at the root index and follow the cross-links.', 'coywolf-seo' ),
				array(
					__( 'read this site\'s content as structured markdown', 'coywolf-seo' ),
					__( 'find articles about a topic on this site', 'coywolf-seo' ),
				)
			);
		}

		// EntityMap (Labs) — only when enabled with a live endpoint.
		if ( ! empty( $config['include_entitymap'] ) && $this->labs_feature_url( 'coywolf_seo_entitymap', '/entitymap.json' ) ) {
			$entries[] = $this->entry(
				'entitymap',
				__( 'EntityMap', 'coywolf-seo' ),
				'application/json',
				$this->labs_feature_url( 'coywolf_seo_entitymap', '/entitymap.json' ),
				__( 'A spec-conformant EntityMap v1.0 index of the Wikidata-grounded entities this site is about or mentions, with publisher-attributed evidence passages.', 'coywolf-seo' ),
				array(
					__( 'find the entities and topics this site covers', 'coywolf-seo' ),
					__( 'get Wikidata identifiers for what this site is about', 'coywolf-seo' ),
				)
			);
		}

		// llms.txt (core feature) — only when that feature is on.
		if ( ! empty( $config['include_llms'] ) && $this->llms_enabled() ) {
			$entries[] = $this->entry(
				'llms-txt',
				'llms.txt',
				'text/markdown',
				home_url( '/llms.txt' ),
				__( 'An llms.txt index of this site\'s key pages for LLMs, with per-page Markdown available by content negotiation.', 'coywolf-seo' ),
				array(
					__( 'get an index of this site\'s key pages for an LLM', 'coywolf-seo' ),
					__( 'read this site\'s pages as plain markdown', 'coywolf-seo' ),
				)
			);
		}

		// Optional MCP server card.
		$mcp = esc_url_raw( (string) $config['mcp_url'] );
		if ( '' !== $mcp ) {
			$entries[] = $this->entry(
				'mcp-server',
				__( 'MCP server', 'coywolf-seo' ),
				'application/mcp-server-card+json',
				$mcp,
				__( 'A Model Context Protocol server this site exposes for agents to connect to.', 'coywolf-seo' ),
				array( __( 'connect to this site\'s MCP server', 'coywolf-seo' ) )
			);
		}

		// Custom entries (already sanitized on save).
		foreach ( (array) $config['entries'] as $custom ) {
			if ( ! is_array( $custom ) || empty( $custom['displayName'] ) || empty( $custom['type'] ) || empty( $custom['url'] ) ) {
				continue;
			}
			$name    = ! empty( $custom['name'] ) ? (string) $custom['name'] : sanitize_title( (string) $custom['displayName'] );
			$queries = array();
			if ( ! empty( $custom['representativeQueries'] ) && is_array( $custom['representativeQueries'] ) ) {
				foreach ( $custom['representativeQueries'] as $q ) {
					$q = trim( (string) $q );
					if ( '' !== $q ) {
						$queries[] = $q;
					}
				}
			}
			$entries[] = $this->entry(
				$name,
				(string) $custom['displayName'],
				(string) $custom['type'],
				(string) $custom['url'],
				isset( $custom['description'] ) ? (string) $custom['description'] : '',
				$queries
			);
		}

		return $entries;
	}

	/**
	 * Build one catalog entry. identifier is a domain-anchored ARD URN
	 * (urn:air:<publisher-fqdn>:<name>); url is used (never inline data).
	 *
	 * @param string   $name    Terminal URN segment / slug.
	 * @param string   $display Human display name.
	 * @param string   $type    IANA media type of the resource.
	 * @param string   $url     Resource URL.
	 * @param string   $desc    Optional description.
	 * @param string[] $queries Optional representative natural-language queries.
	 * @return array
	 */
	private function entry( $name, $display, $type, $url, $desc = '', $queries = array() ) {
		$entry = array(
			'identifier'  => 'urn:air:' . $this->publisher() . ':' . sanitize_title( $name ),
			'displayName' => $display,
			'type'        => $type,
			'url'         => $url,
			'updatedAt'   => gmdate( 'c' ),
		);
		if ( '' !== trim( (string) $desc ) ) {
			$entry['description'] = (string) $desc;
		}
		if ( ! empty( $queries ) ) {
			$entry['representativeQueries'] = array_values( $queries );
		}
		return $entry;
	}

	/**
	 * The publisher FQDN used in entry URNs and host.identifier.
	 *
	 * @return string
	 */
	private function publisher() {
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return '' !== $host ? $host : 'localhost';
	}

	/**
	 * The public URL of a Labs bundle feature when it is enabled with a live
	 * endpoint, else an empty string. Reads the feature's option directly (no
	 * hard dependency on the feature object) the way llms.txt does.
	 *
	 * @param string $option Feature option key.
	 * @param string $path   Public path the feature serves at.
	 * @return string
	 */
	private function labs_feature_url( $option, $path ) {
		$s = get_option( $option, array() );
		if ( is_array( $s ) && ! empty( $s['enabled'] ) && ! empty( $s['live_endpoint'] ) ) {
			return home_url( $path );
		}
		return '';
	}

	/**
	 * Whether the core llms.txt feature is enabled.
	 *
	 * @return bool
	 */
	private function llms_enabled() {
		return class_exists( 'Coywolf_SEO_Options' ) && (bool) Coywolf_SEO_Options::get( 'llms_enabled' );
	}

	/**
	 * Pretty-print a catalog array to JSON (trailing newline), or '' on failure.
	 *
	 * @param array $catalog Catalog.
	 * @return string
	 */
	private function encode( array $catalog ) {
		$json = wp_json_encode( $catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		return false === $json ? '' : $json . "\n";
	}
}
