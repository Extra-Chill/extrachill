<?php
/**
 * TinyMCE Editor Customization
 *
 * Customizes TinyMCE editor for bbPress forms with styling and functionality enhancements.
 *
 * @package ExtraChillCommunity
 * @subpackage ForumFeatures\Content\Editor
 */

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

function bbp_load_custom_autosave_plugin($plugins) {
    if (is_bbpress()) {
        $autosave_plugin_url = EXTRACHILL_COMMUNITY_PLUGIN_URL . '/bbpress/autosave/plugin.min.js';
        $plugins['autosave'] = $autosave_plugin_url;
    }
    return $plugins;
}
add_filter('mce_external_plugins', 'bbp_load_custom_autosave_plugin');

/**
 * Autosave triggers 1.5s after typing stops, clears draft on form submission
 */
function bbp_autosave_tinymce_settings($init) {
    if (is_bbpress()) {
        $init['autosave_ask_before_unload'] = false;
        $init['autosave_interval'] = '999999s'; 
        $init['autosave_prefix'] = 'bbp-tinymce-autosave-{path}{query}-{id}-';
        $init['autosave_restore_when_empty'] = true;
        $init['autosave_retention'] = '43200m';

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

function extrachill_output_tinymce_setup_script() {
    if (!is_bbpress()) {
        return;
    }
    ?>
    <script type="text/javascript">
    window.extrachillTinymceSetup = function(editor) {
        var debounceTimer;
        var saveDelay = 1500;
        editor.on('input keyup', function(e) {
            var nonTriggerKeys = [ 33, 34, 35, 36, 37, 38, 39, 40 ];
            if (e && e.keyCode && nonTriggerKeys.includes(e.keyCode)) {
                 return;
            }

            if (editor.plugins.autosave && typeof editor.plugins.autosave.storeDraft === 'function') {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function() {
                    if (!editor.removed) {
                        try {
                             editor.plugins.autosave.storeDraft();
                        } catch (saveError) {
                             console.error('TinyMCE autosave error:', saveError);
                        }
                    }
                }, saveDelay);
            }
        });
        var form = editor.getElement().closest('form');
        if (form) {
            form.addEventListener('submit', function() {
                if (editor.plugins.autosave && typeof editor.plugins.autosave.removeDraft === 'function') {
                    if (!editor.removed) {
                         try {
                              editor.plugins.autosave.removeDraft(false);
                         } catch (clearError) {
                              console.error('TinyMCE clear draft error:', clearError);
                         }
                    }
                }
            }, false);
        }
    };
    </script>
    <?php
}
add_action('wp_footer', 'extrachill_output_tinymce_setup_script', 99);
