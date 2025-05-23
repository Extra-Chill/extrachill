<?php
/**
 * Handles the weekly performance email for extrachill.link pages.
 *
 * - Schedules a weekly cron job.
 * - Fetches link pages opted into weekly emails.
 * - Gathers analytics data for the past week.
 * - Generates and sends HTML email summaries.
 *
 * @package ExtrchCo
 */

defined( 'ABSPATH' ) || exit;

// --- WP Cron Scheduling ---

// Define a custom cron schedule if not already defined (e.g., by another part of the theme/plugin)
if ( ! wp_next_scheduled( 'extrch_send_weekly_link_page_performance_emails_hook' ) ) {
    // Schedule to run weekly. For testing, you might change this to 'hourly' or a shorter interval.
    // WordPress's 'weekly' schedule runs once per week.
    wp_schedule_event( time(), 'weekly', 'extrch_send_weekly_link_page_performance_emails_hook' );
}

// Hook our main function to the scheduled event
add_action( 'extrch_send_weekly_link_page_performance_emails_hook', 'extrch_process_weekly_performance_emails' );

/**
 * Main function to process and send weekly performance emails.
 * Iterates through users and sends one consolidated email per user for all their opted-in link pages.
 *
 * @param int|null $debug_target_user_id If provided, only process for this specific user ID (for debugging).
 */
function extrch_process_weekly_performance_emails( $debug_target_user_id = null ) {
    $users_data_for_email = []; // Stores [ user_email => [ 'display_name' => string, 'link_pages_data' => [ link_page_data_1, ... ] ] ]

    $user_objects_to_process = [];
    if ( $debug_target_user_id && $user_object = get_userdata( absint( $debug_target_user_id ) ) ) {
        $user_objects_to_process[] = $user_object;
    } else if (!$debug_target_user_id) {
        // Get all users who might have band profiles linked.
        $user_query_args = array(
            'meta_query' => array(
                array(
                    'key'     => '_band_profile_ids', // User meta linking user to band_profile post IDs
                    'compare' => 'EXISTS',
                ),
            ),
            'fields' => 'all_with_meta',
        );
        $user_objects_to_process = get_users( $user_query_args );
    }

    if ( empty( $user_objects_to_process ) ) {
        // error_log("Weekly Email: No users found with linked band profiles or debug_target_user_id is invalid.");
        return;
    }

    $end_date   = current_time('Y-m-d');
    $start_date = date('Y-m-d', strtotime('-6 days', current_time('timestamp')));

    foreach ( $user_objects_to_process as $user_object ) {
        if ( ! $user_object || empty( $user_object->user_email ) ) {
            continue;
        }

        $user_email = $user_object->user_email;
        $user_display_name = $user_object->display_name;

        $band_profile_ids_for_user = get_user_meta( $user_object->ID, '_band_profile_ids', true );
        
        // Ensure $band_profile_ids_for_user is an array
        if ( !is_array( $band_profile_ids_for_user ) ) {
            $band_profile_ids_for_user = !empty( $band_profile_ids_for_user ) ? array( $band_profile_ids_for_user ) : array();
        }

        if ( empty( $band_profile_ids_for_user ) ) {
            continue; // User not linked to any band profiles
        }

        $user_specific_link_pages_data = [];

        foreach ( $band_profile_ids_for_user as $band_profile_id_raw ) {
            $band_profile_id = absint( $band_profile_id_raw );
            if ( ! $band_profile_id || get_post_type( $band_profile_id ) !== 'band_profile' ) {
                continue;
            }

            $link_page_id = get_post_meta( $band_profile_id, '_extrch_link_page_id', true );
            if ( ! $link_page_id || get_post_type( $link_page_id ) !== 'band_link_page' ) {
                continue;
            }

            $is_notification_enabled = get_post_meta( $link_page_id, '_link_page_enable_weekly_notifications', true );
            if ( $is_notification_enabled !== '1' ) {
                continue; // Skip if this link page notifications are not enabled
            }

            $analytics_data = extrch_fetch_link_page_analytics_for_email( $link_page_id, $start_date, $end_date );
            
            $band_post = get_post( $band_profile_id );
            if ($band_post) { // Ensure band_post is valid
                $analytics_data['band_name'] = get_the_title( $band_profile_id );
                $analytics_data['band_slug'] = $band_post->post_name;
                $analytics_data['link_page_public_url'] = 'https://extrachill.link/' . $band_post->post_name;
                $manage_url = add_query_arg( 'band_id', $band_profile_id, site_url('/manage-link-page/') );
                $analytics_data['manage_link_page_url'] = $manage_url;
                $analytics_data['analytics_tab_url'] = add_query_arg( 'tab', 'analytics', $manage_url );
                $analytics_data['settings_tab_url'] = add_query_arg( 'tab', 'advanced', $manage_url );
                $user_specific_link_pages_data[] = $analytics_data;
            }
        }

        if ( ! empty( $user_specific_link_pages_data ) ) {
            $users_data_for_email[$user_email] = [
                'display_name'      => $user_display_name,
                'link_pages_data'   => $user_specific_link_pages_data,
            ];
        }
    }

    // Send one consolidated email per user
    foreach ( $users_data_for_email as $email_address => $user_data ) {
        if ( empty( $user_data['link_pages_data'] ) ) {
            continue;
        }
        
        $email_subject = sprintf( esc_html__( 'Your Weekly extrachill.link Performance Summary', 'generatepress_child' ) );
        $email_body    = extrch_generate_consolidated_performance_email_html( $user_data['display_name'], $user_data['link_pages_data'] );

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        $from_email = 'stats@extrachill.link';
        $headers[] = 'From: Extra Chill Stats <' . $from_email . '>';

        if ( $debug_target_user_id ) { // If debugging a specific user, send to admin
            $admin_email = get_option('admin_email');
            $mail_sent = wp_mail( $admin_email, "[CONSOLIDATED TEST UserID: {$debug_target_user_id}] " . $email_subject, $email_body, $headers );
            $debug_message = "Consolidated weekly performance TEST email for User ID: {$debug_target_user_id} attempted. wp_mail() returned: " . ($mail_sent ? 'true' : 'false') . ". Sent to ADMIN: " . $admin_email;
            error_log($debug_message);
            wp_die($debug_message);
        } else {
            $mail_sent = wp_mail( $email_address, $email_subject, $email_body, $headers );
            if (!$mail_sent) {
                error_log("Consolidated weekly performance email FAILED to send to: {$email_address}");
            }
            // error_log("Consolidated weekly performance email sent to: {$email_address}");
        }
    }
}

