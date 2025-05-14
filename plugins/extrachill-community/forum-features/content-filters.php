<?php
function custom_bbp_make_dofollow_links($content) {
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    $links = $dom->getElementsByTagName('a');
    foreach ($links as $link) {
        $href = $link->getAttribute('href');
        if (strpos($href, 'https://extrachill.com') !== false) {
            $link->removeAttribute('rel'); // Remove the rel attribute entirely
        }
    }

    $html = $dom->saveHTML();
    // This is where you ensure the doctype and html/body tags are not improperly added to your fragment
    $html = preg_replace(array('/^<!DOCTYPE.+?>/', '/<html>/i', '/<\/html>/i', '/<body>/i', '/<\/body>/i'), array('', '', '', '', ''), $html);
    return trim($html);
}


function custom_instagram_embed_handler($matches, $attr, $url, $rawattr) {
    // Check if the URL is an Instagram profile
    if (preg_match('#https?://(www\.)?instagram\.com/[a-zA-Z0-9_.-]+/?$#i', $url)) {
        $embed = sprintf(
            '<blockquote class="instagram-media" data-instgrm-permalink="%s" data-instgrm-version="14" style=" background:#FFF; border:0; border-radius:3px; box-shadow:0 0 1px 0 rgba(0,0,0,0.5),0 1px 10px 0 rgba(0,0,0,0.15); margin: 1px; max-width:540px; min-width:326px; padding:0; width:99.375%%; width:-webkit-calc(100%% - 2px); width:calc(100%% - 2px);"><a href="%s" target="_blank"></a></blockquote><script async src="//www.instagram.com/embed.js"></script>',
            esc_url($url),
            esc_url($url)
        );
    } else {
        // For posts or reels, use the existing embed format
        $embed = sprintf(
            '<iframe src="%s/embed" width="400" height="500" frameborder="0" scrolling="no" allowtransparency="true"></iframe>',
            esc_url($matches[0])
        );
    }

    return apply_filters('custom_instagram_embed', $embed, $matches, $attr, $url, $rawattr);
}

function register_custom_instagram_embed_handler() {
    wp_embed_register_handler(
        'instagram',
        '#https?://(www\.)?instagram\.com/(p|reel)/[a-zA-Z0-9_-]+#i',
        'custom_instagram_embed_handler'
    );

    // Register the handler for Instagram profiles as well
    wp_embed_register_handler(
        'instagram_profile',
        '#https?://(www\.)?instagram\.com/[a-zA-Z0-9_.-]+/?$#i',
        'custom_instagram_embed_handler'
    );
}
add_action('init', 'register_custom_instagram_embed_handler');



// fix extra space in mentions
/*
function fix_bbp_mentions_after_wpautop($content) {
    // Define the pattern to find <a> tags with <br> directly inside them, within bbPress content
    $pattern = '/<a href="([^"]+)" class="bbp-user-mention[^"]*">\s*<br\s*\/?>\s*(.*?)<\/a>/i';

    // Replacement pattern without <br>
    $replacement = '<a href="$1" class="bbp-user-mention">$2</a>';

    // Replace the pattern in the content
    $fixed_content = preg_replace($pattern, $replacement, $content);

    return $fixed_content;
}
// Apply this fix after wpautop and bbp_rel_nofollow for both replies and topics
add_filter('bbp_get_reply_content', 'fix_bbp_mentions_after_wpautop', 70);
add_filter('bbp_get_topic_content', 'fix_bbp_mentions_after_wpautop', 70);

*/
function embed_tweets($content) {
    // Adjusted the regex to be more selective, specifically targeting tweet status URLs
    $pattern = '/https?:\/\/(?:www\.)?(twitter\.com|x\.com)\/(?:#!\/)?(\w+)\/status(?:es)?\/(\d+)/i';

    // Replace callback function
    $callback = function($matches) {
        // Building the Tweet URL with the original domain found (twitter.com or x.com)
        $tweet_url = 'https://' . $matches[1] . '/' . $matches[2] . '/status/' . $matches[3];

        // Twitter oEmbed API endpoint with the Tweet URL
        $oembed_endpoint = 'https://publish.twitter.com/oembed?url=' . urlencode($tweet_url);

        // Make the API call to get the embed code
        $response = wp_remote_get($oembed_endpoint);

        // If the API call was successful, replace the URL with the embed code
        if (!is_wp_error($response) && isset($response['body'])) {
            $embed_data = json_decode($response['body'], true);
            return '<div class="twitter-embed">' . $embed_data['html'] . '</div>'; // Wrap in a div to manage styling and formatting independently
        }

        // If the API call failed, just return the original URL
        return $matches[0];
    };

    // Disable wpautop for specific block
    remove_filter('the_content', 'wpautop');
    remove_filter('the_excerpt', 'wpautop');

    // Run our regex on the content and replace URLs with embed codes
    $new_content = preg_replace_callback($pattern, $callback, $content);

    // Re-enable wpautop for other content
    add_filter('the_content', 'wpautop');
    add_filter('the_excerpt', 'wpautop');

    return $new_content;
}

// Hook our function to content filters
add_filter('the_content', 'embed_tweets', 9); // Priority set to 9 to run before wpautop at priority 10
add_filter('bbp_get_reply_content', 'embed_tweets', 9);
add_filter('bbp_get_topic_content', 'embed_tweets', 9);

