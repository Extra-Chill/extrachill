<?php

// this code is used to create a custom post type for newsletters and send them to Sendy
function create_newsletter_post_type() {
    register_post_type('newsletter', array(
        'labels' => array(
            'name' => __('Newsletters'),
            'singular_name' => __('Newsletter'),
            'add_new' => __('Create Newsletter')
        ),
        'public' => true,
        'has_archive' => true,
        'rewrite' => array('slug' => 'newsletters'),
        'supports' => array('title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments'),
        'show_in_rest' => true,
        'menu_position' => 6,
        'menu_icon' => 'dashicons-email-alt',
    ));
}
add_action('init', 'create_newsletter_post_type');

function check_conditions($post_id, $post) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return false;
    }
    if (wp_is_post_revision($post_id) || 'newsletter' !== get_post_type($post_id) || 'publish' !== get_post_status($post_id)) {
        return false;
    }
    return true;
}
function prepare_email_content($post) {
    $content = apply_filters('the_content', $post->post_content);
    
    // Ensure images are responsive and add necessary styles
    $content = preg_replace('/<img(.+?)src="(.*?)"(.*?)>/i', '<img$1src="$2"$3 style="height: auto; max-width:100%; object-fit:contain;">', $content);
    
    // Use a regular expression to find all YouTube iframe embeds and replace them with thumbnails
    $content = preg_replace_callback('/<figure[^>]*>\s*<div class="wp-block-embed__wrapper">\s*<iframe[^>]+src="https:\/\/www\.youtube\.com\/embed\/([a-zA-Z0-9_\-]+)[^"]*"[^>]*><\/iframe>\s*<\/div>\s*<\/figure>/s', function($matches) {
        $videoId = $matches[1];  // Capture the YouTube video ID from the iframe
        $videoUrl = "https://www.youtube.com/watch?v=$videoId";  // YouTube video URL
        $thumbnailUrl = "https://img.youtube.com/vi/$videoId/maxresdefault.jpg";  // YouTube thumbnail URL
        return '<a href="' . $videoUrl . '" target="_blank"><img src="' . $thumbnailUrl . '" alt="Watch our video" style="height: auto; max-width: 100%; display: block; margin: 0 auto;"></a>';
    }, $content);

    // Center captions and ensure proper styling for figures and figcaptions
    $content = preg_replace('/<figure([^>]*)>/i', '<figure$1 style="text-align: center; margin: auto;">', $content);
    $content = preg_replace('/<figcaption([^>]*)>/i', '<figcaption$1 style="text-align: center;font-size: 15px;padding:5px;">', $content);

    // Add styles directly to paragraph tags, headings, ordered lists, and unordered lists
    $content = preg_replace('/<p([^>]*)>/i', '<p$1 style="font-size: 16px; line-height:1.75em;">', $content);
    $content = preg_replace('/<h2([^>]*)>/i', '<h2$1 style="text-align: center;">', $content);
    $content = preg_replace('/<(ol|ul)([^>]*)>/i', '<$1$2 style="font-size: 16px; line-height:1.75em;padding-inline-start:20px;">', $content);
    
    // Add styles directly to li tags
    $content = preg_replace('/<li([^>]*)>/i', '<li$1 style="margin: 10px 0;">', $content);

    // Inserting the Extra Chill logo at the top of the email content with inline styles
    $logo = '<a href="https://extrachill.com" style="text-align: center; display: block; margin: 20px auto;border-bottom:2px solid #53940b;"><img src="https://extrachill.com/wp-content/uploads/2023/09/extra-chill-logo-no-bg-1.png" alt="Extra Chill Logo" style="padding-bottom:10px;max-width: 60px; height: auto; display: block; margin: 0 auto;"></a>';
    $content = $logo . $content;  // Prepending the logo to the content

    $subject = $post->post_title;
    $unsubscribe_link = '<p style="text-align: center; margin-top: 20px; font-size: 16px;"><unsubscribe style="color: #666666; text-decoration: none;">Unsubscribe here</unsubscribe></p>';
    $html_template = <<<HTML
<html>
<head>
    <title>{$subject}</title>
</head>
<body style="background: #d8d8d8; font-family: Helvetica, sans-serif; padding: 0; margin: 0; width: 100%; display: flex; justify-content: center; align-items: center;">
    <div style="background: #fff; border: 1px solid #000; max-width: 600px; margin: 20px auto; padding: 0 20px; box-sizing: border-box;">
        {$content}
        <footer style="text-align: center; padding-top: 20px; font-size: 16px; line-height: 1.5em;">
            <p>Read this newsletter & all others on the web at <a href="https://extrachill.com/newsletters">extrachill.com/newsletters</a></p>
            <p>You received this email because you've connected with Extra Chill in some way over the years. Thanks for supporting independent music.</p>
            {$unsubscribe_link}
        </footer>
    </div>
</body>
</html>
HTML;

    return array(
        'subject' => $subject, 
        'html_template' => $html_template, 
        'plain_text' => strip_tags($content)
    );
}








