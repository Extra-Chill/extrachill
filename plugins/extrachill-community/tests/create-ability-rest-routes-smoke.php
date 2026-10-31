<?php
/** Actual core REST route coverage for Community create abilities. */

namespace AgentsAPI\AI {
	class WP_Agent_Execution_Principal {
		public $acting_user_id;
		public $effective_agent_id;
		public $capability_ceiling;

		public function __construct( $acting_user_id, $effective_agent_id = '__wordpress_user__', $capability_ceiling = null ) {
			$this->acting_user_id     = $acting_user_id;
			$this->effective_agent_id = $effective_agent_id;
			$this->capability_ceiling = $capability_ceiling;
		}
	}
}

namespace {
	// Runs in two contexts: standalone with an explicit WordPress root, and
	// under the host-smoke backend, which requires this file with WordPress
	// already loaded. Only bootstrap WordPress when it is not present yet.
	if ( ! defined( 'ABSPATH' ) ) {
		$wordpress_root = $argv[1] ?? getenv( 'WP_ROOT' ) ?: '';
		if ( '' === $wordpress_root || ! file_exists( $wordpress_root . '/wp-load.php' ) ) {
			fwrite( STDERR, "Usage: php tests/create-ability-rest-routes-smoke.php /path/to/wordpress\n" );
			exit( 1 );
		}

		define( 'SHORTINIT', true );
		require $wordpress_root . '/wp-load.php';
	}

	if ( ! function_exists( '__' ) ) {
		function __( $text ) { return $text; }
	}
	if ( ! function_exists( 'is_user_logged_in' ) ) {
		function is_user_logged_in() { return get_current_user_id() > 0; }
	}
	if ( ! function_exists( 'filter_block_content' ) ) {
		function filter_block_content( $text ) { return $text; }
	}

	require_once ABSPATH . WPINC . '/rest-api.php';
	require_once ABSPATH . WPINC . '/kses.php';
	require_once ABSPATH . WPINC . '/class-wp-http-response.php';
	require_once ABSPATH . WPINC . '/rest-api/class-wp-rest-request.php';
	require_once ABSPATH . WPINC . '/rest-api/class-wp-rest-response.php';
	require_once ABSPATH . WPINC . '/rest-api/class-wp-rest-server.php';
	require_once ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-controller.php';
	require_once ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-abilities-v1-run-controller.php';
	require_once ABSPATH . WPINC . '/abilities-api/class-wp-ability.php';

	define( 'EXTRACHILL_COMMUNITY_PUBLIC_VOICE_META', '_extrachill_public_voice' );
	define( 'EXTRACHILL_COMMUNITY_AUTOMATED_META', '_extrachill_automated_agent' );

	class WP_Agent_Access {
		public static function get_current_principal() {
			return $GLOBALS['_rest_voice_principal'];
		}
	}
	if ( ! class_exists( 'WP_Post' ) ) {
		class WP_Post {
			public function __construct( array $data ) {
				foreach ( $data as $key => $value ) {
					$this->$key = $value;
				}
			}
		}
	}

	$GLOBALS['_rest_voice_abilities']    = array();
	$GLOBALS['_rest_voice_actions']      = array();
	$GLOBALS['_rest_voice_caps']         = array();
	$GLOBALS['_rest_voice_current_user'] = 0;
	$GLOBALS['_rest_voice_inserted']     = array();
	$GLOBALS['_rest_voice_meta']         = array();
	$GLOBALS['_rest_voice_posts']        = array();
	$GLOBALS['_rest_voice_principal']    = null;

	function wp_register_ability( $name, $args ) {
		if ( in_array( $name, array( 'extrachill/community-create-topic', 'extrachill/community-create-reply', 'extrachill/community-update-topic', 'extrachill/community-update-reply' ), true ) ) {
			$GLOBALS['_rest_voice_abilities'][ $name ] = new WP_Ability( $name, $args );
		}
	}

	function wp_get_ability( $name ) {
		return $GLOBALS['_rest_voice_abilities'][ $name ] ?? null;
	}

	function get_current_user_id() { return $GLOBALS['_rest_voice_current_user']; }
	function user_can( $user_id, $capability ) { return ! empty( $GLOBALS['_rest_voice_caps'][ $user_id ][ $capability ] ); }
	function get_userdata( $user_id ) { return in_array( (int) $user_id, array( 7, 8 ), true ) ? (object) array( 'ID' => (int) $user_id, 'display_name' => 'User ' . (int) $user_id ) : false; }
	function ec_get_blog_id() { return 1; }
	function ec_get_artists_for_user( $user_id ) { return 7 === (int) $user_id ? array( 101 ) : array(); }
	function ec_user_can( $capability, $context = array() ) { return 'manage_artist' === $capability && 7 === (int) ( $context['user_id'] ?? 0 ) && 101 === (int) ( $context['artist_id'] ?? 0 ); }

