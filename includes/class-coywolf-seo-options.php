<?php
/**
 * Settings store for Coywolf SEO.
 *
 * Single source of truth for the plugin's options: defaults, retrieval,
 * sanitization, and the Schema.org type/property catalogs the admin UI and
 * front-end output both read from.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Options access and catalogs.
 */
final class Coywolf_SEO_Options {

	/**
	 * Option name holding every plugin setting.
	 */
	const OPTION = 'coywolf_seo_settings';

	/**
	 * Cached settings for the current request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default values for every setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Site Details.
			'og_image_id'                  => 0,
			'entity_type'                  => 'organization', // organization | person.
			'person_user_id'               => 0,
			'org_properties'               => array(), // Ordered list of array( 'prop' => ..., 'value' => ... ).
			'homepage_title'               => '',
			'homepage_description'         => '',
			'append_site_name'             => false,
			'post_page_type'               => 'WebPage',
			'post_article_type'            => 'Article',
			'page_page_type'               => 'WebPage',
			'page_article_type'            => 'none',
			'cat_hide_prefix'              => false,
			// Settings.
			'access_role'                  => 'administrator', // administrator | editor.
			'force_rewrite_titles'         => false,
			'exclude_meta_desc'            => false,
			'robots_index'                 => true,
			'robots_follow'                => true,
			'robots_max_image'             => true,
			'robots_max_snippet'           => true,
			'robots_max_video'             => true,
			// Give every content heading a `jump-`-prefixed id so it can be
			// linked to directly (and so the Table of Contents can anchor to
			// it). On by default; manually set HTML anchors are left as-is.
			'heading_ids'                  => true,
			// Table of Contents jump-link scroll offset (0 = off). Applied as
			// scroll-margin-top on pages that use the TOC so a sticky header
			// does not cover a jumped-to heading.
			'scroll_margin_top'            => 0,
			'scroll_margin_unit'           => 'rem', // rem | px.
			// IndexNow.
			'indexnow_enabled'             => false,
			'indexnow_key'                 => '',
			// News sitemap.
			'sitemap_exclude_posts'        => false,
			'sitemap_exclude_pages'        => false,
			'sitemap_exclude_categories'   => false,
			'sitemap_exclude_users'        => false,
			'news_enabled'                 => false,
			'news_include_posts'           => true,
			'news_include_pages'           => false,
			'news_cat_mode'                => 'all', // all | include | exclude.
			'news_cats'                    => array(),
			// LLMs.txt + Markdown source endpoints (Discovery). Off by default —
			// an explicit opt-in. When off, no /llms.txt, no .md routes, no head
			// links, and no extra headers are emitted by this feature.
			'llms_enabled'                 => false,
			'llms_md_endpoints'            => true,  // Serve per-page .../index.html.md.
			'llms_entities'                => true,  // Include the entities topic index / .md frontmatter.
			'llms_entity_min'              => 2,     // Min articles an entity is `about` before it earns an llms.txt section.
			'llms_entity_placement'        => 'optional', // 'optional' | 'main' — where the entity topic index sits.
			'llms_licence'                 => '',    // Optional content licence noted in .md frontmatter.
			// AI schema enrichment.
			'ai_enabled'                   => false,
			'ai_descriptions'              => false,
			// Active AI service: 'anthropic' (Claude) | 'openai' | 'google' (Gemini).
			'ai_service'                   => 'anthropic',
			// Per-service model + API key. ai_model / ai_api_key are Claude's (kept
			// under their original names so pre-multi-provider installs are unchanged).
			'ai_model'                     => '',
			'ai_api_key'                   => '',
			'ai_model_openai'              => '',
			'ai_api_key_openai'            => '',
			'ai_model_google'              => '',
			'ai_api_key_google'            => '',
			// Image Text defaults (AI-written alt/title/caption/description).
			'image_text_write_alt'         => true,
			'image_text_write_title'       => false,
			'image_text_write_caption'     => true,
			'image_text_write_description' => false,
			'image_text_overwrite'         => false,
			'image_text_instructions'      => '',
			// Master feature toggles. Stored as "off" flags so the Settings
			// "Turn off features" checkboxes map directly; default false = the
			// feature is on, preserving behavior on existing installs.
			'feature_ai_off'               => false,
			'feature_schema_off'           => false,
			'feature_sitemaps_off'         => false,
			'feature_links_off'            => false,
			'feature_redirects_off'        => false,
			'feature_robots_off'           => false,
			// Link Manager settings (relocated from the standalone plugin).
			'lm_speed'                     => 'default',
			'lm_user_agent'                => '',
		);
	}

	/**
	 * Get all settings merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved = get_option( self::OPTION, array() );
			$saved = is_array( $saved ) ? $saved : array();

			// Migration: the four per-type "Append site name" options were
			// consolidated into one. Carry an enabled legacy flag over.
			if ( ! array_key_exists( 'append_site_name', $saved ) ) {
				foreach ( array( 'post_append_site_name', 'page_append_site_name', 'cat_append_site_name', 'tag_append_site_name' ) as $legacy ) {
					if ( ! empty( $saved[ $legacy ] ) ) {
						$saved['append_site_name'] = true;
						break;
					}
				}
			}

			self::$cache = wp_parse_args( $saved, self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Whether a master feature is enabled (not turned off on the Settings page).
	 *
	 * @param string $feature One of 'ai', 'schema', 'sitemaps'.
	 * @return bool
	 */
	public static function feature_enabled( $feature ) {
		return ! (bool) self::get( 'feature_' . $feature . '_off' );
	}

