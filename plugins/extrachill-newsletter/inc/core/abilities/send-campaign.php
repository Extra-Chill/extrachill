<?php
/**
 * Send Campaign Ability
 *
 * Sends or schedules a newsletter post to selected Sendy lists (or every
 * list). This plugin owns the policy: building the email from the post,
 * resolving "all lists", the dry-run preview, and the double-send guard. The
 * Sendy transport lives in data-machine-business
 * (`datamachine/sendy-send-campaign`).
 *
 * Sendy's API cannot send an existing draft, so a send always creates a new
 * campaign; the draft tracked by `extrachill/push-campaign` is left alone.
 *
 * @package ExtraChillNewsletter
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_NEWSLETTER_SEND_DEFAULT_TIMEZONE = 'America/New_York';

add_action( 'wp_abilities_api_init', 'extrachill_newsletter_register_send_campaign_ability' );

/**
 * Register the send-campaign ability.
 */
function extrachill_newsletter_register_send_campaign_ability() {
	$id_list = array(
		'type'  => 'array',
		'items' => array( 'type' => 'string' ),
	);

	wp_register_ability(
		'extrachill/send-campaign',
		array(
			'label'               => __( 'Send Campaign', 'extrachill-newsletter' ),
			'description'         => __( 'Send or schedule a newsletter post to selected Sendy lists, or to all lists. Dry run by default: returns the subject, sender, resolved lists and audience size without sending. A real send refuses to repeat unless forced.', 'extrachill-newsletter' ),
			'category'            => 'extrachill-newsletter',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'            => array(
						'type'        => 'integer',
						'description' => __( 'Newsletter post ID to send.', 'extrachill-newsletter' ),
					),
					'list_ids'           => $id_list + array( 'description' => __( 'Sendy list IDs (encrypted hashes) to send to. Use this or all_lists.', 'extrachill-newsletter' ) ),
					'all_lists'          => array(
						'type'        => 'boolean',
						'description' => __( 'Send to every Sendy list. Use this or list_ids.', 'extrachill-newsletter' ),
					),
					'exclude_list_ids'   => $id_list + array( 'description' => __( 'Lists removed from the target set (their subscribers still receive it through any other targeted list).', 'extrachill-newsletter' ) ),
					'schedule_date_time' => array(
						'type'        => 'string',
						'description' => __( 'Optional send time as "Y-m-d H:i" (minutes in multiples of 5). Omit to send now.', 'extrachill-newsletter' ),
					),
					'schedule_timezone'  => array(
						'type'        => 'string',
						'description' => __( 'IANA timezone for schedule_date_time. Defaults to America/New_York.', 'extrachill-newsletter' ),
					),
					'dry_run'            => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => __( 'Preview only (default true). Pass false to actually send.', 'extrachill-newsletter' ),
					),
					'force'              => array(
						'type'        => 'boolean',
						'description' => __( 'Allow a real send even though this post was already sent or scheduled.', 'extrachill-newsletter' ),
					),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'dry_run'      => array( 'type' => 'boolean' ),
					'sent'         => array( 'type' => 'boolean' ),
					'scheduled'    => array( 'type' => 'boolean' ),
					'post_id'      => array( 'type' => 'integer' ),
					'subject'      => array( 'type' => 'string' ),
					'from_name'    => array( 'type' => 'string' ),
					'from_email'   => array( 'type' => 'string' ),
					'lists'        => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'     => array( 'type' => 'string' ),
								'name'   => array( 'type' => 'string' ),
								'active' => array( 'type' => 'integer' ),
							),
						),
					),
					'excluded'     => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'total_active' => array(
						'type'        => 'integer',
						'description' => __( 'Sum of active subscribers across targeted lists, before Sendy dedupes addresses on several lists.', 'extrachill-newsletter' ),
					),
					'schedule'     => array( 'type' => array( 'object', 'null' ) ),
					'previous'     => array( 'type' => array( 'object', 'null' ) ),
					'message'      => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_newsletter_ability_send_campaign',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => true,
				),
			),
		)
	);
}

/**
 * Preview, send, or schedule a newsletter post to Sendy lists.
 *
 * @param array $input See the ability input schema.
 * @return array|WP_Error
 */