	function get_post( $post_id ) { return isset( $GLOBALS['_rest_voice_posts'][ $post_id ] ) ? clone $GLOBALS['_rest_voice_posts'][ $post_id ] : null; }
	function get_post_field( $field, $post_id ) { return $GLOBALS['_rest_voice_posts'][ $post_id ]->$field ?? ''; }
	function get_post_meta( $post_id, $key ) { return $GLOBALS['_rest_voice_meta'][ $post_id ][ $key ] ?? ''; }
	function update_post_meta( $post_id, $key, $value ) { $GLOBALS['_rest_voice_meta'][ $post_id ][ $key ] = $value; return true; }
	function delete_post_meta( $post_id, $key ) { unset( $GLOBALS['_rest_voice_meta'][ $post_id ][ $key ] ); return true; }
	function get_permalink( $post ) { $id = is_object( $post ) ? $post->ID : $post; return 101 === (int) $id ? 'https://artist.example/artist/101' : 'https://community.example/post/' . (int) $id; }
	function get_the_post_thumbnail_url() { return 'https://artist.example/artist/101.jpg'; }
	function get_author_posts_url( $user_id ) { return 'https://community.example/author/' . (int) $user_id; }

	function bbp_get_forum_post_type() { return 'forum'; }
	function bbp_get_topic_post_type() { return 'topic'; }
	function bbp_get_reply_post_type() { return 'reply'; }
	function bbp_get_public_status_id() { return 'publish'; }
	function bbp_get_topic_forum_id() { return 10; }
	function bbp_get_reply_to() { return 0; }
	function bbp_get_topic_permalink( $topic_id ) { return 'https://community.example/topic/' . (int) $topic_id; }
	function bbp_get_reply_url( $reply_id ) { return 'https://community.example/reply/' . (int) $reply_id; }
	function bbp_insert_topic( $data ) {
		$GLOBALS['_rest_voice_inserted'][] = array( 'type' => 'topic', 'data' => $data );
		$GLOBALS['_rest_voice_posts'][30] = new WP_Post( array_merge( array( 'ID' => 30, 'post_status' => 'publish' ), $data ) );
		return 30;
	}
	function bbp_insert_reply( $data ) {
		$GLOBALS['_rest_voice_inserted'][] = array( 'type' => 'reply', 'data' => $data );
		$GLOBALS['_rest_voice_posts'][40] = new WP_Post( array_merge( array( 'ID' => 40, 'post_status' => 'publish' ), $data ) );
		return 40;
	}
	function wp_update_post() { return new WP_Error( 'not_used' ); }
	function bbp_past_edit_lock() { return false; }
	function extrachill_community_maybe_convert_markdown( $content ) { return $content; }

	function rest_voice_reset( $user_id = 0, $principal = null ) {
		$GLOBALS['_rest_voice_current_user'] = $user_id;
		$GLOBALS['_rest_voice_principal']    = $principal;
		$GLOBALS['_rest_voice_caps']         = array( 7 => array( 'publish_topics' => true, 'publish_replies' => true ) );
		$GLOBALS['_rest_voice_inserted']     = array();
		$GLOBALS['_rest_voice_meta']         = array();
		$GLOBALS['_rest_voice_posts']        = array(
			10  => new WP_Post( array( 'ID' => 10, 'post_type' => 'forum', 'post_status' => 'publish', 'post_author' => 7, 'post_title' => 'Forum' ) ),
			20  => new WP_Post( array( 'ID' => 20, 'post_type' => 'topic', 'post_status' => 'publish', 'post_author' => 7, 'post_parent' => 10, 'post_title' => 'Topic' ) ),
			101 => new WP_Post( array( 'ID' => 101, 'post_type' => 'artist_profile', 'post_status' => 'publish', 'post_author' => 7, 'post_title' => 'Human Resources' ) ),
		);
	}

	function rest_voice_request( $ability_name, array $input ) {
		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/' . $ability_name . '/run' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => $input ) ) );
		return rest_do_request( $request );
	}

	function rest_voice_assert( $condition, $message ) {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}

	require dirname( __DIR__ ) . '/inc/content/public-voice-contract.php';
	require dirname( __DIR__ ) . '/inc/content/public-voices.php';
	require dirname( __DIR__ ) . '/inc/core/ability-helpers.php';
	require dirname( __DIR__ ) . '/inc/content/topic-reply-write.php';
	require dirname( __DIR__ ) . '/inc/content/topic-reply-editor-permissions.php';
	require dirname( __DIR__ ) . '/inc/content/topic-reply-abilities.php';
	extrachill_community_register_topic_reply_abilities();