	/**
	 * Persist a partial set of settings (merged over what is stored).
	 *
	 * @param array $partial Keys to update, already sanitized.
	 * @return void
	 */
	public static function update( array $partial ) {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		update_option( self::OPTION, array_merge( $saved, $partial ) );
		self::$cache = null;
	}

	/**
	 * Sanitize a raw settings array (admin form input). Unknown keys are
	 * dropped; values are coerced to their expected shapes.
	 *
	 * @param array $raw Raw input.
	 * @return array Sanitized subset, safe to pass to update().
	 */
	public static function sanitize( array $raw ) {
		$out      = array();
		$defaults = self::defaults();

		$booleans = array(
			'append_site_name',
			'cat_hide_prefix',
			'force_rewrite_titles',
			'exclude_meta_desc',
			'heading_ids',
			'robots_index',
			'robots_follow',
			'robots_max_image',
			'robots_max_snippet',
			'robots_max_video',
			'indexnow_enabled',
			'sitemap_exclude_posts',
			'sitemap_exclude_pages',
			'sitemap_exclude_categories',
			'sitemap_exclude_users',
			'news_enabled',
			'news_include_posts',
			'news_include_pages',
			'llms_enabled',
			'llms_md_endpoints',
			'llms_entities',
			'ai_enabled',
			'ai_descriptions',
			'image_text_write_alt',
			'image_text_write_title',
			'image_text_write_caption',
			'image_text_write_description',
			'image_text_overwrite',
			'feature_ai_off',
			'feature_schema_off',
			'feature_sitemaps_off',
			'feature_links_off',
			'feature_redirects_off',
			'feature_robots_off',
		);
		foreach ( $booleans as $key ) {
			if ( array_key_exists( $key, $raw ) ) {
				$out[ $key ] = ! empty( $raw[ $key ] );
			}
		}

		if ( isset( $raw['image_text_instructions'] ) ) {
			$out['image_text_instructions'] = sanitize_textarea_field( $raw['image_text_instructions'] );
		}

		if ( isset( $raw['llms_licence'] ) ) {
			$out['llms_licence'] = sanitize_text_field( $raw['llms_licence'] );
		}
		if ( isset( $raw['llms_entity_min'] ) ) {
			$out['llms_entity_min'] = max( 1, min( 999, (int) $raw['llms_entity_min'] ) );
		}
		if ( isset( $raw['llms_entity_placement'] ) ) {
			$out['llms_entity_placement'] = in_array( $raw['llms_entity_placement'], array( 'optional', 'main' ), true ) ? $raw['llms_entity_placement'] : 'optional';
		}

		if ( isset( $raw['scroll_margin_top'] ) ) {
			$out['scroll_margin_top'] = max( 0, min( 999, (int) $raw['scroll_margin_top'] ) );
		}
		if ( isset( $raw['scroll_margin_unit'] ) ) {
			$out['scroll_margin_unit'] = in_array( $raw['scroll_margin_unit'], array( 'rem', 'px' ), true ) ? $raw['scroll_margin_unit'] : 'rem';
		}

		if ( isset( $raw['og_image_id'] ) ) {
			$out['og_image_id'] = max( 0, (int) $raw['og_image_id'] );
		}
		if ( isset( $raw['person_user_id'] ) ) {
			$out['person_user_id'] = max( 0, (int) $raw['person_user_id'] );
		}
		if ( isset( $raw['entity_type'] ) ) {
			$out['entity_type'] = ( 'person' === $raw['entity_type'] ) ? 'person' : 'organization';
		}
		if ( isset( $raw['access_role'] ) ) {
			$out['access_role'] = ( 'editor' === $raw['access_role'] ) ? 'editor' : 'administrator';
		}
		foreach ( array( 'homepage_title', 'homepage_description' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( $raw[ $key ] );
			}
		}

		$page_types = self::page_types();
		foreach ( array( 'post_page_type', 'page_page_type' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$out[ $key ] = isset( $page_types[ $raw[ $key ] ] ) ? $raw[ $key ] : $defaults[ $key ];
			}
		}

		$article_types = self::article_types();
		foreach ( array( 'post_article_type', 'page_article_type' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$value       = $raw[ $key ];
				$out[ $key ] = ( 'none' === $value || isset( $article_types[ $value ] ) ) ? $value : $defaults[ $key ];
			}
		}

		if ( isset( $raw['org_properties'] ) && is_array( $raw['org_properties'] ) ) {
			$out['org_properties'] = self::sanitize_properties( $raw['org_properties'], self::organization_properties() );
		}

		if ( isset( $raw['indexnow_key'] ) ) {
			$out['indexnow_key'] = preg_replace( '/[^a-zA-Z0-9-]/', '', (string) $raw['indexnow_key'] );
		}
		if ( isset( $raw['ai_service'] ) ) {
			$out['ai_service'] = in_array( $raw['ai_service'], array( 'anthropic', 'openai', 'google' ), true ) ? $raw['ai_service'] : 'anthropic';
		}
		foreach ( array( 'ai_model', 'ai_model_openai', 'ai_model_google' ) as $coywolf_seo_model_key ) {
			if ( isset( $raw[ $coywolf_seo_model_key ] ) ) {
				$out[ $coywolf_seo_model_key ] = preg_replace( '/[^a-z0-9.\-]/', '', strtolower( (string) $raw[ $coywolf_seo_model_key ] ) );
			}
		}
		foreach ( array( 'ai_api_key', 'ai_api_key_openai', 'ai_api_key_google' ) as $coywolf_seo_key_key ) {
			if ( isset( $raw[ $coywolf_seo_key_key ] ) ) {
				$out[ $coywolf_seo_key_key ] = preg_replace( '/\s+/', '', (string) $raw[ $coywolf_seo_key_key ] );
			}
		}
		if ( isset( $raw['news_cat_mode'] ) ) {
			$out['news_cat_mode'] = in_array( $raw['news_cat_mode'], array( 'all', 'include', 'exclude' ), true ) ? $raw['news_cat_mode'] : 'all';
		}
		if ( isset( $raw['news_cats'] ) ) {
			$out['news_cats'] = array_values( array_filter( array_map( 'absint', (array) $raw['news_cats'] ) ) );
		}

		// Link Manager settings.
		if ( isset( $raw['lm_speed'] ) ) {
			$out['lm_speed'] = in_array( $raw['lm_speed'], array( 'polite', 'default', 'fast', 'faster' ), true ) ? $raw['lm_speed'] : 'default';
		}
		if ( isset( $raw['lm_user_agent'] ) ) {
			// Strip CR/LF/TAB/NUL first (header-injection guard) before the
			// standard text-field sanitize.
			$ua                   = preg_replace( '/[\r\n\t\0]+/', ' ', (string) $raw['lm_user_agent'] );
			$out['lm_user_agent'] = trim( sanitize_text_field( $ua ) );
		}

		return $out;
	}

