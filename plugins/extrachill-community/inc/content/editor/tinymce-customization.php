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
 * Configure autosave and paste handling for bbPress TinyMCE editors
 * Timer-based autosave disabled in favor of manual trigger on typing pause
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

/**
 * Output JavaScript setup function for TinyMCE autosave configuration
 * Triggers draft save 1.5 seconds after typing stops, clears draft on form submission
 */
function extrachill_output_tinymce_setup_script() {
    if (!is_bbpress()) {
        return;
    }
    ?>
    <script type="text/javascript">
    window.extrachillTinymceSetup = function(editor) {
        console.log('[SETUP] Initializing setup for editor:', editor.id);
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
                        console.log('[SAVE] Calling storeDraft for editor:', editor.id);
                        try {
                             editor.plugins.autosave.storeDraft();
                        } catch (saveError) {
                             console.error('[SAVE] Error during storeDraft call:', saveError);
                        }
                    } else {
                         console.warn('[SAVE] Editor was removed before storeDraft could be called:', editor.id);
                    }
                }, saveDelay);
            } else {
                if (!editor._autosaveChecked) {
                    console.error('[SAVE] TinyMCE autosave plugin or storeDraft method not found for editor:', editor.id);
                    editor._autosaveChecked = true;
                }
            }
        }); 
        var form = editor.getElement().closest('form');
        if (form) {
            console.log('[SETUP] Found parent form for editor:', editor.id, form);
            form.addEventListener('submit', function() {
                if (editor.plugins.autosave && typeof editor.plugins.autosave.removeDraft === 'function') {
                    if (!editor.removed) {
                        console.log('[CLEAR] Calling removeDraft for editor:', editor.id);
                         try {
                              editor.plugins.autosave.removeDraft(false);
                         } catch (clearError) {
                              console.error('[CLEAR] Error during removeDraft call:', clearError);
                         }
                    } else {
                        console.warn('[CLEAR] Editor was removed before removeDraft could be called:', editor.id);
                    }
                } else {
                     console.error('[CLEAR] TinyMCE autosave plugin or removeDraft method not found for editor:', editor.id);
                }
            }, false);
        } else {
             console.error('[SETUP] Could not find parent form for TinyMCE editor:', editor.id);
        }
    };
    </script>
    <?php
}
add_action('wp_footer', 'extrachill_output_tinymce_setup_script', 99);
