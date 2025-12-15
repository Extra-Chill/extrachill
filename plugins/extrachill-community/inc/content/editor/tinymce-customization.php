<?php
/**
 * TinyMCE Editor Customization
 *
 * Customizes TinyMCE editor for bbPress forms with styling and functionality enhancements.
 * Skipped when Blocks Everywhere plugin is active (Gutenberg replaces TinyMCE).
 *
 * @package ExtraChillCommunity
 * @subpackage ForumFeatures\Content\Editor
 */

include_once ABSPATH . 'wp-admin/includes/plugin.php';
if (is_plugin_active('blocks-everywhere/blocks-everywhere.php')) {
	return;
}

function bbp_enable_visual_editor($args = array()) {
    $args['tinymce'] = array('content_css' => EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/tinymce-editor.css');
    $args['quicktags'] = false;
    $args['teeny'] = false;
    return $args;
}
add_filter('bbp_after_get_the_content_parse_args', 'bbp_enable_visual_editor', 999);

function bbp_add_tinymce_stylesheet($mce_css) {
    $version = filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/tinymce-editor.css');
    $mce_css .= ', ' . EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/tinymce-editor.css?ver=' . $version;
    return $mce_css;
}
add_filter('mce_css', 'bbp_add_tinymce_stylesheet');

function bbp_tinymce_paste_plugin($plugins = array()) {
    if (is_bbpress()) {
        $plugins[] = 'paste';
    }
    return $plugins;
}
add_filter('bbp_get_tiny_mce_plugins', 'bbp_tinymce_paste_plugin');

function bbp_customize_tinymce_buttons($buttons) {
    if ( is_bbpress() || is_singular('artist_profile') ) {
        $desired_buttons = array(
            'bold',
            'italic',
            'image',
            'blockquote',
            'link', 'unlink',
            'undo',
            'redo',
            'formatselect'
        );
        return $desired_buttons;
    }
    return $buttons;
}
add_filter('mce_buttons', 'bbp_customize_tinymce_buttons', 50);


function bbp_load_mentions_plugin($plugins) {
    if (is_bbpress()) {
        $plugins['extrachillmentionssocial'] = EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/js/extrachill-mentions.js';
    }
    return $plugins;
}
add_filter('mce_external_plugins', 'bbp_load_mentions_plugin');

/**
 * bbPress TinyMCE initialization
 *
 * Adds paste settings and wires the JS setup handler.
 */
function bbp_autosave_tinymce_settings($init) {
    if (is_bbpress()) {
        $init['paste_as_text'] = false;
        $init['paste_auto_cleanup_on_paste'] = true;
        $init['paste_remove_styles'] = true;
        $init['paste_remove_styles_if_webkit'] = true;
        $init['paste_strip_class_attributes'] = 'all';
        $init['paste_retain_style_properties'] = '';

        $init['setup'] = 'extrachillTinymceSetup';
    }
    return $init;
}
add_filter('tiny_mce_before_init', 'bbp_autosave_tinymce_settings');
