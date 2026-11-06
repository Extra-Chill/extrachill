<?php
/** Regression cases run inside the combined real-runtime fixture. */

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$target = array( 'link_page_id' => $created, 'owner_reference' => ec_artist_link_page_owner_reference( 20 ) );

// Simulate old metadata whose redundant styles disagree with canonical settings.
update_post_meta( $created, '_link_page_custom_css_vars', array(
	'_link_page_profile_img_shape' => 'circle',
	'--link-page-profile-img-shape' => 'rectangle',
	'--link-page-background-image-url' => '',
	'--link-page-text-color' => '#123456',
) );
$response = extrachill_artist_platform_ability_save_link_page_settings( array(
	'artist_id' => 20,
	'settings' => array( 'profile_image_shape' => 'square' ),
	'background_image_id' => 55,
) );
$assert( ! is_wp_error( $response ), 'Settings save failed.' );
$assert( $response === ec_read_link_page( $target ), 'Settings response differs from fresh read.' );

foreach ( array( 4, 1, 7 ) as $caller_blog ) {
	switch_to_blog( $caller_blog );
	$response = extrachill_artist_platform_ability_save_link_page_styles( array(
		'artist_id' => 20,
		'css_vars' => array( '--link-page-button-radius' => '12px' ),
	) );
	$assert( ! is_wp_error( $response ), 'Style save failed.' );
	$assert( $response === ec_read_link_page( $target ), 'Styles response differs from fresh read.' );
	$persistence = ec_read_link_page_persistence( $created );
	$assert( $response['css_vars'] === $persistence['css_vars'], 'Response differs from storage projection.' );
	$assert( '' !== $response['background_image_url'], 'Attachment URL is missing.' );
	$assert( $response['background_image_url'] === $response['css_vars']['--link-page-background-image-url'], 'Background URLs disagree.' );
	$assert( 'square' === $response['profile_img_shape'], 'Canonical shape was lost.' );
	foreach ( array( '_link_page_profile_img_shape', '--link-page-profile-img-shape' ) as $alias ) {
		$assert( $response['profile_img_shape'] === $response['css_vars'][ $alias ], 'Shape alias contradicts canonical setting.' );
	}
	$assert( '#123456' === $response['css_vars']['--link-page-text-color'], 'Style patch lost existing data.' );
	$assert( '12px' === $response['css_vars']['--link-page-button-radius'], 'Style patch was not saved.' );
	$assert( $caller_blog === get_current_blog_id(), 'Caller blog was not restored.' );
	restore_current_blog();
}

$response = extrachill_artist_platform_ability_save_link_page_settings( array(
	'artist_id' => 20,
	'background_image_id' => 0,
	'settings' => array( 'profile_image_shape' => 'rectangle' ),
) );
$assert( ! is_wp_error( $response ), 'Image removal failed.' );
$assert( $response === ec_read_link_page( $target ), 'Removal response differs from fresh read.' );
$assert( '' === $response['background_image_url'] && '' === $response['css_vars']['--link-page-background-image-url'], 'Removed image left a stale URL.' );
$assert( 'rectangle' === $response['profile_img_shape'] && 'rectangle' === $response['css_vars']['_link_page_profile_img_shape'], 'Shape change left a stale alias.' );
echo wp_json_encode( array( 'save_styles_response' => true ) );
