<?php
add_action('wp_insert_post', 'sync_article_to_community_forum', 10, 3);

function sync_article_to_community_forum($post_id, $post, $update) {
    if (!should_sync_article($post_id, $post)) {
        return;
    }

    $community_user_id = map_author_id_to_community_id($post_id);

    if (null === $community_user_id) {
        // Skip syncing if there's no corresponding community user ID
        return;
    }

    $api_url = 'https://community.extrachill.com/wp-json/extrachill/v1/sync-article';
    $credentials = ['username' => 'chubes', 'app_password' => 'RJkvKGQPWybAorJ2xQxFBtbK'];

    $response = sync_article_to_api($post_id, $community_user_id, $api_url, $credentials);

    if (is_wp_error($response)) {
        error_log('Error syncing article to community forum: ' . $response->get_error_message());
        return;
    }

    handle_sync_response($response, $post_id);
}

function should_sync_article($post_id, $post) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE || wp_is_post_revision($post_id) || 'post' !== $post->post_type || $post->post_status !== 'publish') {
        return false;
    }

    if (function_exists('get_coauthors')) {
        $coauthors = get_coauthors($post_id);
        foreach ($coauthors as $coauthor) {
            if ($coauthor->user_nicename === 'saraichinwag') {
                return false;
            }
        }
    } else {
        $author_id = get_post_field('post_author', $post_id);
        if ($author_id && get_the_author_meta('user_nicename', $author_id) === 'saraichinwag') {
            return false;
        }
    }

    // Check if the author is mapped to a community ID
    if (null === map_author_id_to_community_id($post_id)) {
        return false;
    }

    return true;
}

function map_author_id_to_community_id($post_id) {
    $id_mapping = [
        1 => 1, 
        38 => 28, 
        30 => 53, 
        35 => 50, 
        34 => 52, 
        33 => 82, 
        37 => 51, 
        32 => 180,
        40 => 61,
    ];

    $author_ids = [];

    if (function_exists('get_coauthors')) {
        $coauthors = get_coauthors($post_id);
        foreach ($coauthors as $coauthor) {
            $author_ids[] = $coauthor->ID;
        }
    } else {
        $author_ids[] = get_post_field('post_author', $post_id);
    }

    foreach ($author_ids as $author_id) {
        if (isset($id_mapping[$author_id])) {
            return $id_mapping[$author_id];
        }
    }

    return null;
}

function sync_article_to_api($post_id, $community_user_id, $api_url, $credentials) {
    $post_url = get_permalink($post_id);
    $published_date = get_the_date('c', $post_id);

    $payload = json_encode([
        'title' => get_the_title($post_id),
        'content' => apply_filters('the_content', get_post_field('post_content', $post_id)),
        'author_id' => $community_user_id,
        'main_site_post_id' => $post_id,
        'post_url' => $post_url,
        'published_date' => $published_date,
    ]);

    return wp_remote_post($api_url, [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($credentials['username'] . ':' . $credentials['app_password']),
            'Content-Type' => 'application/json',
        ],
        'body' => $payload,
    ]);
}

function handle_sync_response($response, $post_id) {
    $body = wp_remote_retrieve_body($response);
    error_log('Article sync successful: ' . $body);
    $data = json_decode($body, true);

    if (!empty($data['topic_url'])) {
        $topic_url = $data['topic_url'];
        update_post_meta($post_id, 'extrachill_forum_topic_url', $topic_url);
    }
}

function insert_forum_link_into_post($post_id) {
    // Define the same ID mapping as used in your sync function
    $id_mapping = [
        1 => 1,
        38 => 28,
        30 => 53,
        35 => 50,
        34 => 52,
        33 => 82,
        37 => 51,
        32 => 180,
        40 => 61,
    ];

    // Retrieve the author ID for the current post
    $author_id = get_post_field('post_author', $post_id);

    // Check if the author's ID is in the ID mapping
    if (!array_key_exists($author_id, $id_mapping)) {
        // If not, do not proceed with inserting the forum link
        return;
    }

    // Proceed with inserting the forum link if the author is in the map
    $topic_url = get_post_meta($post_id, 'extrachill_forum_topic_url', true);
    if (!empty($topic_url)) {
        echo "<div class='community-cta'>
        <h3>Discuss this post in the Extra Chill Community</h2>
                <p>Read this post and more without ads in the <a href='{$topic_url}' target='_blank'>Extra Chill Community</a>! Share your thoughts, suggest future updates, and connect with other readers of Extra Chill. We hope you like it here, and decide to stick around!</p>
              </div>";
    }
}
add_action('extrachill_insert_forum_link', 'insert_forum_link_into_post', 10, 1);


function extrachill_remove_unmapped_author_postmeta() {
    $id_mapping = [
        // Define the ID mapping here for reference
        1 => 1,
        38 => 28,
        30 => 53,
        35 => 50,
        34 => 52,
        33 => 82,
        37 => 51,
        32 => 180,
        40 => 61,
    ];

    $args = [
        'posts_per_page' => -1, // Process all posts
        'post_type' => 'post', // Adjust if you have custom post types
        'post_status' => 'publish', // Only look at published posts
    ];

    $posts = get_posts($args);

    foreach ($posts as $post) {
        $author_id = $post->post_author;

        if (!array_key_exists($author_id, $id_mapping)) {
            // If the author ID is not in the mapping, delete the post meta
            delete_post_meta($post->ID, 'extrachill_forum_topic_url');
        }
    }
}

// Run the function - comment out after use to avoid re-execution
// extrachill_remove_unmapped_author_postmeta();

add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/author-posts-count/(?P<author_id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'extrachill_get_author_posts_count',
        'permission_callback' => '__return_true',
    ));
});

function extrachill_get_author_posts_count($request) {
    $author_id = $request['author_id'];
    if (!is_numeric($author_id)) {
        return new WP_Error('invalid_author_id', 'Invalid author ID', array('status' => 400));
    }

    $args = array(
        'author' => $author_id,
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => 1,
    );

    $query = new WP_Query($args);
    $total_posts = $query->found_posts;

    return rest_ensure_response(array('post_count' => $total_posts));
}

?>
