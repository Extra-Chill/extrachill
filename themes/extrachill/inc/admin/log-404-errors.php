<?php

// Create or update the custom table for logging 404 errors if it doesn't exist
function create_404_log_table_once() {
    // Check if we've already created the table (using WordPress option)
    if (get_option('404_log_table_created') === 'yes') {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . '404_log';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        url varchar(255) NOT NULL,
        referrer varchar(255) DEFAULT '' NOT NULL,
        user_agent text NOT NULL,
        ip_address varchar(100) NOT NULL,
        PRIMARY KEY (id),
        INDEX time_idx (time),
        INDEX url_idx (url(50))
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Log the result for debugging
    if ($wpdb->last_error) {
        error_log("Error creating 404_log table: " . $wpdb->last_error);
    } else {
        error_log("404_log table created/updated successfully.");
        // Mark as created so we don't check again
        update_option('404_log_table_created', 'yes');
    }
}

add_action('init', 'create_404_log_table_once');


// Log 404 errors
function log_404_errors() {
    if (is_404()) {
        $url = esc_url($_SERVER['REQUEST_URI']);
        // Skip logging if the URL starts with /event/
        if (preg_match('/^\/event\//', $url)) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . '404_log';

        // Only create table if it hasn't been created yet
        if (get_option('404_log_table_created') !== 'yes') {
            create_404_log_table_once();
        }

        $referrer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '';
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $time = current_time('mysql');

        $result = $wpdb->insert(
            $table_name,
            array(
                'time' => $time,
                'url' => $url,
                'referrer' => $referrer,
                'user_agent' => $user_agent,
                'ip_address' => $ip_address
            )
        );

        // Log any errors for debugging
        if ($result === false) {
            error_log("Error inserting 404 log: " . $wpdb->last_error);
        } else {
            error_log("404 log inserted successfully for URL: $url");
        }
    }
}

add_action('template_redirect', 'log_404_errors');


// Schedule a daily event to send the 404 error log email
function schedule_404_log_email() {
    if (!wp_next_scheduled('send_404_log_email')) {
        wp_schedule_event(time(), 'daily', 'send_404_log_email');
    }
}

add_action('wp', 'schedule_404_log_email');

// Send the 404 error log email
function send_404_log_email() {
    global $wpdb;
    $table_name = $wpdb->prefix . '404_log';

    $results = $wpdb->get_results("SELECT * FROM $table_name WHERE DATE(time) = CURDATE()");

    if ($results) {
        $message = "Here are the 404 errors logged today:\n\n";
        $newest_time = '';

        foreach ($results as $row) {
            $message .= "{$row->time} - {$row->url}\n";
            $message .= !empty($row->referrer) ? "Referrer: {$row->referrer}\n" : "Referrer: N/A\n";
            $message .= !empty($row->user_agent) ? "User Agent: {$row->user_agent}\n" : "User Agent: N/A\n";
            $message .= !empty($row->ip_address) ? "IP Address: {$row->ip_address}\n" : "IP Address: N/A\n";
            $message .= "\n";

            // Update the newest_time with the latest time from the results
            if ($newest_time < $row->time) {
                $newest_time = $row->time;
            }
        }

        $admin_email = get_option('admin_email');
        $subject = "Daily 404 Error Log";

        wp_mail($admin_email, $subject, $message);

        // Clear the log for the day and any entries older than the newest_time
        if ($newest_time) {
            $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE time <= %s", $newest_time));
        }
    }
}

add_action('send_404_log_email', 'send_404_log_email');
