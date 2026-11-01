<?php
/**
 * Stories contract parity and public-only Festival Wire reads.
 *
 * Reuses the Blog standalone harness (WordPress stubs and schema validator).
 * Run: php tests/smoke-stories-abilities.php
 *
 * @package ExtraChillNewsWire
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertions, not HTML output.

require dirname( __DIR__, 2 ) . '/extrachill-blog/tests/stories-abilities-smoke.php';
require dirname( __DIR__ ) . '/includes/stories-abilities.php';

$blog_abilities = $stories_abilities;
ec_news_wire_register_stories_abilities();

foreach ( array( 'extrachill/list-stories', 'extrachill/get-story' ) as $name ) {
	$wire_ability = $stories_abilities[ $name ];
	stories_expect( $blog_abilities[ $name ]['output_schema'] === $wire_ability['output_schema'], "$name must have exactly the Blog schema." );
	stories_expect( 'extrachill-news-wire' === $wire_ability['category'], 'Wire owns its ability registrations.' );
	stories_expect( true === $wire_ability['meta']['show_in_rest'], 'Wire Stories must be REST-visible.' );
	stories_expect( '__return_true' === $wire_ability['permission_callback'], 'Wire Stories must be public reads.' );
}

$wire_list = $stories_abilities['extrachill/list-stories'];
$wire_get  = $stories_abilities['extrachill/get-story'];
$wire_post = new WP_Post(
	array(
		'ID'           => 201,
		'post_type'    => 'festival_wire',
		'post_title'   => 'Festival lineup &amp; more',
		'post_content' => 'Wire body',
	)
);

$stories_query_posts = array( $wire_post, $published );
$stories_posts[201]  = $wire_post;
foreach ( array( 'draft', 'pending', 'private', 'future', 'trash', 'auto-draft', 'publish' ) as $index => $hidden_status ) {
	$hidden_id                   = 210 + $index;
	$hidden                      = new WP_Post(
		array(
			'ID'            => $hidden_id,
			'post_type'     => 'festival_wire',
			'post_status'   => $hidden_status,
			'post_password' => 'publish' === $hidden_status ? 'secret' : '',
		)
	);
	$stories_posts[ $hidden_id ] = $hidden;
	$stories_query_posts[]       = $hidden;
	$hidden_error                = call_user_func( $wire_get['execute_callback'], array( 'id' => $hidden_id ) );
	stories_expect( $hidden_error instanceof WP_Error && array( 'status' => 404 ) === $hidden_error->data, "Wire get must 404 for $hidden_status or protected posts." );
}

$stories_found_posts = 1;
$wire_result         = call_user_func( $wire_list['execute_callback'], array() );
stories_validate( $wire_result, $blog_abilities['extrachill/list-stories']['output_schema'] );
stories_expect( array( 201 ) === array_column( $wire_result['items'], 'id' ), 'Wire list excludes other post types, unpublished and protected posts.' );
stories_expect( 'festival_wire' === $stories_last_query_args['post_type'], 'Wire queries its own post type.' );
stories_expect( 'publish' === $stories_last_query_args['post_status'] && false === $stories_last_query_args['has_password'], 'Wire query restricts public visibility.' );

$wire_detail = call_user_func( $wire_get['execute_callback'], array( 'id' => 201 ) );
stories_validate( $wire_detail, $blog_abilities['extrachill/get-story']['output_schema'] );
stories_expect( 'Festival lineup & more' === $wire_detail['title'], 'Wire uses the shared projection.' );
stories_expect( 'festival_wire' === $GLOBALS['stories_taxonomy_post_type'], 'Wire detail enumerates its own post type taxonomies.' );
stories_expect( false !== strpos( $wire_detail['content_html'], 'Wire body' ), 'Wire get renders content.' );
foreach ( array( 101, 999, 0 ) as $missing_id ) {
	$missing_error = call_user_func( $wire_get['execute_callback'], array( 'id' => $missing_id ) );
	stories_expect( $missing_error instanceof WP_Error && array( 'status' => 404 ) === $missing_error->data, 'Wire get rejects Blog posts and missing IDs.' );
}

print "Wire Stories abilities tests passed.\n";
