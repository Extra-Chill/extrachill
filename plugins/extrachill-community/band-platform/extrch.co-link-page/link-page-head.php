<?php
/**
 * Custom <head> content for the isolated extrachill.link page.
 *
 * This function outputs the minimal, necessary head elements,
 * replacing wp_head() for the single band link page template.
 *
 * @package ExtrchCo
 *
 * @param int $band_id The ID of the associated band_profile post.
 * @param int $link_page_id The ID of the band_link_page post.
 */
function extrch_link_page_custom_head( $band_id, $link_page_id ) {
    global $extrch_link_page_fonts; // Ensure font config is available

    // Basic Meta
    echo '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';

    // Title and Description
    $band_title = $band_id ? get_the_title( $band_id ) : 'Link Page';
    $band_excerpt = $band_id ? get_the_excerpt( $band_id ) : 'All important links in one place.';
    echo '<title>' . esc_html( $band_title ) . ' | extrachill.link</title>'; // Or your site name
    echo '<meta name="description" content="' . esc_attr( wp_strip_all_tags( $band_excerpt ) ) . '">';

    // Favicon
    $site_icon_url = get_site_icon_url( 32 ); // Get site icon URL, 32x32 size is common for favicons
    if ( $site_icon_url ) {
        echo '<link rel="icon" href="' . esc_url( $site_icon_url ) . '" sizes="32x32" />';
        echo '<link rel="icon" href="' . esc_url( $site_icon_url ) . '" sizes="192x192" />'; // Add a larger size too
        echo '<link rel="apple-touch-icon" href="' . esc_url( $site_icon_url ) . '">';
    }

    // Stylesheets
    $theme_uri = get_stylesheet_directory_uri();
    $theme_dir = get_stylesheet_directory();

    $extrch_links_css_path = '/band-platform/extrch.co-link-page/css/extrch-links.css';
    $share_modal_css_path = '/band-platform/extrch.co-link-page/css/extrch-share-modal.css';

    if ( file_exists( $theme_dir . $extrch_links_css_path ) ) {
        echo '<link rel="stylesheet" href="' . esc_url( $theme_uri . $extrch_links_css_path ) . '?ver=' . esc_attr( filemtime( $theme_dir . $extrch_links_css_path ) ) . '">';
    }
    if ( file_exists( $theme_dir . $share_modal_css_path ) ) { // Enqueue Share Modal CSS
        echo '<link rel="stylesheet" href="' . esc_url( $theme_uri . $share_modal_css_path ) . '?ver=' . esc_attr( filemtime( $theme_dir . $share_modal_css_path ) ) . '">';
    }
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.1/css/all.min.css">';

    // Share Modal Script
    $share_modal_js_path = '/band-platform/extrch.co-link-page/js/extrch-share-modal.js';
    if (file_exists($theme_dir . $share_modal_js_path)) {
        echo '<script src="' . esc_url($theme_uri . $share_modal_js_path) . '?ver=' . esc_attr(filemtime($theme_dir . $share_modal_js_path)) . '" defer></script>';
    }

    // Inline body margin reset (from single-band_link_page.php)
    echo '<style>body{margin:0;padding:0;}</style>';

    // Localize session data for link-page-session.js
    $session_data = array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'band_id'  => $band_id, // Use the $band_id passed to this function
    );
    echo '<script>window.extrchSessionData = ' . wp_json_encode( $session_data ) . ';</script>';

    // Custom CSS Variables and Google Font
    $custom_vars_json = get_post_meta( $link_page_id, '_link_page_custom_css_vars', true );
    $final_custom_vars_style = '';
    $processed_vars = array();
    $default_title_font_value = 'WilcoLoftSans'; // From font config
    $default_title_font_stack = "'WilcoLoftSans', Helvetica, Arial, sans-serif"; // From font config
    $selected_title_font_value = $default_title_font_value;
    $selected_title_font_stack = $default_title_font_stack;

    // Defaults for body font
    $default_body_font_value = 'OpenSans'; // Example from font config, adjust if different
    $default_body_font_stack = "'Open Sans', Helvetica, Arial, sans-serif"; // Example from font config
    $selected_body_font_value = $default_body_font_value;
    $selected_body_font_stack = $default_body_font_stack;

    if ( !empty( $custom_vars_json ) ) {
        $custom_vars = json_decode( $custom_vars_json, true );
        if ( is_array( $custom_vars ) ) {
            foreach ( $custom_vars as $k => $v ) {
                if ( $k === '--link-page-title-font-family' ) {
                    $font_found_in_config = false;
                    if (is_array($extrch_link_page_fonts)) {
                        foreach ( $extrch_link_page_fonts as $font ) {
                            if ( $font['value'] === $v || $font['stack'] === $v ) {
                                $selected_title_font_value = $font['value'];
                                $selected_title_font_stack = $font['stack'];
                                $processed_vars[$k] = $font['stack'];
                                $font_found_in_config = true;
                                break;
                            }
                        }
                    }
                    if (!$font_found_in_config) {
                         // Handle cases where the stored value might be a direct font name not in simple config
                        if (strpos($v, ',') === false && strpos($v, "'") === false && strpos($v, '"') === false) { // Likely a single font name
                            $processed_vars[$k] = "'" . $v . "', " . $default_title_font_stack; // Build a stack
                            $selected_title_font_value = $v; // Assume this is the value to check for Google Font
                            $selected_title_font_stack = $processed_vars[$k];
                        } else {
                            $processed_vars[$k] = $v ?: $default_title_font_stack;
                            // Attempt to parse the first font from the stack for Google Font check
                            $first_font_in_stack = explode(',', $v)[0];
                            $selected_title_font_value = trim($first_font_in_stack, " '\"");
                            $selected_title_font_stack = $v;
                        }
                    }
                } elseif ( $k === '--link-page-body-font-family' ) {
                    $font_found_in_config = false;
                    if (is_array($extrch_link_page_fonts)) {
                        foreach ( $extrch_link_page_fonts as $font ) {
                            if ( $font['value'] === $v || $font['stack'] === $v ) {
                                $selected_body_font_value = $font['value'];
                                $selected_body_font_stack = $font['stack'];
                                $processed_vars[$k] = $font['stack'];
                                $font_found_in_config = true;
                                break;
                            }
                        }
                    }
                    if (!$font_found_in_config) {
                        if (strpos($v, ',') === false && strpos($v, "'") === false && strpos($v, '"') === false) {
                            $processed_vars[$k] = "'" . $v . "', " . $default_body_font_stack;
                            $selected_body_font_value = $v;
                            $selected_body_font_stack = $processed_vars[$k];
                        } else {
                            $processed_vars[$k] = $v ?: $default_body_font_stack;
                            $first_font_in_stack = explode(',', $v)[0];
                            $selected_body_font_value = trim($first_font_in_stack, " '\"");
                            $selected_body_font_stack = $v;
                        }
                    }
                } else {
                    $processed_vars[$k] = $v;
                }
            }
        }
    }

    // Fallback: if no font-family set in custom_vars, ensure default is processed
    if ( !isset( $processed_vars['--link-page-title-font-family'] ) ) {
        $processed_vars['--link-page-title-font-family'] = $default_title_font_stack;
        $selected_title_font_value = $default_title_font_value; // Ensure this is set for Google Font check
    }
    // Fallback for body font
    if ( !isset( $processed_vars['--link-page-body-font-family'] ) ) {
        $processed_vars['--link-page-body-font-family'] = $default_body_font_stack;
        $selected_body_font_value = $default_body_font_value;
    }
    
    // Construct the :root CSS variables style tag
    if ( !empty( $processed_vars ) ) {
        $final_custom_vars_style .= '<style id="extrch-link-page-custom-vars">:root {';
        foreach ( $processed_vars as $k => $v ) {
            if ( isset( $v ) && $v !== '' ) {
                $key_sanitized = esc_html( $k );
                $value_trimmed = trim( $v );
                // Font family stack might contain unescaped characters if not from config, but should be safe if from config.
                // Other values are typically colors or simple strings.
                $is_font_family_var = ( $k === '--link-page-title-font-family' || $k === '--link-page-body-font-family' );
                $final_custom_vars_style .= $key_sanitized . ':' . ( $is_font_family_var ? $value_trimmed : esc_html( $value_trimmed ) ) . ';';
            }
        }
        $final_custom_vars_style .= '}</style>';
        echo $final_custom_vars_style;
    }

    // Google Font Link (based on the determined $selected_title_font_value and $selected_body_font_value)
    $google_font_params_to_enqueue = [];

    if (is_array($extrch_link_page_fonts)) {
        // Check title font
        foreach ( $extrch_link_page_fonts as $font_entry ) {
            if ( $font_entry['value'] === $selected_title_font_value && !empty($font_entry['google_font_param']) && $font_entry['google_font_param'] !== 'local_default' && $font_entry['google_font_param'] !== 'inherit') {
                if (!in_array($font_entry['google_font_param'], $google_font_params_to_enqueue)) {
                    $google_font_params_to_enqueue[] = $font_entry['google_font_param'];
                }
                break;
            }
        }
        // Check body font
        foreach ( $extrch_link_page_fonts as $font_entry ) {
            if ( $font_entry['value'] === $selected_body_font_value && !empty($font_entry['google_font_param']) && $font_entry['google_font_param'] !== 'local_default' && $font_entry['google_font_param'] !== 'inherit') {
                if (!in_array($font_entry['google_font_param'], $google_font_params_to_enqueue)) {
                    $google_font_params_to_enqueue[] = $font_entry['google_font_param'];
                }
                break;
            }
        }
    }

    if ( !empty($google_font_params_to_enqueue) ) {
        $font_families_string = implode('&family=', array_map('urlencode', $google_font_params_to_enqueue));
        $font_url = 'https://fonts.googleapis.com/css2?family=' . $font_families_string . '&display=swap';
        echo '<link rel="stylesheet" href="' . esc_url( $font_url ) . '" media="print" onload="this.media=\'all\'">';
        echo '<noscript><link rel="stylesheet" href="' . esc_url( $font_url ) . '"></noscript>';
    }

    // Action hook for any other critical head items (use sparingly)
    do_action('extrch_link_page_minimal_head', $link_page_id, $band_id);

    // --- Embed Tracking Pixels ---
    // Meta Pixel
    $meta_pixel_id = get_post_meta($link_page_id, '_link_page_meta_pixel_id', true);
    if (!empty($meta_pixel_id) && ctype_digit($meta_pixel_id)) {
        echo "<!-- Meta Pixel Code -->\n";
        echo "<script>\n";
        echo "!function(f,b,e,v,n,t,s)\n";
        echo "{if(f.fbq)return;n=f.fbq=function(){n.callMethod?\n";
        echo "n.callMethod.apply(n,arguments):n.queue.push(arguments)};\n";
        echo "if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';\n";
        echo "n.queue=[];t=b.createElement(e);t.async=!0;\n";
        echo "t.src=v;s=b.getElementsByTagName(e)[0];\n";
        echo "s.parentNode.insertBefore(t,s)}(window, document,'script',\n";
        echo "'https://connect.facebook.net/en_US/fbevents.js');\n";
        echo "fbq('init', '" . esc_js($meta_pixel_id) . "');\n";
        echo "fbq('track', 'PageView');\n";
        echo "</script>\n";
        echo "<noscript><img height=\"1\" width=\"1\" style=\"display:none\"\n";
        echo "src=\"https://www.facebook.com/tr?id=" . esc_attr($meta_pixel_id) . "&ev=PageView&noscript=1\"\n";
        echo "/></noscript>\n";
        echo "<!-- End Meta Pixel Code -->\n";
    }
    // End Meta Pixel

    // --- Google Tag (gtag.js) ---  
    $google_tag_id = get_post_meta($link_page_id, '_link_page_google_tag_id', true);
    if (!empty($google_tag_id) && preg_match('/^(G|AW)-[a-zA-Z0-9]+$/', $google_tag_id)) {
        echo "<!-- Google Tag Manager -->\n";
        echo "<script async src=\"https://www.googletagmanager.com/gtag/js?id=" . esc_attr($google_tag_id) . "\"></script>\n";
        echo "<script>\n";
        echo "  window.dataLayer = window.dataLayer || [];\n";
        echo "  function gtag(){dataLayer.push(arguments);}\n";
        echo "  gtag('js', new Date());\n";
        echo "\n";
        echo "  gtag('config', '" . esc_js($google_tag_id) . "');\n";
        echo "</script>\n";
        echo "<!-- End Google Tag Manager -->\n";
    }
    // End Google Tag

    // Placeholder for Google Tag Manager / GA4 gtag.js in the future
    // $google_tag_id = get_post_meta($link_page_id, '_link_page_google_tag_id', true);
    // if (!empty($google_tag_id)) { ... }

    // Google Tag Manager
    echo '<meta name="description" content="' . esc_attr( wp_strip_all_tags( $band_excerpt ) ) . '">';

    echo '<!-- Google Tag Manager -->';
    echo '<script>';
    echo '(function(w,d,s,l,i){';
    echo 'w[l]=w[l]||[];';
    echo 'w[l].push({\'gtm.start\': new Date().getTime(), event:\'gtm.js\'});';
    echo 'var f=d.getElementsByTagName(s)[0],';
    echo 'j=d.createElement(s),';
    echo 'dl=l!=\'dataLayer\'?\'&l=\'+l:\'\';';
    echo 'j.async=true;';
    echo 'j.src=\'https://www.googletagmanager.com/gtm.js?id=\'+i+dl;';
    echo 'f.parentNode.insertBefore(j,f);';
    echo '})(window,document,\'script\',\'dataLayer\',\'GTM-NXKDLFD\');';
    echo '</script>';
    echo '<!-- End Google Tag Manager -->';

} 