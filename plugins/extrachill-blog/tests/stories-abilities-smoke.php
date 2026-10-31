<?php
/**
 * Focused coverage for the public Stories abilities (list-stories, get-story).
 *
 * Standalone: stubs the WordPress primitives the abilities use, loads the real
 * inc/core/stories-abilities.php, and asserts the contract.
 *
 * Run: php tests/stories-abilities-smoke.php
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed -- Standalone harness defines its WordPress class stubs inline.
// phpcs:disable WordPress.NamingConventions.ValidFunctionName.FunctionDoubleUnderscore, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stubs mirror WordPress core function signatures.
// phpcs:disable WordPress.WP.AlternativeFunctions.strip_tags_strip_tags, WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded; stubs use native PHP.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion messages, never rendered as HTML.

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public $code;
	public $message;
	public $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
}

class WP_Post {
	public $ID;
	public $post_type     = 'post';
	public $post_status   = 'publish';
	public $post_password = '';
	public $post_title    = '';
	public $post_excerpt  = '';
	public $post_content  = '';
	public $post_author   = 7;
	public $post_date_gmt = '2026-01-02 03:04:05';

	public function __construct( array $fields ) {
		foreach ( $fields as $key => $value ) {
			$this->$key = $value;
		}
	}
}

class WP_Term {
	public $term_id;
	public $name;
	public $slug;

	public function __construct( $term_id, $name, $slug ) {
		$this->term_id = $term_id;
		$this->name    = $name;
		$this->slug    = $slug;
	}
}

/**
 * Query stub. Returns the configured rows verbatim (simulating a filter that
 * widened the query) so the ability's own publish/password guard is exercised.
 */
class WP_Query {
	public $posts;
	public $found_posts;
	public $max_num_pages;

	public function __construct( $args ) {
		$GLOBALS['stories_last_query_args'] = $args;
		$this->posts                        = $GLOBALS['stories_query_posts'];
		$this->found_posts                  = $GLOBALS['stories_found_posts'];
		$this->max_num_pages                = (int) ceil( $this->found_posts / $args['posts_per_page'] );
	}
}

$stories_abilities       = array();
$stories_category        = null;
$stories_last_query_args = null;
$stories_query_posts     = array();
$stories_found_posts     = 0;
$stories_posts           = array();
$stories_thumbnails      = array( 101 => 900 );
$stories_terms           = array();
$stories_content_post_id = null;

