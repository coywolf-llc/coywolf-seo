<?php
/**
 * Bot catalog.
 *
 * Loads the bundled Cloudflare Bot Directory (microlinkhq/cloudflare-bot-
 * directory) and groups bots by category for the rule editor. The catalog
 * ships with the plugin and is refreshed through normal plugin updates.
 *
 * Upstream record shape (the fields we use):
 *   {
 *     "name": "GPTBot",
 *     "operator": "OpenAI",
 *     "category": "AI_CRAWLER",          // single UPPER_SNAKE token
 *     "description": "...",
 *     "userAgents": ["GPTBot"],          // robots.txt product token(s)
 *     "userAgentPatterns": ["gptbot"]    // lowercase match patterns
 *   }
 *
 * @package CoywolfRobotsTxtManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coywolf_SEO_Robots_Bots {

	/**
	 * Friendlier labels for the UPPER_SNAKE category tokens. Anything not
	 * listed falls back to {@see self::humanize()}.
	 *
	 * @var array<string,string>
	 */
	private static $category_labels = array(
		'AI_CRAWLER'                 => 'AI Crawler',
		'AI_ASSISTANT'               => 'AI Assistant',
		'AI_SEARCH'                  => 'AI Search',
		'SEARCH_ENGINE_CRAWLER'      => 'Search Engine Crawler',
		'SEARCH_ENGINE_OPTIMIZATION' => 'Search Engine Optimization',
		'MONITORING_AND_ANALYTICS'   => 'Monitoring & Analytics',
		'ADVERTISING_AND_MARKETING'  => 'Advertising & Marketing',
		'SOCIAL_MEDIA_MARKETING'     => 'Social Media Marketing',
		'PAGE_PREVIEW'               => 'Page Preview',
		'FEED_FETCHER'               => 'Feed Fetcher',
		'ACADEMIC_RESEARCH'          => 'Academic Research',
		'SECURITY'                   => 'Security',
		'ACCESSIBILITY'              => 'Accessibility',
		'AGGREGATOR'                 => 'Aggregator',
		'ARCHIVER'                   => 'Archiver',
		'WEBHOOKS'                   => 'Webhooks',
		'OTHER'                      => 'Other',
	);

	/**
	 * Lowercased-alias => canonical-token map, built lazily once per request
	 * by {@see self::alias_map()}.
	 *
	 * @var array<string,string>|null
	 */
	private static $alias_map = null;

	/**
	 * The merged catalog (Cloudflare data + bundled AI-crawler supplement),
	 * built once per request by {@see self::all()}.
	 *
	 * @var array|null
	 */
	private static $catalog_cache = null;

	/**
	 * The active catalog: the bundled Cloudflare JSON with the curated
	 * AI-crawler supplement merged in (so well-known AI crawlers the Cloudflare
	 * directory omits are still selectable and still match on import).
	 *
	 * @return array
	 */
	public static function all() {
		if ( null !== self::$catalog_cache ) {
			return self::$catalog_cache;
		}
		self::$catalog_cache = self::merge_supplement( self::bundled() );
		return self::$catalog_cache;
	}

	/**
	 * The catalog bundled with the plugin (fallback / first run).
	 *
	 * @return array
	 */
	public static function bundled() {
		// The bundled file never changes within a request; decode it once. It's
		// ~350 KB, and all()/by_category()/known_agents() can each ask for it
		// during a single admin render.
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$file = __DIR__ . '/data/cloudflare-bots.json';
		if ( ! is_readable( $file ) ) {
			$cache = array();
			return $cache;
		}
		$data  = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$cache = is_array( $data ) ? $data : array();
		return $cache;
	}

	/**
	 * Curated supplement of well-known AI crawlers that the Cloudflare Bot
	 * Directory doesn't list as first-class records (ClaudeBot, CCBot,
	 * Google-Extended, anthropic-ai, Applebot-Extended, and friends). Bundled
	 * with the plugin and merged into {@see self::all()} so these crawlers are
	 * selectable by category and match when an existing robots.txt is imported.
	 *
	 * @return array
	 */
	public static function supplement() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$file = __DIR__ . '/data/ai-crawlers.json';
		if ( ! is_readable( $file ) ) {
			$cache = array();
			return $cache;
		}
		$data  = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$cache = is_array( $data ) ? $data : array();
		return $cache;
	}

	/**
	 * Curated robots.txt tokens for the Cloudflare bots, keyed by upstream
	 * slug: `{ "<slug>": { "token": "...", "aliases": ["..."] } }`. Each bot's
	 * correct `User-agent` token (and the variant spellings that should match
	 * it on import) was researched once and is regenerated for new/changed bots
	 * by `bin/update-bots.php`. {@see self::token()} prefers it; {@see
	 * self::alias_map()} folds the aliases in. Empty if the file is missing
	 * (then {@see self::token()} falls back to its heuristic).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function bot_tokens() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$file = __DIR__ . '/data/bot-tokens.json';
		if ( ! is_readable( $file ) ) {
			$cache = array();
			return $cache;
		}
		$data  = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$cache = is_array( $data ) ? $data : array();
		return $cache;
	}

	/**
	 * Ordered log of curated-token renames: `[ { "from": "Old", "to": "New" } ]`.
	 * When a bot's token is renamed, an entry is appended here so existing rules
	 * (which stored the old token) can be migrated to the new one — see
	 * {@see Coywolf_SEO_Robots::maybe_apply_token_renames()}.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function token_renames() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$file = __DIR__ . '/data/token-renames.json';
		if ( ! is_readable( $file ) ) {
			$cache = array();
			return $cache;
		}
		$data  = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$cache = is_array( $data ) ? $data : array();
		return $cache;
	}

	/**
	 * Append the curated supplement to a catalog, skipping any record whose
	 * token a catalog entry already claims (case-insensitively) — so we only
	 * fill gaps and never duplicate a bot the upstream directory already has.
	 *
	 * @param array $base Catalog records (Cloudflare, bundled or refreshed).
	 * @return array
	 */
	private static function merge_supplement( $base ) {
		$supp = self::supplement();
		if ( empty( $supp ) ) {
			return $base;
		}
		$have = array();
		foreach ( $base as $bot ) {
			$token = strtolower( self::token( $bot ) );
			if ( '' !== $token ) {
				$have[ $token ] = true;
			}
		}
		$out = $base;
		foreach ( $supp as $bot ) {
			$token = strtolower( self::token( $bot ) );
			if ( '' !== $token && ! isset( $have[ $token ] ) ) {
				$out[]          = $bot;
				$have[ $token ] = true;
			}
		}
		return $out;
	}

	/**
	 * Bots grouped by display category, deduped by robots.txt token. Each
	 * entry is reduced to the fields the editor needs. Categories and the
	 * bots within them are sorted alphabetically.
	 *
	 * @return array<string,array<int,array<string,string>>>
	 */
	public static function by_category() {
		$cats = array();
		foreach ( self::all() as $bot ) {
			$token = self::token( $bot );
			if ( '' === $token ) {
				continue;
			}
			$label = self::category_label( isset( $bot['category'] ) ? $bot['category'] : '' );

			if ( ! isset( $cats[ $label ] ) ) {
				$cats[ $label ] = array();
			}
			// Dedupe by token within a category (a few records share a token).
			$cats[ $label ][ strtolower( $token ) ] = array(
				'token'       => $token,
				'name'        => isset( $bot['name'] ) ? (string) $bot['name'] : $token,
				'operator'    => isset( $bot['operator'] ) ? (string) $bot['operator'] : '',
				'description' => isset( $bot['description'] ) ? trim( (string) $bot['description'] ) : '',
			);
		}

		ksort( $cats, SORT_NATURAL | SORT_FLAG_CASE );
		$out = array();
		foreach ( $cats as $label => $bots ) {
			$bots = array_values( $bots );
			usort(
				$bots,
				static function ( $a, $b ) {
					return strcasecmp( $a['name'], $b['name'] );
				}
			);
			$out[ $label ] = $bots;
		}

		return $out;
	}

	/**
	 * Every robots.txt token in the catalog, keyed for fast lookup. Used to
	 * tell a catalog bot from a hand-entered custom agent when editing.
	 *
	 * @return array<string,true>
	 */
	public static function known_agents() {
		$set = array();
		foreach ( self::all() as $bot ) {
			$token = self::token( $bot );
			if ( '' !== $token ) {
				$set[ $token ] = true;
			}
		}
		return $set;
	}

	/**
	 * The robots.txt User-agent token for a bot record.
	 *
	 * A robots.txt User-agent is a bare "product token" — letters, digits,
	 * `.`, `_`, `-`, no spaces, no URL. The Cloudflare catalog isn't that
	 * tidy: `userAgentPatterns` is sometimes a clean token ("Googlebot/"),
	 * but sometimes a URL ("http://yandex.com/bots") or a phrase
	 * ("Yahoo! Slurp"); `userAgents` holds the full UA string and is useless
	 * here. So we take the first *clean* token across, in order: a pattern,
	 * the name as a whole, the last word of the name, then the slug. Only if
	 * none of those is a valid token do we fall back to the raw name/pattern/
	 * slug (rare, messy records that ship no real token).
	 *
	 * @param array $bot Bot record.
	 * @return string
	 */
	public static function token( $bot ) {
		// A curated override (includes/data/bot-tokens.json), keyed by the
		// upstream slug, is the authoritative token when present — it fixes the
		// records whose pattern/name heuristic below picks a wrong or generic
		// token (e.g. "BrightEdge Bot" -> "BrightEdge", "Toutiao" -> "Bytespider").
		if ( ! empty( $bot['slug'] ) ) {
			$curated = self::bot_tokens();
			$slug    = (string) $bot['slug'];
			if ( isset( $curated[ $slug ] ) ) {
				// Explicitly excluded — there's no robots.txt-targetable token for
				// this bot (e.g. a "signed agent" that identifies cryptographically,
				// not via a User-agent). Returning '' drops it from the catalog
				// (by_category()/known_agents()/alias_map() all skip empty tokens).
				if ( ! empty( $curated[ $slug ]['excluded'] ) ) {
					return '';
				}
				if ( isset( $curated[ $slug ]['token'] ) ) {
					$t = rtrim( trim( (string) $curated[ $slug ]['token'] ), '/' );
					// Curated tokens are human-vetted, so also accept an optional
					// "/version" suffix the strict heuristic rejects — e.g. ThousandEyes
					// documents the identifier "TE/1.0". URLs are still rejected (the
					// suffix must be a digit-led version, not "://...").
					if ( preg_match( '#^[A-Za-z0-9][A-Za-z0-9._-]*(?:/[0-9][0-9A-Za-z._-]*)?$#', $t ) ) {
						return $t;
					}
				}
			}
		}
		if ( ! empty( $bot['userAgentPatterns'] ) && is_array( $bot['userAgentPatterns'] ) ) {
			foreach ( $bot['userAgentPatterns'] as $pattern ) {
				$clean = self::clean_token( rtrim( trim( (string) $pattern ), '/' ) );
				if ( '' !== $clean && 'user_agent' !== $clean ) {
					return $clean;
				}
			}
		}
		$name = isset( $bot['name'] ) ? trim( (string) $bot['name'] ) : '';
		if ( '' !== $name ) {
			$clean = self::clean_token( $name );
			if ( '' !== $clean ) {
				return $clean;
			}
			// "Yahoo Slurp" -> "Slurp" (the last word is the real token).
			$parts = preg_split( '/\s+/', $name );
			$clean = self::clean_token( (string) end( $parts ) );
			if ( '' !== $clean ) {
				return $clean;
			}
		}
		if ( ! empty( $bot['slug'] ) ) {
			$clean = self::clean_token( trim( (string) $bot['slug'] ) );
			if ( '' !== $clean ) {
				return $clean;
			}
		}
		// No clean token anywhere — keep the legacy fallback so the bot still
		// shows up (just with a less-than-ideal token).
		if ( '' !== $name ) {
			return $name;
		}
		if ( ! empty( $bot['userAgentPatterns'][0] ) ) {
			return rtrim( trim( (string) $bot['userAgentPatterns'][0] ), '/' );
		}
		return ! empty( $bot['slug'] ) ? trim( (string) $bot['slug'] ) : '';
	}

	/**
	 * Return $s if it's a valid robots.txt product token, else ''.
	 *
	 * A product token starts with a letter or digit and contains only
	 * letters, digits, `.`, `_`, and `-` — no spaces, slashes, colons, or
	 * other punctuation. `*` is allowed on its own (the all-robots wildcard)
	 * so callers can round-trip it.
	 *
	 * @param string $s Candidate.
	 * @return string
	 */
	private static function clean_token( $s ) {
		$s = trim( (string) $s );
		if ( '*' === $s ) {
			return $s;
		}
		return preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $s ) ? $s : '';
	}

	/**
	 * Map an arbitrary User-agent token (as written in someone's robots.txt)
	 * to the catalog's canonical token for that bot, or '' if unknown.
	 *
	 * This is what lets the importer recognize "Bingbot", "Slurp", and
	 * "YandexBot" as the catalog bots bingbot / Slurp / YandexBot instead of
	 * filing them as custom agents. Matching is case-insensitive and ignores
	 * a trailing slash, and considers every alias a record carries (its
	 * patterns, name, and slug), not just its canonical token.
	 *
	 * @param string $agent Raw User-agent token.
	 * @return string Canonical catalog token, or '' if not a known bot.
	 */
	public static function canonical_token( $agent ) {
		$agent = strtolower( rtrim( trim( (string) $agent ), '/' ) );
		if ( '' === $agent || '*' === $agent ) {
			return '';
		}
		$map = self::alias_map();
		return isset( $map[ $agent ] ) ? $map[ $agent ] : '';
	}

	/**
	 * Lowercased alias => canonical-token lookup, built once per request.
	 *
	 * Two passes so canonical tokens win over softer aliases: first register
	 * every bot's own {@see self::token()} (these are authoritative — if two
	 * bots disagree, the first-seen canonical token holds), then fold in the
	 * weaker aliases (patterns, name, slug) without overwriting a token that
	 * a canonical pass already claimed.
	 *
	 * @return array<string,string>
	 */
	private static function alias_map() {
		if ( null !== self::$alias_map ) {
			return self::$alias_map;
		}
		$bots = self::all();
		$map  = array();

		// Pass 1: canonical tokens are authoritative.
		foreach ( $bots as $bot ) {
			$token = self::token( $bot );
			if ( '' === $token ) {
				continue;
			}
			$key = strtolower( rtrim( $token, '/' ) );
			if ( '' !== $key && ! isset( $map[ $key ] ) ) {
				$map[ $key ] = $token;
			}
		}

		// Pass 2: softer aliases fill gaps but never clobber a token.
		$curated = self::bot_tokens();
		foreach ( $bots as $bot ) {
			$token = self::token( $bot );
			if ( '' === $token ) {
				continue;
			}
			$aliases = array();
			if ( ! empty( $bot['userAgentPatterns'] ) && is_array( $bot['userAgentPatterns'] ) ) {
				$aliases = $bot['userAgentPatterns'];
			}
			if ( isset( $bot['name'] ) ) {
				$aliases[] = $bot['name'];
			}
			if ( isset( $bot['slug'] ) ) {
				$aliases[] = $bot['slug'];
				// Curated aliases (variant spellings, old patterns, UA-embedded
				// tokens) so e.g. "BrightEdge Crawler" still maps to "BrightEdge".
				if ( ! empty( $curated[ $bot['slug'] ]['aliases'] ) && is_array( $curated[ $bot['slug'] ]['aliases'] ) ) {
					$aliases = array_merge( $aliases, $curated[ $bot['slug'] ]['aliases'] );
				}
			}
			foreach ( $aliases as $alias ) {
				$key = strtolower( rtrim( trim( (string) $alias ), '/' ) );
				if ( '' === $key || 'user_agent' === $key || '*' === $key ) {
					continue;
				}
				if ( ! isset( $map[ $key ] ) ) {
					$map[ $key ] = $token;
				}
			}
		}

		self::$alias_map = $map;
		return $map;
	}

	/**
	 * Human-friendly label for a category token.
	 *
	 * @param string $cat Raw category token.
	 * @return string
	 */
	public static function category_label( $cat ) {
		$cat = trim( (string) $cat );
		if ( '' === $cat ) {
			return 'Other';
		}
		if ( isset( self::$category_labels[ $cat ] ) ) {
			return self::$category_labels[ $cat ];
		}
		return self::humanize( $cat );
	}

	/**
	 * Turn an UPPER_SNAKE token into Title Case words.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function humanize( $token ) {
		$words = explode( '_', strtolower( $token ) );
		$words = array_map( 'ucfirst', $words );
		return implode( ' ', $words );
	}

	/**
	 * Total number of bots in the active catalog.
	 *
	 * @return int
	 */
	public static function count() {
		return count( self::all() );
	}
}
