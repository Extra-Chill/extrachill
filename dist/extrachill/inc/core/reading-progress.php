<?php
// Add the reading progress bar script on every page
function add_reading_progress_bar() {
    // Enqueue the JavaScript file with dynamic versioning
    wp_enqueue_script(
        'reading-progress-script',
        get_template_directory_uri() . '/js/reading-progress.js',
        array(),
        filemtime(get_template_directory() . '/js/reading-progress.js'),
        true // Load the script in the footer
    );
}
add_action('wp_enqueue_scripts', 'add_reading_progress_bar');
