<?php
function custom_permalinks_redirect() {
    // Get the current URL path and remove query parameters
    $request_uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

    // Path to your exported CSV file
    $csv_file_path = get_template_directory() . '/custom-permalinks.csv';

    // Check if the CSV file exists
    if (!file_exists($csv_file_path)) {
        error_log('CSV file not found: ' . $csv_file_path);
        return;
    }

    // Check if the CSV file exists and open it
    if (($handle = fopen($csv_file_path, "r")) !== FALSE) {
        // Array to hold custom permalinks and their corresponding post IDs
        $custom_permalinks = array();

        // Skip the header row
        fgetcsv($handle, 1000, ",");

        // Read each line in the CSV file
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (isset($data[0]) && isset($data[1])) {
                $post_id = $data[0];
                $custom_permalink = trim($data[1], '/');
                $custom_permalinks[$custom_permalink] = $post_id;
            }
        }
        fclose($handle);

        // Extract the base URL without the feed or other segments
        $base_request_uri = explode('/', $request_uri)[0];

        // Check if the base URL matches any custom permalink
        if (array_key_exists($base_request_uri, $custom_permalinks)) {
            $post_id = $custom_permalinks[$base_request_uri];
            $new_url = get_permalink($post_id);

            if ($new_url) {
                // Reconstruct the target URL, preserving the original segments like /feed
                $new_url_path = trim(parse_url($new_url, PHP_URL_PATH), '/') . substr($request_uri, strlen($base_request_uri));

                // Check if the new URL path is different from the request URI to avoid redirect loops
                if ($new_url_path !== $request_uri) {
                    error_log('Redirecting ' . $request_uri . ' to ' . home_url($new_url_path));
                    // Redirect to the new URL
                    wp_redirect(home_url($new_url_path), 301);
                    exit;
                } else {
                    error_log('Redirect loop detected for ' . $request_uri . '. No redirection applied.');
                }
            } else {
                error_log('Failed to get permalink for post ID: ' . $post_id);
            }
        } else {
        }
    } else {
        error_log('Failed to open CSV file: ' . $csv_file_path);
    }
}
// add_action('template_redirect', 'custom_permalinks_redirect');
