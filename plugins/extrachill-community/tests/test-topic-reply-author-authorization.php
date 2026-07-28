<?php
/** Standalone regression test for topic/reply author authorization. */

namespace AgentsAPI\AI {
	class WP_Agent_Execution_Principal {
		public $acting_user_id;
		public $capability_ceiling;

		public function __construct( $acting_user_id, $capability_ceiling = null ) {
			$this->acting_user_id    = $acting_user_id;
			$this->capability_ceiling = $capability_ceiling;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	class WP_Error {
		private $code;

		public function __construct( $code ) {
			$this->code = $code;
		}

		public function get_error_code() {
			return $this->code;
		}
	}

	class WP_Agent_Capability_Ceiling {
		public $user_id;
		public $allowed_capabilities;

		public function __construct( $user_id, $allowed_capabilities = null ) {
			$this->user_id              = $user_id;
			$this->allowed_capabilities = $allowed_capabilities;
		}
	}

	class WP_Agent_Access {
		public static function get_current_principal( $context = array() ) {
			return $GLOBALS['_test_principal'];
		}
	}

	class WP_Agent_WordPress_Authorization_Policy {
		public function can( $principal, $capability ) {
			if ( $principal->capability_ceiling instanceof WP_Agent_Capability_Ceiling
				&& is_array( $principal->capability_ceiling->allowed_capabilities )
				&& ! in_array( $capability, $principal->capability_ceiling->allowed_capabilities, true )
			) {
				return false;
			}

			return user_can( $principal->acting_user_id, $capability );
		}
	}

	$GLOBALS['_test_current_user'] = 0;
	$GLOBALS['_test_principal']    = null;
	$GLOBALS['_test_caps']         = array();
	$GLOBALS['_test_posts']        = array();
	$GLOBALS['_test_actions']      = array();
	$GLOBALS['_test_blog_id']      = 1;
	$GLOBALS['_test_switches']     = array();

	function get_current_user_id() {
		return $GLOBALS['_test_current_user'];
	}

	function user_can( $user_id, $capability ) {
		return ! empty( $GLOBALS['_test_caps'][ $user_id ][ $capability ] );
	}

	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}

	function ec_get_blog_id() {
		return 2;
	}

	function get_current_blog_id() {
		return $GLOBALS['_test_blog_id'];
	}

	function switch_to_blog( $blog_id ) {
		$GLOBALS['_test_switches'][] = array( 'switch', $blog_id, get_current_user_id() );
		$GLOBALS['_test_blog_id']    = $blog_id;
	}

	function restore_current_blog() {
		$GLOBALS['_test_switches'][] = array( 'restore', 1, get_current_user_id() );
		$GLOBALS['_test_blog_id']    = 1;
	}

	function sanitize_text_field( $value ) {
		return trim( $value );
	}

	function wp_kses_post( $value ) {
		return $value;
	}

	function extrachill_community_maybe_convert_markdown( $value ) {
		return $value;
	}

	function get_post( $post_id ) {
		if ( 10 === $post_id ) {
			return (object) array( 'post_type' => 'forum' );
		}
		if ( 20 === $post_id ) {
			return (object) array( 'post_type' => 'topic', 'post_parent' => 10 );
		}
		return null;
	}

	function bbp_get_forum_post_type() {
		return 'forum';
	}

	function bbp_get_topic_post_type() {
		return 'topic';
	}

	function bbp_get_reply_post_type() {
		return 'reply';
	}

	function bbp_get_public_status_id() {
		return 'publish';
	}

	function bbp_get_topic_forum_id() {
		return 10;
	}

	function bbp_insert_topic( $data ) {
		$GLOBALS['_test_posts']['topic'] = $data;
		return 30;
	}

	function bbp_insert_reply( $data ) {
		$GLOBALS['_test_posts']['reply'] = $data;
		return 40;
	}

	function bbp_get_topic_permalink() {
		return 'https://example.com/topic';
	}

	function bbp_get_reply_url() {
		return 'https://example.com/reply';
	}

	function do_action( $hook ) {
		$GLOBALS['_test_actions'][ $hook ] = func_get_args();
	}

	function extrachill_test_reset( $user_id = 0, $principal = null, $caps = array() ) {
		$GLOBALS['_test_current_user'] = $user_id;
		$GLOBALS['_test_principal']    = $principal;
		$GLOBALS['_test_caps']         = $caps;
		$GLOBALS['_test_posts']        = array();
		$GLOBALS['_test_actions']      = array();
		$GLOBALS['_test_switches']     = array();
	}

	function extrachill_test_assert( $condition, $message ) {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}

	require __DIR__ . '/../inc/core/ability-helpers.php';
	require __DIR__ . '/../inc/content/topic-reply-write.php';

	$topic = array( 'forum_id' => 10, 'title' => 'Title', 'content' => 'Content' );
	$reply = array( 'topic_id' => 20, 'content' => 'Reply' );

