<?php
/**
 * Actual core REST route coverage for Community create abilities.
 *
 * Requires a fully booted WordPress: it runs through the host-smoke backend
 * (declared in homeboy.json `wordpress_test_paths`). Users, forums, topics, and
 * post meta are real rows in the sandbox database and every request goes
 * through core's Abilities REST run route. This plugin, bbPress, Agents API,
 * and the network plugins are mounted but not activated there, so the test
 * stubs only the functions and classes those inactive plugins would provide.
 */

namespace AgentsAPI\AI {
	if ( ! class_exists( WP_Agent_Execution_Principal::class ) ) {
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
}

namespace {
	if ( ! defined( 'ABSPATH' ) || ! function_exists( 'did_action' ) || ! did_action( 'init' ) ) {
		fwrite( STDERR, "create-ability-rest-routes-smoke.php requires a booted WordPress; run it through the host-smoke backend.\n" );
		exit( 1 );
	}

	if ( ! defined( 'EXTRACHILL_COMMUNITY_PUBLIC_VOICE_META' ) ) {
		define( 'EXTRACHILL_COMMUNITY_PUBLIC_VOICE_META', '_extrachill_public_voice' );
	}
	if ( ! defined( 'EXTRACHILL_COMMUNITY_AUTOMATED_META' ) ) {
		define( 'EXTRACHILL_COMMUNITY_AUTOMATED_META', '_extrachill_automated_agent' );
	}

	$GLOBALS['_rest_voice_inserted']  = array();
	$GLOBALS['_rest_voice_principal'] = null;

	// Agents API: the host resolves the trusted execution principal.
	if ( ! class_exists( 'WP_Agent_Access' ) ) {
		class WP_Agent_Access {
			public static function get_current_principal() {
				return $GLOBALS['_rest_voice_principal'];
			}
		}
	}

	// Network and users plugins: user 7 manages artist 101.
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		function ec_get_blog_id() { return get_current_blog_id(); }
	}
	if ( ! function_exists( 'ec_with_blog' ) ) {
		function ec_with_blog( $blog_id, $callback ) {
			if ( ! is_multisite() || (int) get_current_blog_id() === (int) $blog_id ) {
				return $callback();
			}
			switch_to_blog( $blog_id );
			try {
				return $callback();
			} finally {
				restore_current_blog();
			}
		}
	}
	if ( ! function_exists( 'ec_get_artists_for_user' ) ) {
		function ec_get_artists_for_user( $user_id ) { return 7 === (int) $user_id ? array( 101 ) : array(); }
	}
	if ( ! function_exists( 'ec_user_can' ) ) {
		function ec_user_can( $capability, $context = array() ) { return 'manage_artist' === $capability && 7 === (int) ( $context['user_id'] ?? 0 ) && 101 === (int) ( $context['artist_id'] ?? 0 ); }
	}

	if ( ! function_exists( 'ec_get_artist_identity' ) ) {
		function ec_get_artist_identity( array $by ) {
			$artist = get_post( (int) ( $by['profile_id'] ?? 0 ) );
			if ( ! $artist || 'artist_profile' !== $artist->post_type ) {
				return null;
			}
			// Same record shape as the network plugin's identity read.
			return array(
				'profile_id'    => (int) $artist->ID,
				'term_id'       => 0,
				'slug'          => $artist->post_name,
				'name'          => $artist->post_title,
				'status'        => $artist->post_status,
				'permalink'     => 'https://artist.example/artist/' . (int) $artist->ID,
				'thumbnail_id'  => 0,
				'thumbnail_url' => 'https://artist.example/artist/' . (int) $artist->ID . '.jpg',
				'term_slug'     => '',
				'term_name'     => '',
				'binding_valid' => false,
			);
		}
	}

