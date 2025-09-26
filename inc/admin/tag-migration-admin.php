<?php
/**
 * Tag Migration Admin Tool
 *
 * Usage: Include this file from your theme or plugin. Visit Tools > Tag Migration.
 * After migration, you can safely delete this file.
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', function() {
    add_management_page(
        'Tag Migration',
        'Tag Migration',
        'manage_options',
        'tag-migration-admin',
        'tag_migration_admin_page'
    );
});

function tag_migration_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }

    $per_page = 100;
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $offset = ($paged - 1) * $per_page;

    // Handle migration action
    $report = array();
    if (isset($_POST['tag_migration_action']) && isset($_POST['tag_ids']) && check_admin_referer('tag_migration_action')) {
        $tag_ids = array_map('intval', (array)$_POST['tag_ids']);
        $taxonomy = sanitize_key($_POST['tag_migration_action']);
        if (in_array($taxonomy, array('festival', 'artist', 'venue'))) {
            $report = tag_migration_perform_bulk($tag_ids, $taxonomy);
        }
    }

    // Query tags
    $args = array(
        'taxonomy' => 'post_tag',
        'hide_empty' => false,
        'number' => $per_page,
        'offset' => $offset,
        'orderby' => 'count',
        'order' => 'DESC',
        'search' => $search ? "*{$search}*" : '',
    );
    $tags = get_terms($args);
    $total_tags = wp_count_terms('post_tag', array('hide_empty' => false));
    $total_pages = ceil($total_tags / $per_page);

    // Admin page UI
    echo '<div class="wrap"><h1>Tag Migration</h1>';
    echo '<p>Select tags and migrate them to the desired taxonomy. Tags will be removed from posts and deleted if unused.</p>';

    // Search form
    echo '<form method="get" style="margin-bottom:1em;">';
    echo '<input type="hidden" name="page" value="tag-migration-admin">';
    echo '<input type="text" name="s" value="' . esc_attr($search) . '" placeholder="Search tags...">';
    echo '<input type="submit" class="button" value="Search">';
    echo '</form>';

    // Show report if migration was performed
    if (!empty($report)) {
        echo '<div class="notice notice-success"><ul>';
        foreach ($report as $line) {
            echo '<li>' . esc_html($line) . '</li>';
        }
        echo '</ul></div>';
    }

    // Tag list form
    echo '<form method="post">';
    wp_nonce_field('tag_migration_action');
    echo '<table class="widefat fixed striped">';
    echo '<thead><tr><th style="width:30px"><input type="checkbox" id="select-all-tags"></th><th>Tag Name</th><th>Slug</th><th>Count</th></tr></thead><tbody>';
    foreach ($tags as $tag) {
        echo '<tr>';
        echo '<td><input type="checkbox" name="tag_ids[]" value="' . esc_attr($tag->term_id) . '"></td>';
        echo '<td>' . esc_html($tag->name) . '</td>';
        echo '<td>' . esc_html($tag->slug) . '</td>';
        echo '<td>' . intval($tag->count) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p style="margin-top:1em;">';
    echo '<input type="submit" name="tag_migration_action" class="button button-primary" value="festival" onclick="return confirm(\'Migrate selected tags to Festival?\');"> ';
    echo '<input type="submit" name="tag_migration_action" class="button" value="artist" onclick="return confirm(\'Migrate selected tags to Artist?\');"> ';
    echo '<input type="submit" name="tag_migration_action" class="button" value="venue" onclick="return confirm(\'Migrate selected tags to Venue?\');">';
    echo '</p>';
    echo '</form>';

    // Pagination
    if ($total_pages > 1) {
        echo '<div class="tablenav"><div class="tablenav-pages">';
        for ($i = 1; $i <= $total_pages; $i++) {
            $url = add_query_arg(array('page' => 'tag-migration-admin', 'paged' => $i, 's' => $search), admin_url('tools.php'));
            if ($i == $paged) {
                echo '<span class="current" style="margin:0 4px;font-weight:bold;">' . $i . '</span>';
            } else {
                echo '<a href="' . esc_url($url) . '" style="margin:0 4px;">' . $i . '</a>';
            }
        }
        echo '</div></div>';
    }

    // Select all JS
    echo '<script>document.getElementById("select-all-tags").addEventListener("change",function(e){var cbs=document.querySelectorAll("input[name=\"tag_ids[]\"]");for(var i=0;i<cbs.length;i++){cbs[i].checked=this.checked;}});</script>';
    echo '</div>';
}

function tag_migration_perform_bulk($tag_ids, $taxonomy) {
    $report = array();
    foreach ($tag_ids as $tag_id) {
        $tag = get_term($tag_id, 'post_tag');
        if (!$tag || is_wp_error($tag)) continue;
        // Create taxonomy term if not exists
        $term = term_exists($tag->slug, $taxonomy);
        if (!$term) {
            $term = wp_insert_term($tag->name, $taxonomy, array('slug' => $tag->slug));
        }
        $term_id = is_array($term) ? $term['term_id'] : $term;
        // Get all posts with this tag
        $posts = get_objects_in_term($tag_id, 'post_tag');
        foreach ($posts as $post_id) {
            wp_set_object_terms($post_id, intval($term_id), $taxonomy, true);
            wp_remove_object_terms($post_id, intval($tag_id), 'post_tag');
        }
        // Delete tag if no longer used
        $count = get_term($tag_id, 'post_tag')->count;
        if ($count === 0) {
            wp_delete_term($tag_id, 'post_tag');
            $report[] = sprintf('Migrated and deleted tag "%s" (slug: %s) to %s.', $tag->name, $tag->slug, $taxonomy);
        } else {
            $report[] = sprintf('Migrated tag "%s" (slug: %s) to %s, but it is still used elsewhere.', $tag->name, $tag->slug, $taxonomy);
        }
    }
    return $report;
} 