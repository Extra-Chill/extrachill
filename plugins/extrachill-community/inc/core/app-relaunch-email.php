<?php
/**
 * One-time app relaunch email to dormant Community participants.
 *
 * Owns the segment and send logic behind `wp extrachill community
 * app-relaunch-email`. The CLI is a thin adapter over the
 * `extrachill/community-app-relaunch-email` ability registered here.
 *
 * Segment: users who have posted a Community topic or reply ever, but none in
 * the last 90 days. Excluded: users who opted out of notification email, team,
 * admin and moderator accounts, the network bot account, spam, deleted or
 * blocked accounts, invalid email addresses, and anyone already sent this
 * email. Other plugins can add exclusions (for example newsletter bounces)
 * through the `extrachill_community_app_relaunch_exclusion_reason` filter.
 *
 * Dry run is the default. Sending needs an explicit `send` flag and both store
 * listings configured in the network options `extrachill_app_store_id` and
 * `extrachill_play_package`. Mail goes through the platform queued mail path
 * (ec_send_email_queued()), one job per recipient, with the one-tap
 * notification unsubscribe link from extrachill-users. A per-user sent marker
 * is claimed before each send, so re-running never double-sends.
 *
 * Issue: Extra-Chill/extrachill#1417.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

/** User meta holding the Unix time the relaunch email was queued for a user. */
const EXTRACHILL_COMMUNITY_APP_RELAUNCH_SENT_META = 'ec_app_relaunch_email_sent_at';

/** Days without a Community post before a participant counts as dormant. */
const EXTRACHILL_COMMUNITY_APP_RELAUNCH_DORMANT_DAYS = 90;

/** Network option holding the numeric App Store ID. */
const EXTRACHILL_COMMUNITY_APP_STORE_ID_OPTION = 'extrachill_app_store_id';

/** Network option holding the Google Play package name. */
const EXTRACHILL_COMMUNITY_PLAY_PACKAGE_OPTION = 'extrachill_play_package';

add_action( 'wp_abilities_api_init', 'extrachill_community_register_app_relaunch_ability' );

/**
 * Register the app relaunch email ability.
 */