/**
 * Gathers data and sends a performance email for a single link page.
 *
 * @param int $link_page_id The ID of the band_link_page post.
 */
function extrch_send_single_link_page_performance_email( $link_page_id ) {
    $band_profile_id = get_post_meta( $link_page_id, '_associated_band_profile_id', true );
    if ( ! $band_profile_id ) {
        // error_log("Weekly Email: Could not find associated band_profile_id for link_page_id: " . $link_page_id);
        return;
    }

    $band_post = get_post( $band_profile_id );
    if ( ! $band_post || $band_post->post_type !== 'band_profile' ) {
        // error_log("Weekly Email: Invalid band_profile post for ID: " . $band_profile_id);
        return;
    }
    $band_name = get_the_title( $band_profile_id );
    $band_slug = $band_post->post_name;
    $link_page_public_url = 'https://extrachill.link/' . $band_slug;

    // 1. Get recipient email addresses
    $recipient_emails = array();
    $band_member_ids = get_post_meta( $band_profile_id, '_band_member_ids', true ); // Assuming this meta stores user IDs
    
    // Fallback or alternative: get user IDs from '_band_profile_ids' user meta
    if ( empty( $band_member_ids ) ) {
        $user_query = new WP_User_Query( array(
            'meta_key' => '_band_profile_ids',
            'meta_value' => $band_profile_id,
            'meta_compare' => 'LIKE', // If stored as serialized array, e.g. a:1:{i:0;s:2:"ID";} or just the ID
            'fields' => 'ID',
        ) );
        $user_ids = $user_query->get_results();
        if (!empty($user_ids)) {
            $band_member_ids = $user_ids;
        }
    }


    if ( ! empty( $band_member_ids ) && is_array( $band_member_ids ) ) {
        foreach ( $band_member_ids as $user_id ) {
            $user_info = get_userdata( $user_id );
            if ( $user_info && ! empty( $user_info->user_email ) ) {
                $recipient_emails[] = $user_info->user_email;
            }
        }
    }
    
    $recipient_emails = array_unique( $recipient_emails );

    if ( empty( $recipient_emails ) ) {
        // error_log("Weekly Email: No recipient emails found for band_profile_id: " . $band_profile_id);
        return;
    }

    // 2. Fetch analytics data for the past week
    // Dates for the last 7 days
    $end_date   = current_time('Y-m-d');
    $start_date = date('Y-m-d', strtotime('-6 days', current_time('timestamp'))); // Past 7 days including today

    $analytics_data = extrch_fetch_link_page_analytics_for_email( $link_page_id, $start_date, $end_date );

    // 3. Generate HTML Email Content
    $email_subject = sprintf( esc_html__( 'Your Weekly extrachill.link Performance: %s', 'generatepress_child' ), $band_name );
    $email_body    = extrch_generate_performance_email_html( $band_name, $link_page_public_url, $analytics_data, $link_page_id, $band_profile_id );

    // 4. Send Email
    $headers = array( 'Content-Type: text/html; charset=UTF-8' );
    $from_email = 'stats@extrachill.link'; 
    $headers[] = 'From: Extra Chill Stats <' . $from_email . '>';
    
    // For testing with manual trigger, send to admin only
    if (defined('EXTRCH_DEBUG_WEEKLY_EMAIL_TO_ADMIN') && EXTRCH_DEBUG_WEEKLY_EMAIL_TO_ADMIN === true) {
        $admin_email = get_option('admin_email');
        $mail_sent = wp_mail( $admin_email, "[TEST] " . $email_subject, $email_body, $headers );
        $debug_message = "Weekly performance TEST email for link page ID: {$link_page_id} attempted. wp_mail() returned: " . ($mail_sent ? 'true' : 'false') . ". Sent to ADMIN: " . $admin_email;
        error_log($debug_message);
        // wp_die is removed from here to be consistent with the consolidated debug trigger below, which is more critical.
    } else {
        // Regular email sending to all recipients
        foreach($recipient_emails as $email) {
            wp_mail( $email, $email_subject, $email_body, $headers );
        }
        // error_log("Weekly performance email sent for link page ID: {$link_page_id} to: " . implode(", ", $recipient_emails));
    }
}

