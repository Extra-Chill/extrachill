<?php
/**
 * Photo Contest domain: dedicated newsletter integration.
 *
 * Entries and votes both subscribe with the `photo_contest` context via
 * extrachill_network_subscribe(). The Sendy list is configured in the
 * Extra Chill Newsletter settings (photo_contest_list_id); there is no
 * fallback list.
 *
 * @package ExtraChillContentBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the photo_contest newsletter integration.
 *
 * @param array<string, array<string, string>> $integrations Registered integrations.
 * @return array<string, array<string, string>>
 */
function extrachill_content_blocks_photo_contest_register_newsletter_integration( $integrations ) {
	$integrations['photo_contest'] = array(
		'label'       => __( 'Photo Contest Block', 'extrachill-content-blocks' ),
		'description' => __( 'Newsletter subscription when users enter or vote in a photo contest', 'extrachill-content-blocks' ),
		'list_id_key' => 'photo_contest_list_id',
		'enable_key'  => 'enable_photo_contest',
		'plugin'      => 'extrachill-content-blocks',
	);

	return $integrations;
}
add_filter( 'newsletter_form_integrations', 'extrachill_content_blocks_photo_contest_register_newsletter_integration' );