function extrachill_community_register_app_relaunch_ability() {
	wp_register_ability(
		'extrachill/community-app-relaunch-email',
		array(
			'label'               => __( 'App Relaunch Email', 'extra-chill-community' ),
			'description'         => __( 'Build the dormant Community participant segment and, with send=true, queue the one-time app relaunch email. Dry run by default.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => array(),
				'properties'           => array(
					'send'        => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Queue the email. False (default) only reports the segment.',
					),
					'limit'       => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => 'Max emails to queue in this run. 0 means the whole segment.',
					),
					'sample_size' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100,
						'default' => 10,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'mode'                 => array( 'type' => 'string' ),
					'participants'         => array( 'type' => 'integer' ),
					'recent'               => array( 'type' => 'integer' ),
					'dormant'              => array( 'type' => 'integer' ),
					'excluded'             => array( 'type' => 'object' ),
					'eligible'             => array( 'type' => 'integer' ),
					'store_links_ready'    => array( 'type' => 'boolean' ),
					'sample'               => array( 'type' => 'array' ),
					'sent'                 => array( 'type' => 'integer' ),
					'failed'               => array( 'type' => 'integer' ),
					'skipped_already_sent' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_app_relaunch_email',
			'permission_callback' => 'extrachill_community_app_relaunch_permission',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Only trusted CLI or network admins may build the segment or send.
 *
 * @return bool
 */
function extrachill_community_app_relaunch_permission() {
	if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
		return true;
	}

	return current_user_can( 'manage_network_options' );
}

/**
 * Ability execute callback.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_app_relaunch_email( $input = array() ) {
	return extrachill_community_app_relaunch_run( is_array( $input ) ? $input : array() );
}

/**
 * Resolve the store links from network options.
 *
 * @return array{ready: bool, app_store_url: string, play_url: string}
 */
function extrachill_community_app_relaunch_store_links() {
	$store_id = trim( (string) get_site_option( EXTRACHILL_COMMUNITY_APP_STORE_ID_OPTION, '' ) );
	$package  = trim( (string) get_site_option( EXTRACHILL_COMMUNITY_PLAY_PACKAGE_OPTION, '' ) );

	// Accept "id123" or "123"; anything else is not a usable store ID.
	$store_id = preg_replace( '/^id/i', '', $store_id );
	if ( ! preg_match( '/^\d+$/', (string) $store_id ) ) {
		$store_id = '';
	}
	if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+$/', $package ) ) {
		$package = '';
	}

	return array(
		'ready'         => '' !== $store_id && '' !== $package,
		'app_store_url' => '' !== $store_id ? 'https://apps.apple.com/app/id' . $store_id : '',
		'play_url'      => '' !== $package ? 'https://play.google.com/store/apps/details?id=' . rawurlencode( $package ) : '',
	);
}

/**
 * Build the segment and optionally queue the email.
 *
 * @param array $input {
 *     @type bool $send        Queue the email. Default false (dry run).
 *     @type int  $limit       Max emails to queue this run. 0 = all.
 *     @type int  $sample_size Number of sample rows to return.
 * }
 * @param array $deps Optional seams for tests: `candidates` (callable returning
 *                    rows of user_id + last_post_gmt), `last_topic` (callable
 *                    taking a user ID, returning title + url or null), `queue`
 *                    (callable taking ec_send_email_queued() args), `now` (int).
 * @return array|WP_Error
 */
function extrachill_community_app_relaunch_run( array $input, array $deps = array() ) {
	$send        = ! empty( $input['send'] );
	$limit       = max( 0, (int) ( $input['limit'] ?? 0 ) );
	$sample_size = min( 100, max( 0, (int) ( $input['sample_size'] ?? 10 ) ) );
	$now         = isset( $deps['now'] ) ? (int) $deps['now'] : time();
	$links       = extrachill_community_app_relaunch_store_links();

	if ( $send ) {
		if ( ! $links['ready'] ) {
			return new WP_Error(
				'app_store_links_unset',
				sprintf(
					'Refusing to send: set the network options %1$s and %2$s once the app is live in both stores.',
					EXTRACHILL_COMMUNITY_APP_STORE_ID_OPTION,
					EXTRACHILL_COMMUNITY_PLAY_PACKAGE_OPTION
				)
			);
		}
		if ( ! function_exists( 'ec_notifications_unsubscribe_url' ) ) {
			return new WP_Error( 'unsubscribe_unavailable', 'Refusing to send: the extrachill-users one-tap unsubscribe link is unavailable.' );
		}
		if ( ! isset( $deps['queue'] ) && ! function_exists( 'ec_send_email_queued' ) ) {
			return new WP_Error( 'mail_unavailable', 'Refusing to send: ec_send_email_queued() is unavailable.' );
		}
	}

	$candidates_cb = $deps['candidates'] ?? 'extrachill_community_app_relaunch_query_participants';
	$last_topic_cb = $deps['last_topic'] ?? 'extrachill_community_app_relaunch_last_topic';
	$queue_cb      = $deps['queue'] ?? 'ec_send_email_queued';

	// Segment queries and role checks run in the Community blog context, where
	// the forum posts and bbPress roles live.
	$switch = function_exists( 'extrachill_community_switch_to_community_blog' )
		? extrachill_community_switch_to_community_blog()
		: array( 'switched' => false );

	try {
		$segment = extrachill_community_app_relaunch_build_segment( (array) call_user_func( $candidates_cb ), $now );

		$targets = $segment['eligible'];
		if ( $send && $limit > 0 ) {
			$targets = array_slice( $targets, 0, $limit );
		}

		// Resolve personalization while still in the Community context.
		$details = array();
		$wanted  = $send ? $targets : array_slice( $segment['eligible'], 0, $sample_size );
		foreach ( $wanted as $row ) {
			$details[ $row['user_id'] ] = call_user_func( $last_topic_cb, $row['user_id'] );
		}
	} finally {
		if ( ! empty( $switch['switched'] ) ) {
			restore_current_blog();
		}
	}

	$sample = array();
	foreach ( array_slice( $segment['eligible'], 0, $sample_size ) as $row ) {
		$user     = get_userdata( $row['user_id'] );
		$topic    = $details[ $row['user_id'] ] ?? null;
		$sample[] = array(
			'user_id'       => $row['user_id'],
			'display_name'  => $user ? (string) $user->display_name : '',
			'last_post_gmt' => $row['last_post_gmt'],
			'last_topic'    => is_array( $topic ) ? (string) ( $topic['title'] ?? '' ) : '',
		);
	}

	$result = array(
		'mode'                 => $send ? 'send' : 'dry-run',
		'participants'         => $segment['participants'],
		'recent'               => $segment['recent'],
		'dormant'              => $segment['dormant'],
		'excluded'             => $segment['excluded'],
		'eligible'             => count( $segment['eligible'] ),
		'store_links_ready'    => $links['ready'],
		'sample'               => $sample,
		'sent'                 => 0,
		'failed'               => 0,
		'skipped_already_sent' => 0,
	);

	if ( ! $send ) {
		return $result;
	}

	// Mail is sent outside any switch_to_blog(): ec_send_email_queued()
	// resolves the SMTP site itself and must not be wrapped.
	foreach ( $targets as $row ) {
		$outcome = extrachill_community_app_relaunch_send_one( $row['user_id'], $details[ $row['user_id'] ] ?? null, $links, $queue_cb, $now );
		if ( 'sent' === $outcome ) {
			++$result['sent'];
		} elseif ( 'already_sent' === $outcome ) {
			++$result['skipped_already_sent'];
		} else {
			++$result['failed'];
		}
	}

	return $result;
}

/**
 * Split participant rows into recent, excluded and eligible.
 *
 * @param array $rows Rows with `user_id` and `last_post_gmt` (Y-m-d H:i:s, UTC).
 * @param int   $now  Reference Unix time.
 * @return array{participants:int, recent:int, dormant:int, excluded:array<string,int>, eligible:array<int,array{user_id:int,last_post_gmt:string}>}
 */
function extrachill_community_app_relaunch_build_segment( array $rows, $now ) {
	$cutoff  = (int) $now - EXTRACHILL_COMMUNITY_APP_RELAUNCH_DORMANT_DAYS * DAY_IN_SECONDS;
	$segment = array(
		'participants' => 0,
		'recent'       => 0,
		'dormant'      => 0,
		'excluded'     => array(),
		'eligible'     => array(),
	);
	$seen    = array();

	foreach ( $rows as $row ) {
		$row     = (array) $row;
		$user_id = (int) ( $row['user_id'] ?? 0 );
		$last    = (string) ( $row['last_post_gmt'] ?? '' );
		if ( $user_id <= 0 || isset( $seen[ $user_id ] ) ) {
			continue;
		}
		$seen[ $user_id ] = true;
		++$segment['participants'];

		$last_ts = strtotime( $last . ' UTC' );
		if ( false === $last_ts || $last_ts >= $cutoff ) {
			++$segment['recent'];
			continue;
		}
		++$segment['dormant'];

		$reason = extrachill_community_app_relaunch_exclusion_reason( $user_id );
		if ( '' !== $reason ) {
			$segment['excluded'][ $reason ] = ( $segment['excluded'][ $reason ] ?? 0 ) + 1;
			continue;
		}

		$segment['eligible'][] = array(
			'user_id'       => $user_id,
			'last_post_gmt' => $last,
		);
	}

	return $segment;
}

/**
 * Why a dormant participant must not get the email, or '' when eligible.
 *
 * @param int $user_id User ID.
 * @return string Exclusion reason slug, or ''.
 */
function extrachill_community_app_relaunch_exclusion_reason( $user_id ) {
	$user_id = (int) $user_id;
	$user    = get_userdata( $user_id );

	if ( ! $user || empty( $user->user_email ) || ! is_email( $user->user_email ) ) {
		$reason = 'invalid_user';
	} elseif ( extrachill_community_app_relaunch_is_bot( $user ) ) {
		$reason = 'bot';
	} elseif ( extrachill_community_app_relaunch_is_team( $user ) ) {
		$reason = 'team';
	} elseif ( extrachill_community_app_relaunch_opted_out( $user_id ) ) {
		$reason = 'opted_out';
	} elseif ( extrachill_community_app_relaunch_already_sent( $user_id ) ) {
		$reason = 'already_sent';
	} else {
		$reason = '';
	}

	/**
	 * Filter the exclusion reason for a dormant participant.
	 *
	 * Return a non-empty slug (for example `bounced` or `newsletter_unsubscribed`)
	 * to exclude the user from the app relaunch email.
	 *
	 * @param string        $reason  Current reason, '' when eligible.
	 * @param int           $user_id User ID.
	 * @param WP_User|false $user    User object.
	 */
	return sanitize_key( (string) apply_filters( 'extrachill_community_app_relaunch_exclusion_reason', $reason, $user_id, $user ) );
}

/**
 * Automation, spam, deleted or blocked accounts.
 *
 * @param WP_User $user User.
 * @return bool
 */
function extrachill_community_app_relaunch_is_bot( $user ) {
	if ( function_exists( 'ec_get_network_bot_user_id' ) && (int) ec_get_network_bot_user_id() === (int) $user->ID ) {
		return true;
	}
	if ( ! empty( $user->spam ) || ! empty( $user->deleted ) ) {
		return true;
	}

	return in_array( 'bbp_blocked', (array) $user->roles, true );
}

/**
 * Team, admin and moderator accounts.
 *
 * @param WP_User $user User.
 * @return bool
 */
function extrachill_community_app_relaunch_is_team( $user ) {
	$user_id = (int) $user->ID;

	if ( function_exists( 'ec_is_team_member' ) && ec_is_team_member( $user_id ) ) {
		return true;
	}
	if ( is_super_admin( $user_id ) ) {
		return true;
	}

	return user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'moderate' );
}

