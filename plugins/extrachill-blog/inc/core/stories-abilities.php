<?php
/**
 * Public Stories abilities.
 *
 * REST-visible, read-only abilities that let core /wp-abilities/v1 clients
 * (the native app) list and read published publication posts.
 *
 * @package ExtraChillBlog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EXTRACHILL_BLOG_STORIES_DEFAULT_PER_PAGE = 10;
const EXTRACHILL_BLOG_STORIES_MAX_PER_PAGE     = 50;
const EXTRACHILL_BLOG_STORIES_EXCERPT_WORDS    = 55;

// Shares the extrachill-blog category; the callback is idempotent and WP dedupes identical hooks.
add_action( 'wp_abilities_api_categories_init', 'extrachill_blog_register_public_projection_ability_category' );
add_action( 'wp_abilities_api_init', 'extrachill_blog_register_stories_abilities' );

/**
 * Map of list-stories filter inputs to the taxonomies they query.
 *
 * @return array<string, string> Input key => taxonomy name.
 */
function extrachill_blog_stories_filter_taxonomies() {
	return array(
		'category' => 'category',
		'tag'      => 'post_tag',
		'festival' => 'festival',
		'artist'   => 'artist',
		'venue'    => 'venue',
		'location' => 'location',
	);
}

/**
 * Register the list-stories and get-story abilities.
 *
 * @return void
 */
function extrachill_blog_register_stories_abilities() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	$meta = array(
		'show_in_rest' => true,
		'annotations'  => array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		),
	);

	$list_properties = array(
		'page'     => array(
			'type'        => 'integer',
			'description' => __( 'Page number, starting at 1.', 'extrachill-blog' ),
			'default'     => 1,
			'minimum'     => 1,
		),
		'per_page' => array(
			'type'        => 'integer',
			'description' => __( 'Stories per page.', 'extrachill-blog' ),
			'default'     => EXTRACHILL_BLOG_STORIES_DEFAULT_PER_PAGE,
			'minimum'     => 1,
			'maximum'     => EXTRACHILL_BLOG_STORIES_MAX_PER_PAGE,
		),
	);
	foreach ( array_keys( extrachill_blog_stories_filter_taxonomies() ) as $filter ) {
		$list_properties[ $filter ] = array(
			'type'        => array( 'integer', 'string' ),
			'description' => sprintf(
				/* translators: %s: filter name. */
				__( 'Only stories assigned this %s term, by term ID or slug.', 'extrachill-blog' ),
				$filter
			),
			'minimum'     => 1,
			'minLength'   => 1,
			'maxLength'   => 200,
		);
	}

	wp_register_ability(
		'extrachill/list-stories',
		array(
			'label'               => __( 'List Stories', 'extrachill-blog' ),
			'description'         => __( 'List published Extra Chill stories newest-first, paginated, with optional taxonomy filters.', 'extrachill-blog' ),
			'category'            => 'extrachill-blog',
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => array(),
				'properties'           => $list_properties,
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items', 'total', 'pages', 'page', 'per_page' ),
				'properties'           => array(
					'items'    => array(
						'type'  => 'array',
						'items' => extrachill_blog_story_item_schema(),
					),
					'total'    => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'pages'    => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => EXTRACHILL_BLOG_STORIES_MAX_PER_PAGE,
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'extrachill_blog_list_stories',
			'permission_callback' => '__return_true',
			'meta'                => $meta,
		)
	);

	wp_register_ability(
		'extrachill/get-story',
		array(
			'label'               => __( 'Get Story', 'extrachill-blog' ),
			'description'         => __( 'Get one published Extra Chill story with rendered content and terms.', 'extrachill-blog' ),
			'category'            => 'extrachill-blog',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => __( 'Post ID of the story.', 'extrachill-blog' ),
						'minimum'     => 1,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => extrachill_blog_story_detail_schema(),
			'execute_callback'    => 'extrachill_blog_get_story',
			'permission_callback' => '__return_true',
			'meta'                => $meta,
		)
	);
}

/**
 * Required keys shared by list items and story detail.
 *
 * @return string[]
 */
function extrachill_blog_story_item_keys() {
	return array( 'id', 'title', 'excerpt', 'date', 'author', 'featured_image', 'url', 'site' );
}

/**
 * JSON Schema for one story summary.
 *
 * @return array<string, mixed>
 */
