<?php

/**
 * Recent Activity Feed Functions
 * Native multisite integration for displaying community forum activity
 * Replaces REST API calls with direct database queries for improved performance
 *
 * @package ExtraChill
 * @since 69.57
 */
/**
 * Generate compact human-readable time difference strings
 * Uses abbreviated format (e.g., "5m ago", "2h ago") for space efficiency
 *
 * @param int $from Unix timestamp of the past time
 * @param int $to   Unix timestamp of current time (defaults to now)
 * @return string Formatted time difference string
 * @since 69.57
 */
function custom_human_time_diff($from, $to = '') {
    if (empty($to)) {
        $to = time();
    }
    $diff = (int) abs($to - $from);

    if ($diff < MINUTE_IN_SECONDS) {
        $since = sprintf(_n('%ss', '%ss', $diff), $diff);
    } elseif ($diff < HOUR_IN_SECONDS) {
        $minutes = floor($diff / MINUTE_IN_SECONDS);
        $since = sprintf(_n('%sm', '%sm', $minutes), $minutes);
    } elseif ($diff < DAY_IN_SECONDS) {
        $hours = floor($diff / HOUR_IN_SECONDS);
        $since = sprintf(_n('%sh', '%sh', $hours), $hours);
    } elseif ($diff < WEEK_IN_SECONDS) {
        $days = floor($diff / DAY_IN_SECONDS);
        $since = sprintf(_n('%sd', '%sd', $days), $days);
    } elseif ($diff < MONTH_IN_SECONDS) {
        $weeks = floor($diff / WEEK_IN_SECONDS);
        $since = sprintf(_n('%sw', '%sw', $weeks), $weeks);
    } elseif ($diff < YEAR_IN_SECONDS) {
        $months = floor($diff / MONTH_IN_SECONDS);
        $since = sprintf(_n('%smon', '%smon', $months), $months);
    } else {
        $years = floor($diff / YEAR_IN_SECONDS);
        $since = sprintf(_n('%syr', '%syr', $years), $years);
    }
    return $since . __(' ago');
}

/**
 * Fetch recent activity from community site using native multisite functions
 * Direct replacement for REST API calls - provides significant performance improvement
 * Queries bbPress topics and replies from community.extrachill.com via multisite
 *
 * @param int $limit Number of activities to fetch (default: 10)
 * @return array Array of activity data with user, topic, and forum information
 * @since 69.57
 *
 * @throws Exception If multisite operation fails, logs error and returns empty array
 */
function ec_fetch_recent_activity_multisite( $limit = 10 ) {
	if ( ! is_multisite() ) {
		error_log( 'Recent activity multisite error: WordPress multisite not detected' );
		return array();
	}

	$activities = array();

	// Switch to community site (blog ID 2)
	switch_to_blog( 2 );

	try {
		// Query recent bbPress activity
		$args = array(
			'post_type' => array( 'topic', 'reply' ),
			'post_status' => 'publish',
			'posts_per_page' => $limit,
			'orderby' => 'date',
			'order' => 'DESC',
			'meta_query' => array(
				'relation' => 'AND',
				array(
					'key' => '_bbp_forum_id',
					'value' => '1494', // Exclude restricted forum
					'compare' => '!=',
				),
			),
		);

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();
				$post_type = get_post_type( $post_id );

				$forum_id = ( 'reply' === $post_type ) ? get_post_meta( get_post_meta( $post_id, '_bbp_topic_id', true ), '_bbp_forum_id', true ) : get_post_meta( $post_id, '_bbp_forum_id', true );
				$forum_title = get_the_title( $forum_id );
				$forum_url = get_permalink( $forum_id );

				$topic_title = ( 'reply' === $post_type ) ? get_the_title( get_post_meta( $post_id, '_bbp_topic_id', true ) ) : get_the_title( $post_id );
				$topic_url = get_permalink( $post_id );

				$author_id = get_the_author_meta( 'ID' );
				$username = get_the_author();
				$user_profile_url = get_author_posts_url( $author_id );

				$activities[] = array(
					'type' => ( 'reply' === $post_type ) ? 'Reply' : 'Topic',
					'username' => $username,
					'user_profile_url' => $user_profile_url,
					'topic_title' => $topic_title,
					'topic_url' => $topic_url,
					'forum_title' => $forum_title,
					'forum_url' => $forum_url,
					'date_time' => get_the_date( 'c' ),
				);
			}
			wp_reset_postdata();
		}
	} catch ( Exception $e ) {
		error_log( 'Recent activity multisite error: ' . $e->getMessage() );
		$activities = array();
	} finally {
		// Always restore current blog
		restore_current_blog();
	}

	return $activities;
}

/**
 * WordPress shortcode handler for displaying recent community activity
 * Implements 10-minute caching to reduce database queries and improve performance
 *
 * @return string HTML output for recent activity display
 * @since 69.57
 */
function extrachill_recent_activity_shortcode() {
    $transient_name = 'extrachill_recent_activity';
    $activities = get_transient($transient_name);

    if ($activities === false) {
        $activities = ec_fetch_recent_activity_multisite( 10 );

        if ( empty( $activities ) ) {
            return 'Could not retrieve recent activity.';
        }

        set_transient($transient_name, $activities, 10 * MINUTE_IN_SECONDS);
    }

    $output = '<div class="extrachill-recent-activity">';
    if (!empty($activities)) {
        $output .= '<ul>';
        $counter = 0;
        foreach ($activities as $activity) {
            if (!is_array($activity)) {
                continue;
            }
            $dateFormatted = custom_human_time_diff(strtotime($activity['date_time']));
            $counter++;
            if ($activity['type'] === 'Reply') {
                $output .= sprintf(
                    '<li><a href="%s">%s</a> replied to <a id="topic-%d" href="%s">%s</a> in <a href="%s">%s</a> - %s</li>',
                    esc_url($activity['user_profile_url']),
                    esc_html($activity['username']),
                    $counter,
                    esc_url($activity['topic_url']),
                    esc_html($activity['topic_title']),
                    esc_url($activity['forum_url']),
                    esc_html($activity['forum_title']),
                    $dateFormatted
                );
            } else { // Topic
                $output .= sprintf(
                    '<li><a href="%s">%s</a> posted <a id="topic-%d" href="%s">%s</a> in <a href="%s">%s</a> - %s</li>',
                    esc_url($activity['user_profile_url']),
                    esc_html($activity['username']),
                    $counter,
                    esc_url($activity['topic_url']),
                    esc_html($activity['topic_title']),
                    esc_url($activity['forum_url']),
                    esc_html($activity['forum_title']),
                    $dateFormatted
                );
            }
        }
        $output .= '</ul>';
    } else {
        $output .= 'No recent activity found.';
    }

    $output .= '<a id="visit-community" href="https://community.extrachill.com"><button>Visit Community</button></a>';
    $output .= '</div>';

    return $output;
}

add_shortcode('extrachill_recent_activity', 'extrachill_recent_activity_shortcode');
