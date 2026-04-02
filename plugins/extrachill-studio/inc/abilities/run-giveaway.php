<?php
/**
 * Run Giveaway Ability
 *
 * Full giveaway workflow as a single ability. Composes:
 *   - extrachill/resolve-instagram-media → resolve input to media ID
 *   - datamachine/instagram-read        → fetch all comments
 *   - datamachine/instagram-comment-reply → announce winners
 *
 * @package    ExtraChillStudio
 * @subpackage Abilities
 * @since      0.5.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filter giveaway entries from a list of normalized comments.
 *
 * Rules:
 *   - Deduplicate by username (one entry per person).
 *   - Optionally require @mentions (tag a friend).
 *   - Exclude specific usernames (e.g. previously drawn winners).
 *
 * @param array    $comments          Normalized SocialComment arrays.
 * @param array    $rules             Giveaway rules.
 * @param string[] $exclude_usernames Usernames to exclude (lowercase).
 * @return array{ entries: array, stats: array }
 */
function ec_studio_filter_giveaway_entries( array $comments, array $rules, array $exclude_usernames = array() ): array {
	$require_tag = ! empty( $rules['require_tag'] );
	$min_tags    = max( 1, absint( $rules['min_tags'] ?? 1 ) );
	$exclude_set = array_flip( array_map( 'strtolower', $exclude_usernames ) );
	$seen        = array();
	$entries     = array();

	foreach ( $comments as $comment ) {
		$username = strtolower( $comment['author_username'] ?? '' );

		// Exclude specified usernames.
		if ( isset( $exclude_set[ $username ] ) ) {
			continue;
		}

		// One entry per person.
		if ( isset( $seen[ $username ] ) ) {
			continue;
		}

		// Must have @mentions if required.
		if ( $require_tag ) {
			$mention_count = count( $comment['mentions'] ?? array() );
			if ( $mention_count < $min_tags ) {
				continue;
			}
		}

		$seen[ $username ] = true;
		$entries[]         = $comment;
	}

	return array(
		'entries' => $entries,
		'stats'   => array(
			'total_comments' => count( $comments ),
			'valid_entries'  => count( $entries ),
			'filtered_out'   => count( $comments ) - count( $entries ),
			'unique_users'   => count( $seen ),
		),
	);
}

/**
 * Crypto-secure random selection of winners.
 *
 * @param array $entries Filtered valid entries.
 * @param int   $count   Number of winners to pick.
 * @return array Selected entries (subset of $entries).
 */
function ec_studio_pick_giveaway_winners( array $entries, int $count ): array {
	$total = count( $entries );
	if ( 0 === $total || $count <= 0 ) {
		return array();
	}

	$actual = min( $count, $total );

	// Shuffle using random_int() for crypto-secure selection.
	$indices = range( 0, $total - 1 );
	for ( $i = $total - 1; $i > 0; $i-- ) {
		$j              = random_int( 0, $i );
		$tmp            = $indices[ $i ];
		$indices[ $i ]  = $indices[ $j ];
		$indices[ $j ]  = $tmp;
	}

	$winners = array();
	for ( $i = 0; $i < $actual; $i++ ) {
		$winners[] = $entries[ $indices[ $i ] ];
	}

	return $winners;
}

/**
 * Register the run-giveaway ability.
 */
