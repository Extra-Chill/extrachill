<?php
/**
 * Spotify Embed Customization
 * Removes wp-embed-aspect-21-9 class from Spotify embeds for custom styling
 *
 * @package ExtraChill
 * @since 1.0
 */

/**
 * Remove wp-embed-aspect-21-9 class from Spotify embeds
 */
function remove_spotify_aspect_ratio_class( $content ) {
    $spotify_embed_html = '<figure class="wp-block-embed is-type-rich is-provider-spotify wp-block-embed-spotify wp-embed-aspect-21-9 wp-has-aspect-ratio">';
    $pos = strpos( $content, $spotify_embed_html );
    while ( $pos !== false ) {
        $new_embed_html = '<figure class="wp-block-embed is-type-rich is-provider-spotify wp-block-embed-spotify">';
        $content = substr_replace( $content, $new_embed_html, $pos, strlen( $spotify_embed_html ) );
        $pos = strpos( $content, $spotify_embed_html, $pos + strlen( $new_embed_html ) );
    }
    return $content;
}
add_filter( 'the_content', 'remove_spotify_aspect_ratio_class' );