function extrachill_blog_story_item_schema() {
	return array(
		'type'                 => 'object',
		'required'             => extrachill_blog_story_item_keys(),
		'properties'           => array(
			'id'             => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'title'          => array( 'type' => 'string' ),
			'excerpt'        => array(
				'type'        => 'string',
				'description' => 'Plain-text excerpt.',
			),
			'date'           => array(
				'type'        => 'string',
				'format'      => 'date-time',
				'description' => 'Publish date, ISO 8601 in GMT.',
			),
			'author'         => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'name' ),
				'properties'           => array(
					'id'   => array( 'type' => 'integer' ),
					'name' => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
			'featured_image' => array(
				'type'                 => array( 'object', 'null' ),
				'required'             => array( 'url', 'width', 'height', 'alt' ),
				'properties'           => array(
					'url'    => array(
						'type'   => 'string',
						'format' => 'uri',
					),
					'width'  => array( 'type' => 'integer' ),
					'height' => array( 'type' => 'integer' ),
					'alt'    => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
			'url'            => array(
				'type'   => 'string',
				'format' => 'uri',
			),
			'site'           => array(
				'type'                 => 'object',
				'required'             => array( 'blog_id', 'host' ),
				'properties'           => array(
					'blog_id' => array( 'type' => 'integer' ),
					'host'    => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
		),
		'additionalProperties' => false,
	);
}

/**
 * JSON Schema for a full story.
 *
 * @return array<string, mixed>
 */
function extrachill_blog_story_detail_schema() {
	$schema = extrachill_blog_story_item_schema();

	$schema['required'][]                 = 'content_html';
	$schema['required'][]                 = 'terms';
	$schema['properties']['content_html'] = array(
		'type'        => 'string',
		'description' => 'Rendered post content (the_content output).',
	);
	$schema['properties']['terms']        = array(
		'type'                 => 'object',
		'description'          => 'Assigned public terms grouped by taxonomy name.',
		'additionalProperties' => array(
			'type'  => 'array',
			'items' => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'name', 'slug', 'url' ),
				'properties'           => array(
					'id'   => array( 'type' => 'integer' ),
					'name' => array( 'type' => 'string' ),
					'slug' => array( 'type' => 'string' ),
					'url'  => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
		),
	);

	return $schema;
}

/**
 * Clamp a pagination value into bounds.
 *
 * @param mixed $value   Raw value.
 * @param int   $fallback Value when missing or invalid.
 * @param int   $max     Upper bound, or 0 for none.
 * @return int
 */
function extrachill_blog_stories_clamp( $value, $fallback, $max = 0 ) {
	$value = is_numeric( $value ) ? (int) $value : $fallback;
	$value = max( 1, $value );

	return $max > 0 ? min( $max, $value ) : $value;
}

/**
 * Build the WP_Query arguments for a list-stories request.
 *
 * Only published, non-password-protected posts are ever selected.
 *
 * @param array<string, mixed>|null $input Ability input.
 * @return array<string, mixed>
 */
function extrachill_blog_stories_query_args( $input ) {
	$input = is_array( $input ) ? $input : array();

	$args = array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'has_password'        => false,
		'orderby'             => 'date',
		'order'               => 'DESC',
		'ignore_sticky_posts' => true,
		'paged'               => extrachill_blog_stories_clamp( $input['page'] ?? null, 1 ),
		'posts_per_page'      => extrachill_blog_stories_clamp( $input['per_page'] ?? null, EXTRACHILL_BLOG_STORIES_DEFAULT_PER_PAGE, EXTRACHILL_BLOG_STORIES_MAX_PER_PAGE ),
	);

	$tax_query = array();
	foreach ( extrachill_blog_stories_filter_taxonomies() as $key => $taxonomy ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
			continue;
		}

		$value = $input[ $key ];
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			$tax_query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => array( (int) $value ),
			);
			continue;
		}

		$tax_query[] = array(
			'taxonomy' => $taxonomy,
			'field'    => 'slug',
			'terms'    => array( sanitize_title( (string) $value ) ),
		);
	}

	if ( ! empty( $tax_query ) ) {
		$tax_query['relation'] = 'AND';
		$args['tax_query']     = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Filtering by taxonomy is the purpose of these inputs.
	}

	return $args;
}

/**
 * Whether a post may be exposed publicly as a story.
 *
 * @param mixed $post Candidate post.
 * @return bool
 */
function extrachill_blog_is_public_story( $post ) {
	return $post instanceof WP_Post
		&& 'post' === $post->post_type
		&& 'publish' === $post->post_status
		&& '' === (string) $post->post_password;
}

/**
 * Decode stored entities in a display string.
 *
 * @param string $value Raw value.
 * @return string
 */
function extrachill_blog_stories_decode( $value ) {
	return trim( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) );
}

/**
 * Plain-text excerpt for a story.
 *
 * @param WP_Post $post Story.
 * @return string
 */