	// bbPress: insertion records the call and writes a real post.
	if ( ! function_exists( 'bbp_get_forum_post_type' ) ) {
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
			return wp_insert_post( array_merge( $data, array( 'import_id' => 30 ) ) );
		}
		function bbp_insert_reply( $data ) {
			$GLOBALS['_rest_voice_inserted'][] = array( 'type' => 'reply', 'data' => $data );
			return wp_insert_post( array_merge( $data, array( 'import_id' => 40 ) ) );
		}
		function bbp_past_edit_lock() { return false; }
	}
	if ( ! function_exists( 'extrachill_community_maybe_convert_markdown' ) ) {
		function extrachill_community_maybe_convert_markdown( $content ) { return $content; }
	}

	register_post_type( 'forum', array( 'public' => true, 'hierarchical' => true ) );
	register_post_type( 'topic', array( 'public' => true ) );
	register_post_type( 'reply', array( 'public' => true ) );
	register_post_type( 'artist_profile', array( 'public' => true ) );

	// Only user 7 may publish topics and replies.
	add_filter(
		'user_has_cap',
		static function ( $allcaps, $caps, $args, $user ) {
			return 7 === (int) $user->ID ? array( 'publish_topics' => true, 'publish_replies' => true ) : array();
		},
		PHP_INT_MAX,
		4
	);

	function rest_voice_assert( $condition, $message ) {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}

	function rest_voice_user( $id ) {
		global $wpdb;
		if ( ! get_userdata( $id ) ) {
			$wpdb->insert(
				$wpdb->users,
				array(
					'ID'              => $id,
					'user_login'      => 'rest-voice-' . $id,
					'user_pass'       => wp_hash_password( wp_generate_password() ),
					'user_nicename'   => 'rest-voice-' . $id,
					'user_email'      => 'rest-voice-' . $id . '@example.com',
					'user_registered' => '2024-01-01 00:00:00',
					'display_name'    => 'User ' . $id,
				)
			);
			wp_cache_delete( $id, 'users' );
		}
		rest_voice_assert( get_userdata( $id ) instanceof WP_User, "Fixture user {$id} must exist." );
	}

	function rest_voice_post( $id, array $data ) {
		$inserted = wp_insert_post( array_merge( array( 'import_id' => $id, 'post_status' => 'publish', 'post_author' => 7 ), $data ), true );
		rest_voice_assert( $id === $inserted, "Fixture post {$id} must be created with its requested ID: " . wp_json_encode( $inserted ) );
	}

	function rest_voice_reset( $user_id = 0, $principal = null ) {
		wp_set_current_user( $user_id );
		$GLOBALS['_rest_voice_principal'] = $principal;
		$GLOBALS['_rest_voice_inserted']  = array();
		foreach ( array( 30, 40 ) as $created_id ) {
			if ( get_post( $created_id ) ) {
				wp_delete_post( $created_id, true );
			}
		}
	}

	function rest_voice_request( $ability_name, array $input ) {
		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/' . $ability_name . '/run' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => $input ) ) );
		return rest_do_request( $request );
	}

	rest_voice_user( 7 );
	rest_voice_user( 8 );
	rest_voice_post( 10, array( 'post_type' => 'forum', 'post_title' => 'Forum' ) );
	rest_voice_post( 20, array( 'post_type' => 'topic', 'post_parent' => 10, 'post_title' => 'Topic' ) );
	rest_voice_post( 101, array( 'post_type' => 'artist_profile', 'post_title' => 'Human Resources' ) );

	require dirname( __DIR__ ) . '/inc/content/public-voice-contract.php';
	require dirname( __DIR__ ) . '/inc/content/public-voices.php';
	require dirname( __DIR__ ) . '/inc/core/ability-helpers.php';
	require dirname( __DIR__ ) . '/inc/content/topic-reply-write.php';
	require dirname( __DIR__ ) . '/inc/content/topic-reply-editor-permissions.php';
	require dirname( __DIR__ ) . '/inc/content/topic-reply-abilities.php';
	require_once __DIR__ . '/support/booted-ability-registry.php';
	extrachill_community_test_register_abilities( 'extrachill_community_register_topic_reply_abilities' );

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
	rest_voice_assert( 200 === $response->get_status() && 'artist:101' === get_post_meta( 30, EXTRACHILL_COMMUNITY_PUBLIC_VOICE_META, true ), 'Managed voice must be reauthorized and persisted through the route: ' . wp_json_encode( array( $response->get_status(), $response->get_data(), get_post_meta( 30 ) ) ) );

	rest_voice_reset( 7 );
	$response = rest_voice_request( 'extrachill/community-create-topic', array( 'forum_id' => 10, 'title' => 'Denied voice', 'content' => 'Body', 'public_voice' => 'artist:999' ) );
	rest_voice_assert( 'public_voice_not_managed' === $response->get_data()['code'] && empty( $GLOBALS['_rest_voice_inserted'] ), 'Unmanaged voice must be denied at execute-time reauthorization without a write.' );

	rest_voice_reset( 7 );
	$response = rest_voice_request( 'extrachill/community-update-topic', array( 'topic_id' => 20, 'content' => 'Hidden update' ) );
	rest_voice_assert( 404 === $response->get_status() && 'rest_ability_not_found' === $response->get_data()['code'], 'Update abilities must remain unavailable through the standard runner.' );

	echo "PASS: Core REST create routes enforce trusted principals, author binding, and managed voice authorization.\n";
}
