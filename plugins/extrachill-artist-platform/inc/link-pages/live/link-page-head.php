<?php
/**
 * Custom <head> content for extrachill.link pages
 *
 * Outputs minimal head elements for artist link pages, replacing wp_head()
 * to maintain lightweight isolated page templates.
 *
 * @package ExtraChillArtistPlatform
 *
 * @param int $artist_id The ID of the associated artist_profile post.
 * @param int $link_page_id The ID of the artist_link_page post.
 */
function extrch_link_page_custom_head( $artist_id, $link_page_id ) {

    echo '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';

    $artist_title = $artist_id ? get_the_title( $artist_id ) : 'Link Page';
    $artist_excerpt = $artist_id ? get_the_excerpt( $artist_id ) : 'All important links in one place.';
    echo '<title>' . esc_html( $artist_title ) . ' | extrachill.link</title>';
    echo '<meta name="description" content="' . esc_attr( wp_strip_all_tags( $artist_excerpt ) ) . '">';

    $site_icon_url = get_site_icon_url( 32 );
    if ( $site_icon_url ) {
        echo '<link rel="icon" href="' . esc_url( $site_icon_url ) . '" sizes="32x32" />';
        echo '<link rel="icon" href="' . esc_url( $site_icon_url ) . '" sizes="192x192" />';
        echo '<link rel="apple-touch-icon" href="' . esc_url( $site_icon_url ) . '">';
    }

    // Link page assets are enqueued via extrch_link_page_minimal_head.
    // These templates bypass wp_head(), so styles are printed below
    // via wp_print_styles() after the enqueue hook fires.

    $data = ec_get_link_page_data( $artist_id, $link_page_id );
    $final_vars = $data['css_vars'];

    echo ec_generate_css_variables_style_block( $final_vars, 'extrch-link-page-custom-vars' );

    $font_manager = ExtraChillArtistPlatform_Fonts::instance();
    $font_values = array();

    if ( isset( $data['raw_font_values']['title_font'] ) && ! empty( $data['raw_font_values']['title_font'] ) ) {
        $font_values[] = $data['raw_font_values']['title_font'];
    }

    if ( isset( $data['raw_font_values']['body_font'] ) && ! empty( $data['raw_font_values']['body_font'] ) ) {
        $font_values[] = $data['raw_font_values']['body_font'];
    }

    $font_url = $font_manager->get_google_fonts_url( $font_values );
    if ( ! empty( $font_url ) ) {
        echo '<link rel="stylesheet" href="' . esc_url($font_url) . '" media="print" onload="this.media=\'all\'">';
        echo '<noscript><link rel="stylesheet" href="' . esc_url($font_url) . '"></noscript>';
    }

    $local_fonts_css = $font_manager->get_local_fonts_css( $font_values );
    if ( ! empty( $local_fonts_css ) ) {
        echo '<style>' . $local_fonts_css . '</style>';
    }

    do_action( 'extrch_link_page_minimal_head', $link_page_id, $artist_id );
    wp_print_styles();

    $meta_pixel_id = $data['settings']['meta_pixel_id'] ?? '';
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

    $google_tag_id = $data['settings']['google_tag_id'] ?? '';
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