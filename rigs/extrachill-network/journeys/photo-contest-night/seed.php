<?php
/**
 * Seed the photo contest night journey.
 *
 * Recreates a live photo contest page on extrachill.com (resolved by domain)
 * using only the extrachill/photo-contest block: entries and voting open now,
 * a scheduled backstop close in the future, finalists display on. Three
 * earlier entrants are submitted through the product's own domain function
 * (extrachill_content_blocks_photo_contest_submit_entry) so the voter walks
 * a realistic gallery. The only substitution is the photo store seam
 * (`extrachill_content_blocks_photo_contest_store_photo`): a run-php process
 * has no HTTP upload, so wp_handle_upload's is_uploaded_file() check cannot
 * pass. The seed sideloads the generated JPEG instead. The browser entrant
 * in the journey goes through the real upload path.
 *
 * @package ExtraChillNetwork
 */

$sites = get_sites( array( 'domain' => 'extrachill.com', 'number' => 1 ) );
if ( empty( $sites ) ) {
	throw new RuntimeException( 'extrachill.com is not in the booted topology.' );
}
$blog_id = (int) $sites[0]->blog_id;

update_site_option( 'ec_turnstile_site_key', '1x00000000000000000000AA' );
update_site_option( 'ec_turnstile_secret_key', '1x0000000000000000000000000000000AA' );
delete_site_option( 'ec_photo_contest_journey_rest' );
delete_site_option( 'ec_photo_contest_journey_observations' );

switch_to_blog( $blog_id );
update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules();
wp_set_current_user( 1 );

$contest_id = 'contest-journey-night';
$attrs      = array(
	'contestId'      => $contest_id,
	'heading'        => 'Enter the Contest',
	'eventTitle'     => 'Rig Halloween Night',
	'eventDate'      => 'Sat · 7PM',
	'eventVenue'     => 'Rig Hall',
	'ticketUrl'      => 'http://extrachill.com/tickets/',
	'entriesCloseAt' => gmdate( 'Y-m-d\TH:i', time() + 3 * DAY_IN_SECONDS ),
	'votingClosesAt' => gmdate( 'Y-m-d\TH:i', time() + 3 * DAY_IN_SECONDS ),
	'showFinalists'  => true,
	'finalistCount'  => 10,
);
$content = "<!-- wp:paragraph -->\n<p>Win cash on stage. Enter a photo of your costume, share it to get votes, and the top 10 make the live finals.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:extrachill/photo-contest " . wp_json_encode( $attrs ) . ' /-->';

$existing = get_page_by_path( 'photo-contest-night' );
$postarr  = array(
	'post_title'   => 'Photo Contest Night',
	'post_name'    => 'photo-contest-night',
	'post_status'  => 'publish',
	'post_type'    => 'page',
	'post_content' => wp_slash( $content ),
);
if ( $existing ) {
	$postarr['ID'] = $existing->ID;
}
$page_id = wp_insert_post( $postarr, true );
if ( is_wp_error( $page_id ) ) {
	restore_current_blog();
	throw new RuntimeException( 'Failed to seed the contest page: ' . $page_id->get_error_message() );
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$store = static function ( $stored, $post_id, $file, $display_name ) {
	$copy = wp_tempnam( 'seed-entry.jpg' );
	copy( $file['tmp_name'], $copy );
	$id = media_handle_sideload(
		array(
			'name'     => $file['name'],
			'tmp_name' => $copy,
		),
		$post_id,
		$display_name . ' — contest entry'
	);
	return $id;
};
add_filter( 'extrachill_content_blocks_photo_contest_store_photo', $store, 10, 4 );

$seeded  = array();
$entrants = array(
	array( 'Vampire Dolly', 'rig-entrant-a@example.test', array( 150, 20, 40 ) ),
	array( 'Mothman Mike', 'rig-entrant-b@example.test', array( 30, 60, 120 ) ),
	array( 'Disco Witch', 'rig-entrant-c@example.test', array( 90, 30, 140 ) ),
);
foreach ( $entrants as $i => $entrant ) {
	$img = imagecreatetruecolor( 800, 1000 );
	imagefill( $img, 0, 0, imagecolorallocate( $img, $entrant[2][0], $entrant[2][1], $entrant[2][2] ) );
	imagestring( $img, 5, 40, 40, $entrant[0] . ' #' . $i . ' ' . wp_generate_password( 8, false ), imagecolorallocate( $img, 255, 255, 255 ) );
	$tmp = wp_tempnam( 'seed.jpg' );
	imagejpeg( $img, $tmp, 85 );
	imagedestroy( $img );

	$result = extrachill_content_blocks_photo_contest_submit_entry(
		array(
			'post_id'      => (int) $page_id,
			'contest_id'   => $contest_id,
			'email'        => $entrant[1],
			'display_name' => $entrant[0],
			'instagram'    => '',
			'honeypot'     => '',
			'file'         => array(
				'name'     => sanitize_title( $entrant[0] ) . '.jpg',
				'type'     => 'image/jpeg',
				'tmp_name' => $tmp,
				'error'    => 0,
				'size'     => filesize( $tmp ),
			),
		)
	);
	$seeded[] = is_wp_error( $result )
		? array( 'name' => $entrant[0], 'error' => $result->get_error_code() . ': ' . $result->get_error_message() )
		: array( 'name' => $entrant[0], 'entry_id' => (int) ( $result['entry']['id'] ?? 0 ), 'share_url' => $result['entry']['share_url'] ?? '' );
}
remove_filter( 'extrachill_content_blocks_photo_contest_store_photo', $store, 10 );

$fixture = array(
	'schema'          => 'extrachill-network/journey-fixture/photo-contest-night/v1',
	'blog_id'         => $blog_id,
	'page_id'         => (int) $page_id,
	'contest_id'      => $contest_id,
	'url'             => get_permalink( $page_id ),
	'seeded'          => $seeded,
	'block_available' => WP_Block_Type_Registry::get_instance()->is_registered( 'extrachill/photo-contest' ),
);

restore_current_blog();
wp_set_current_user( 0 );
update_site_option( 'ec_rig_journey_fixture_photo_contest_night', $fixture );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable fixture evidence.
printf( "EXTRACHILL_JOURNEY_FIXTURE:%s\n", base64_encode( (string) wp_json_encode( $fixture ) ) );