	// Anonymous calls fail before either write path can insert content.
	extrachill_test_reset();
	extrachill_test_assert( ! extrachill_community_ability_create_topic_permission( $topic ), 'Anonymous topic permission must fail.' );
	extrachill_test_assert( ! extrachill_community_ability_create_reply_permission( $reply ), 'Anonymous reply permission must fail.' );
	extrachill_test_assert( is_wp_error( extrachill_community_ability_create_topic( $topic ) ), 'Anonymous topic execution must fail.' );
	extrachill_test_assert( is_wp_error( extrachill_community_ability_create_reply( $reply ) ), 'Anonymous reply execution must fail.' );

	// A caller-supplied victim ID is rejected for both write paths.
	$caps = array( 7 => array( 'publish_topics' => true, 'publish_replies' => true ) );
	extrachill_test_reset( 7, new AgentsAPI\AI\WP_Agent_Execution_Principal( 7 ), $caps );
	$victim_topic            = $topic;
	$victim_topic['user_id'] = 8;
	$victim_reply            = $reply;
	$victim_reply['user_id'] = 8;
	extrachill_test_assert( 'author_mismatch' === extrachill_community_ability_create_topic( $victim_topic )->get_error_code(), 'Topic victim author must be rejected.' );
	extrachill_test_assert( 'author_mismatch' === extrachill_community_ability_create_reply( $victim_reply )->get_error_code(), 'Reply victim author must be rejected.' );

	// Authenticated self-authorship remains compatible with legacy self IDs.
	$self_topic            = $topic;
	$self_topic['user_id'] = 7;
	$self_reply            = $reply;
	$self_reply['user_id'] = 7;
	$topic_result          = extrachill_community_ability_create_topic( $self_topic );
	$reply_result          = extrachill_community_ability_create_reply( $self_reply );
	extrachill_test_assert( 7 === $topic_result['author_id'] && 7 === $GLOBALS['_test_posts']['topic']['post_author'], 'Topic author must be the authenticated user.' );
	extrachill_test_assert( 7 === $reply_result['author_id'] && 7 === $GLOBALS['_test_posts']['reply']['post_author'], 'Reply author must be the authenticated user.' );
	extrachill_test_assert( 7 === $GLOBALS['_test_actions']['bbp_new_topic'][4], 'Topic side effects must receive the authenticated user.' );
	extrachill_test_assert( 7 === $GLOBALS['_test_actions']['bbp_new_reply'][5], 'Reply side effects must receive the authenticated user.' );
	extrachill_test_assert( array( array( 'switch', 2, 7 ), array( 'restore', 1, 7 ), array( 'switch', 2, 7 ), array( 'restore', 1, 7 ) ) === $GLOBALS['_test_switches'], 'Cross-site capability checks must preserve the authenticated network user.' );

	// Ambient WordPress and Agents API identities must agree.
	extrachill_test_reset( 7, new AgentsAPI\AI\WP_Agent_Execution_Principal( 8 ), $caps );
	extrachill_test_assert( 'execution_principal_mismatch' === extrachill_community_ability_create_topic( $topic )->get_error_code(), 'Mismatched topic principal must fail.' );
	extrachill_test_assert( 'execution_principal_mismatch' === extrachill_community_ability_create_reply( $reply )->get_error_code(), 'Mismatched reply principal must fail.' );

	// Capability ceilings cannot substitute a different effective user.
	$ceiling = new WP_Agent_Capability_Ceiling( 8, array( 'publish_topics', 'publish_replies' ) );
	extrachill_test_reset( 0, new AgentsAPI\AI\WP_Agent_Execution_Principal( 9, $ceiling ), array( 8 => array( 'publish_topics' => true, 'publish_replies' => true ) ) );
	extrachill_test_assert( 'execution_principal_mismatch' === extrachill_community_ability_create_topic( $topic )->get_error_code(), 'Mismatched topic capability principal must fail.' );
	extrachill_test_assert( 'execution_principal_mismatch' === extrachill_community_ability_create_reply( $reply )->get_error_code(), 'Mismatched reply capability principal must fail.' );

	// A host-authorized execution principal can post as its acting user.
	$agent_caps = array( 9 => array( 'publish_topics' => true, 'publish_replies' => true ) );
	extrachill_test_reset( 0, new AgentsAPI\AI\WP_Agent_Execution_Principal( 9 ), $agent_caps );
	extrachill_test_assert( 9 === extrachill_community_ability_create_topic( $topic )['author_id'], 'Authorized agent topic must use its acting user.' );
	extrachill_test_assert( 9 === extrachill_community_ability_create_reply( $reply )['author_id'], 'Authorized agent reply must use its acting user.' );

	// Authenticated users without the bbPress publish capability fail closed.
	extrachill_test_reset( 11, new AgentsAPI\AI\WP_Agent_Execution_Principal( 11 ) );
	extrachill_test_assert( 'cannot_publish' === extrachill_community_ability_create_topic( $topic )->get_error_code(), 'Underprivileged topic execution must fail.' );
	extrachill_test_assert( 'cannot_publish' === extrachill_community_ability_create_reply( $reply )->get_error_code(), 'Underprivileged reply execution must fail.' );

	echo "PASS: Topic and reply writes bind authorship to the authorized execution actor.\n";
}
