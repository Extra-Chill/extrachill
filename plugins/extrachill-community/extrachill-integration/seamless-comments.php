<?php
/// seamless-comments.php which lives on community.extrachill.com to serve the comments form on extrachill.com

add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/comments/form', array(
        'methods' => 'GET',
        'callback' => 'serve_comment_form',
        'permission_callback' => '__return_true',
    ));
});

function serve_comment_form(WP_REST_Request $request) {
    header('Content-Type: text/html; charset=utf-8');

    // Directly outputting the HTML content
    echo '
    <div id="community-comment-form" data-post-id="" data-username="" data-email="" data-user-id="">
        <h2>Leave a Comment</h2>
        <form action="https://extrachill.com/wp-json/extrachill/v1/community-comment" method="post">
            <p>Hello, <span id="user-name">Guest</span>!</p>
            <p>
                <label for="comment">Comment:</label>
                <textarea id="comment" name="comment" rows="4" required></textarea>
            </p>
            <input type="hidden" name="comment_parent" id="comment_parent" value="0" />
            <p><input type="submit" value="Post Comment" /></p>
        </form>
    </div>';

    exit; // Ensure no further processing or output occurs after this point
}