// Meta Box for Sendy Actions
function add_sendy_meta_box() {
    add_meta_box('sendy_meta_box', 'Sendy Integration', 'sendy_meta_box_html', 'newsletter', 'side', 'high');
}

function sendy_meta_box_html($post) {
    wp_nonce_field('sendy_nonce_action', 'sendy_nonce_field');
    echo '<button type="button" class="button button-primary" id="push_to_sendy">Push to Sendy</button>';
    // Add JS to handle the AJAX request on button click
    ?>
<script type="text/javascript">
document.getElementById('push_to_sendy').addEventListener('click', function() {
    var postId = <?php echo json_encode($post->ID); ?>;
    var ajaxUrl = <?php echo json_encode(admin_url('admin-ajax.php')); ?>;
    var data = new URLSearchParams({
        action: 'push_to_sendy_ajax',
        post_id: postId,
        nonce: <?php echo json_encode(wp_create_nonce('push_to_sendy_nonce')); ?>
    });
    fetch(ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: data
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Successfully pushed to Sendy!');
        } else {
            alert('Error: ' + (data.data || 'An undefined error occurred'));
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        alert('Fetch error: ' + error.message);
    });
});

</script>

    <?php
}

add_action('add_meta_boxes', 'add_sendy_meta_box');

// AJAX Handler for Pushing Data to Sendy
function push_to_sendy_ajax() {
    // Check for POST request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        wp_send_json_error('Invalid request type', 400);
        return;
    }

    // Verify Nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'push_to_sendy_nonce')) {
        wp_send_json_error('Nonce verification failed or insufficient permissions', 401);
        return;
    }

    // Verify post ID and permissions
    $post_id = intval($_POST['post_id']);
    if (!$post_id || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error('Invalid post ID or insufficient permissions', 403);
        return;
    }

    // Fetch the post and proceed with sending to Sendy
    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error('Post not found', 404);
        return;
    }

    $email_data = prepare_email_content($post);
    $result = send_campaign_to_sendy($post_id, $email_data);
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message(), 500);
    } else {
        wp_send_json_success('Campaign successfully updated or created');
    }
}
add_action('wp_ajax_push_to_sendy_ajax', 'push_to_sendy_ajax');
add_action('wp_ajax_nopriv_push_to_sendy_ajax', 'push_to_sendy_ajax'); // If needed


