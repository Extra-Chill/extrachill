<?php
function bbp_enable_visual_editor( $args = array() ) {
    $args['tinymce'] = true;
    $args['quicktags'] = false;
    $args['teeny'] = false;
    return $args;
}
add_filter( 'bbp_after_get_the_content_parse_args', 'bbp_enable_visual_editor' );

function bbp_tinymce_paste_plain_text( $plugins = array() ) {
    $plugins[] = 'paste';
    // Ensure the autosave plugin is added to the list of TinyMCE plugins
    return $plugins;
}
add_filter( 'bbp_get_tiny_mce_plugins', 'bbp_tinymce_paste_plain_text' );

function customize_tinymce_buttons($buttons) {
    // Clear the existing buttons array
    $buttons = array();
    
    // Add desired buttons in the order you want them to appear
    $buttons[] = 'formatselect';
    $buttons[] = 'bold';
    $buttons[] = 'italic';
    $buttons[] = 'underline';
    $buttons[] = 'strikethrough';
    $buttons[] = 'image';
    // Add the restoredraft button to allow users to restore autosaved content
    $buttons[] = 'restoredraft';
    
    return $buttons;
}
add_filter('mce_buttons', 'customize_tinymce_buttons');

function customize_tinymce_block_formats($init_array) {
    $init_array['block_formats'] = 'Paragraph=p;Header 1=h1;Header 2=h2;Header 3=h3;Header 4=h4';
    return $init_array;
}
add_filter('tiny_mce_before_init', 'customize_tinymce_block_formats');

function load_custom_tinymce_plugin($plugins) {
    // Define the URL of the autosave plugin.js file
    $autosave_plugin_url = get_stylesheet_directory_uri() . '/bbpress/autosave/plugin.min.js';

    // Add the autosave plugin to the list of plugins
    $plugins['autosave'] = $autosave_plugin_url;

    return $plugins;
}
add_filter('mce_external_plugins', 'load_custom_tinymce_plugin');

// Function to add Autosave specific options
function add_autosave_options_to_tinymce($init) {
    // Adding Autosave options based on the documentation
    $init['autosave_ask_before_unload'] = true; // or false to disable the unload confirmation
    $init['autosave_interval'] = '20s'; // Interval for autosaving
    $init['autosave_prefix'] = 'bbp-tinymce-autosave-{path}{query}-{id}-'; // Prefix for storage keys
    $init['autosave_restore_when_empty'] = false; // Auto restore when editor is empty
    $init['autosave_retention'] = '45m'; // Retention period for the saved content

    return $init;
}
add_filter('tiny_mce_before_init', 'add_autosave_options_to_tinymce');
