<?php
/** Seed the Studio-owned social scenario against canonical Users/Socials state. */

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Bootstrap\ActivationServiceProvider;
use DataMachine\Core\Agents\AgentBundler;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Core\Steps\SystemTask\SystemTaskStep;
use DataMachineSocials\Handlers\Bluesky\BlueskyAuth;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;

if ( ! class_exists( ActivationServiceProvider::class ) || ! class_exists( InstagramAuth::class ) || ! function_exists( 'ec_grant_brand_socials' ) ) {
	throw new RuntimeException( 'The real Data Machine, Socials, and Extra Chill Users runtime is required.' );
}
if ( ! defined( 'EXTRACHILL_STUDIO_VERSION' ) || ! function_exists( 'ExtraChillStudio\\enqueue_social_publish' ) ) {
	throw new RuntimeException( 'The mounted Studio runtime is not active.' );
}

ActivationServiceProvider::ensure_all_tables();
datamachine_register_core_actions();
new SystemTaskStep();
datamachine_socials_bootstrap();
DataMachine\Engine\Tasks\TaskRegistry::reset();

$users = array(
	101 => array( 'login' => 'chris-gardner-operator', 'display' => 'Chris Gardner', 'role' => 'editor' ),
	102 => array( 'login' => 'ordinary-team-operator', 'display' => 'Ordinary Team User', 'role' => 'author' ),
	103 => array( 'login' => 'studio-operator-system-owner', 'display' => 'Studio Operator System Owner', 'role' => 'administrator' ),
);

foreach ( $users as $user_id => $fixture ) {
	$user = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		global $wpdb;
		$created = $wpdb->insert(
			$wpdb->users,
			array(
				'ID'              => $user_id,
				'user_login'      => $fixture['login'],
				'user_pass'       => wp_hash_password( wp_generate_password( 32, true, true ) ),
				'user_nicename'   => $fixture['login'],
				'user_email'      => $fixture['login'] . '@example.test',
				'user_registered' => current_time( 'mysql', true ),
				'display_name'    => $fixture['display'],
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $created ) {
			throw new RuntimeException( 'Unable to create operator user ' . $user_id . '.' );
		}
		clean_user_cache( $user_id );
	}
	$user = new WP_User( $user_id );
	$user->set_role( $fixture['role'] );
}

// Canonical team identity comes from Extra Chill Users; Studio only adds scenario grants.
$gardner = new WP_User( 101 );
$gardner->add_role( 'extra_chill_team' );
$gardner->add_cap( 'datamachine_use_tools' );
$ordinary = new WP_User( 102 );
$ordinary->add_role( 'extra_chill_team' );
$ordinary->add_cap( 'datamachine_use_tools' );
ec_grant_brand_socials( 101 );
ec_revoke_brand_socials( 102 );

if ( ! user_can( 101, 'manage_brand_socials' ) || ! user_can( 101, 'access_studio' ) || user_can( 102, 'manage_brand_socials' ) || ! user_can( 102, 'access_studio' ) ) {
	throw new RuntimeException( 'Canonical team/grant capability fixture is invalid.' );
}

$execution_owner_user_id = DirectoryManager::get_default_agent_user_id();
$agents                  = new Agents();
$owned_agents            = $agents->get_all_by_owner_id( $execution_owner_user_id );
if ( ! empty( $owned_agents ) ) {
	$agent_id   = (int) $owned_agents[0]['agent_id'];
	$agent_slug = (string) $owned_agents[0]['agent_slug'];
} else {
	$agent_slug = 'studio-social-operator-owner';
	$agent_id   = $agents->create_if_missing( $agent_slug, 'Studio Social Operator Owner', $execution_owner_user_id );
}
if ( $agent_id <= 0 ) {
	throw new RuntimeException( 'Unable to create the stable Data Machine execution owner.' );
}
update_user_meta( $execution_owner_user_id, AgentBundler::ACTIVE_AGENT_META_KEY, $agent_slug );

$instagram = new InstagramAuth();
$instagram->save_config( array( 'app_id' => 'fixture-instagram-app', 'app_secret' => 'fixture-instagram-secret' ) );
$instagram->save_account(
	array(
		'access_token'     => 'fixture-instagram-access-token',
		'token_expires_at' => time() + MONTH_IN_SECONDS,
		'user_id'          => '17841400000000000',
		'username'         => 'extrachill',
	)
);
$bluesky = new BlueskyAuth();
$bluesky->save_config( array( 'username' => 'extrachill.com', 'app_password' => 'fixture-bluesky-app-password' ) );