	$GLOBALS['wp_rest_server'] = new WP_REST_Server();
	$controller                = new WP_REST_Abilities_V1_Run_Controller();
	$GLOBALS['wp_actions']['rest_api_init'] = 1;
	$controller->register_routes();

	rest_voice_assert( true === wp_get_ability( 'extrachill/community-create-topic' )->get_meta_item( 'show_in_rest' ), 'Create topic must be exposed through the standard runner.' );
	rest_voice_assert( true === wp_get_ability( 'extrachill/community-create-reply' )->get_meta_item( 'show_in_rest' ), 'Create reply must be exposed through the standard runner.' );
	rest_voice_assert( false === wp_get_ability( 'extrachill/community-update-topic' )->get_meta_item( 'show_in_rest' ), 'Update topic must remain hidden.' );
	rest_voice_assert( false === wp_get_ability( 'extrachill/community-update-reply' )->get_meta_item( 'show_in_rest' ), 'Update reply must remain hidden.' );

	rest_voice_reset( 7 );
	$response = rest_voice_request( 'extrachill/community-create-topic', array( 'forum_id' => 10, 'title' => 'Human topic', 'content' => 'Body' ) );
	rest_voice_assert( 200 === $response->get_status() && 7 === ( $response->get_data()['author_id'] ?? 0 ), 'Authenticated human must create through the actual topic run route: ' . wp_json_encode( array( $response->get_status(), $response->get_data() ) ) );

	rest_voice_reset( 0, new AgentsAPI\AI\WP_Agent_Execution_Principal( 7, 'roadie' ) );
	$response = rest_voice_request( 'extrachill/community-create-reply', array( 'topic_id' => 20, 'content' => 'Agent reply' ) );
	rest_voice_assert( 200 === $response->get_status() && 7 === $response->get_data()['author_id'], 'Host-resolved agent principal must create as its trusted acting user.' );

	rest_voice_reset( 7 );
	$response = rest_voice_request( 'extrachill/community-create-topic', array( 'forum_id' => 10, 'title' => 'Spoof', 'content' => 'Body', 'user_id' => 8 ) );
	rest_voice_assert( in_array( $response->get_status(), array( 401, 403 ), true ) && 'rest_ability_cannot_execute' === ( $response->get_data()['code'] ?? '' ) && empty( $GLOBALS['_rest_voice_inserted'] ), 'Spoofed user_id must be denied before execution: ' . wp_json_encode( array( $response->get_status(), $response->get_data() ) ) );

	rest_voice_reset();
	$response = rest_voice_request( 'extrachill/community-create-reply', array( 'topic_id' => 20, 'content' => 'Anonymous' ) );
	rest_voice_assert( 401 === $response->get_status() && 'rest_ability_cannot_execute' === ( $response->get_data()['code'] ?? '' ) && empty( $GLOBALS['_rest_voice_inserted'] ), 'Anonymous REST execution must be denied.' );

	rest_voice_reset( 7 );
	$response = rest_voice_request( 'extrachill/community-create-topic', array( 'forum_id' => 10, 'title' => 'Voice', 'content' => 'Body', 'public_voice' => 'artist:101' ) );
	rest_voice_assert( 200 === $response->get_status() && 'artist:101' === get_post_meta( 30, EXTRACHILL_COMMUNITY_PUBLIC_VOICE_META ), 'Managed voice must be reauthorized and persisted through the route: ' . wp_json_encode( array( $response->get_status(), $response->get_data(), $GLOBALS['_rest_voice_meta'] ) ) );

	rest_voice_reset( 7 );
	$response = rest_voice_request( 'extrachill/community-create-topic', array( 'forum_id' => 10, 'title' => 'Denied voice', 'content' => 'Body', 'public_voice' => 'artist:999' ) );
	rest_voice_assert( 'public_voice_not_managed' === $response->get_data()['code'] && empty( $GLOBALS['_rest_voice_inserted'] ), 'Unmanaged voice must be denied at execute-time reauthorization without a write.' );

	rest_voice_reset( 7 );
	$response = rest_voice_request( 'extrachill/community-update-topic', array( 'topic_id' => 20, 'content' => 'Hidden update' ) );
	rest_voice_assert( 404 === $response->get_status() && 'rest_ability_not_found' === $response->get_data()['code'], 'Update abilities must remain unavailable through the standard runner.' );

	echo "PASS: Core REST create routes enforce trusted principals, author binding, and managed voice authorization.\n";
}