	/**
	 * Input metadata for schema properties: the HTML input type that fits
	 * the property, and the sub-fields of structured properties. Properties
	 * not listed here use a plain text input.
	 *
	 * @return array Property => array( input | fields ).
	 */
	public static function property_inputs() {
		// Entity-reference properties (worksFor, founder, …) share one shallow
		// sub-field set: a name, a URL, and an @id pointing at the referenced
		// node. Defined once and reused to stay DRY.
		$entity_ref = array(
			'fields' => array(
				'name' => array(
					'label' => __( 'Name', 'coywolf-seo' ),
					'input' => 'text',
				),
				'url'  => array(
					'label' => __( 'URL', 'coywolf-seo' ),
					'input' => 'url',
				),
				'@id'  => array(
					'label' => __( '@id (entity reference)', 'coywolf-seo' ),
					'input' => 'url',
				),
			),
		);

		return array(
			'@id'                => array( 'input' => 'url' ),
			'url'                => array( 'input' => 'url' ),
			'sameAs'             => array( 'input' => 'url' ),
			'logo'               => array( 'input' => 'image' ),
			'image'              => array( 'input' => 'image' ),
			'email'              => array( 'input' => 'email' ),
			'telephone'          => array( 'input' => 'tel' ),
			'faxNumber'          => array( 'input' => 'tel' ),
			'foundingDate'       => array( 'input' => 'date' ),
			'birthDate'          => array( 'input' => 'date' ),
			'numberOfEmployees'  => array( 'input' => 'number' ),
			'ethicsPolicy'       => array( 'input' => 'url' ),
			'address'            => array(
				'fields' => array(
					'streetAddress'   => array(
						'label' => __( 'Street address', 'coywolf-seo' ),
						'input' => 'text',
					),
					'addressLocality' => array(
						'label' => __( 'City', 'coywolf-seo' ),
						'input' => 'text',
					),
					'addressRegion'   => array(
						'label' => __( 'Region / State', 'coywolf-seo' ),
						'input' => 'text',
					),
					'postalCode'      => array(
						'label' => __( 'Postal code', 'coywolf-seo' ),
						'input' => 'text',
					),
					'addressCountry'  => array(
						'label' => __( 'Country', 'coywolf-seo' ),
						'input' => 'text',
					),
				),
			),
			'contactPoint'       => array(
				'fields' => array(
					'telephone'   => array(
						'label' => __( 'Telephone', 'coywolf-seo' ),
						'input' => 'tel',
					),
					'email'       => array(
						'label' => __( 'Email', 'coywolf-seo' ),
						'input' => 'email',
					),
					'contactType' => array(
						'label' => __( 'Contact type (customer support, sales, …)', 'coywolf-seo' ),
						'input' => 'text',
					),
				),
			),
			'worksFor'           => $entity_ref,
			'affiliation'        => $entity_ref,
			'alumniOf'           => $entity_ref,
			'memberOf'           => $entity_ref,
			'founder'            => $entity_ref,
			'parentOrganization' => $entity_ref,
			'subOrganization'    => $entity_ref,
			'brand'              => $entity_ref,
		);
	}

