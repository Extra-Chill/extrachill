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
 * Giveaway entry modes.
 *
 * - per_comment: every qualifying comment is one entry, so a person with more
 *   qualifying comments holds proportionally more of the pool. This matches
 *   "more tags = more entries" giveaway rules.
 * - per_user: every person with at least one qualifying comment holds exactly
 *   one entry.
 */
const EC_STUDIO_GIVEAWAY_ENTRY_MODES  = array( 'per_comment', 'per_user' );
const EC_STUDIO_GIVEAWAY_DEFAULT_MODE = 'per_comment';

/**
 * Normalize a requested entry mode, falling back to the default.
 *
 * @param mixed $mode Requested entry mode.
 * @return string
 */
function ec_studio_normalize_giveaway_entry_mode( $mode ): string {
	return is_string( $mode ) && in_array( $mode, EC_STUDIO_GIVEAWAY_ENTRY_MODES, true )
		? $mode
		: EC_STUDIO_GIVEAWAY_DEFAULT_MODE;
}

/**
 * Filter giveaway entries from a list of normalized comments.
 *
 * Rules:
 *   - Optionally require @mentions (tag a friend), at least `min_tags` each.
 *   - Exclude specific usernames (e.g. previously drawn winners).
 *   - `entry_mode` decides whether each qualifying comment is an entry
 *     (per_comment, default) or each qualifying person is one entry
 *     (per_user). A comment that fails the rules never counts for its author,
 *     so in per_user mode a person's entry is their first qualifying comment.
 *
 * @param array    $comments          Normalized SocialComment arrays.
 * @param array    $rules             Giveaway rules: require_tag, min_tags, entry_mode.
 * @param string[] $exclude_usernames Usernames to exclude.
 * @return array{ entries: array, stats: array }
 */
function ec_studio_filter_giveaway_entries( array $comments, array $rules, array $exclude_usernames = array() ): array {
	$require_tag     = ! empty( $rules['require_tag'] );
	$min_tags        = max( 1, absint( $rules['min_tags'] ?? 1 ) );
	$entry_mode      = ec_studio_normalize_giveaway_entry_mode( $rules['entry_mode'] ?? null );
	$exclude_set     = array_flip( array_map( 'strtolower', $exclude_usernames ) );
	$entries_by_user = array();
	$entries         = array();

	foreach ( $comments as $comment ) {
		$username = strtolower( $comment['author_username'] ?? '' );

		// Exclude specified usernames.
		if ( isset( $exclude_set[ $username ] ) ) {
			continue;
		}

		// Must have enough @mentions if required.
		if ( $require_tag && count( $comment['mentions'] ?? array() ) < $min_tags ) {
			continue;
		}

		// One entry per person in per_user mode.
		if ( 'per_user' === $entry_mode && isset( $entries_by_user[ $username ] ) ) {
			continue;
		}

		$entries_by_user[ $username ] = ( $entries_by_user[ $username ] ?? 0 ) + 1;
		$entries[]                    = $comment;
	}

	arsort( $entries_by_user );

	return array(
		'entries' => $entries,
		'stats'   => array(
			'entry_mode'      => $entry_mode,
			'total_comments'  => count( $comments ),
			'valid_entries'   => count( $entries ),
			'filtered_out'    => count( $comments ) - count( $entries ),
			'unique_users'    => count( $entries_by_user ),
			'entries_by_user' => $entries_by_user,
		),
	);
}

/**
 * Crypto-secure random selection of winners.
 *
 * Every entry has the same chance of being drawn. Winners are distinct
 * people: once someone wins, their remaining entries leave the pool, so the
 * result holds at most one winner per username.
 *
 * @param array $entries Filtered valid entries.
 * @param int   $count   Number of winners to pick.
 * @return array Selected entries (subset of $entries), in draw order.
 */
function ec_studio_pick_giveaway_winners( array $entries, int $count ): array {
	$pool    = array_values( $entries );
	$winners = array();
	$drawn   = 0;

	while ( $drawn < $count && ! empty( $pool ) ) {
		$pool_size = count( $pool );
		$winner    = $pool[ random_int( 0, $pool_size - 1 ) ];
		$winners[] = $winner;
		++$drawn;

		$username = strtolower( $winner['author_username'] ?? '' );
		$pool     = array_values(
			array_filter(
				$pool,
				static function ( $entry ) use ( $winner, $username ) {
					if ( '' === $username ) {
						return $entry !== $winner;
					}
					return strtolower( $entry['author_username'] ?? '' ) !== $username;
				}
			)
		);
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
				'description'         => __( 'Pick random winners from Instagram post comments and optionally announce them. By default every qualifying comment is one entry (entry_mode per_comment); use per_user for one entry per person.', 'extrachill-studio' ),
				'category'            => 'extrachill',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'media_input'  => array(
							'type'        => 'string',
							'description' => __( 'Instagram post URL, shortcode, or numeric media ID.', 'extrachill-studio' ),
						),
						'entry_mode'   => array(
							'type'        => 'string',
							'enum'        => EC_STUDIO_GIVEAWAY_ENTRY_MODES,
							'description' => __( 'How entries are counted. per_comment (default): every qualifying comment is one entry, so more tagging comments mean more chances. per_user: every qualifying person is one entry. Every entry in the pool has equal odds; winners are always distinct people.', 'extrachill-studio' ),
							'default'     => EC_STUDIO_GIVEAWAY_DEFAULT_MODE,
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
function ec_studio_execute_run_giveaway( array $input ) {
	$media_input  = $input['media_input'] ?? '';
	$require_tag  = $input['require_tag'] ?? true;
	$min_tags     = max( 1, absint( $input['min_tags'] ?? 1 ) );
	$entry_mode   = ec_studio_normalize_giveaway_entry_mode( $input['entry_mode'] ?? null );
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
		'entry_mode'  => $entry_mode,
	);
	$filtered = ec_studio_filter_giveaway_entries( $comments, $rules );
	$entries  = $filtered['entries'];
	$stats    = $filtered['stats'];

	if ( empty( $entries ) ) {
		return new \WP_Error(
			'no_valid_entries',
			sprintf( 'No valid entries after filtering. %d comments, %d filtered out.', $stats['total_comments'], $stats['filtered_out'] ),
			array(
				'status' => 404,
				'stats'  => $stats,
			)
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
			'rank'           => $index + 1,
			'username'       => $username,
			'comment_id'     => $winner['id'] ?? '',
			'comment_text'   => $winner['text'] ?? '',
			'mentions'       => $winner['mentions'] ?? array(),
			'announced'      => false,
			'reply_id'       => null,
			'announce_error' => null,
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
		'media_id'   => $media_id,
		'platform'   => $platform,
		'entry_mode' => $entry_mode,
		'winners'    => $winners,
		'stats'      => $stats,
		'partial'    => ! empty( $comments_result['data']['partial'] ),
		'pages'      => $comments_result['data']['pages'] ?? null,
	);
}

ec_studio_register_run_giveaway_ability();
