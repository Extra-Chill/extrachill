<?php
function bbp_enable_visual_editor($args = array()) {
    $args['tinymce'] = true;
    $args['quicktags'] = false;
    $args['teeny'] = false;
    return $args;
}
add_filter('bbp_after_get_the_content_parse_args', 'bbp_enable_visual_editor');

function bbp_tinymce_paste_plain_text($plugins = array()) {
    $plugins[] = 'paste';
    return $plugins;
}
add_filter('bbp_get_tiny_mce_plugins', 'bbp_tinymce_paste_plain_text');

function customize_tinymce_buttons($buttons) {
    $buttons = array();
    $buttons[] = 'bold';
    $buttons[] = 'italic';
    $buttons[] = 'underline';
    $buttons[] = 'strikethrough';
    $buttons[] = 'image';
    $buttons[] = 'blockquote';
    $buttons[] = 'restoredraft';
    $buttons[] = 'undo';
    $buttons[] = 'redo';
    return $buttons;
}
add_filter('mce_buttons', 'customize_tinymce_buttons');

function load_custom_tinymce_plugin($plugins) {
    $autosave_plugin_url = get_stylesheet_directory_uri() . '/bbpress/autosave/plugin.min.js';
    $plugins['autosave'] = $autosave_plugin_url;
    return $plugins;
}
add_filter('mce_external_plugins', 'load_custom_tinymce_plugin');

function add_autosave_options_to_tinymce($init) {
    $init['autosave_ask_before_unload'] = true;
    $init['autosave_interval'] = '20s';
    $init['autosave_prefix'] = 'bbp-tinymce-autosave-{path}{query}-{id}-';
    $init['autosave_restore_when_empty'] = false;
    // Set retention period to 2880 minutes (48 hours)
    $init['autosave_retention'] = '2880m';
    return $init;
}
add_filter('tiny_mce_before_init', 'add_autosave_options_to_tinymce');
?>
