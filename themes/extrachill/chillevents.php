<?php

add_action( 'rest_api_init', function () {
  register_rest_route( 'chill-events/v1', array(
    'methods' => 'GET',
    'callback' => 'chill_events',
  ) );
} );

$request = wp_remote_get( 'https://api.songkick.com/api/3.0/metro_areas/24585/calendar.json?apikey=yHjEm7a7ChaE5mfn' );
if( is_wp_error( $request ) ) {
  return false; // Bail early
}
$body = wp_remote_retrieve_body( $request );
$data = json_decode( $body );
if( ! empty( $data ) ) {
  
}
?>