function ec_studio_register_run_giveaway_ability(): void {
	if ( ! class_exists( 'WP_Ability' ) ) {
		return;
	}

	$register = function () {
		wp_register_ability(
			'extrachill/run-giveaway',
			array(
				'label'               => __( 'Run Giveaway', 'extrachill-studio' ),
				'description'         => __( 'Pick random winners from Instagram post comments and optionally announce them.', 'extrachill-studio' ),
				'category'            => 'extrachill',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'media_input'  => array(
							'type'        => 'string',
							'description' => __( 'Instagram post URL, shortcode, or numeric media ID.', 'extrachill-studio' ),
						),
						'require_tag'  => array(
							'type'        => 'boolean',
							'description' => __( 'Require commenters to tag a friend.', 'extrachill-studio' ),
							'default'     => true,
						),
						'min_tags'     => array(
							'type'        => 'integer',
							'description' => __( 'Minimum number of tagged friends.', 'extrachill-studio' ),
							'default'     => 1,
							'minimum'     => 1,
						),
						'winner_count' => array(
							'type'        => 'integer',
							'description' => __( 'Number of winners to pick.', 'extrachill-studio' ),
							'default'     => 1,
							'minimum'     => 1,
							'maximum'     => 50,
						),
						'announce'     => array(
							'type'        => 'boolean',
							'description' => __( 'Auto-reply to each winner\'s comment to announce them.', 'extrachill-studio' ),
							'default'     => false,
						),
						'message'      => array(
							'type'        => 'string',
							'description' => __( 'Announcement message template. Use {username} as placeholder.', 'extrachill-studio' ),
							'default'     => 'Congratulations @{username}, you won the giveaway! Check your DMs for details.',
						),
					),
					'required'   => array( 'media_input' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'winners' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'stats'   => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => 'ec_studio_execute_run_giveaway',
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	};

	if ( doing_action( 'wp_abilities_api_init' ) ) {
		$register();
	} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
		add_action( 'wp_abilities_api_init', $register );
	}
}

/**
 * Execute callback for run-giveaway.
 *
 * @param array $input Input parameters.
 * @return array|\WP_Error Result.
 */
function ec_studio_execute_run_giveaway( array $input ): array|\WP_Error {
	$media_input  = $input['media_input'] ?? '';
	$require_tag  = $input['require_tag'] ?? true;
	$min_tags     = max( 1, absint( $input['min_tags'] ?? 1 ) );
	$winner_count = max( 1, min( 50, absint( $input['winner_count'] ?? 1 ) ) );
	$announce     = ! empty( $input['announce'] );
	$message_tpl  = $input['message'] ?? 'Congratulations @{username}, you won the giveaway! Check your DMs for details.';

	// Step 1: Resolve media input to numeric ID.
	$resolve_ability = wp_get_ability( 'extrachill/resolve-instagram-media' );
	if ( ! $resolve_ability ) {
		return new \WP_Error( 'ability_missing', 'extrachill/resolve-instagram-media ability not available.', array( 'status' => 500 ) );
	}

	$resolved = $resolve_ability->execute( array( 'input' => $media_input ) );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}

	$media_id = $resolved['media_id'] ?? '';
	$platform = $resolved['platform'] ?? 'instagram';

	if ( empty( $media_id ) ) {
		return new \WP_Error( 'resolve_failed', 'Could not resolve media input to an ID.', array( 'status' => 400 ) );
	}

	// Step 2: Fetch all comments.
	$read_ability = wp_get_ability( 'datamachine/instagram-read' );
	if ( ! $read_ability ) {
		return new \WP_Error( 'ability_missing', 'datamachine/instagram-read ability not available.', array( 'status' => 500 ) );
	}

	$comments_result = $read_ability->execute( array(
		'action'   => 'comments_all',
		'media_id' => $media_id,
	) );

	if ( is_wp_error( $comments_result ) ) {
		return $comments_result;
	}

	$comments = $comments_result['data']['comments'] ?? array();

	if ( empty( $comments ) ) {
		return new \WP_Error( 'no_comments', 'No comments found on this post.', array( 'status' => 404 ) );
	}

	// Step 3: Filter entries.
	$rules    = array(
		'require_tag' => $require_tag,
		'min_tags'    => $min_tags,
	);
	$filtered = ec_studio_filter_giveaway_entries( $comments, $rules );
	$entries  = $filtered['entries'];
	$stats    = $filtered['stats'];

	if ( empty( $entries ) ) {
		return new \WP_Error(
			'no_valid_entries',
			sprintf( 'No valid entries after filtering. %d comments, %d filtered out.', $stats['total_comments'], $stats['filtered_out'] ),
			array( 'status' => 404, 'stats' => $stats )
		);
	}

	// Step 4: Pick winners.
	$winners_raw = ec_studio_pick_giveaway_winners( $entries, $winner_count );
	$winners     = array();

	// Step 5: Optionally announce each winner.
	$reply_ability = $announce ? wp_get_ability( 'datamachine/instagram-comment-reply' ) : null;

	foreach ( $winners_raw as $index => $winner ) {
		$username = $winner['author_username'] ?? 'winner';
		$message  = str_replace( '{username}', $username, $message_tpl );

		$winner_data = array(
			'rank'            => $index + 1,
			'username'        => $username,
			'comment_id'      => $winner['id'] ?? '',
			'comment_text'    => $winner['text'] ?? '',
			'mentions'        => $winner['mentions'] ?? array(),
			'announced'       => false,
			'reply_id'        => null,
			'announce_error'  => null,
		);

		if ( $announce && $reply_ability && ! empty( $winner['id'] ) ) {
			$reply_result = $reply_ability->execute( array(
				'comment_id' => $winner['id'],
				'message'    => $message,
			) );

			if ( is_wp_error( $reply_result ) ) {
				$winner_data['announce_error'] = $reply_result->get_error_message();
			} else {
				$winner_data['announced'] = true;
				$winner_data['reply_id']  = $reply_result['data']['reply_id'] ?? null;
			}
		}

		$winners[] = $winner_data;
	}

	return array(
		'media_id' => $media_id,
		'platform' => $platform,
		'winners'  => $winners,
		'stats'    => $stats,
		'partial'  => ! empty( $comments_result['data']['partial'] ),
		'pages'    => $comments_result['data']['pages'] ?? null,
	);
}

ec_studio_register_run_giveaway_ability();