function extrachill_blog_story_excerpt( $post ) {
	if ( '' !== trim( (string) $post->post_excerpt ) ) {
		$text = wp_strip_all_tags( $post->post_excerpt );
	} else {
		$content = strip_shortcodes( excerpt_remove_blocks( (string) $post->post_content ) );
		$text    = wp_trim_words( $content, EXTRACHILL_BLOG_STORIES_EXCERPT_WORDS, '…' );
	}

	return extrachill_blog_stories_decode( preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Featured image payload for a story.
 *
 * @param WP_Post $post Story.
 * @return array<string, mixed>|null
 */
function extrachill_blog_story_featured_image( $post ) {
	$thumbnail_id = (int) get_post_thumbnail_id( $post );
	if ( $thumbnail_id <= 0 ) {
		return null;
	}

	$image = wp_get_attachment_image_src( $thumbnail_id, 'large' );
	if ( ! is_array( $image ) || empty( $image[0] ) ) {
		return null;
	}

	return array(
		'url'    => (string) $image[0],
		'width'  => (int) $image[1],
		'height' => (int) $image[2],
		'alt'    => extrachill_blog_stories_decode( get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) ),
	);
}

/**
 * Summary payload for one story.
 *
 * @param WP_Post $post Story.
 * @return array<string, mixed>
 */
function extrachill_blog_story_item( $post ) {
	$author_id = (int) $post->post_author;
	$host      = wp_parse_url( home_url(), PHP_URL_HOST );
	$timestamp = strtotime( $post->post_date_gmt . ' UTC' );
	if ( false === $timestamp ) {
		$timestamp = 0;
	}

	return array(
		'id'             => (int) $post->ID,
		'title'          => extrachill_blog_stories_decode( get_the_title( $post ) ),
		'excerpt'        => extrachill_blog_story_excerpt( $post ),
		'date'           => gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ),
		'author'         => array(
			'id'   => $author_id,
			'name' => extrachill_blog_stories_decode( get_the_author_meta( 'display_name', $author_id ) ),
		),
		'featured_image' => extrachill_blog_story_featured_image( $post ),
		'url'            => (string) get_permalink( $post ),
		'site'           => array(
			'blog_id' => (int) get_current_blog_id(),
			'host'    => is_string( $host ) ? $host : '',
		),
	);
}

/**
 * Assigned public terms grouped by taxonomy.
 *
 * @param WP_Post $post Story.
 * @return array<string, array<int, array<string, mixed>>>|stdClass
 */
function extrachill_blog_story_terms( $post ) {
	$grouped = array();

	foreach ( get_object_taxonomies( 'post', 'objects' ) as $taxonomy ) {
		if ( empty( $taxonomy->public ) || 'post_format' === $taxonomy->name ) {
			continue;
		}

		$terms = get_the_terms( $post, $taxonomy->name );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			continue;
		}

		foreach ( $terms as $term ) {
			$url = get_term_link( $term, $taxonomy->name );

			$grouped[ $taxonomy->name ][] = array(
				'id'   => (int) $term->term_id,
				'name' => extrachill_blog_stories_decode( $term->name ),
				'slug' => (string) $term->slug,
				'url'  => is_string( $url ) ? $url : '',
			);
		}
	}

	// An empty group must still serialize as a JSON object.
	return empty( $grouped ) ? new stdClass() : $grouped;
}

/**
 * Execute extrachill/list-stories.
 *
 * @param array<string, mixed>|null $input Ability input.
 * @return array<string, mixed>
 */
function extrachill_blog_list_stories( $input = array() ) {
	$args  = extrachill_blog_stories_query_args( $input );
	$query = new WP_Query( $args );

	$items = array();
	foreach ( $query->posts as $post ) {
		// Defense in depth: never leak anything the query should not have matched.
		if ( $post instanceof WP_Post && extrachill_blog_is_public_story( $post ) ) {
			$items[] = extrachill_blog_story_item( $post );
		}
	}

	return array(
		'items'    => $items,
		'total'    => (int) $query->found_posts,
		'pages'    => (int) $query->max_num_pages,
		'page'     => (int) $args['paged'],
		'per_page' => (int) $args['posts_per_page'],
	);
}

/**
 * Execute extrachill/get-story.
 *
 * @param array<string, mixed>|null $input Ability input.
 * @return array<string, mixed>|WP_Error
 */
function extrachill_blog_get_story( $input ) {
	$id   = is_array( $input ) && isset( $input['id'] ) ? (int) $input['id'] : 0;
	$post = $id > 0 ? get_post( $id ) : null;

	if ( ! extrachill_blog_is_public_story( $post ) ) {
		return new WP_Error(
			'extrachill_blog_story_not_found',
			__( 'Story not found.', 'extrachill-blog' ),
			array( 'status' => 404 )
		);
	}

	$item = extrachill_blog_story_item( $post );

	// Render as the theme would: the_content filters expect global post data.
	$previous_post   = $GLOBALS['post'] ?? null;
	$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Temporarily set for the_content, restored below.
	setup_postdata( $post );

	$content = apply_filters( 'the_content', $post->post_content );
	$content = str_replace( ']]>', ']]&gt;', (string) $content );

	$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the previous global post.
	if ( $previous_post instanceof WP_Post ) {
		setup_postdata( $previous_post );
	}

	$item['content_html'] = $content;
	$item['terms']        = extrachill_blog_story_terms( $post );

	return $item;
}