function send_campaign_to_sendy($post_id, $email_data) {
    $campaign_id = get_post_meta($post_id, '_sendy_campaign_id', true);
    $sendy_url = 'https://mail.extrachill.com/sendy/api/campaigns/status.php';
    $checkData = array(
        'api_key' => 'z7RZLH84oEKNzMvFZhdt',
        'campaign_id' => $campaign_id
    );

    // Initialize cURL session to check if campaign exists
    $ch = curl_init($sendy_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/x-www-form-urlencoded"));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($checkData));
    $exists = curl_exec($ch);
    curl_close($ch);

    // Determine if we should update or create new campaign based on existence check
    if (trim($exists) === 'Campaign exists') {
        $sendy_url = 'https://mail.extrachill.com/sendy/api/campaigns/update.php';
    } else {
        $sendy_url = 'https://mail.extrachill.com/sendy/api/campaigns/create.php';
        $campaign_id = false;  // Reset campaign_id if not found to ensure a new one is created
    }

    // Prepare data for sending or updating the campaign
    $postData = array(
        'api_key' => 'z7RZLH84oEKNzMvFZhdt',
        'from_name' => 'Extra Chill',
        'from_email' => 'newsletter@extrachill.com',
        'reply_to' => 'chubes@extrachill.com',
        'subject' => $email_data['subject'],
        'plain_text' => $email_data['plain_text'],
        'html_text' => $email_data['html_template'],
        'list_ids' => 'L3SqZJUj8NY892RnvQOvMzLA,...',
        'brand_id' => '1'
    );

    if ($campaign_id) {
        $postData['campaign_id'] = $campaign_id;  // Include campaign ID to update existing campaign
    }

    // Send or update the campaign via cURL
    $ch = curl_init($sendy_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/x-www-form-urlencoded"));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    $response = curl_exec($ch);
    curl_close($ch);

    // If a new campaign ID is generated (when creating a new campaign), save it
    if (!$campaign_id && is_numeric($response)) {
        update_post_meta($post_id, '_sendy_campaign_id', $response);
    }

    // Log the response from Sendy for debugging purposes
}


function sync_newsletter_to_sendy($post_id, $post, $update) {
    if (!check_conditions($post_id, $post)) {
        return;
    }
    
    $email_data = prepare_email_content($post);
    send_campaign_to_sendy($post_id, $email_data);
}

function extrachill_submit_newsletter_form() {
    check_ajax_referer('newsletter_nonce', 'nonce');

    $email = sanitize_email($_POST['email']);

    $sendy_url = 'https://mail.extrachill.com/sendy'; // Ensure this URL is correct
    $list_id = 'D763iZceU7My0uwjlBwsTC8A'; // Confirm that this is the correct list ID
    $api_key = 'z7RZLH84oEKNzMvFZhdt'; // Ensure this API key is active and correct

    // Prepare data for the Sendy API call
    $postData = array(
        'email' => $email,
        'list' => $list_id,
        'boolean' => 'true',
        'api_key' => $api_key
    );

    // Initialize cURL session
    $ch = curl_init("$sendy_url/subscribe");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Check for cURL errors first
    if ($curl_error) {
        wp_send_json_error('Curl error: ' . $curl_error);
        return;
    }

    // Check response from Sendy
    if ($response === '1' || strpos($response, 'Success') !== false) {
        wp_send_json_success('Subscribed successfully');
    } else {
        // Log detailed error message
        wp_send_json_error('Failed to subscribe, Sendy response: ' . $response);
    }
}

add_action('wp_ajax_submit_newsletter_form', 'extrachill_submit_newsletter_form');
add_action('wp_ajax_nopriv_submit_newsletter_form', 'extrachill_submit_newsletter_form');

function enqueue_newsletter_popup_scripts() {
    // Define pages where the script should not be loaded
    $excluded_pages = ['contact-us', 'open-mic-signup', 'thank-you', 'cart', 'checkout'];

    // Check if the current page is one of the excluded pages or contains the image voting block (excluding the homepage)
    if ((is_page($excluded_pages) || (is_singular() && has_block('chill-generators/image-voting'))) && !is_front_page()) {
        return; // Do not enqueue the script on excluded pages or pages with the image voting block, unless it's the homepage
    }

    // Only enqueue if the session token is empty
    if (empty($_COOKIE['ecc_user_session_token'])) {
        // Define a version number for the script based on file modification time for cache busting
        $script_version = filemtime(get_template_directory() . '/js/subscribe.js');

        wp_enqueue_script('custom-popup', get_template_directory_uri() . '/js/subscribe.js', array(), $script_version, true);

        wp_localize_script('custom-popup', 'newsletter_vars', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('newsletter_popup_nonce'),
            'isUserLoggedIn' => !empty($_COOKIE['ecc_user_session_token'])  // Updated to check cookie
        ));
    }
}
add_action('wp_enqueue_scripts', 'enqueue_newsletter_popup_scripts');

function submit_newsletter_popup_form() {
    check_ajax_referer('newsletter_popup_nonce', 'nonce');
    $email = sanitize_email($_POST['email']);
    $list_id = 'YormkKgHWtLGWq6I83Av763Q'; // Use your actual Sendy list ID
    $sendy_url = 'https://mail.extrachill.com/sendy'; // Use your actual Sendy installation URL
    $api_key = 'z7RZLH84oEKNzMvFZhdt'; // Replace with your actual Sendy API key

    $response = wp_remote_post("$sendy_url/subscribe", array(
        'body' => array(
            'email' => $email,
            'list' => $list_id,
            'boolean' => 'true',
            'api_key' => $api_key // Including the API key in the request
        )
    ));

    if (wp_remote_retrieve_response_code($response) == 200) {
        wp_send_json_success();
    } else {
        wp_send_json_error(array('data' => 'There was an error with the subscription.'));
    }
}
add_action('wp_ajax_submit_newsletter_popup_form', 'submit_newsletter_popup_form');
add_action('wp_ajax_nopriv_submit_newsletter_popup_form', 'submit_newsletter_popup_form');

// Add Recent Newsletters Shortcode
function recent_newsletters_shortcode() {
    // Query for the 3 most recent newsletters
    $args = array(
        'post_type'      => 'newsletter', // Change this to match your custom post type slug
        'posts_per_page' => 3,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
    );

    $newsletter_query = new WP_Query($args);

    // Start output buffering
    ob_start();

    if ($newsletter_query->have_posts()) {
        echo '<h3 class="widget-title"><span>Recent Newsletters</span></h3>';
        echo '<div class="recent-newsletters-widget">';
        echo '<ul>';

        while ($newsletter_query->have_posts()) {
            $newsletter_query->the_post();
            echo '<li>';
            echo '<a href="' . get_permalink() . '"><b>' . get_the_title() . '</b></a><br>';
            echo '<span class="newsletter-date">Sent on ' . get_the_date() . '</span>';
            echo '</li>';
        }

        echo '</ul>';

        // Add the "View All" link
        echo '<a href="' . get_post_type_archive_link('newsletter') . '" class="view-all-newsletters">View All Newsletters</a>';

        echo '</div>';
    } else {
        echo '<p>No newsletters found.</p>';
    }

    // Reset post data
    wp_reset_postdata();

    // Return the buffered content
    return ob_get_clean();
}

// Register the shortcode
add_shortcode('recent_newsletters', 'recent_newsletters_shortcode');
