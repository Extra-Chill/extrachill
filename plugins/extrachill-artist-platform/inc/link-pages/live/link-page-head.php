<?php
/**
 * Custom <head> content for the isolated extrachill.link page.
 *
 * This function outputs the minimal, necessary head elements,
 * replacing wp_head() for the single band link page template.
 *
 * @package ExtrchCo
 *
 * @param int $artist_id The ID of the associated artist_profile post.
 * @param int $link_page_id The ID of the artist_link_page post.
 */
// Custom CSS variables and Google Fonts are now handled inline below

function extrch_link_page_custom_head( $artist_id, $link_page_id ) {
    // Font configuration now handled by centralized font manager

    // Basic Meta
    echo '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';

    // Title and Description
    $artist_title = $artist_id ? get_the_title( $artist_id ) : 'Link Page';
    $artist_excerpt = $artist_id ? get_the_excerpt( $artist_id ) : 'All important links in one place.';
    echo '<title>' . esc_html( $artist_title ) . ' | extrachill.link</title>'; // Or your site name
    echo '<meta name="description" content="' . esc_attr( wp_strip_all_tags( $artist_excerpt ) ) . '">';

    // Favicon
    $site_icon_url = get_site_icon_url( 32 ); // Get site icon URL, 32x32 size is common for favicons
    if ( $site_icon_url ) {
        echo '<link rel="icon" href="' . esc_url( $site_icon_url ) . '" sizes="32x32" />';
        echo '<link rel="icon" href="' . esc_url( $site_icon_url ) . '" sizes="192x192" />'; // Add a larger size too
        echo '<link rel="apple-touch-icon" href="' . esc_url( $site_icon_url ) . '">';
    }

    // Stylesheets
    $theme_uri = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;
    $theme_dir = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR;

    $extrch_links_css_path = '/assets/css/extrch-links.css';
    $share_modal_css_path = '/assets/css/extrch-share-modal.css';

    if ( file_exists( $theme_dir . $extrch_links_css_path ) ) {
        echo '<link rel="stylesheet" href="' . esc_url( $theme_uri . $extrch_links_css_path ) . '?ver=' . esc_attr( filemtime( $theme_dir . $extrch_links_css_path ) ) . '">';
    }
    if ( file_exists( $theme_dir . $share_modal_css_path ) ) { // Enqueue Share Modal CSS
        echo '<link rel="stylesheet" href="' . esc_url( $theme_uri . $share_modal_css_path ) . '?ver=' . esc_attr( filemtime( $theme_dir . $share_modal_css_path ) ) . '">';
    }
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.1/css/all.min.css">';

    // Share Modal Script
    $share_modal_js_path = '/assets/js/extrch-share-modal.js';
    if (file_exists($theme_dir . $share_modal_js_path)) {
        echo '<script src="' . esc_url($theme_uri . $share_modal_js_path) . '?ver=' . esc_attr(filemtime($theme_dir . $share_modal_js_path)) . '" defer></script>';
    }

    // Subscribe Feature Script (corrected path)
    $subscribe_js_path = '/assets/js/link-page-subscribe.js';
    if (file_exists($theme_dir . $subscribe_js_path)) {
        echo '<script src="' . esc_url($theme_uri . $subscribe_js_path) . '?ver=' . esc_attr(filemtime($theme_dir . $subscribe_js_path)) . '" defer></script>';
        // Localize ajaxurl for the subscribe JS
        echo '<script>var ajaxurl = "' . esc_url(admin_url('admin-ajax.php')) . '";</script>';
    }

    // Inline body margin reset (from single-artist_link_page.php)
    echo '<style>body{margin:0;padding:0;}</style>';

    // Localize session data for link-page-session.js
    $session_data = array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'artist_id'  => $artist_id, // Use the $artist_id passed to this function
    );
    echo '<script>window.extrchSessionData = ' . wp_json_encode( $session_data ) . ';</script>';

    // Output custom CSS variables and Google Fonts
    // Get processed data from LinkPageDataProvider (includes font processing)
    $data = LinkPageDataProvider::get_data( $link_page_id, $artist_id );
    $final_vars = $data['css_vars'];
    
    // Output CSS variables
    echo '<style id="extrch-link-page-custom-vars">:root {';
    foreach ($final_vars as $key => $value) {
        if (!empty($value)) {
            echo esc_html($key) . ':' . esc_html($value) . ';';
        }
    }
    echo '}</style>';

    // Get and output Google Fonts using centralized font manager
    $font_manager = ExtraChillArtistPlatform_Fonts::instance();
    $font_values = array();
    
    // Extract font values from CSS vars that are set
    if ( isset( $final_vars['--link-page-title-font-family'] ) ) {
        // Try to find the original font value from the raw meta data
        $custom_vars_data = get_post_meta( $link_page_id, '_link_page_custom_css_vars', true );
        if ( isset( $custom_vars_data['--link-page-title-font-family'] ) ) {
            $font_values[] = $custom_vars_data['--link-page-title-font-family'];
        } else {
            $font_values[] = ExtraChillArtistPlatform_Fonts::DEFAULT_TITLE_FONT;
        }
    }
    
    if ( isset( $final_vars['--link-page-body-font-family'] ) ) {
        $custom_vars_data = get_post_meta( $link_page_id, '_link_page_custom_css_vars', true );
        if ( isset( $custom_vars_data['--link-page-body-font-family'] ) ) {
            $font_values[] = $custom_vars_data['--link-page-body-font-family'];
        } else {
            $font_values[] = ExtraChillArtistPlatform_Fonts::DEFAULT_BODY_FONT;
        }
    }
    
    // Generate Google Fonts URL
    $font_url = $font_manager->get_google_fonts_url( $font_values );
    if ( ! empty( $font_url ) ) {
        echo '<link rel="stylesheet" href="' . esc_url($font_url) . '" media="print" onload="this.media=\'all\'">';
        echo '<noscript><link rel="stylesheet" href="' . esc_url($font_url) . '"></noscript>';
    }

    // Action hook for any other critical head items (use sparingly)
    do_action('extrch_link_page_minimal_head', $link_page_id, $artist_id);

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

    // Google Tag Manager

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