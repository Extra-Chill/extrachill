<?php
/**
 * Outputs custom CSS variables and Google Fonts for a link page.
 * Used in both the public/live page and the manage page.
 *
 * @param int $link_page_id The ID of the band_link_page post.
 * @param array $extrch_link_page_fonts The font config array.
 */
function extrch_link_page_custom_vars_and_fonts_head( $link_page_id, $extrch_link_page_fonts ) {
    // Add logging to debug data retrieval and decoding
    // error_log('[DEBUG] extrch_link_page_custom_vars_and_fonts_head called for link_page_id: ' . $link_page_id);

    $custom_vars_json = get_post_meta( $link_page_id, '_link_page_custom_css_vars', true );
    // error_log('[DEBUG] Raw custom_vars_json: ' . print_r($custom_vars_json, true));

    // Define the canonical set of variables and their defaults
    $defaults = [
        '--link-page-background-color' => '#121212',
        '--link-page-card-bg-color' => 'rgba(0,0,0,0.4)',
        '--link-page-text-color' => '#e5e5e5',
        '--link-page-link-text-color' => '#ffffff',
        '--link-page-button-bg-color' => '#0b5394',
        '--link-page-button-border-color' => '#0b5394',
        '--link-page-button-hover-bg-color' => '#53940b',
        '--link-page-button-hover-text-color' => '#ffffff',
        '--link-page-muted-text-color' => '#aaa',
        '--link-page-title-font-family' => "'WilcoLoftSans', Helvetica, Arial, sans-serif",
        '--link-page-title-font-size' => '2.1em',
        '--link-page-body-font-family' => "'WilcoLoftSans', Helvetica, Arial, sans-serif",
        '--link-page-body-font-size' => '1em',
        '--link-page-profile-img-size' => '30%',
        '--link-page-profile-img-aspect-ratio' => '1 / 1',
        '--link-page-profile-img-border-radius' => '8px',
        '--link-page-button-radius' => '8px',
        '--link-page-overlay-color' => 'rgba(0,0,0,0.5)',
    ];

    $final_custom_vars_style = '';
    $processed_vars = $defaults;
    $default_title_font_value = 'WilcoLoftSans';
    $default_title_font_stack = "'WilcoLoftSans', Helvetica, Arial, sans-serif";
    $selected_title_font_value = $default_title_font_value;
    $selected_title_font_stack = $default_title_font_stack;
    $default_body_font_value = 'OpenSans';
    $default_body_font_stack = "'Open Sans', Helvetica, Arial, sans-serif";
    $selected_body_font_value = $default_body_font_value;
    $selected_body_font_stack = $default_body_font_stack;
    if ( !empty( $custom_vars_json ) ) {
        $custom_vars = json_decode( $custom_vars_json, true );
        if ( is_array( $custom_vars ) ) {
            foreach ( $custom_vars as $k => $v ) {
                if ($k === '--link-page-title-font-family') {
                    $font_found_in_config = false;
                    if (is_array($extrch_link_page_fonts)) {
                        foreach ($extrch_link_page_fonts as $font) {
                            if ($font['value'] === $v || $font['stack'] === $v) {
                                $selected_title_font_value = $font['value'];
                                $selected_title_font_stack = $font['stack'];
                                $processed_vars[$k] = $font['stack'];
                                $font_found_in_config = true;
                                break;
                            }
                        }
                    }
                    if (!$font_found_in_config) {
                        if (strpos($v, ',') === false && strpos($v, "'") === false && strpos($v, '"') === false) {
                            $processed_vars[$k] = "'" . $v . "', " . $default_title_font_stack;
                            $selected_title_font_value = $v;
                            $selected_title_font_stack = $processed_vars[$k];
                        } else {
                            $processed_vars[$k] = $v ?: $default_title_font_stack;
                            $first_font_in_stack = explode(',', $v)[0];
                            $selected_title_font_value = trim($first_font_in_stack, " '");
                            $selected_title_font_stack = $v;
                        }
                    }
                } elseif ($k === '--link-page-body-font-family') {
                    $font_found_in_config = false;
                    if (is_array($extrch_link_page_fonts)) {
                        foreach ($extrch_link_page_fonts as $font) {
                            if ($font['value'] === $v || $font['stack'] === $v) {
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
                            $selected_body_font_value = trim($first_font_in_stack, " '");
                            $selected_body_font_stack = $v;
                        }
                    }
                } else {
                    $processed_vars[$k] = $v;
                }
            }
        }
    }
    // Always ensure the canonical background color variable is present
    if (!isset($processed_vars['--link-page-background-color'])) {
        $processed_vars['--link-page-background-color'] = $defaults['--link-page-background-color'];
    }
    if (!empty($processed_vars)) {
        // Output the style tag ONLY in the <head>, never in the body or inside the preview container.
        $final_custom_vars_style .= '<style id="extrch-link-page-custom-vars">:root {';
        foreach ($processed_vars as $k => $v) {
            if (isset($v) && $v !== '') {
                $key_sanitized = esc_html($k);
                $value_trimmed = trim($v);
                $is_font_family_var = ($k === '--link-page-title-font-family' || $k === '--link-page-body-font-family');
                $output_value = $is_font_family_var ? html_entity_decode($value_trimmed, ENT_QUOTES, 'UTF-8') : esc_html($value_trimmed);
                $final_custom_vars_style .= $key_sanitized . ':' . $output_value . ';';
            }
        }
        $final_custom_vars_style .= '}</style>';
        echo $final_custom_vars_style;
    }
    $google_font_params_to_enqueue = [];
    if (is_array($extrch_link_page_fonts)) {
        foreach ($extrch_link_page_fonts as $font_entry) {
            if ($font_entry['value'] === $selected_title_font_value && !empty($font_entry['google_font_param']) && $font_entry['google_font_param'] !== 'local_default' && $font_entry['google_font_param'] !== 'inherit') {
                if (!in_array($font_entry['google_font_param'], $google_font_params_to_enqueue)) {
                    $google_font_params_to_enqueue[] = $font_entry['google_font_param'];
                }
                break;
            }
        }
        foreach ($extrch_link_page_fonts as $font_entry) {
            if ($font_entry['value'] === $selected_body_font_value && !empty($font_entry['google_font_param']) && $font_entry['google_font_param'] !== 'local_default' && $font_entry['google_font_param'] !== 'inherit') {
                if (!in_array($font_entry['google_font_param'], $google_font_params_to_enqueue)) {
                    $google_font_params_to_enqueue[] = $font_entry['google_font_param'];
                }
                break;
            }
        }
    }
    if (!empty($google_font_params_to_enqueue)) {
        $font_families_string = implode('&family=', $google_font_params_to_enqueue);
        $font_url = 'https://fonts.googleapis.com/css2?family=' . $font_families_string . '&display=swap';
        echo '<link rel="stylesheet" href="' . $font_url . '" media="print" onload="this.media=\'all\'">';
        echo '<noscript><link rel="stylesheet" href="' . $font_url . '"></noscript>';
    }
} 