	/**
	 * Sanitize one property value by its input type.
	 *
	 * @param string $value Raw value.
	 * @param string $input Input type.
	 * @return string
	 */
	private static function sanitize_property_value( $value, $input ) {
		$value = (string) $value;
		switch ( $input ) {
			case 'url':
			case 'image':
				return esc_url_raw( $value );
			case 'email':
				return sanitize_email( $value );
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Sanitize a property repeater submission into ordered prop/value rows.
	 * Structured properties (address, contactPoint) carry an array value of
	 * their sub-fields; everything else is a string.
	 *
	 * @param array $rows    Raw rows ( each array with 'prop' and 'value' ).
	 * @param array $catalog Allowed property names => labels.
	 * @return array
	 */
	public static function sanitize_properties( array $rows, array $catalog ) {
		$inputs = self::property_inputs();
		$clean  = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['prop'] ) ) {
				continue;
			}
			$prop = (string) $row['prop'];
			if ( ! isset( $catalog[ $prop ] ) ) {
				continue;
			}
			$meta = isset( $inputs[ $prop ] ) ? $inputs[ $prop ] : array( 'input' => 'text' );

			if ( isset( $meta['fields'] ) ) {
				$raw_value = isset( $row['value'] ) && is_array( $row['value'] ) ? $row['value'] : array();
				$value     = array();
				foreach ( $meta['fields'] as $sub => $sub_meta ) {
					$sub_value = isset( $raw_value[ $sub ] ) ? self::sanitize_property_value( $raw_value[ $sub ], $sub_meta['input'] ) : '';
					if ( '' !== $sub_value ) {
						$value[ $sub ] = $sub_value;
					}
				}
				if ( empty( $value ) ) {
					continue;
				}
			} else {
				$input = isset( $meta['input'] ) ? $meta['input'] : 'text';
				$value = isset( $row['value'] ) && ! is_array( $row['value'] ) ? self::sanitize_property_value( $row['value'], $input ) : '';
				if ( '' === $value ) {
					continue;
				}
			}

			$clean[] = array(
				'prop'  => $prop,
				'value' => $value,
			);
		}
		return $clean;
	}

	/**
	 * Schema.org WebPage type and subtypes.
	 *
	 * @return array Type => label.
	 */
	public static function page_types() {
		return array(
			'WebPage'           => 'Web Page',
			'AboutPage'         => 'About Page',
			'CheckoutPage'      => 'Checkout Page',
			'CollectionPage'    => 'Collection Page',
			'ContactPage'       => 'Contact Page',
			'FAQPage'           => 'FAQ Page',
			'ItemPage'          => 'Item Page',
			'MedicalWebPage'    => 'Medical Web Page',
			'ProfilePage'       => 'Profile Page',
			'QAPage'            => 'Q&A Page',
			'RealEstateListing' => 'Real Estate Listing',
			'SearchResultsPage' => 'Search Results Page',
		);
	}

	/**
	 * Schema.org Article type and subtypes.
	 *
	 * @return array Type => label.
	 */
	public static function article_types() {
		return array(
			'Article'                  => 'Article',
			'AdvertiserContentArticle' => 'Advertiser Content Article',
			'AnalysisNewsArticle'      => 'Analysis News Article',
			'APIReference'             => 'API Reference',
			'AskPublicNewsArticle'     => 'Ask Public News Article',
			'BackgroundNewsArticle'    => 'Background News Article',
			'BlogPosting'              => 'Blog Posting',
			'DiscussionForumPosting'   => 'Discussion Forum Posting',
			'LiveBlogPosting'          => 'Live Blog Posting',
			'MedicalScholarlyArticle'  => 'Medical Scholarly Article',
			'NewsArticle'              => 'News Article',
			'OpinionNewsArticle'       => 'Opinion News Article',
			'Report'                   => 'Report',
			'ReportageNewsArticle'     => 'Reportage News Article',
			'ReviewNewsArticle'        => 'Review News Article',
			'SatiricalArticle'         => 'Satirical Article',
			'ScholarlyArticle'         => 'Scholarly Article',
			'SocialMediaPosting'       => 'Social Media Posting',
			'TechArticle'              => 'Tech Article',
		);
	}

	/**
	 * Schema.org Organization properties offered in the Site Details
	 * property picker. Values are entered as text or URLs; the schema
	 * builder shapes the well-known ones (logo, image, sameAs) into their
	 * proper structures.
	 *
	 * @return array Property => label.
	 */
	public static function organization_properties() {
		return array(
			'@id'                => '@id',
			'name'               => 'name',
			'alternateName'      => 'alternateName',
			'legalName'          => 'legalName',
			'description'        => 'description',
			'url'                => 'url',
			'logo'               => 'logo',
			'image'              => 'image',
			'email'              => 'email',
			'telephone'          => 'telephone',
			'faxNumber'          => 'faxNumber',
			'address'            => 'address',
			'location'           => 'location',
			'areaServed'         => 'areaServed',
			'foundingDate'       => 'foundingDate',
			'foundingLocation'   => 'foundingLocation',
			'founder'            => 'founder',
			'numberOfEmployees'  => 'numberOfEmployees',
			'duns'               => 'duns',
			'taxID'              => 'taxID',
			'vatID'              => 'vatID',
			'leiCode'            => 'leiCode',
			'naics'              => 'naics',
			'isicV4'             => 'isicV4',
			'iso6523Code'        => 'iso6523Code',
			'tickerSymbol'       => 'tickerSymbol',
			'sameAs'             => 'sameAs',
			'slogan'             => 'slogan',
			'keywords'           => 'keywords',
			'knowsAbout'         => 'knowsAbout',
			'knowsLanguage'      => 'knowsLanguage',
			'award'              => 'award',
			'brand'              => 'brand',
			'parentOrganization' => 'parentOrganization',
			'subOrganization'    => 'subOrganization',
			'memberOf'           => 'memberOf',
			'member'             => 'member',
			'sponsor'            => 'sponsor',
			'funder'             => 'funder',
			'contactPoint'       => 'contactPoint',
			'identifier'         => 'identifier',
		);
	}

	/**
	 * Schema.org Person properties offered in the Authors property picker.
	 * Values are entered as text or URLs; the schema builder shapes the
	 * well-known ones (image, sameAs, worksFor) into their proper
	 * structures.
	 *
	 * @return array Property => label.
	 */
	public static function person_properties() {
		return array(
			'@id'                       => '@id',
			'name'                      => 'name',
			'additionalName'            => 'additionalName',
			'alternateName'             => 'alternateName',
			'givenName'                 => 'givenName',
			'familyName'                => 'familyName',
			'honorificPrefix'           => 'honorificPrefix',
			'honorificSuffix'           => 'honorificSuffix',
			'description'               => 'description',
			'disambiguatingDescription' => 'disambiguatingDescription',
			'url'                       => 'url',
			'image'                     => 'image',
			'email'                     => 'email',
			'telephone'                 => 'telephone',
			'jobTitle'                  => 'jobTitle',
			'worksFor'                  => 'worksFor',
			'affiliation'               => 'affiliation',
			'alumniOf'                  => 'alumniOf',
			'memberOf'                  => 'memberOf',
			'hasOccupation'             => 'hasOccupation',
			'knowsAbout'                => 'knowsAbout',
			'knowsLanguage'             => 'knowsLanguage',
			'nationality'               => 'nationality',
			'homeLocation'              => 'homeLocation',
			'workLocation'              => 'workLocation',
			'address'                   => 'address',
			'birthDate'                 => 'birthDate',
			'birthPlace'                => 'birthPlace',
			'award'                     => 'award',
			'brand'                     => 'brand',
			'callSign'                  => 'callSign',
			'colleague'                 => 'colleague',
			'gender'                    => 'gender',
			'sameAs'                    => 'sameAs',
		);
	}

	/**
	 * Option name holding per-user author schema properties.
	 */
	const AUTHORS_OPTION = 'coywolf_seo_authors';

	/**
	 * All saved author property sets, keyed by user ID.
	 *
	 * @return array
	 */
	public static function authors_all() {
		$saved = get_option( self::AUTHORS_OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Saved property rows for one author.
	 *
	 * @param int $user_id User ID.
	 * @return array|null Ordered prop/value rows, or null when never saved.
	 */
	public static function author_rows( $user_id ) {
		$all = self::authors_all();
		return isset( $all[ $user_id ] ) && is_array( $all[ $user_id ] ) ? $all[ $user_id ] : null;
	}

	/**
	 * Persist the property rows for one author.
	 *
	 * @param int   $user_id User ID.
	 * @param array $rows    Sanitized prop/value rows.
	 * @return void
	 */
	public static function save_author_rows( $user_id, array $rows ) {
		$all             = self::authors_all();
		$all[ $user_id ] = $rows;
		update_option( self::AUTHORS_OPTION, $all );
	}

	/**
	 * Per-post SEO meta for a post, merged over empty defaults.
	 *
	 * @param int $post_id Post ID.
	 * @return array { title, description, page_type, article_type, noindex, nofollow, canonical }
	 */
	public static function post_meta( $post_id ) {
		$defaults = array(
			'title'        => '',
			'description'  => '',
			'page_type'    => '',
			'article_type' => '',
			'noindex'      => false,
			'nofollow'     => false,
			'canonical'    => '',
		);
		$meta     = get_post_meta( $post_id, '_coywolf_seo', true );
		if ( ! is_array( $meta ) ) {
			return $defaults;
		}
		return wp_parse_args( $meta, $defaults );
	}

	/**
	 * The em dash used between a title and the appended site name.
	 *
	 * @return string
	 */
	public static function separator() {
		return '—';
	}
}
