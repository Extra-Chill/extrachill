<?php
function huberpress_quick_post_form() {
    // Check for form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Initialize an error array
        $errors = [];

        // Validate title
        if (empty($_POST['bbp_topic_title'])) {
            $errors['bbp_topic_title'] = 'Please enter a title.';
        }

        // Validate content
        if (empty($_POST['bbp_topic_content'])) {
            $errors['bbp_topic_content'] = 'Please enter some content.';
        }

        // Validate forum selection
        if (empty($_POST['bbp_forum_id']) || $_POST['bbp_forum_id'] === '0') {
            $errors['bbp_forum_id'] = 'Please select a forum.';
        }

        // If there are no errors, process the form
        if (empty($errors)) {
            // Process your form: insert the topic, etc.
            // Redirect or display a success message
        }
    }

    // Always show the form (with possible error messages)
    echo '<div id="quick-post-container">';
    echo '<button id="quick-post-toggle">Quick Post</button>';

    echo '<div id="quick-post-form" style="display:none;">';
    echo '<form id="quick-post-topic" name="quick-post-topic" method="post">';

    bbp_topic_form_fields(); // This includes the nonce fields

    // Display errors or set value if the form was submitted
    $title_value = isset($_POST['bbp_topic_title']) ? esc_attr($_POST['bbp_topic_title']) : '';
    $content_value = isset($_POST['bbp_topic_content']) ? esc_textarea($_POST['bbp_topic_content']) : '';

    // Title field with error message
    echo '<p><label for="bbp_topic_title">Title:</label><br />';
    echo '<input type="text" id="bbp_topic_title" name="bbp_topic_title" value="' . $title_value . '" size="40" />';
    if (!empty($errors['bbp_topic_title'])) {
        echo '<span class="error">' . esc_html($errors['bbp_topic_title']) . '</span>';
    }
    echo '</p>';

    // Content field with error message
    echo '<p><label for="bbp_topic_content">Content:</label><br />';
    echo '<textarea id="bbp_topic_content" name="bbp_topic_content" rows="4" cols="40">' . $content_value . '</textarea>';
    if (!empty($errors['bbp_topic_content'])) {
        echo '<span class="error">' . esc_html($errors['bbp_topic_content']) . '</span>';
    }
    echo '</p>';

    // Forum dropdown with error message
    echo '<p><label for="bbp_forum_id">Forum:</label><br />';
    huberpress_custom_forum_dropdown(); // Custom dropdown function call
    if (!empty($errors['bbp_forum_id'])) {
        echo '<span class="error">' . esc_html($errors['bbp_forum_id']) . '</span>';
    }
    echo '</p>';

    echo '<p><input type="submit" id="bbp_topic_submit" name="bbp_topic_submit" value="Submit"></p>';
    echo '</form></div></div>';

    // Inline JavaScript for toggling the form display
    echo "<script>
jQuery(document).ready(function($) {
    $('#quick-post-topic').submit(function(e) {
        var error = false;
        var errorMessage = '';

        // Check title
        if ($('#bbp_topic_title').val().trim() === '') {
            errorMessage += 'Title is required.\n';
            error = true;
        }

        // Check content
        if ($('#bbp_topic_content').val().trim() === '') {
            errorMessage += 'Content is required.\n';
            error = true;
        }

        // Check forum selection
        if ($('#bbp_forum_id').val() === '') {
            errorMessage += 'Please select a forum.\n';
            error = true;
        }

        // If there are errors, prevent form submission and show alert
        if (error) {
            e.preventDefault(); // Prevent form submission
            alert(errorMessage); // Show alert with error messages
        }
        // If no errors, form will submit normally
    });

    $('#quick-post-toggle').click(function() {
        $('#quick-post-form').slideToggle('fast');
    });
});
</script>
";
}

function huberpress_custom_forum_dropdown() {
    $sections = [
        'Community Boards' => 'top',
        'The Rabbit Hole' => 'middle', // This section requires dynamic ordering
        'Specialty Boards' => 'bottom',
    ];

    if (is_user_logged_in() && get_user_meta(get_current_user_id(), 'extrachill_team', true) == '1') {
        $sections['Private'] = 'private';
    }

    echo '<select id="bbp_forum_id" name="bbp_forum_id">';
    echo '<option value="">Select Forum</option>';

    foreach ($sections as $label => $section) {
        $args = [
            'post_type' => bbp_get_forum_post_type(),
            'posts_per_page' => -1,
            'meta_query' => [
                ['key' => '_bbp_forum_section', 'value' => $section, 'compare' => '=']
            ],
            'orderby' => 'menu_order', // Default ordering
            'order' => 'ASC',
        ];

        // For the "Rabbit Hole" section, order by last active time dynamically
        if ($section === 'middle') {
            $args['meta_key'] = '_bbp_last_active_time';
            $args['orderby'] = 'meta_value';
            $args['order'] = 'DESC';
        }

        $forums_query = new WP_Query($args);

        if ($forums_query->have_posts()) {
            echo '<optgroup label="' . esc_attr($label) . '">';
            while ($forums_query->have_posts()) {
                $forums_query->the_post();
                echo '<option value="' . get_the_ID() . '">' . get_the_title() . '</option>';
            }
            echo '</optgroup>';
        }
    }

    echo '</select>';
    wp_reset_postdata();
}




 add_action('bbp_template_before_forums_loop', 'huberpress_quick_post_form');
 add_action('chill_before_user_dashboard', 'huberpress_quick_post_form');
