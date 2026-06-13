<?php
/**
 * Schema.org JSON-LD output for Coywolf SEO.
 *
 * One @graph in the head of the homepage, posts, pages, categories, and
 * tags. The graph is built from the Site Details settings (the publisher
 * Organization or Person and its properties), the Authors page (Person
 * properties per author), and the per-post schema type overrides.
 *
 * Property rows from the admin property pickers are plain text/URL values;
 * the well-known object-valued ones (logo, image, sameAs, worksFor, and
 * friends) are shaped into their proper structures here, and a property
 * entered more than once becomes an array of values.
 *
 * @package CoywolfSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON-LD graph builder.
 */
final class Coywolf_SEO_Schema {

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_action( 'wp_head', array( $this, 'output' ), 5 );
	}

	/**
	 * Print the JSON-LD graph for managed contexts.
	 */
	public function output() {
		// Master toggle: when Schema.org markup is turned off, emit no JSON-LD
		// at all (stored entities/settings are preserved, just not output).
		if ( ! Coywolf_SEO_Options::feature_enabled( 'schema' ) ) {
			return;
		}
		$graph = $this->build_graph();
		if ( empty( $graph ) ) {
			return;
		}
		$data  = array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $graph ),
		);
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG;
		$json  = wp_json_encode( $data, $flags );
		if ( false === $json || null === $json ) {
			// Invalid UTF-8 somewhere in the content: retry with
			// substitution rather than silently printing nothing.
			$json = wp_json_encode( $data, $flags | JSON_INVALID_UTF8_SUBSTITUTE );
		}
		if ( ! $json ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON built by wp_json_encode; HTML-escaping would corrupt the JSON-LD.
		echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
	}

	/**
	 * Build the graph for the current main query.
	 *
	 * @return array Empty when the context is not managed.
	 */
	public function build_graph() {
		$head = Coywolf_SEO::instance()->head();

		if ( is_front_page() ) {
			$webpage = array(
				'@type'    => 'WebPage',
				'@id'      => home_url( '/' ) . '#webpage',
				'url'      => home_url( '/' ),
				'name'     => Coywolf_SEO::instance()->titles()->managed_title(),
				'isPartOf' => array( '@id' => $this->website_id() ),
				'about'    => array( '@id' => $this->entity_id() ),
			);
			$desc    = $head->source_description();
			if ( '' !== $desc ) {
				$webpage['description'] = $desc;
			}
			return array_merge( array( $webpage ), $this->common_nodes() );
		}

		if ( is_home() ) {
			// The blog posts index (when a static front page is set).
			$posts_page = (int) get_option( 'page_for_posts' );
			$url        = $posts_page ? get_permalink( $posts_page ) : home_url( '/' );
			$collection = array(
				'@type'    => 'CollectionPage',
				'@id'      => $url . '#webpage',
				'url'      => $url,
				'name'     => Coywolf_SEO::instance()->titles()->managed_title(),
				'isPartOf' => array( '@id' => $this->website_id() ),
			);
			return array_merge( array( $collection ), $this->common_nodes() );
		}

		if ( is_singular( array( 'post', 'page' ) ) ) {
			return $this->singular_graph();
		}

		if ( is_category() || is_tag() ) {
			$term = get_queried_object();
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) ) {
				return array();
			}
			$collection = array(
				'@type'    => 'CollectionPage',
				'@id'      => $link . '#webpage',
				'url'      => $link,
				'name'     => $term->name,
				'isPartOf' => array( '@id' => $this->website_id() ),
			);
			$desc       = trim( wp_strip_all_tags( (string) term_description( $term ), true ) );
			if ( '' !== $desc ) {
				$collection['description'] = $desc;
			}
			return array_merge( array( $collection ), $this->common_nodes() );
		}

		return array();
	}

	/**
	 * The WebPage (typed) and optional Article (typed) nodes for a post or
	 * page, plus the author Person and the common site nodes.
	 *
	 * @return array
	 */
	private function singular_graph() {
		$post    = get_queried_object();
		$is_page = 'page' === $post->post_type;
		$meta    = Coywolf_SEO_Options::post_meta( $post->ID );
		$head    = Coywolf_SEO::instance()->head();

		$page_type = $meta['page_type'];
		if ( '' === $page_type ) {
			$page_type = (string) Coywolf_SEO_Options::get( $is_page ? 'page_page_type' : 'post_page_type' );
		}
		$article_type = $meta['article_type'];
		if ( '' === $article_type ) {
			$article_type = (string) Coywolf_SEO_Options::get( $is_page ? 'page_article_type' : 'post_article_type' );
		}

		$url       = $head->canonical_url();
		$url       = $url ? $url : get_permalink( $post );
		$published = get_the_date( 'c', $post );
		$modified  = get_the_modified_date( 'c', $post );

		$webpage = array(
			'@type'         => $page_type,
			'@id'           => $url . '#webpage',
			'url'           => $url,
			'name'          => single_post_title( '', false ),
			'isPartOf'      => array( '@id' => $this->website_id() ),
			'datePublished' => $published,
			'dateModified'  => $modified,
		);
		$desc    = $head->source_description();
		if ( '' !== $desc ) {
			$webpage['description'] = $desc;
		}

		$image = $this->featured_image_object( $post->ID );
		if ( $image ) {
			$webpage['primaryImageOfPage'] = $image;
		}

		// A Table of Contents block is a declarable accessibility feature
		// (schema.org/accessibilityFeature); it goes on the Article when one
		// exists, otherwise on the page node.
		$has_toc = has_block( 'coywolf-seo/table-of-contents', $post );
		if ( $has_toc && ( 'none' === $article_type || '' === $article_type ) ) {
			$webpage['accessibilityFeature'] = array( 'tableOfContents' );
		}

		$nodes = array( $webpage );

		if ( 'none' !== $article_type && '' !== $article_type ) {
			$article = array(
				'@type'            => $article_type,
				'@id'              => $url . '#article',
				'headline'         => wp_html_excerpt( single_post_title( '', false ), 110 ),
				'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
				'datePublished'    => $published,
				'dateModified'     => $modified,
				'publisher'        => array( '@id' => $this->entity_id() ),
			);
			if ( $image ) {
				$article['image'] = $image;
			}
			if ( '' !== $desc ) {
				$article['description'] = $desc;
			}
			if ( $has_toc ) {
				$article['accessibilityFeature'] = array( 'tableOfContents' );
			}

			// AI-grounded entities: main subjects as about, passing
			// references as mentions.
			$entities = Coywolf_SEO_AI::schema_nodes( $post->ID );
			if ( ! empty( $entities['about'] ) ) {
				$article['about'] = $entities['about'];
			}
			if ( ! empty( $entities['mentions'] ) ) {
				$article['mentions'] = $entities['mentions'];
			}

			$author_node = $this->author_node( (int) $post->post_author );
			if ( $author_node ) {
				$article['author'] = array( '@id' => $author_node['@id'] );
				$nodes[]           = $author_node;
			}

			$nodes[] = $article;
		}

		return array_merge( $nodes, $this->common_nodes() );
	}

	/**
	 * The WebSite node and the publisher entity node.
	 *
	 * @return array
	 */
	private function common_nodes() {
		$website = array(
			'@type'     => 'WebSite',
			'@id'       => $this->website_id(),
			'url'       => home_url( '/' ),
			'name'      => get_bloginfo( 'name' ),
			'publisher' => array( '@id' => $this->entity_id() ),
		);
		$tagline = get_bloginfo( 'description' );
		if ( '' !== $tagline ) {
			$website['description'] = $tagline;
		}
		return array( $website, $this->entity_node() );
	}

	/**
	 * The graph @id of the WebSite node.
	 *
	 * @return string
	 */
	private function website_id() {
		return home_url( '/' ) . '#website';
	}

	/**
	 * The graph @id of the publisher entity (Organization or Person). An
	 *
	 * @id row in the property editor overrides the default anchor, and
	 * every reference in the graph follows it.
	 *
	 * @return string
	 */
	private function entity_id() {
		$type = Coywolf_SEO_Options::get( 'entity_type' );
		if ( 'person' === $type ) {
			$custom = $this->id_from_rows( Coywolf_SEO_Options::author_rows( (int) Coywolf_SEO_Options::get( 'person_user_id' ) ) );
			return '' !== $custom ? $custom : home_url( '/' ) . '#person';
		}
		$custom = $this->id_from_rows( Coywolf_SEO_Options::get( 'org_properties' ) );
		return '' !== $custom ? $custom : home_url( '/' ) . '#organization';
	}

	/**
	 * The @id row value from a set of property rows, if any.
	 *
	 * @param mixed $rows Property rows.
	 * @return string '' when not set.
	 */
	private function id_from_rows( $rows ) {
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) && isset( $row['prop'] ) && '@id' === $row['prop'] && isset( $row['value'] ) && is_string( $row['value'] ) && '' !== $row['value'] ) {
				return $row['value'];
			}
		}
		return '';
	}

	/**
	 * The publisher entity node, shaped from Site Details.
	 *
	 * @return array
	 */
	private function entity_node() {
		if ( 'person' === Coywolf_SEO_Options::get( 'entity_type' ) ) {
			$user_id = (int) Coywolf_SEO_Options::get( 'person_user_id' );
			$node    = $this->person_from_rows( $user_id );
			if ( null !== $node ) {
				$node['@id'] = $this->entity_id();
				return $node;
			}
			// No user selected yet: publish a minimal valid entity so the
			// graph's publisher references still resolve.
			return array(
				'@type' => 'Organization',
				'@id'   => $this->entity_id(),
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			);
		}

		$rows = Coywolf_SEO_Options::get( 'org_properties' );
		$node = array(
			'@type' => 'Organization',
			'@id'   => $this->entity_id(),
		);
		$node = array_merge( $node, $this->shape_rows( is_array( $rows ) ? $rows : array(), 'Organization' ) );
		if ( empty( $node['name'] ) ) {
			$node['name'] = get_bloginfo( 'name' );
		}
		if ( empty( $node['url'] ) ) {
			$node['url'] = home_url( '/' );
		}
		return $node;
	}

	/**
	 * The Person node for an article author. Uses the Authors page data
	 * when saved; falls back to the user account basics.
	 *
	 * @param int $user_id Author user ID.
	 * @return array|null Null for an unknown user.
	 */
	private function author_node( $user_id ) {
		$node = $this->person_from_rows( $user_id );
		if ( null === $node ) {
			return null;
		}
		// An @id row from the Authors page wins; default to the archive anchor.
		if ( empty( $node['@id'] ) ) {
			$node['@id'] = get_author_posts_url( $user_id ) . '#person';
		}
		return $node;
	}

	/**
	 * Build a Person node from saved Authors rows, falling back to the
	 * user's account details.
	 *
	 * @param int $user_id User ID.
	 * @return array|null Null when the user does not exist.
	 */
	private function person_from_rows( $user_id ) {
		$user = $user_id ? get_userdata( $user_id ) : false;
		$rows = $user_id ? Coywolf_SEO_Options::author_rows( $user_id ) : null;

		$node = array( '@type' => 'Person' );
		if ( is_array( $rows ) && ! empty( $rows ) ) {
			$node = array_merge( $node, $this->shape_rows( $rows, 'Person' ) );
		}

		if ( empty( $node['name'] ) ) {
			if ( ! $user ) {
				return null;
			}
			$node['name'] = $user->display_name;
			$node['url']  = $user->user_url ? $user->user_url : get_author_posts_url( $user->ID );
		}
		return $node;
	}

	/**
	 * Shape prop/value rows into schema properties. A property repeated
	 * across rows becomes an array of values; object-valued properties get
	 * their expected structure.
	 *
	 * @param array  $rows        Ordered prop/value rows.
	 * @param string $parent_type 'Organization' or 'Person'.
	 * @return array
	 */
	private function shape_rows( array $rows, $parent_type ) {
		// Properties whose value is another typed object, keyed by the type
		// to wrap the text value in (as its name).
		$object_props = array(
			'founder'            => 'Person',
			'parentOrganization' => 'Organization',
			'subOrganization'    => 'Organization',
			'memberOf'           => 'Organization',
			'member'             => 'Person',
			'sponsor'            => 'Organization',
			'funder'             => 'Organization',
			'brand'              => 'Brand',
			'worksFor'           => 'Organization',
			'affiliation'        => 'Organization',
			'alumniOf'           => 'Organization',
			'colleague'          => 'Person',
		);

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['prop'] ) ) {
				continue;
			}
			$prop  = (string) $row['prop'];
			$value = isset( $row['value'] ) ? $row['value'] : '';
			if ( is_array( $value ) ) {
				$value = array_filter( array_map( 'strval', $value ), 'strlen' );
				if ( empty( $value ) ) {
					continue;
				}
			} else {
				$value = trim( (string) $value );
				if ( '' === $value ) {
					continue;
				}
			}

			if ( 'address' === $prop && is_array( $value ) ) {
				$shaped = array_merge( array( '@type' => 'PostalAddress' ), $value );
			} elseif ( 'contactPoint' === $prop && is_array( $value ) ) {
				$shaped = array_merge( array( '@type' => 'ContactPoint' ), $value );
			} elseif ( is_array( $value ) ) {
				continue; // Unknown structured value; never output raw arrays.
			} elseif ( 'logo' === $prop || ( 'image' === $prop && 'Organization' === $parent_type ) ) {
				$shaped = array(
					'@type' => 'ImageObject',
					'url'   => $value,
				);
			} elseif ( isset( $object_props[ $prop ] ) ) {
				$shaped = array(
					'@type' => $object_props[ $prop ],
					'name'  => $value,
				);
			} else {
				$shaped = $value;
			}

			if ( ! isset( $out[ $prop ] ) ) {
				$out[ $prop ] = $shaped;
			} elseif ( '@id' === $prop ) {
				continue; // @id is single-valued; the first row wins.
			} else {
				if ( ! is_array( $out[ $prop ] ) || isset( $out[ $prop ]['@type'] ) ) {
					$out[ $prop ] = array( $out[ $prop ] );
				}
				$out[ $prop ][] = $shaped;
			}
		}
		return $out;
	}

	/**
	 * ImageObject for a post's featured image.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	private function featured_image_object( $post_id ) {
		$thumb_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumb_id ) {
			// Fall back to the default Open Graph image.
			$thumb_id = (int) Coywolf_SEO_Options::get( 'og_image_id' );
		}
		if ( ! $thumb_id ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $thumb_id, 'full' );
		if ( ! $src ) {
			return null;
		}
		$object = array(
			'@type'  => 'ImageObject',
			'url'    => $src[0],
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
		);
		$alt    = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
		if ( '' !== (string) $alt ) {
			$object['caption'] = (string) $alt;
		}
		return $object;
	}
}