require_once ABSPATH . 'wp-admin/includes/image.php';
$uploads = wp_upload_dir();
$media   = array();
foreach ( array( 'crowd' => array( 25, 38, 66 ), 'stage' => array( 113, 45, 189 ) ) as $slug => $rgb ) {
	$path  = trailingslashit( $uploads['path'] ) . 'gardner-social-operator-' . $slug . '.jpg';
	$image = imagecreatetruecolor( 1200, 800 );
	$color = imagecolorallocate( $image, $rgb[0], $rgb[1], $rgb[2] );
	imagefill( $image, 0, 0, $color );
	imagejpeg( $image, $path, 88 );
	imagedestroy( $image );
	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'Gardner operator ' . $slug,
			'post_status'    => 'inherit',
		),
		$path
	);
	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		throw new RuntimeException( 'Unable to create public JPEG fixture.' );
	}
	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );
	$media[] = array(
		'id'        => (int) $attachment_id,
		'source_id' => '1:' . $attachment_id,
		'url'       => (string) wp_get_attachment_url( $attachment_id ),
	);
}

$article_id = wp_insert_post(
	array(
		'post_title'   => 'Gardner Operator Canonical Article',
		'post_excerpt' => 'A canonical Extra Chill article for stateful social operations.',
		'post_content' => '<p>A canonical Extra Chill article for stateful social operations.</p>',
		'post_status'  => 'publish',
		'post_author'  => 101,
	),
	true
);
if ( is_wp_error( $article_id ) ) {
	throw new RuntimeException( 'Unable to create canonical article: ' . $article_id->get_error_message() );
}
set_post_thumbnail( $article_id, $media[0]['id'] );

$draft_id = wp_insert_post(
	array(
		'post_title'  => 'Gardner Instagram and Bluesky Review',
		'post_status' => 'pending',
		'post_author' => 101,
	),
	true
);
if ( is_wp_error( $draft_id ) ) {
	throw new RuntimeException( 'Unable to create pending Studio social draft: ' . $draft_id->get_error_message() );
}

$original_caption = 'Initial caption Gardner changes before approval.';
$approved_caption = 'Edited and approved: local music belongs to the people who build the scene.';
update_post_meta( $draft_id, '_studio_social_platforms', array( 'instagram', 'bluesky' ) );
update_post_meta( $draft_id, '_studio_social_caption', $original_caption );
update_post_meta( $draft_id, '_studio_social_media_kind', 'image' );
update_post_meta( $draft_id, '_studio_social_images', array( $media[0] ) );
update_post_meta( $draft_id, '_studio_social_source_post_id', (int) $article_id );
update_post_meta( $draft_id, '_studio_social_source_url', (string) get_permalink( $article_id ) );

// Gardner changes both caption and media before approval; these values become frozen input.
update_post_meta( $draft_id, '_studio_social_caption', $approved_caption );
update_post_meta( $draft_id, '_studio_social_images', array( $media[1] ) );

$page_id = wp_insert_post(
	array(
		'post_title'   => 'Studio Social Operator',
		'post_content' => '<!-- wp:extrachill/studio /-->',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_author'  => 103,
	),
	true
);
if ( is_wp_error( $page_id ) ) {
	throw new RuntimeException( 'Unable to create Studio operator page: ' . $page_id->get_error_message() );
}
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', (int) $page_id );

$state = array(
	'schema'                   => 'extrachill-studio/social-operator-state/v1',
	'scenario'                 => 'studio-social-operations',
	'canonical_identity_contract' => array(
		'id'      => 'extra-chill-users/chris-gardner',
		'version' => '1.0.0',
		'commit'  => '627533b541ebdedd7107d543edfef186c07cb48e',
	),
	'gardner_user_id'          => 101,
	'ordinary_user_id'         => 102,
	'execution_owner_user_id'  => $execution_owner_user_id,
	'execution_owner_agent_id' => $agent_id,
	'article_id'               => (int) $article_id,
	'draft_id'                 => (int) $draft_id,
	'page_id'                  => (int) $page_id,
	'media'                    => $media,
	'original_caption'         => $original_caption,
	'approved_caption'         => $approved_caption,
	'approved_caption_hash'    => hash( 'sha256', $approved_caption ),
);
update_option( 'ec_studio_operator_state', $state, false );
update_option( 'ec_studio_operator_provider_ledger', array(), false );
update_option(
	'ec_studio_operator_provider_state',
	array(
		'bluesky_failures_remaining' => 1,
		'comments_mode'               => 'page',
	),
	false
);
update_option(
	'ec_studio_operator_transition_ledger',
	array(
		array( 'state' => 'pending', 'caption_hash' => hash( 'sha256', $original_caption ), 'media' => $media[0]['source_id'] ),
		array( 'state' => 'approved-edit', 'caption_hash' => hash( 'sha256', $approved_caption ), 'media' => $media[1]['source_id'] ),
	),
	false
);

echo wp_json_encode(
	array(
		'schema'                   => $state['schema'],
		'canonical_identity_contract' => $state['canonical_identity_contract'],
		'article_id'               => $article_id,
		'draft_id'                 => $draft_id,
		'media_refs'               => array_column( $media, 'source_id' ),
		'external_writes'          => false,
	),
	JSON_PRETTY_PRINT
);
