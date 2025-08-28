<?php
/**
 * Outputs custom CSS variables and Google Fonts for a link page.
 * Used in both the public/live page and the manage page.
 *
 * @param int $link_page_id The ID of the artist_link_page post.
 * @param array $extrch_link_page_fonts The font config array.
 */
function extrch_link_page_custom_vars_and_fonts_head( $link_page_id, $extrch_link_page_fonts ) {
    $custom_vars_data = get_post_meta( $link_page_id, '_link_page_custom_css_vars', true );
    $custom_vars = is_array($custom_vars_data) ? $custom_vars_data : [];

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

    $final_vars = array_merge($defaults, $custom_vars);
    
    echo '<style id="extrch-link-page-custom-vars">:root {';
    foreach ($final_vars as $key => $value) {
        if (!empty($value)) {
            echo esc_html($key) . ':' . esc_html($value) . ';';
        }
    }
    echo '}</style>';

    $google_fonts = [];
    if (is_array($extrch_link_page_fonts)) {
        foreach ($extrch_link_page_fonts as $font) {
            if (!empty($font['google_font_param']) && 
                $font['google_font_param'] !== 'local_default' && 
                $font['google_font_param'] !== 'inherit') {
                $google_fonts[] = $font['google_font_param'];
            }
        }
    }
    
    if (!empty($google_fonts)) {
        $font_url = 'https://fonts.googleapis.com/css2?family=' . implode('&family=', array_unique($google_fonts)) . '&display=swap';
        echo '<link rel="stylesheet" href="' . esc_url($font_url) . '" media="print" onload="this.media=\'all\'">';
        echo '<noscript><link rel="stylesheet" href="' . esc_url($font_url) . '"></noscript>';
    }
} 