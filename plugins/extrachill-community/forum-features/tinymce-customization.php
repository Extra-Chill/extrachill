<?php
// Enable visual editor specifically for bbPress
function bbp_enable_visual_editor($args = array()) {
    $args['tinymce'] = array('content_css' => '/wp-content/themes/generatepress_child/css/tinymce-editor.css');
    $args['quicktags'] = false;
    $args['teeny'] = false;
    return $args;
}
add_filter('bbp_after_get_the_content_parse_args', 'bbp_enable_visual_editor', 999);

// Add custom stylesheet to TinyMCE
function bbp_add_tinymce_stylesheet($mce_css) {
    $version = filemtime(get_stylesheet_directory() . '/css/tinymce-editor.css');
    $mce_css .= ', ' . get_stylesheet_directory_uri() . '/css/tinymce-editor.css?ver=' . $version;
    return $mce_css;
}
add_filter('mce_css', 'bbp_add_tinymce_stylesheet');

// Add 'paste' plugin to TinyMCE (bbPress context only)
function bbp_tinymce_paste_plugin($plugins = array()) {
    if (function_exists('is_bbpress') && is_bbpress()) {
        $plugins[] = 'paste';
    }
    return $plugins;
}
add_filter('bbp_get_tiny_mce_plugins', 'bbp_tinymce_paste_plugin');

// Customize TinyMCE buttons specifically for bbPress
function bbp_customize_tinymce_buttons($buttons) {
    // This filter runs AFTER other filters like the one adding the custom image button.
    // We want to define the *exact* set of buttons for bbPress editors here.
    // Apply to bbPress pages OR single band profile pages
    if ( (function_exists('is_bbpress') && is_bbpress()) || is_singular('band_profile') ) {
        // Define the desired button array for bbPress editors.
        // Ensure 'image' (our custom one) is included if desired.
        $desired_buttons = array(
            'bold',
            'italic',
            // 'underline', // Keep or remove as needed
            // 'strikethrough', // Keep or remove as needed
            'image', // Keep the custom image button
            'blockquote',
            'link', 'unlink', // Add link/unlink?
            // 'bullist', 'numlist', // Add lists?
            // 'pastetext', // Add paste as text?
            'undo',
            'redo',
            'formatselect'
        );
        return $desired_buttons;
    }
    // If not a bbPress context or single band profile, return the original buttons unmodified
    return $buttons;
}
add_filter('mce_buttons', 'bbp_customize_tinymce_buttons', 50); // Add priority (e.g., 50) to ensure it runs later

// Load custom autosave plugin for bbPress only
function bbp_load_custom_autosave_plugin($plugins) {
    if (function_exists('is_bbpress') && is_bbpress()) {
        $autosave_plugin_url = get_stylesheet_directory_uri() . '/bbpress/autosave/plugin.min.js';
        $plugins['autosave'] = $autosave_plugin_url;
    }
    return $plugins;
}
add_filter('mce_external_plugins', 'bbp_load_custom_autosave_plugin');

// Configure autosave and paste handling specifically for bbPress editors
function bbp_autosave_tinymce_settings($init) {
    if (function_exists('is_bbpress') && is_bbpress()) {
        $init['autosave_ask_before_unload'] = false;
        // Set interval to a very large value to effectively disable the timer-based save.
        // We will trigger saves manually on typing pause via the setup callback.
        $init['autosave_interval'] = '999999s'; 
        $init['autosave_prefix'] = 'bbp-tinymce-autosave-{path}{query}-{id}-';
        $init['autosave_restore_when_empty'] = true;
        $init['autosave_retention'] = '43200m'; // 30 days

        // Paste handling configuration
        $init['paste_as_text'] = false; // Allow formatted paste but clean it
        $init['paste_auto_cleanup_on_paste'] = true;
        $init['paste_remove_styles'] = true;
        $init['paste_remove_styles_if_webkit'] = true;
        $init['paste_strip_class_attributes'] = 'all'; // Remove all class attributes
        $init['paste_retain_style_properties'] = ''; // Don't retain any inline styles

        // Reference a globally defined JavaScript function for the setup callback
        $init['setup'] = 'extrachillTinymceSetup';

    }
    return $init;
}
add_filter('tiny_mce_before_init', 'bbp_autosave_tinymce_settings');

// Define the JavaScript setup function for TinyMCE (Simplified Logging)
function extrachill_output_tinymce_setup_script() {
    // Only output this script on bbPress pages
    if (!function_exists('is_bbpress') || !is_bbpress()) {
        return;
    }
    ?>
    <script type="text/javascript">
    // Ensure this function is globally accessible for TinyMCE
    window.extrachillTinymceSetup = function(editor) {
        console.log('[SETUP] Initializing setup for editor:', editor.id);
        var debounceTimer;
        var saveDelay = 1500; // Save 1.5 seconds after typing stops

        // --- Save on Pause Logic --- 
        editor.on('input keyup', function(e) {
            var nonTriggerKeys = [ 33, 34, 35, 36, 37, 38, 39, 40 ];
            if (e && e.keyCode && nonTriggerKeys.includes(e.keyCode)) {
                 return;
            }

            // Check if the plugin and method exist before attempting to use them
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
                // Log this only once per editor instance if needed to avoid spam
                if (!editor._autosaveChecked) {
                    console.error('[SAVE] TinyMCE autosave plugin or storeDraft method not found for editor:', editor.id);
                    editor._autosaveChecked = true; // Mark as checked
                }
            }
        });

        // --- Clear on Submit Logic --- 
        var form = editor.getElement().closest('form');
        if (form) {
            console.log('[SETUP] Found parent form for editor:', editor.id, form);
            form.addEventListener('submit', function() {
                 // Check if the plugin and method exist before attempting to use them
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

// Load custom extrachill mentions plugin for bbPress only
function bbp_load_extrachill_mentions_plugin($plugins) {
    if (function_exists('is_bbpress') && is_bbpress()) {
        $mentions_plugin_url = get_stylesheet_directory_uri() . '/js/extrachill-mentions.js';
        $plugins['extrachillmentions'] = $mentions_plugin_url;
    }
    return $plugins;
}
//add_filter('mce_external_plugins', 'bbp_load_extrachill_mentions_plugin');
