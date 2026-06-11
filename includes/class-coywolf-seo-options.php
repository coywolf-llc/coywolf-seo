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
			'og_image_id'           => 0,
			'entity_type'           => 'organization', // organization | person.
			'person_user_id'        => 0,
			'org_properties'        => array(), // Ordered list of array( 'prop' => ..., 'value' => ... ).
			'homepage_title'        => '',
			'homepage_description'  => '',
			'post_append_site_name' => false,
			'post_page_type'        => 'WebPage',
			'post_article_type'     => 'Article',
			'page_append_site_name' => false,
			'page_page_type'        => 'WebPage',
			'page_article_type'     => 'none',
			'cat_append_site_name'  => false,
			'tag_append_site_name'  => false,
			// Settings.
			'access_role'           => 'administrator', // administrator | editor.
			'force_rewrite_titles'  => false,
			'exclude_meta_desc'     => false,
			'robots_index'          => true,
			'robots_follow'         => true,
			'robots_max_image'      => true,
			'robots_max_snippet'    => true,
			'robots_max_video'      => true,
		);
	}

	/**
	 * Get all settings merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
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
			'post_append_site_name',
			'page_append_site_name',
			'cat_append_site_name',
			'tag_append_site_name',
			'force_rewrite_titles',
			'exclude_meta_desc',
			'robots_index',
			'robots_follow',
			'robots_max_image',
			'robots_max_snippet',
			'robots_max_video',
		);
		foreach ( $booleans as $key ) {
			if ( array_key_exists( $key, $raw ) ) {
				$out[ $key ] = ! empty( $raw[ $key ] );
			}
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

		return $out;
	}

	/**
	 * Sanitize a property repeater submission into ordered prop/value rows.
	 *
	 * @param array $rows    Raw rows ( each array with 'prop' and 'value' ).
	 * @param array $catalog Allowed property names => labels.
	 * @return array
	 */
	public static function sanitize_properties( array $rows, array $catalog ) {
		$clean = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['prop'] ) ) {
				continue;
			}
			$prop  = (string) $row['prop'];
			$value = isset( $row['value'] ) ? sanitize_text_field( (string) $row['value'] ) : '';
			if ( ! isset( $catalog[ $prop ] ) || '' === $value ) {
				continue;
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
	 * @return array { page_type, article_type, noindex, nofollow, canonical }
	 */
	public static function post_meta( $post_id ) {
		$defaults = array(
			'page_type'    => '',
			'article_type' => '',
			'noindex'      => false,
			'nofollow'     => false,
			'canonical'    => '',
		);
		$meta = get_post_meta( $post_id, '_coywolf_seo', true );
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