function extrachill_newsletter_ability_send_campaign( $input ) {
	$input   = is_array( $input ) ? $input : array();
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$dry_run = ! array_key_exists( 'dry_run', $input ) || false !== $input['dry_run'];
	$force   = ! empty( $input['force'] );

	$post = extrachill_newsletter_send_validate_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$schedule = extrachill_newsletter_send_schedule( $input );
	if ( is_wp_error( $schedule ) ) {
		return $schedule;
	}

	$targets = extrachill_newsletter_send_resolve_lists( $input );
	if ( is_wp_error( $targets ) ) {
		return $targets;
	}

	$previous = extrachill_newsletter_send_previous( $post_id );
	$config   = get_sendy_config();
	$email    = prepare_newsletter_email_content( $post );

	$summary = array(
		'success'      => true,
		'dry_run'      => $dry_run,
		'sent'         => false,
		'scheduled'    => false,
		'post_id'      => $post_id,
		'subject'      => (string) $email['subject'],
		'from_name'    => (string) $config['from_name'],
		'from_email'   => (string) $config['from_email'],
		'lists'        => $targets['lists'],
		'excluded'     => $targets['excluded'],
		'total_active' => $targets['total_active'],
		'schedule'     => $schedule,
		'previous'     => $previous,
	);

	if ( $dry_run ) {
		$summary['message'] = $previous && ! $force
			? __( 'Dry run. This post was already sent or scheduled; a real send needs force.', 'extrachill-newsletter' )
			: __( 'Dry run. Nothing was sent.', 'extrachill-newsletter' );
		return $summary;
	}

	if ( $previous && ! $force ) {
		return new WP_Error(
			'newsletter_already_sent',
			__( 'This newsletter was already sent or scheduled. Pass force to send it again.', 'extrachill-newsletter' ),
			array( 'previous' => $previous )
		);
	}

	$request = array(
		'from_name'  => (string) $config['from_name'],
		'from_email' => (string) $config['from_email'],
		'reply_to'   => (string) $config['reply_to'],
		'subject'    => (string) $email['subject'],
		'plain_text' => (string) $email['plain_text'],
		'html_text'  => (string) $email['html_template'],
		'brand_id'   => (string) $config['brand_id'],
		'title'      => get_the_title( $post ),
		'list_ids'   => wp_list_pluck( $targets['lists'], 'id' ),
	);
	if ( $schedule ) {
		$request['schedule_date_time'] = $schedule['date_time'];
		$request['schedule_timezone']  = $schedule['timezone'];
	}

	$result = extrachill_newsletter_execute_sendy_campaign_ability( 'datamachine/sendy-send-campaign', $request );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( empty( $result['success'] ) ) {
		return new WP_Error(
			'newsletter_send_failed',
			! empty( $result['message'] ) ? (string) $result['message'] : __( 'Sendy did not accept the send.', 'extrachill-newsletter' )
		);
	}

	$scheduled = ! empty( $result['scheduled'] );
	if ( $scheduled ) {
		update_post_meta( $post_id, '_sendy_scheduled_for', $schedule['date_time'] . ' ' . $schedule['timezone'] );
	} else {
		update_post_meta( $post_id, '_sendy_sent_at', gmdate( 'c' ) );
	}
	update_post_meta( $post_id, '_sendy_sent_list_ids', $request['list_ids'] );
	if ( ! empty( $result['campaign_id'] ) ) {
		update_post_meta( $post_id, '_sendy_sent_campaign_id', (string) $result['campaign_id'] );
	}

	$summary['sent']      = ! $scheduled;
	$summary['scheduled'] = $scheduled;
	$summary['message']   = ! empty( $result['message'] ) ? (string) $result['message'] : ( $scheduled ? 'Campaign scheduled' : 'Campaign sending' );

	return $summary;
}

/**
 * Validate that a post is a sendable newsletter.
 *
 * @param int $post_id Post ID.
 * @return WP_Post|WP_Error
 */
