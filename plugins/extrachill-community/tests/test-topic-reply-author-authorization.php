<?php
/** Standalone regression test for topic/reply author authorization. */

namespace AgentsAPI\AI {
	class WP_Agent_Execution_Principal {
		public $acting_user_id;
		public $effective_agent_id;
		public $capability_ceiling;

		public function __construct( $acting_user_id, $capability_ceiling = null, $effective_agent_id = '__wordpress_user__' ) {
			$this->acting_user_id    = $acting_user_id;
			$this->effective_agent_id = $effective_agent_id;
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

		public function allows_capability( $capability ) {
			return null === $this->allowed_capabilities || in_array( $capability, $this->allowed_capabilities, true );
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
	$GLOBALS['_test_inserted']     = array();
	$GLOBALS['_test_actions']      = array();
	$GLOBALS['_test_blog_id']      = 1;
	$GLOBALS['_test_switches']     = array();
	$GLOBALS['_test_users']        = array( 1, 7, 8, 9, 11 );

	function get_current_user_id() {
		return $GLOBALS['_test_current_user'];
	}

	function user_can( $user_id, $capability, $object_id = 0 ) {
		return ! empty( $GLOBALS['_test_caps'][ $user_id ][ $capability ] );
	}

	function get_userdata( $user_id ) {
		return in_array( (int) $user_id, $GLOBALS['_test_users'], true ) ? (object) array( 'ID' => (int) $user_id ) : false;
	}

	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}

	function ec_get_blog_id( $site = '' ) {
		unset( $site );
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
		return isset( $GLOBALS['_test_posts'][ $post_id ] ) ? clone $GLOBALS['_test_posts'][ $post_id ] : null;
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

	function bbp_get_topic_forum_id( $topic_id = 0 ) {
		return 10;
	}

	function bbp_get_reply_topic_id( $reply_id = 0 ) {
		return 20;
	}

	function bbp_get_reply_forum_id( $reply_id = 0 ) {
		return 10;
	}

	function bbp_get_reply_to( $reply_id = 0 ) {
		return 0;
	}

	function bbp_insert_topic( $data, $meta = array() ) {
		$GLOBALS['_test_inserted']['topic'] = $data;
		return 30;
	}

	function bbp_insert_reply( $data, $meta = array() ) {
		$GLOBALS['_test_inserted']['reply'] = $data;
		return 40;
	}

	function bbp_get_topic_permalink( $topic_id = 0 ) {
		return 'https://example.com/topic';
	}

	function bbp_get_reply_url( $reply_id = 0 ) {
		return 'https://example.com/reply';
	}

	function wp_update_post( $update, $return_error = false ) {
		$post_id = (int) $update['ID'];
		foreach ( $update as $field => $value ) {
			if ( 'ID' !== $field ) {
				$GLOBALS['_test_posts'][ $post_id ]->$field = $value;
			}
		}
		$GLOBALS['_test_posts'][ $post_id ]->post_modified_gmt = '2026-07-28 00:00:00';
		return $post_id;
	}

	function mysql_to_rfc3339( $value ) {
		return '2026-07-28T00:00:00+00:00';
	}

	function bbp_past_edit_lock( $post_date_gmt = '' ) {
		return false;
	}

	function __( $value ) {
		return $value;
	}

	function do_action( $hook ) {
		$GLOBALS['_test_actions'][ $hook ] = func_get_args();
	}

	function extrachill_test_reset( $user_id = 0, $principal = null, $caps = array() ) {
		$GLOBALS['_test_current_user'] = $user_id;
		$GLOBALS['_test_principal']    = $principal;
		$GLOBALS['_test_caps']         = $caps;
		$GLOBALS['_test_posts']        = array(
			10 => (object) array(
				'ID'           => 10,
				'post_type'    => 'forum',
				'post_author'  => 7,
				'post_parent'  => 0,
				'post_status'  => 'publish',
				'post_title'   => 'Forum',
				'post_content' => '',
			),
			20 => (object) array(
				'ID'                => 20,
				'post_type'         => 'topic',
				'post_author'       => 7,
				'post_parent'       => 10,
				'post_status'       => 'publish',
				'post_title'        => 'Topic',
				'post_content'      => 'Original topic',
				'post_date_gmt'     => '2026-07-28 00:00:00',
				'post_modified_gmt' => '2026-07-28 00:00:00',
			),
			21 => (object) array(
				'ID'                => 21,
				'post_type'         => 'reply',
				'post_author'       => 7,
				'post_parent'       => 20,
				'post_status'       => 'publish',
				'post_title'        => '',
				'post_content'      => 'Original reply',
				'post_date_gmt'     => '2026-07-28 00:00:00',
				'post_modified_gmt' => '2026-07-28 00:00:00',
			),
		);
		$GLOBALS['_test_inserted']     = array();
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
	require __DIR__ . '/../inc/content/topic-reply-editor-permissions.php';

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
	extrachill_test_reset( 7, new AgentsAPI\AI\WP_Agent_Execution_Principal( 7 ), $caps );
	$self_topic            = $topic;
	$self_topic['user_id'] = 7;
	$self_reply            = $reply;
	$self_reply['user_id'] = 7;
	$topic_result          = extrachill_community_ability_create_topic( $self_topic );
	$reply_result          = extrachill_community_ability_create_reply( $self_reply );
	extrachill_test_assert( 7 === $topic_result['author_id'] && 7 === $GLOBALS['_test_inserted']['topic']['post_author'], 'Topic author must be the authenticated user.' );
	extrachill_test_assert( 7 === $reply_result['author_id'] && 7 === $GLOBALS['_test_inserted']['reply']['post_author'], 'Reply author must be the authenticated user.' );
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

	$update_topic = array( 'topic_id' => 20, 'content' => 'Updated topic' );
	$update_reply = array( 'reply_id' => 21, 'content' => 'Updated reply' );
	$edit_caps    = array( 7 => array( 'edit_topic' => true, 'edit_reply' => true ) );

	// Both update permission and execution paths use the trusted actor.
	extrachill_test_reset( 7, new AgentsAPI\AI\WP_Agent_Execution_Principal( 7 ), $edit_caps );
	extrachill_test_assert( true === extrachill_community_ability_update_topic_permission( $update_topic ), 'Authenticated actor must be allowed to update its topic.' );
	extrachill_test_assert( true === extrachill_community_ability_update_reply_permission( $update_reply ), 'Authenticated actor must be allowed to update its reply.' );
	extrachill_test_assert( ! is_wp_error( extrachill_community_ability_update_topic( $update_topic ) ), 'Authorized topic update must succeed.' );
	extrachill_test_assert( ! is_wp_error( extrachill_community_ability_update_reply( $update_reply ) ), 'Authorized reply update must succeed.' );
	extrachill_test_assert( 'Updated topic' === $GLOBALS['_test_posts'][20]->post_content, 'Topic update must persist.' );
	extrachill_test_assert( 'Updated reply' === $GLOBALS['_test_posts'][21]->post_content, 'Reply update must persist.' );

	// Author reassignment requires edit_others_* as the trusted actor.
	$reassign_caps = array(
		7 => array(
			'edit_topic'          => true,
			'edit_reply'          => true,
			'edit_others_topics'  => true,
			'edit_others_replies' => true,
		),
	);
	extrachill_test_reset( 7, new AgentsAPI\AI\WP_Agent_Execution_Principal( 7 ), $reassign_caps );
	$reassign_topic            = $update_topic;
	$reassign_topic['user_id'] = 8;
	$reassign_reply            = $update_reply;
	$reassign_reply['user_id'] = 8;
	extrachill_test_assert( true === extrachill_community_ability_update_topic_permission( $reassign_topic ), 'Authorized topic reassignment permission must pass.' );
	extrachill_test_assert( true === extrachill_community_ability_update_reply_permission( $reassign_reply ), 'Authorized reply reassignment permission must pass.' );
	extrachill_test_assert( ! is_wp_error( extrachill_community_ability_update_topic( $reassign_topic ) ), 'Authorized topic reassignment must succeed.' );
	extrachill_test_assert( ! is_wp_error( extrachill_community_ability_update_reply( $reassign_reply ) ), 'Authorized reply reassignment must succeed.' );
	extrachill_test_assert( 8 === $GLOBALS['_test_posts'][20]->post_author, 'Topic reassignment must use the validated target.' );
	extrachill_test_assert( 8 === $GLOBALS['_test_posts'][21]->post_author, 'Reply reassignment must use the validated target.' );

	// A delegated principal cannot inherit a different privileged ambient user.
	$ambient_admin_caps = array(
		1 => array(
			'edit_topic'          => true,
			'edit_reply'          => true,
			'edit_others_topics'  => true,
			'edit_others_replies' => true,
		),
	);
	extrachill_test_reset( 1, new AgentsAPI\AI\WP_Agent_Execution_Principal( 7 ), $ambient_admin_caps );
	extrachill_test_assert( false === extrachill_community_ability_update_topic_permission( $reassign_topic ), 'Mismatched principal topic permission must fail.' );
	extrachill_test_assert( false === extrachill_community_ability_update_reply_permission( $reassign_reply ), 'Mismatched principal reply permission must fail.' );
	extrachill_test_assert( 'execution_principal_mismatch' === extrachill_community_ability_update_topic( $reassign_topic )->get_error_code(), 'Mismatched principal topic update must fail.' );
	extrachill_test_assert( 'execution_principal_mismatch' === extrachill_community_ability_update_reply( $reassign_reply )->get_error_code(), 'Mismatched principal reply update must fail.' );

	// Capability ceilings constrain updates and author reassignment.
	$restricted_ceiling = new WP_Agent_Capability_Ceiling( 7, array( 'edit_topic', 'edit_reply' ) );
	extrachill_test_reset( 7, new AgentsAPI\AI\WP_Agent_Execution_Principal( 7, $restricted_ceiling ), $reassign_caps );
	extrachill_test_assert( true === extrachill_community_ability_update_topic_permission( $update_topic ), 'Ceiling-authorized topic edit must pass.' );
	extrachill_test_assert( true === extrachill_community_ability_update_reply_permission( $update_reply ), 'Ceiling-authorized reply edit must pass.' );
	extrachill_test_assert( false === extrachill_community_ability_update_topic_permission( $reassign_topic ), 'Topic reassignment outside the ceiling must fail permission.' );
	extrachill_test_assert( false === extrachill_community_ability_update_reply_permission( $reassign_reply ), 'Reply reassignment outside the ceiling must fail permission.' );
	extrachill_test_assert( 'cannot_change_author' === extrachill_community_ability_update_topic( $reassign_topic )->get_error_code(), 'Topic reassignment outside the ceiling must fail execution.' );
	extrachill_test_assert( 'cannot_change_author' === extrachill_community_ability_update_reply( $reassign_reply )->get_error_code(), 'Reply reassignment outside the ceiling must fail execution.' );

	$topic_only_ceiling = new WP_Agent_Capability_Ceiling( 7, array( 'edit_topic' ) );
	extrachill_test_reset( 7, new AgentsAPI\AI\WP_Agent_Execution_Principal( 7, $topic_only_ceiling ), $edit_caps );
	extrachill_test_assert( false === extrachill_community_ability_update_reply_permission( $update_reply ), 'Reply update outside the ceiling must fail permission.' );
	extrachill_test_assert( 'cannot_edit' === extrachill_community_ability_update_reply( $update_reply )->get_error_code(), 'Reply update outside the ceiling must fail execution.' );

	// Explicit author selection is denied outside WP-CLI, even with a capable target.
	$cli_caps  = array( 8 => array( 'publish_topics' => true, 'publish_replies' => true ) );
	$cli_topic = $topic;
	$cli_reply = $reply;
	$cli_topic['user_id'] = 8;
	$cli_reply['user_id'] = 8;
	extrachill_test_reset( 0, null, $cli_caps );
	extrachill_test_assert( 'missing_user' === extrachill_community_ability_create_topic( $cli_topic )->get_error_code(), 'General topic ability execution cannot select an author.' );
	extrachill_test_assert( 'missing_user' === extrachill_community_ability_create_reply( $cli_reply )->get_error_code(), 'General reply ability execution cannot select an author.' );

	// Trusted WP-CLI preserves the documented explicit-author workflow.
	define( 'WP_CLI', true );
	extrachill_test_reset( 0, null, $cli_caps );
	extrachill_test_assert( true === extrachill_community_ability_create_topic_permission( $cli_topic ), 'WP-CLI topic author selection must pass ability permission.' );
	extrachill_test_assert( true === extrachill_community_ability_create_reply_permission( $cli_reply ), 'WP-CLI reply author selection must pass ability permission.' );
	extrachill_test_assert( 8 === extrachill_community_ability_create_topic( $cli_topic )['author_id'], 'WP-CLI topic author selection must use a valid capable user.' );
	extrachill_test_assert( 8 === extrachill_community_ability_create_reply( $cli_reply )['author_id'], 'WP-CLI reply author selection must use a valid capable user.' );
	$invalid_cli_topic            = $topic;
	$invalid_cli_topic['user_id'] = 99;
	extrachill_test_assert( 'missing_user' === extrachill_community_ability_create_topic( $invalid_cli_topic )->get_error_code(), 'WP-CLI must reject an unknown author.' );
	extrachill_test_reset( 0, new AgentsAPI\AI\WP_Agent_Execution_Principal( 0 ), $cli_caps );
	extrachill_test_assert( 'missing_user' === extrachill_community_ability_create_topic( $cli_topic )->get_error_code(), 'Agent execution cannot inherit WP-CLI author selection.' );
	extrachill_test_reset( 0, null, array() );
	extrachill_test_assert( 'cannot_publish' === extrachill_community_ability_create_reply( $cli_reply )->get_error_code(), 'WP-CLI must reject an author without the bbPress capability.' );

	echo "PASS: Topic and reply writes bind create/update authorization to the trusted execution actor.\n";
}