/**
 * Fetches and summarizes analytics data for the email.
 *
 * @param int    $link_page_id The ID of the band_link_page.
 * @param string $start_date   Start date in Y-m-d format.
 * @param string $end_date     End date in Y-m-d format.
 * @return array               Aggregated analytics data.
 */
function extrch_fetch_link_page_analytics_for_email( $link_page_id, $start_date, $end_date ) {
    global $wpdb;
    $views_table = $wpdb->prefix . 'extrch_link_page_daily_views';
    $clicks_table = $wpdb->prefix . 'extrch_link_page_daily_link_clicks';

    error_log("[ANALYTICS DEBUG] Fetching for Link Page ID: {$link_page_id}, Start: {$start_date}, End: {$end_date}");

    // Link Page Analytics
    $raw_total_views = $wpdb->get_var( $wpdb->prepare(
        "SELECT SUM(view_count) FROM {$views_table} WHERE link_page_id = %d AND stat_date BETWEEN %s AND %s",
        $link_page_id, $start_date, $end_date
    ) );
    error_log("[ANALYTICS DEBUG] Raw Total Views from DB: " . print_r($raw_total_views, true));

    $raw_total_clicks = $wpdb->get_var( $wpdb->prepare(
        "SELECT SUM(click_count) FROM {$clicks_table} WHERE link_page_id = %d AND stat_date BETWEEN %s AND %s",
        $link_page_id, $start_date, $end_date
    ) );
    error_log("[ANALYTICS DEBUG] Raw Total Clicks from DB: " . print_r($raw_total_clicks, true));

    $raw_top_links_results = $wpdb->get_results( $wpdb->prepare(
        "SELECT link_url, SUM(click_count) as total_clicks 
         FROM {$clicks_table} 
         WHERE link_page_id = %d AND stat_date BETWEEN %s AND %s 
         GROUP BY link_url 
         ORDER BY total_clicks DESC 
         LIMIT 3",
        $link_page_id, $start_date, $end_date
    ), ARRAY_A );
    error_log("[ANALYTICS DEBUG] Raw Top Links from DB: " . print_r($raw_top_links_results, true));

    // Forum Activity Analytics
    $forum_id = 0;
    $raw_new_topics_count = 0;
    $raw_new_replies_count = 0;
    $recent_topic_titles = array();

    $band_profile_id = get_post_meta( $link_page_id, '_associated_band_profile_id', true );
    if ( $band_profile_id ) {
        $forum_id = get_post_meta( $band_profile_id, '_band_forum_id', true );
        error_log("[ANALYTICS DEBUG] Band Profile ID: {$band_profile_id}, Forum ID: {$forum_id}");
    } else {
        error_log("[ANALYTICS DEBUG] No Band Profile ID found for Link Page ID: {$link_page_id}");
    }

    if ( $forum_id ) {
        $datetime_start = $start_date . ' 00:00:00';
        $datetime_end   = $end_date   . ' 23:59:59';
        error_log("[ANALYTICS DEBUG] Forum Query Datetime Start: {$datetime_start}, End: {$datetime_end}");

        // New Topics Count
        $raw_new_topics_count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(ID) FROM {$wpdb->posts} 
             WHERE post_type = %s AND post_status = %s AND post_parent = %d 
             AND post_date >= %s AND post_date <= %s",
            'topic', 'publish', $forum_id, $datetime_start, $datetime_end
        ) );
        error_log("[ANALYTICS DEBUG] Raw New Topics Count from DB: " . print_r($raw_new_topics_count, true));

        // New Replies Count
        $raw_new_replies_count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->posts} topic ON p.post_parent = topic.ID
             WHERE p.post_type = %s AND p.post_status = %s 
             AND topic.post_type = %s AND topic.post_parent = %d
             AND p.post_date >= %s AND p.post_date <= %s",
            'reply', 'publish', 'topic', $forum_id, $datetime_start, $datetime_end
        ) );
        error_log("[ANALYTICS DEBUG] Raw New Replies Count from DB: " . print_r($raw_new_replies_count, true));
        
        // Recent Topic Titles
        $recent_topics = get_posts(array(
            'post_type' => 'topic',
            'post_status' => 'publish',
            'post_parent' => $forum_id,
            'date_query' => array(
                array(
                    'after'     => $start_date, // Using Y-m-d format for date_query
                    'before'    => $end_date,
                    'inclusive' => true,
                ),
            ),
            'posts_per_page' => 2,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'id=>parent', 
        ));
        error_log("[ANALYTICS DEBUG] Raw Recent Topics from get_posts: " . print_r($recent_topics, true));
        foreach($recent_topics as $topic) {
            $recent_topic_titles[] = get_the_title($topic->ID);
        }
    } else {
        error_log("[ANALYTICS DEBUG] No Forum ID found or Forum ID is 0, skipping forum stats.");
    }

    return array(
        'total_views'         => $raw_total_views ? (int) $raw_total_views : 0,
        'total_clicks'        => $raw_total_clicks ? (int) $raw_total_clicks : 0,
        'top_links'           => $raw_top_links_results ? $raw_top_links_results : array(),
        'start_date'          => $start_date,
        'end_date'            => $end_date,
        'new_topics_count'    => $raw_new_topics_count ? (int) $raw_new_topics_count : 0,
        'new_replies_count'   => $raw_new_replies_count ? (int) $raw_new_replies_count : 0,
        'recent_topic_titles' => $recent_topic_titles,
    );
}

