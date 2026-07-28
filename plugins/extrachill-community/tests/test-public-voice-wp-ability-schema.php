<?php
/** Runtime contract test using WordPress core's actual WP_Ability validator. */

$wordpress_root = $argv[1] ?? getenv( 'WP_ROOT' ) ?: '';
if ( '' === $wordpress_root || ! file_exists( $wordpress_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php tests/test-public-voice-wp-ability-schema.php /path/to/wordpress\n" );
	exit( 1 );
}

define( 'SHORTINIT', true );
require $wordpress_root . '/wp-load.php';

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

require_once ABSPATH . WPINC . '/rest-api.php';
require_once ABSPATH . WPINC . '/abilities-api/class-wp-ability.php';
require dirname( __DIR__ ) . '/inc/content/public-voice-contract.php';

$GLOBALS['_public_voice_registered_abilities'] = array();
$GLOBALS['_public_voice_output']               = null;

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $args ) {
		if ( in_array( $name, array( 'extrachill/community-create-topic', 'extrachill/community-create-reply', 'extrachill/community-update-topic', 'extrachill/community-update-reply' ), true ) ) {
			$GLOBALS['_public_voice_registered_abilities'][ $name ] = new WP_Ability( $name, $args );
		}
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}

function extrachill_community_ability_create_topic() { return array( 'public_voice' => $GLOBALS['_public_voice_output'] ); }
function extrachill_community_ability_create_topic_permission() { return true; }
function extrachill_community_ability_create_reply() { return array( 'public_voice' => $GLOBALS['_public_voice_output'] ); }
function extrachill_community_ability_create_reply_permission() { return true; }
function extrachill_community_ability_update_topic() { return array( 'public_voice' => $GLOBALS['_public_voice_output'] ); }
function extrachill_community_ability_update_topic_permission() { return true; }
function extrachill_community_ability_update_reply() { return array( 'public_voice' => $GLOBALS['_public_voice_output'] ); }
function extrachill_community_ability_update_reply_permission() { return true; }

require dirname( __DIR__ ) . '/inc/content/topic-reply-abilities.php';
extrachill_community_register_topic_reply_abilities();

function public_voice_ability_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$envelope = array(
	'reference'           => 'artist:101',
	'type'                => 'artist',
	'id'                  => 101,
	'name'                => 'Human Resources',
	'url'                 => 'https://artist.example/human-resources/',
	'accountable_user_id' => 7,
	'automated'           => false,
);

$create_topic = $GLOBALS['_public_voice_registered_abilities']['extrachill/community-create-topic'] ?? null;
$create_reply = $GLOBALS['_public_voice_registered_abilities']['extrachill/community-create-reply'] ?? null;
$update_topic = $GLOBALS['_public_voice_registered_abilities']['extrachill/community-update-topic'] ?? null;
$update_reply = $GLOBALS['_public_voice_registered_abilities']['extrachill/community-update-reply'] ?? null;

$valid_inputs = array(
	array( $create_topic, array( 'forum_id' => 10, 'title' => 'Topic', 'content' => 'Body', 'public_voice' => 'artist:101' ) ),
	array( $create_reply, array( 'topic_id' => 20, 'content' => 'Body', 'public_voice' => 'artist:101' ) ),
	array( $update_topic, array( 'topic_id' => 20, 'content' => 'Body', 'public_voice' => 'artist:101' ) ),
	array( $update_reply, array( 'reply_id' => 21, 'content' => 'Body', 'public_voice' => 'artist:101' ) ),
);

foreach ( $valid_inputs as list( $ability, $valid_input ) ) {
	public_voice_ability_assert( $ability instanceof WP_Ability, 'All four write contracts must register as discoverable WP_Ability instances.' );
	public_voice_ability_assert( true === $ability->validate_input( $valid_input ), 'All write abilities must accept canonical public voices.' );
	$voice_schema = $ability->get_output_schema()['properties']['public_voice'];
	public_voice_ability_assert( array( 'null', 'object' ) === array_column( $voice_schema['oneOf'], 'type' ), 'Output discoverability must declare object or null.' );
	public_voice_ability_assert( false === $voice_schema['oneOf'][1]['additionalProperties'], 'Output envelope must reject undisclosed fields.' );
	public_voice_ability_assert( array_keys( $envelope ) === $voice_schema['oneOf'][1]['required'], 'Output discoverability must declare every runtime envelope field.' );
}

public_voice_ability_assert( is_wp_error( $create_topic->validate_input( array( 'forum_id' => 10, 'title' => 'Topic', 'content' => 'Body', 'public_voice' => '' ) ) ), 'Topic create must reject an empty explicit voice.' );
public_voice_ability_assert( is_wp_error( $create_reply->validate_input( array( 'topic_id' => 20, 'content' => 'Body', 'public_voice' => '' ) ) ), 'Reply create must reject an empty explicit voice.' );
public_voice_ability_assert( true === $update_topic->validate_input( array( 'topic_id' => 20, 'content' => 'Body', 'public_voice' => '' ) ), 'Topic update must accept explicit clear through WP_Ability validation.' );
public_voice_ability_assert( true === $update_reply->validate_input( array( 'reply_id' => 21, 'content' => 'Body', 'public_voice' => '' ) ), 'Reply update must accept explicit clear through WP_Ability validation.' );
public_voice_ability_assert( is_wp_error( $update_topic->validate_input( array( 'topic_id' => 20, 'content' => 'Body', 'public_voice' => 'artist:0' ) ) ), 'Updates must reject malformed references.' );

$GLOBALS['_public_voice_output'] = null;
public_voice_ability_assert( ! is_wp_error( $create_topic->execute( array( 'forum_id' => 10, 'title' => 'Topic', 'content' => 'Body' ) ) ), 'Nullable runtime output must pass WP_Ability output validation.' );
$GLOBALS['_public_voice_output'] = $envelope;
public_voice_ability_assert( ! is_wp_error( $update_reply->execute( array( 'reply_id' => 21, 'content' => 'Body', 'public_voice' => '' ) ) ), 'Bounded runtime object output must pass WP_Ability output validation.' );

echo "PASS: WordPress WP_Ability validates public voice create, clear, and output contracts.\n";