function extrachill_newsletter_send_validate_post( $post_id ) {
	if ( ! $post_id ) {
		return new WP_Error( 'missing_post_id', __( 'post_id is required.', 'extrachill-newsletter' ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'post_not_found', __( 'Post not found.', 'extrachill-newsletter' ) );
	}

	if ( 'newsletter' !== $post->post_type ) {
		return new WP_Error( 'invalid_post_type', __( 'Post must be of type newsletter.', 'extrachill-newsletter' ) );
	}

	if ( ! in_array( $post->post_status, array( 'publish', 'draft' ), true ) ) {
		return new WP_Error( 'invalid_post_status', __( 'Only published or draft newsletters can be sent.', 'extrachill-newsletter' ) );
	}

	if ( '' === trim( (string) $post->post_title ) || '' === trim( wp_strip_all_tags( (string) $post->post_content ) ) ) {
		return new WP_Error( 'empty_newsletter', __( 'A newsletter needs a title and content before it can be sent.', 'extrachill-newsletter' ) );
	}

	return $post;
}

/**
 * Normalize the optional schedule.
 *
 * Format validation (Y-m-d H:i, 5-minute steps, future) is the transport's
 * job; this only applies the newsletter's default timezone.
 *
 * @param array $input Ability input.
 * @return array|null|WP_Error {date_time, timezone} or null to send now.
 */
function extrachill_newsletter_send_schedule( array $input ) {
	$when = isset( $input['schedule_date_time'] ) ? trim( (string) $input['schedule_date_time'] ) : '';
	$tz   = isset( $input['schedule_timezone'] ) ? trim( (string) $input['schedule_timezone'] ) : '';

	if ( '' === $when ) {
		if ( '' !== $tz ) {
			return new WP_Error( 'invalid_schedule', __( 'schedule_timezone requires schedule_date_time.', 'extrachill-newsletter' ) );
		}
		return null;
	}

	return array(
		'date_time' => $when,
		'timezone'  => '' !== $tz ? $tz : EXTRACHILL_NEWSLETTER_SEND_DEFAULT_TIMEZONE,
	);
}

/**
 * Resolve target lists against the live Sendy list catalog.
 *
 * Lists come from `extrachill/newsletter-subscriber-stats` on its API path,
 * which returns Sendy's encrypted list IDs (the DB path returns integer
 * primary keys, which the send API does not accept).
 *
 * @param array $input Ability input.
 * @return array|WP_Error {lists, excluded, total_active}.
 */
function extrachill_newsletter_send_resolve_lists( array $input ) {
	$all_lists = ! empty( $input['all_lists'] );
	$requested = extrachill_newsletter_send_id_list( $input, 'list_ids' );
	$excluded  = extrachill_newsletter_send_id_list( $input, 'exclude_list_ids' );

	if ( $all_lists === ! empty( $requested ) ) {
		return new WP_Error( 'invalid_targets', __( 'Pass either list_ids or all_lists, not both and not neither.', 'extrachill-newsletter' ) );
	}

	$stats = extrachill_newsletter_ability_subscriber_stats(
		array(
			'force_refresh' => true,
			'source'        => 'api',
		)
	);
	if ( is_wp_error( $stats ) ) {
		return $stats;
	}

	$catalog = array();
	foreach ( isset( $stats['lists'] ) && is_array( $stats['lists'] ) ? $stats['lists'] : array() as $list ) {
		$id = isset( $list['id'] ) ? (string) $list['id'] : '';
		if ( '' === $id || ctype_digit( $id ) ) {
			continue; // Integer DB keys are not sendable list IDs.
		}
		$catalog[ $id ] = array(
			'id'     => $id,
			'name'   => isset( $list['name'] ) ? (string) $list['name'] : '',
			'active' => isset( $list['active'] ) ? (int) $list['active'] : 0,
		);
	}

	if ( empty( $catalog ) ) {
		return new WP_Error( 'no_sendy_lists', __( 'Could not load Sendy lists with their encrypted IDs.', 'extrachill-newsletter' ) );
	}

	$unknown = array_diff( array_merge( $requested, $excluded ), array_keys( $catalog ) );
	if ( $unknown ) {
		return new WP_Error(
			'unknown_list_ids',
			/* translators: %s: comma-separated list IDs. */
			sprintf( __( 'Unknown Sendy list IDs: %s', 'extrachill-newsletter' ), implode( ', ', $unknown ) )
		);
	}

	$target_ids = $all_lists ? array_keys( $catalog ) : $requested;
	$target_ids = array_values( array_diff( $target_ids, $excluded ) );

	if ( empty( $target_ids ) ) {
		return new WP_Error( 'no_target_lists', __( 'No lists remain after exclusions.', 'extrachill-newsletter' ) );
	}

	$lists = array();
	$total = 0;
	foreach ( $target_ids as $id ) {
		$lists[] = $catalog[ $id ];
		$total  += $catalog[ $id ]['active'];
	}

	$excluded_lists = array();
	foreach ( $excluded as $id ) {
		$excluded_lists[] = $catalog[ $id ];
	}

	return array(
		'lists'        => $lists,
		'excluded'     => $excluded_lists,
		'total_active' => $total,
	);
}

/**
 * Read a deduped list of string IDs from input.
 *
 * @param array  $input Ability input.
 * @param string $key   Input key.
 * @return string[]
 */
function extrachill_newsletter_send_id_list( array $input, $key ) {
	$values = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();
	$values = array_map( 'trim', array_map( 'strval', $values ) );
	return array_values( array_unique( array_filter( $values, 'strlen' ) ) );
}

/**
 * Describe a previous send or schedule of this post, if any.
 *
 * @param int $post_id Post ID.
 * @return array|null
 */
function extrachill_newsletter_send_previous( $post_id ) {
	$sent_at       = (string) get_post_meta( $post_id, '_sendy_sent_at', true );
	$scheduled_for = (string) get_post_meta( $post_id, '_sendy_scheduled_for', true );

	if ( '' === $sent_at && '' === $scheduled_for ) {
		return null;
	}

	$list_ids = get_post_meta( $post_id, '_sendy_sent_list_ids', true );

	return array(
		'sent_at'       => '' !== $sent_at ? $sent_at : null,
		'scheduled_for' => '' !== $scheduled_for ? $scheduled_for : null,
		'list_ids'      => is_array( $list_ids ) ? array_values( $list_ids ) : array(),
	);
}