/**
 * Generates the HTML content for the weekly performance email.
 *
 * @param string $band_name
 * @param string $link_page_public_url
 * @param array  $analytics_data
 * @param int    $link_page_id
 * @param int    $band_profile_id
 * @return string HTML email content.
 */
function extrch_generate_performance_email_html( $band_name, $link_page_public_url, $analytics_data, $link_page_id, $band_profile_id ) {
    // Styles aligned with root.css (light mode)
    $styles = "
        body { font-family: Arial, sans-serif; color: #000000; margin: 0; padding: 0; background-color: #f4f4f4; }
        .email-container { max-width: 600px; margin: 20px auto; background-color: #ffffff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .header { background-color: #000000; color: #ffffff; padding: 15px 20px; text-align: center; border-top-left-radius: 8px; border-top-right-radius: 8px; }
        .header h1 { margin: 0; font-size: 24px; color: #ffffff; }
        .content { padding: 20px; line-height: 1.6; }
        .content h2 { color: #0b5394; font-size: 20px; margin-bottom: 15px; }
        .stat-item { margin-bottom: 15px; background-color: #f8fafc; padding: 12px 15px; border-radius: 4px; border: 1px solid #eeeeee; }
        .stat-item strong { font-size: 18px; color: #000000; }
        .top-links ul { list-style: none; padding-left: 0; margin-top: 5px; }
        .top-links li { margin-bottom: 8px; padding-bottom: 8px; color: #333333; }
        .top-links li:not(:last-child) { border-bottom: 1px solid #eeeeee; }
        .top-links a { color: #0b5394; text-decoration:none; }
        .top-links a:hover { text-decoration:underline; }
        .cta-button { display: inline-block; background-color: #0b5394; color: #ffffff !important; padding: 10px 20px; text-decoration: none !important; border-radius: 5px; margin-top: 20px; font-weight: bold; }
        .cta-button:hover { background-color: #083b6c; }
        .footer { text-align: center; margin-top: 20px; padding-top: 15px; border-top: 1px solid #dddddd; font-size: 0.9em; color: #6b7280; }
        .footer a { color: #0b5394; text-decoration:none; }
        .footer a:hover { text-decoration:underline; }
        p { margin-bottom: 1em; }
    ";

    $manage_link_page_url = add_query_arg( 'band_id', $band_profile_id, site_url('/manage-link-page/') );
    $analytics_tab_url = add_query_arg( 'tab', 'analytics', $manage_link_page_url ); // Assuming tab system uses ?tab=
    $settings_tab_url = add_query_arg( 'tab', 'advanced', $manage_link_page_url );

    $formatted_start_date = date_i18n( get_option( 'date_format' ), strtotime( $analytics_data['start_date'] ) );
    $formatted_end_date   = date_i18n( get_option( 'date_format' ), strtotime( $analytics_data['end_date'] ) );

    ob_start();
    ?><!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php printf( esc_html__( 'Weekly Performance: %s', 'generatepress_child' ), esc_html( $band_name ) ); ?></title>
        <style type="text/css"><?php echo $styles; // Inline styles for email clients ?></style>
    </head>
    <body>
        <div class="email-container">
            <div class="header">
                <h1><?php esc_html_e( 'Weekly Link Page Summary', 'generatepress_child' ); ?></h1>
            </div>
            <div class="content">
                <p><?php printf( esc_html__( 'Hi %s,', 'generatepress_child' ), esc_html( $band_name ) ); ?></p>
                <p><?php printf( esc_html__( 'Here is the performance summary for your extrachill.link page (%1$s) for the week of %2$s - %3$s:', 'generatepress_child' ), '<a href="' . esc_url( $link_page_public_url ) . '">' . esc_html( $link_page_public_url ) . '</a>', esc_html( $formatted_start_date ), esc_html( $formatted_end_date ) ); ?></p>

                <div class="stat-item">
                    <?php esc_html_e( 'Total Page Views:', 'generatepress_child' ); ?>
                    <strong><?php echo number_format_i18n( $analytics_data['total_views'] ); ?></strong>
                </div>

                <div class="stat-item">
                    <?php esc_html_e( 'Total Link Clicks:', 'generatepress_child' ); ?>
                    <strong><?php echo number_format_i18n( $analytics_data['total_clicks'] ); ?></strong>
                </div>

                <?php if ( ! empty( $analytics_data['top_links'] ) ) : ?>
                    <h2><?php esc_html_e( 'Top Clicked Links This Week:', 'generatepress_child' ); ?></h2>
                    <div class="top-links">
                        <ul>
                            <?php foreach ( $analytics_data['top_links'] as $link ) : ?>
                                <li>
                                    <?php echo esc_html( $link['link_url'] ); ?>: 
                                    <strong><?php echo number_format_i18n( $link['total_clicks'] ); ?> <?php esc_html_e( 'clicks', 'generatepress_child' ); ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <h2><?php esc_html_e( 'Your Band Forum Activity This Week:', 'generatepress_child' ); ?></h2>
                <div class="stat-item">
                    <?php esc_html_e( 'New Topics Started:', 'generatepress_child' ); ?>
                    <strong><?php echo number_format_i18n( $analytics_data['new_topics_count'] ); ?></strong>
                </div>
                <div class="stat-item">
                    <?php esc_html_e( 'New Replies Posted:', 'generatepress_child' ); ?>
                    <strong><?php echo number_format_i18n( $analytics_data['new_replies_count'] ); ?></strong>
                </div>
                <?php if ( ! empty( $analytics_data['recent_topic_titles'] ) ) : ?>
                    <p><strong><?php esc_html_e( 'Recent discussions:', 'generatepress_child' ); ?></strong></p>
                    <div class="top-links">
                        <ul>
                            <?php foreach ( $analytics_data['recent_topic_titles'] as $topic_title ) : ?>
                                <li><?php echo esc_html( $topic_title ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php elseif ($analytics_data['new_topics_count'] > 0 || $analytics_data['new_replies_count'] > 0) : ?>
                     <p><?php esc_html_e( 'Check out your band forum to see the latest posts!', 'generatepress_child' ); ?></p>
                <?php else : ?>
                    <p><?php esc_html_e( 'No new topics or replies in your band forum this week. Time to start a conversation!', 'generatepress_child' ); ?></p>
                <?php endif; ?>

                <p style="text-align:center;">
                    <a href="<?php echo esc_url( $analytics_tab_url ); ?>" class="cta-button"><?php esc_html_e( 'View Full Analytics', 'generatepress_child' ); ?></a>
                </p>
            </div>
            <div class="footer">
                <p><?php printf( esc_html__( 'To change your email preferences, visit your %s.', 'generatepress_child' ), '<a href="' . esc_url( $settings_tab_url ) . '">Link Page Settings</a>' ); ?></p>
                <p>&copy; <?php echo date('Y'); ?> <?php bloginfo('name'); ?></p>
            </div>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

/**
 * Generates the HTML content for the CONSOLIDATED weekly performance email.
 *
 * @param string $user_display_name The display name of the user.
 * @param array  $all_link_pages_data Array of analytics data for each link page.
 * @return string HTML email content.
 */
function extrch_generate_consolidated_performance_email_html( $user_display_name, $all_link_pages_data ) {
    // Styles aligned with root.css (light mode)
    $styles = "
        body { font-family: Arial, sans-serif; color: #000000; margin: 0; padding: 0; background-color: #f4f4f4; }
        .email-container { max-width: 600px; margin: 20px auto; background-color: #ffffff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .header { background-color: #000000; color: #ffffff; padding: 15px 20px; text-align: center; border-top-left-radius: 8px; border-top-right-radius: 8px; }
        .header h1 { margin: 0; font-size: 24px; color: #ffffff; }
        .content { padding: 20px; line-height: 1.6; }
        .band-section { margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px dashed #dddddd; }
        .band-section:last-child { border-bottom: none; }
        .band-section h2 { color: #0b5394; font-size: 22px; margin-top:0; margin-bottom: 15px; }
        .band-section h3 { color: #333333; font-size: 18px; margin-bottom: 10px; }
        .stat-item { margin-bottom: 15px; background-color: #f8fafc; padding: 12px 15px; border-radius: 4px; border: 1px solid #eeeeee; }
        .stat-item strong { font-size: 18px; color: #000000; }
        .top-links ul { list-style: none; padding-left: 0; margin-top: 5px; }
        .top-links li { margin-bottom: 8px; padding-bottom: 8px; color: #333333;}
        .top-links li:not(:last-child) { border-bottom: 1px solid #eeeeee; }
        .top-links a { color: #0b5394; text-decoration:none; }
        .top-links a:hover { text-decoration:underline; }
        .cta-button { display: inline-block; background-color: #0b5394; color: #ffffff !important; padding: 10px 20px; text-decoration: none !important; border-radius: 5px; margin-top: 10px; margin-right:10px; font-weight: bold; }
        .cta-button:hover { background-color: #083b6c; } /* Using button-hover-bg from root.css */
        .footer { text-align: center; margin-top: 20px; padding-top: 15px; border-top: 1px solid #dddddd; font-size: 0.9em; color: #6b7280; }
        .footer a { color: #0b5394; text-decoration:none; }
        .footer a:hover { text-decoration:underline; }
        p { margin-bottom: 1em; }
    ";

    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php esc_html_e( 'Your Weekly extrachill.link Performance Summary', 'generatepress_child' ); ?></title>
        <style type="text/css"><?php echo $styles; ?></style>
    </head>
    <body>
        <div class="email-container">
            <div class="header">
                <h1><?php esc_html_e( 'Weekly Performance Summary', 'generatepress_child' ); ?></h1>
            </div>
            <div class="content">
                <p><?php printf( esc_html__( 'Hi %s,', 'generatepress_child' ), esc_html( $user_display_name ) ); ?></p>
                <p><?php esc_html_e( 'Here is your performance summary for your extrachill.link pages and associated band forums for the past week:', 'generatepress_child' ); ?></p>

                <?php foreach ( $all_link_pages_data as $data ) : ?>
                    <div class="band-section">
                        <h2><?php echo esc_html( $data['band_name'] ); ?></h2>
                        
                        <h3><?php printf( esc_html__( 'Link Page: %s', 'generatepress_child' ), '<a href="' . esc_url( $data['link_page_public_url'] ) . '">' . esc_html( str_replace("https://", "", $data['link_page_public_url'])) . '</a>' ); ?></h3>
                        <div class="stat-item">
                            <?php esc_html_e( 'Total Page Views:', 'generatepress_child' ); ?>
                            <strong><?php echo number_format_i18n( $data['total_views'] ); ?></strong>
                        </div>
                        <div class="stat-item">
                            <?php esc_html_e( 'Total Link Clicks:', 'generatepress_child' ); ?>
                            <strong><?php echo number_format_i18n( $data['total_clicks'] ); ?></strong>
                        </div>
                        <?php if ( ! empty( $data['top_links'] ) ) : ?>
                            <p><strong><?php esc_html_e( 'Top Clicked Links:', 'generatepress_child' ); ?></strong></p>
                            <div class="top-links">
                                <ul>
                                    <?php foreach ( $data['top_links'] as $link ) : ?>
                                        <li>
                                            <?php echo esc_html( $link['link_url'] ); ?>: 
                                            <strong><?php echo number_format_i18n( $link['total_clicks'] ); ?> <?php esc_html_e( 'clicks', 'generatepress_child' ); ?></strong>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <h3><?php esc_html_e( 'Band Forum Activity:', 'generatepress_child' ); ?></h3>
                        <div class="stat-item">
                            <?php esc_html_e( 'New Topics Started:', 'generatepress_child' ); ?>
                            <strong><?php echo number_format_i18n( $data['new_topics_count'] ); ?></strong>
                        </div>
                        <div class="stat-item">
                            <?php esc_html_e( 'New Replies Posted:', 'generatepress_child' ); ?>
                            <strong><?php echo number_format_i18n( $data['new_replies_count'] ); ?></strong>
                        </div>
                        <?php if ( ! empty( $data['recent_topic_titles'] ) ) : ?>
                            <p><strong><?php esc_html_e( 'Recent discussions:', 'generatepress_child' ); ?></strong></p>
                            <div class="top-links">
                                <ul>
                                    <?php foreach ( $data['recent_topic_titles'] as $topic_title ) : ?>
                                        <li><?php echo esc_html( $topic_title ); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php elseif ($data['new_topics_count'] > 0 || $data['new_replies_count'] > 0) : ?>
                            <p><?php esc_html_e( 'Check out your band forum to see the latest posts!', 'generatepress_child' ); ?></p>
                        <?php else : ?>
                            <p><?php esc_html_e( 'No new activity in this band forum this week.', 'generatepress_child' ); ?></p>
                        <?php endif; ?>
                        <p style="margin-top:15px;">
                            <a href="<?php echo esc_url( $data['analytics_tab_url'] ); ?>" class="cta-button"><?php esc_html_e( 'View Full Analytics', 'generatepress_child' ); ?></a>
                            <a href="<?php echo esc_url( $data['settings_tab_url'] ); ?>" class="cta-button" style="background-color:#6c757d;"><?php esc_html_e( 'Manage Settings', 'generatepress_child' ); ?></a>
                        </p>
                    </div> <!-- .band-section -->
                <?php endforeach; ?>
            </div>
            <div class="footer">
                <p><?php esc_html_e( 'You are receiving this email because you opted in for weekly performance summaries for one or more of your extrachill.link pages.', 'generatepress_child' ); ?></p>
                <p><?php printf( esc_html__( 'To change email preferences for a specific link page, please visit its settings page via the "Manage Settings" button above or by managing your bands on %s.', 'generatepress_child' ), '<a href="' . esc_url(site_url()) . '">' . esc_html(get_bloginfo('name')) . '</a>' ); ?></p>
                <p>&copy; <?php echo date('Y'); ?> <?php bloginfo('name'); ?></p>
            </div>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

// New Admin trigger for consolidated user email
add_action( 'admin_init', function() {
    if ( isset( $_GET['debug_send_consolidated_email_user'] ) && current_user_can('manage_options') ) {
        $user_id_to_test = absint($_GET['debug_send_consolidated_email_user']);
        $user_info = get_userdata($user_id_to_test);

        if ($user_id_to_test && $user_info) {
            error_log("[DEBUG CONSOLIDATED EMAIL] Triggered for User ID: {$user_id_to_test}. User display: {$user_info->display_name}");
            // Call the main processing function, which will handle its own wp_die() with mail status
            extrch_process_weekly_performance_emails($user_id_to_test); 
            // The extrch_process_weekly_performance_emails should call wp_die itself if $debug_target_user_id is set.
            // So, we should not reach here if that function works as expected.
            wp_die("[DEBUG CONSOLIDATED EMAIL] Fallback: Processing finished for User ID: {$user_id_to_test}, but extrch_process_weekly_performance_emails did not call wp_die(). This is unexpected.");
        } else {
            wp_die("[DEBUG CONSOLIDATED EMAIL] Error: Invalid User ID ({$user_id_to_test}) or user not found.");
        }
    }

    // Keep the old trigger for single link page tests, ensuring it doesn't conflict
    if ( isset( $_GET['debug_send_weekly_email'] ) && !isset($_GET['debug_send_consolidated_email_user']) && current_user_can('manage_options') ) {
        $link_page_id_to_test = absint($_GET['debug_send_weekly_email']);
        if ($link_page_id_to_test) {
            define('EXTRCH_DEBUG_WEEKLY_EMAIL_TO_ADMIN', true); 
            // We need to capture the output of extrch_send_single_link_page_performance_email related to mail_sent
            // This function doesn't return it directly, but it logs and we can wp_die here too.
            $admin_email_for_single_test = get_option('admin_email');
            // Temporarily, let's just call it. The internal logging will show wp_mail result.
            extrch_send_single_link_page_performance_email($link_page_id_to_test);
            // The $debug_message is now internal to extrch_send_single_link_page_performance_email
            wp_die('Single link page test email function executed for link page ID: ' . $link_page_id_to_test . '. Check error log for wp_mail() result. Email attempted to admin: ' . $admin_email_for_single_test);
        }
    }
} );

?> 