/**
 * Opted out of notification email (the flag the one-tap unsubscribe sets).
 *
 * @param int $user_id User ID.
 * @return bool
 */
function extrachill_community_app_relaunch_opted_out( $user_id ) {
	if ( function_exists( 'ec_notifications_email_user_opted_out' ) ) {
		return (bool) ec_notifications_email_user_opted_out( $user_id );
	}

	return (bool) get_user_meta( (int) $user_id, 'ec_notification_emails_disabled', true );
}

/**
 * Has this user already been sent the relaunch email?
 *
 * @param int $user_id User ID.
 * @return bool
 */
function extrachill_community_app_relaunch_already_sent( $user_id ) {
	return (int) get_user_meta( (int) $user_id, EXTRACHILL_COMMUNITY_APP_RELAUNCH_SENT_META, true ) > 0;
}

/**
 * Queue the email for one user, claiming the sent marker first.
 *
 * @param int        $user_id  User ID.
 * @param array|null $topic    Last thread (title, url) or null.
 * @param array      $links    Store links.
 * @param callable   $queue_cb Queue implementation.
 * @param int        $now      Reference Unix time.
 * @return string `sent`, `already_sent`, `excluded` or `failed`.
 */
function extrachill_community_app_relaunch_send_one( $user_id, $topic, array $links, $queue_cb, $now ) {
	$user_id = (int) $user_id;

	// Re-check at dispatch time: a user may have unsubscribed since the scan.
	$reason = extrachill_community_app_relaunch_exclusion_reason( $user_id );
	if ( 'already_sent' === $reason ) {
		return 'already_sent';
	}
	if ( '' !== $reason ) {
		return 'excluded';
	}

	// Claim the marker atomically; a concurrent run loses the unique add.
	if ( ! add_user_meta( $user_id, EXTRACHILL_COMMUNITY_APP_RELAUNCH_SENT_META, (int) $now, true ) ) {
		return 'already_sent';
	}

	$user     = get_userdata( $user_id );
	$email    = extrachill_community_app_relaunch_build_email( $user, $topic, $links );
	$envelope = call_user_func( $queue_cb, $email );

	if ( is_array( $envelope ) && ! empty( $envelope['success'] ) ) {
		return 'sent';
	}

	// Release the claim so a later run can retry this user.
	delete_user_meta( $user_id, EXTRACHILL_COMMUNITY_APP_RELAUNCH_SENT_META );
	$error = is_wp_error( $envelope ) ? $envelope->get_error_message() : ( is_array( $envelope ) && isset( $envelope['error'] ) && is_scalar( $envelope['error'] ) ? (string) $envelope['error'] : 'queue failed' );
	error_log( sprintf( 'extrachill_community_app_relaunch: enqueue failed for user %d: %s', $user_id, substr( $error, 0, 200 ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational logging.

	return 'failed';
}

/**
 * Build the ec_send_email_queued() arguments for one user.
 *
 * @param WP_User    $user  Recipient.
 * @param array|null $topic Last thread (title, url) or null.
 * @param array      $links Store links.
 * @return array
 */
function extrachill_community_app_relaunch_build_email( $user, $topic, array $links ) {
	$subject   = __( 'The Extra Chill Community has an app now', 'extra-chill-community' );
	$title     = is_array( $topic ) ? trim( (string) ( $topic['title'] ?? '' ) ) : '';
	$topic_url = is_array( $topic ) ? (string) ( $topic['url'] ?? '' ) : '';
	$home_url  = function_exists( 'ec_get_site_url' ) ? trailingslashit( (string) ec_get_site_url( 'community' ) ) : home_url( '/' );

	$body = '';
	if ( '' !== $title ) {
		$body .= '<p>' . sprintf(
			/* translators: %s: title of the user's last Community thread. */
			esc_html__( 'Last time you were around, you were talking in "%s".', 'extra-chill-community' ),
			esc_html( $title )
		) . '</p>';
	}
	$body .= '<p>' . esc_html__( 'The Community has an app now: replies reach your phone, so you won\'t miss it when someone answers.', 'extra-chill-community' ) . '</p>';
	$body .= '<p><a href="' . esc_url( $links['app_store_url'] ) . '">' . esc_html__( 'Get it on the App Store', 'extra-chill-community' ) . '</a>'
		. ' &middot; <a href="' . esc_url( $links['play_url'] ) . '">' . esc_html__( 'Get it on Google Play', 'extra-chill-community' ) . '</a></p>';

	if ( function_exists( 'ec_notifications_email_unsubscribe_footer_html' ) ) {
		$body .= ec_notifications_email_unsubscribe_footer_html( (int) $user->ID );
	} elseif ( function_exists( 'ec_notifications_unsubscribe_url' ) ) {
		$body .= '<p style="font-size:12px;color:#888;margin-top:24px;">'
			. esc_html__( 'Don\'t want these emails?', 'extra-chill-community' ) . ' '
			. '<a href="' . esc_url( ec_notifications_unsubscribe_url( (int) $user->ID ) ) . '" style="color:#888;">'
			. esc_html__( 'Unsubscribe', 'extra-chill-community' ) . '</a>.</p>';
	}

	return array(
		'to'       => $user->user_email,
		'subject'  => $subject,
		'template' => 'extrachill/branded',
		'context'  => array(
			'subject_html'   => esc_html( $subject ),
			'recipient_name' => $user->display_name,
			'body_html'      => $body,
			'cta_url'        => '' !== $topic_url ? $topic_url : $home_url,
			'cta_label'      => '' !== $topic_url ? __( 'Pick up where you left off', 'extra-chill-community' ) : __( 'Visit the Community', 'extra-chill-community' ),
			'preheader'      => __( 'Replies now reach your phone.', 'extra-chill-community' ),
		),
	);
}

/**
 * Every Community poster with their latest topic/reply time.
 *
 * Runs in the Community blog context.
 *
 * @return array<int, array{user_id:int, last_post_gmt:string}>
 */
function extrachill_community_app_relaunch_query_participants() {
	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT post_author AS user_id, MAX(post_date_gmt) AS last_post_gmt
		FROM {$wpdb->posts}
		WHERE post_type IN ('topic','reply') AND post_status IN ('publish','closed') AND post_author > 0
		GROUP BY post_author
		ORDER BY last_post_gmt DESC",
		ARRAY_A
	);

	return array_map(
		static function ( $row ) {
			return array(
				'user_id'       => (int) $row['user_id'],
				'last_post_gmt' => (string) $row['last_post_gmt'],
			);
		},
		(array) $rows
	);
}

/**
 * The thread a user last posted in.
 *
 * Runs in the Community blog context.
 *
 * @param int $user_id User ID.
 * @return array{title:string, url:string}|null
 */
function extrachill_community_app_relaunch_last_topic( $user_id ) {
	global $wpdb;

	$post = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT ID, post_type, post_parent FROM {$wpdb->posts}
			WHERE post_author = %d AND post_type IN ('topic','reply') AND post_status IN ('publish','closed')
			ORDER BY post_date_gmt DESC LIMIT 1",
			(int) $user_id
		)
	);
	if ( ! $post ) {
		return null;
	}

	$topic_id = (int) $post->ID;
	if ( 'reply' === $post->post_type ) {
		$topic_id = (int) get_post_meta( (int) $post->ID, '_bbp_topic_id', true );
		if ( $topic_id <= 0 ) {
			$topic_id = (int) $post->post_parent;
		}
	}

	$topic = $topic_id > 0 ? get_post( $topic_id ) : null;
	if ( ! $topic || ! in_array( $topic->post_status, array( 'publish', 'closed' ), true ) ) {
		return null;
	}

	return array(
		'title' => html_entity_decode( (string) get_the_title( $topic ), ENT_QUOTES, 'UTF-8' ),
		'url'   => (string) get_permalink( $topic ),
	);
}
