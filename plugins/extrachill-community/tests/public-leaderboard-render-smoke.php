<?php
/** Anonymous rendering executes the public ability without REST discovery. */
define( 'ABSPATH', __DIR__ );
function wp_get_ability( $name ) {
	assert( 'extrachill/users-leaderboard' === $name );
	return new class() {
		public function execute( $input ) {
			assert( array( 'page' => 1, 'per_page' => 25 ) === $input );
			return array( 'items' => array( array( 'display_name' => '</script><visitor>' ) ), 'pagination' => array( 'total_pages' => 2 ) );
		}
	};
}
function get_template_directory_uri() { return 'https://example.test/theme'; }
function is_wp_error( $value ) { return false; }
function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_url( $value ) { return esc_attr( $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
$attributes = array();
ob_start();
require dirname( __DIR__ ) . '/src/blocks/leaderboard/render.php';
$html = ob_get_clean();
if ( ! preg_match( '/data-initial="([^"]+)"/', $html, $matches ) ) {
	throw new RuntimeException( 'Missing initial leaderboard payload' );
}
$data = json_decode( html_entity_decode( $matches[1], ENT_QUOTES ), true );
if ( '</script><visitor>' !== $data['items'][0]['display_name'] || false !== strpos( $html, '<visitor>' ) ) {
	throw new RuntimeException( 'Invalid or unsafe initial payload' );
}
echo "PASS: anonymous leaderboard receives safely encoded initial data without discovery.\n";