function add_action() {}
function __( $text ) { return $text; }
function __return_true() { return true; }
function wp_has_ability_category() { return null !== $GLOBALS['stories_category']; }
function wp_register_ability_category( $name, $args ) {
	$GLOBALS['stories_category'] = compact( 'name', 'args' );
}
function wp_register_ability( $name, $args ) {
	$GLOBALS['stories_abilities'][ $name ] = $args;
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_title( $value ) { return strtolower( trim( preg_replace( '/[^A-Za-z0-9]+/', '-', $value ), '-' ) ); }
function wp_strip_all_tags( $text ) { return trim( strip_tags( $text ) ); }
function strip_shortcodes( $text ) { return preg_replace( '/\[[^\]]+\]/', '', $text ); }
function excerpt_remove_blocks( $text ) { return preg_replace( '/<!--.*?-->/s', '', $text ); }
function wp_trim_words( $text, $num_words, $more ) {
	$words = preg_split( '/\s+/', trim( strip_tags( $text ) ) );
	return count( $words ) > $num_words ? implode( ' ', array_slice( $words, 0, $num_words ) ) . $more : implode( ' ', $words );
}
function get_post_thumbnail_id( $post ) { return $GLOBALS['stories_thumbnails'][ $post->ID ] ?? 0; }
function wp_get_attachment_image_src( $id, $size ) {
	return 900 === $id ? array( 'https://extrachill.com/wp-content/uploads/cover-1024x683.jpg', 1024, 683, true ) : false;
}
function get_post_meta( $id, $key ) { return 900 === $id && '_wp_attachment_image_alt' === $key ? 'Band &amp; crowd' : ''; }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function home_url() { return 'https://extrachill.com'; }
function get_the_title( $post ) { return $post->post_title; }
function get_the_author_meta( $field, $id ) { return 7 === $id ? 'Chris &quot;Chubes&quot; Huber' : ''; }
function get_permalink( $post ) { return 'https://extrachill.com/story-' . $post->ID . '/'; }
function get_current_blog_id() { return 1; }
function get_post( $id ) { return $GLOBALS['stories_posts'][ $id ] ?? null; }
function setup_postdata( $post ) { $GLOBALS['stories_content_post_id'] = $post->ID; }
function apply_filters( $hook, $value ) {
	return 'the_content' === $hook ? '<p>' . $value . ' (post ' . $GLOBALS['stories_content_post_id'] . ')</p>]]>' : $value;
}
function get_object_taxonomies() {
	return array(
		(object) array(
			'name'   => 'category',
			'public' => true,
		),
		(object) array(
			'name'   => 'post_format',
			'public' => true,
		),
		(object) array(
			'name'   => 'festival',
			'public' => true,
		),
		(object) array(
			'name'   => 'author',
			'public' => false,
		),
		(object) array(
			'name'   => 'artist',
			'public' => true,
		),
	);
}
function get_the_terms( $post, $taxonomy ) { return $GLOBALS['stories_terms'][ $taxonomy ] ?? false; }
function get_term_link( $term, $taxonomy ) { return 'https://extrachill.com/' . $taxonomy . '/' . $term->slug . '/'; }

require dirname( __DIR__ ) . '/inc/core/stories-abilities.php';

function stories_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Minimal JSON Schema conformance check for the subset the abilities use.
 */
function stories_validate( $value, $schema, $path = '$' ) {
	$types = (array) ( $schema['type'] ?? array() );
	if ( null === $value ) {
		stories_expect( in_array( 'null', $types, true ), "$path must not be null." );
		return;
	}
	if ( in_array( 'object', $types, true ) && ( is_array( $value ) || $value instanceof stdClass ) && ( $value instanceof stdClass || array() === $value || array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ) {
		$value = (array) $value;
		foreach ( $schema['required'] ?? array() as $key ) {
			stories_expect( array_key_exists( $key, $value ), "$path.$key is required." );
		}
		foreach ( $value as $key => $child ) {
			if ( isset( $schema['properties'][ $key ] ) ) {
				stories_validate( $child, $schema['properties'][ $key ], "$path.$key" );
			} elseif ( isset( $schema['additionalProperties'] ) && is_array( $schema['additionalProperties'] ) ) {
				stories_validate( $child, $schema['additionalProperties'], "$path.$key" );
			} else {
				stories_expect( false !== ( $schema['additionalProperties'] ?? true ), "$path.$key is not allowed." );
			}
		}
		return;
	}
	if ( in_array( 'array', $types, true ) && is_array( $value ) ) {
		foreach ( $value as $index => $child ) {
			stories_validate( $child, $schema['items'], "$path[$index]" );
		}
		return;
	}
	if ( in_array( 'integer', $types, true ) && is_int( $value ) ) {
		stories_expect( ! isset( $schema['minimum'] ) || $value >= $schema['minimum'], "$path below minimum." );
		stories_expect( ! isset( $schema['maximum'] ) || $value <= $schema['maximum'], "$path above maximum." );
		return;
	}
	if ( in_array( 'string', $types, true ) && is_string( $value ) ) {
		if ( 'uri' === ( $schema['format'] ?? '' ) ) {
			stories_expect( false !== filter_var( $value, FILTER_VALIDATE_URL ), "$path must be a URI." );
		}
		if ( 'date-time' === ( $schema['format'] ?? '' ) ) {
			stories_expect( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value ), "$path must be an ISO 8601 GMT date." );
		}
		return;
	}
	throw new RuntimeException( "$path has the wrong type." );
}

// Registration contract.
extrachill_blog_register_stories_abilities();

foreach ( array( 'extrachill/list-stories', 'extrachill/get-story' ) as $name ) {
	stories_expect( isset( $stories_abilities[ $name ] ), "$name must be registered." );
	$ability = $stories_abilities[ $name ];
	stories_expect( 'extrachill-blog' === $ability['category'], "$name must use the Blog category." );
	stories_expect( '__return_true' === $ability['permission_callback'], "$name must be publicly readable." );
	stories_expect( true === $ability['meta']['show_in_rest'], "$name must be visible to /wp-abilities/v1." );
	stories_expect( true === $ability['meta']['annotations']['readonly'], "$name must be read-only." );
	stories_expect( false === $ability['input_schema']['additionalProperties'], "$name input must reject unknown fields." );
	stories_expect( is_callable( $ability['execute_callback'] ), "$name execute callback must exist." );
}

$list_ability = $stories_abilities['extrachill/list-stories'];
$list_input   = $list_ability['input_schema'];
stories_expect( array() === $list_input['default'], 'list-stories must run with no input.' );
stories_expect( 50 === $list_input['properties']['per_page']['maximum'], 'per_page must be capped at 50.' );
stories_expect( 1 === $list_input['properties']['per_page']['minimum'] && 1 === $list_input['properties']['page']['minimum'], 'Pagination minimums must be 1.' );
foreach ( array( 'category', 'tag', 'festival', 'artist', 'venue', 'location' ) as $filter ) {
	stories_expect( array( 'integer', 'string' ) === $list_input['properties'][ $filter ]['type'], "$filter must accept a term ID or slug." );
}
$item_keys = array( 'id', 'title', 'excerpt', 'date', 'author', 'featured_image', 'url', 'site' );
stories_expect( $item_keys === $list_ability['output_schema']['properties']['items']['items']['required'], 'List items must require the full story summary.' );
stories_expect( array( 'items', 'total', 'pages', 'page', 'per_page' ) === $list_ability['output_schema']['required'], 'List envelope must be exact.' );
$get_ability = $stories_abilities['extrachill/get-story'];
stories_expect( array( 'id' ) === $get_ability['input_schema']['required'], 'get-story must require an id.' );
stories_expect( array_merge( $item_keys, array( 'content_html', 'terms' ) ) === $get_ability['output_schema']['required'], 'get-story must add content_html and terms.' );

// Query args: only published, non-password posts, newest first.
$args = extrachill_blog_stories_query_args( array() );
stories_expect( 'post' === $args['post_type'], 'Only posts are listed.' );
stories_expect( 'publish' === $args['post_status'], 'Only published posts are listed.' );
stories_expect( false === $args['has_password'], 'Password-protected posts are excluded at query time.' );
stories_expect( 'date' === $args['orderby'] && 'DESC' === $args['order'] && true === $args['ignore_sticky_posts'], 'Stories are strictly newest-first.' );
stories_expect( 1 === $args['paged'] && 10 === $args['posts_per_page'], 'Defaults are page 1, 10 per page.' );
stories_expect( ! isset( $args['tax_query'] ), 'No filters means no tax_query.' );

// Pagination clamping.
$clamped = extrachill_blog_stories_query_args( array(
	'page'     => 0,
	'per_page' => 500,
) );
stories_expect( 1 === $clamped['paged'] && 50 === $clamped['posts_per_page'], 'Out-of-range pagination is clamped to [1, 50].' );
$clamped = extrachill_blog_stories_query_args( array(
	'page'     => -3,
	'per_page' => -1,
) );
stories_expect( 1 === $clamped['paged'] && 1 === $clamped['posts_per_page'], 'Negative pagination is clamped to 1 (never -1 = unbounded).' );
$clamped = extrachill_blog_stories_query_args( array(
	'page'     => '4',
	'per_page' => 'lots',
) );
stories_expect( 4 === $clamped['paged'] && 10 === $clamped['posts_per_page'], 'Numeric strings are honored, garbage falls back to defaults.' );

// Filters map to tax_query.
$filtered       = extrachill_blog_stories_query_args(
	array(
		'category' => 'Live Reviews',
		'tag'      => 12,
		'festival' => 'bonnaroo',
		'artist'   => '345',
		'venue'    => 'the-pour-house',
		'location' => 'charleston',
	)
);
$tax_query_args = $filtered['tax_query'];
stories_expect( 'AND' === $tax_query_args['relation'], 'Multiple filters must all apply.' );
unset( $tax_query_args['relation'] );
stories_expect(
	array(
		array(
			'taxonomy' => 'category',
			'field'    => 'slug',
			'terms'    => array( 'live-reviews' ),
		),
		array(
			'taxonomy' => 'post_tag',
			'field'    => 'term_id',
			'terms'    => array( 12 ),
		),
		array(
			'taxonomy' => 'festival',
			'field'    => 'slug',
			'terms'    => array( 'bonnaroo' ),
		),
		array(
			'taxonomy' => 'artist',
			'field'    => 'term_id',
			'terms'    => array( 345 ),
		),
		array(
			'taxonomy' => 'venue',
			'field'    => 'slug',
			'terms'    => array( 'the-pour-house' ),
		),
		array(
			'taxonomy' => 'location',
			'field'    => 'slug',
			'terms'    => array( 'charleston' ),
		),
	) === array_values( $tax_query_args ),
	'Filters must map to the right taxonomy with id or slug fields.'
);
$single = extrachill_blog_stories_query_args( array( 'festival' => '' ) );
stories_expect( ! isset( $single['tax_query'] ), 'Empty filters are ignored.' );

// list-stories execution: drafts, private, password-protected, and non-posts never leak.
$published           = new WP_Post(
	array(
		'ID'           => 101,
		'post_title'   => 'Live at the Pour House &amp; More',
		'post_excerpt' => '<strong>Hand-written</strong> &amp; short.',
	)
);
$auto                = new WP_Post(
	array(
		'ID'           => 102,
		'post_title'   => 'Auto excerpt',
		'post_content' => '<!-- wp:paragraph --><p>' . str_repeat( 'word ', 60 ) . '[gallery]</p><!-- /wp:paragraph -->',
	)
);
$stories_query_posts = array(
	$published,
	new WP_Post( array(
		'ID'          => 103,
		'post_status' => 'draft',
	) ),
	new WP_Post( array(
		'ID'          => 104,
		'post_status' => 'private',
	) ),
	new WP_Post( array(
		'ID'            => 105,
		'post_password' => 'secret',
	) ),
	new WP_Post( array(
		'ID'        => 106,
		'post_type' => 'page',
	) ),
	$auto,
);
$stories_found_posts = 23;

$list = extrachill_blog_list_stories( array(
	'page'     => 2,
	'per_page' => 10,
) );
stories_validate( $list, $list_ability['output_schema'] );
stories_expect( array( 101, 102 ) === array_column( $list['items'], 'id' ), 'Only published, unprotected posts are returned.' );
stories_expect( 23 === $list['total'] && 3 === $list['pages'] && 2 === $list['page'] && 10 === $list['per_page'], 'Pagination totals must be reported.' );
stories_expect( 'publish' === $stories_last_query_args['post_status'], 'The executed query must be publish-only.' );

$item = $list['items'][0];
stories_expect( array_keys( $item ) === $item_keys, 'Items must have exactly the documented keys in order.' );
stories_expect( 'Live at the Pour House & More' === $item['title'], 'Titles are entity-decoded.' );
stories_expect( 'Hand-written & short.' === $item['excerpt'], 'Excerpts are plain text.' );
stories_expect( '2026-01-02T03:04:05Z' === $item['date'], 'Dates are ISO 8601 GMT.' );
stories_expect( array(
	'id'   => 7,
	'name' => 'Chris "Chubes" Huber',
) === $item['author'], 'Author is id + decoded display name.' );
stories_expect(
	array(
		'url'    => 'https://extrachill.com/wp-content/uploads/cover-1024x683.jpg',
		'width'  => 1024,
		'height' => 683,
		'alt'    => 'Band & crowd',
	) === $item['featured_image'],
	'Featured image exposes url, width, height, alt.'
);
stories_expect( 'https://extrachill.com/story-101/' === $item['url'], 'URL is the canonical permalink.' );
stories_expect( array(
	'blog_id' => 1,
	'host'    => 'extrachill.com',
) === $item['site'], 'Site identifies the blog and host.' );

$auto_item = $list['items'][1];
stories_expect( null === $auto_item['featured_image'], 'Missing featured image is null.' );
stories_expect( false === strpos( $auto_item['excerpt'], '<' ) && false === strpos( $auto_item['excerpt'], '[gallery]' ), 'Generated excerpts strip tags, blocks, and shortcodes.' );
stories_expect( 55 === count( explode( ' ', rtrim( $auto_item['excerpt'], '…' ) ) ), 'Generated excerpts are trimmed to 55 words.' );

$stories_query_posts = array();
$stories_found_posts = 0;
$empty               = extrachill_blog_list_stories( null );
stories_validate( $empty, $list_ability['output_schema'] );
stories_expect( array() === $empty['items'] && 0 === $empty['total'] && 0 === $empty['pages'], 'Empty results are well-formed.' );

// get-story.
$stories_posts = array(
	101 => $published,
	103 => new WP_Post( array(
		'ID'          => 103,
		'post_status' => 'draft',
	) ),
	104 => new WP_Post( array(
		'ID'          => 104,
		'post_status' => 'private',
	) ),
	105 => new WP_Post( array(
		'ID'            => 105,
		'post_password' => 'secret',
	) ),
	106 => new WP_Post( array(
		'ID'        => 106,
		'post_type' => 'page',
	) ),
	107 => new WP_Post( array(
		'ID'          => 107,
		'post_status' => 'future',
	) ),
);
foreach ( array( 103, 104, 105, 106, 107, 999, 0 ) as $missing_id ) {
	$missing_error = extrachill_blog_get_story( array( 'id' => $missing_id ) );
	stories_expect( is_wp_error( $missing_error ), "get-story must refuse post $missing_id." );
	stories_expect( 'extrachill_blog_story_not_found' === $missing_error->code && array( 'status' => 404 ) === $missing_error->data, "get-story must 404 for post $missing_id." );
}
stories_expect( is_wp_error( extrachill_blog_get_story( null ) ), 'get-story must 404 without input.' );

$previous                = new WP_Post( array( 'ID' => 55 ) );
$GLOBALS['post']         = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seeds the global post to prove it is restored.
$published->post_content = 'Body copy';
$stories_terms           = array(
	'category'    => array( new WP_Term( 3, 'Live Music &amp; Reviews', 'live-music' ) ),
	'post_format' => array( new WP_Term( 9, 'Gallery', 'post-format-gallery' ) ),
	'festival'    => array( new WP_Term( 4, 'Bonnaroo', 'bonnaroo' ), new WP_Term( 5, 'Moonrise', 'moonrise' ) ),
	'author'      => array( new WP_Term( 6, 'Guest', 'cap-guest' ) ),
);
$story                   = extrachill_blog_get_story( array( 'id' => 101 ) );
stories_expect( ! is_wp_error( $story ), 'Published stories resolve.' );
stories_validate( $story, $get_ability['output_schema'] );
stories_expect( array_merge( $item_keys, array( 'content_html', 'terms' ) ) === array_keys( $story ), 'Story has the summary keys plus content_html and terms.' );
stories_expect( '<p>Body copy (post 101)</p>]]&gt;' === $story['content_html'], 'content_html is the_content output rendered for this post.' );
stories_expect( $previous === $GLOBALS['post'] && 55 === $stories_content_post_id, 'Global post data is restored after rendering.' );
stories_expect( array( 'category', 'festival' ) === array_keys( $story['terms'] ), 'Terms are grouped by public taxonomy, skipping post_format and private taxonomies.' );
stories_expect(
	array(
		'id'   => 3,
		'name' => 'Live Music & Reviews',
		'slug' => 'live-music',
		'url'  => 'https://extrachill.com/category/live-music/',
	) === $story['terms']['category'][0],
	'Terms expose id, decoded name, slug, url.'
);
stories_expect( array( 'bonnaroo', 'moonrise' ) === array_column( $story['terms']['festival'], 'slug' ), 'All terms in a taxonomy are returned.' );

$stories_terms = array();
$bare          = extrachill_blog_get_story( array( 'id' => 101 ) );
stories_expect( $bare['terms'] instanceof stdClass && '{}' === json_encode( $bare['terms'] ), 'No terms serializes as a JSON object.' );
stories_validate( $bare, $get_ability['output_schema'] );

print "Stories abilities tests passed.\n";
