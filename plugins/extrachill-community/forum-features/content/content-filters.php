<?php
function custom_bbp_make_dofollow_links($content) {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

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
            if ($embed_data && isset($embed_data['html'])) {
                return '<div class="twitter-embed">' . $embed_data['html'] . '</div>'; // Wrap in a div to manage styling and formatting independently
            }
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

// Remove inline style attributes from <img> tags in post/bbPress content
function strip_img_inline_styles($content) {
    if (stripos($content, '<img') === false) {
        return $content;
    }
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $imgs = $dom->getElementsByTagName('img');
    foreach ($imgs as $img) {
        $img->removeAttribute('style');
    }
    $html = $dom->saveHTML();
    // Remove doctype/html/body wrappers
    $html = preg_replace(array('/^<!DOCTYPE.+?>/', '/<html>/i', '/<\/html>/i', '/<body>/i', '/<\/body>/i'), array('', '', '', '', ''), $html);
    return trim($html);
}
add_filter('the_content', 'strip_img_inline_styles', 20);
add_filter('bbp_get_reply_content', 'strip_img_inline_styles', 20);
add_filter('bbp_get_topic_content', 'strip_img_inline_styles', 20);

/**
 * Clean Apple/Word markup from pasted content
 * Removes Apple-specific classes and formatting that displays as raw HTML
 */
function ec_clean_apple_word_markup($content) {
    if (empty($content) || !is_string($content)) {
        return $content;
    }
    
    if (stripos($content, 'class=') === false) {
        return $content;
    }

    // First decode HTML entities to handle encoded quotes
    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    if (empty($content) || !is_string($content)) {
        return $content;
    }
    
    // Define quote patterns (regular quotes, left/right double quotes) using hex codes
    $quote_chars = '["' . "\xE2\x80\x9C" . "\xE2\x80\x9D" . "\xE2\x80\xB3" . "\xE2\x80\x9F" . ']';
    
    // Remove Apple-specific classes and spans
    $content = preg_replace('/<span class=' . $quote_chars . 'Apple-converted-space' . $quote_chars . '[^>]*>(\s*)<\/span>/i', '$1', $content);
    $content = preg_replace('/<span class=' . $quote_chars . 's\d+' . $quote_chars . '[^>]*>(.*?)<\/span>/i', '$1', $content);
    
    // Remove paragraph classes (p1, p2, etc.)
    $content = preg_replace('/<p class=' . $quote_chars . 'p\d+' . $quote_chars . '[^>]*>/i', '<p>', $content);
    
    // Remove other Apple/Word specific classes
    $content = preg_replace('/<(p|span|div) class=' . $quote_chars . '[^' . $quote_chars . ']*Apple[^' . $quote_chars . ']*' . $quote_chars . '[^>]*>/i', '<$1>', $content);
    
    // Clean up Word-style spans that just wrap text unnecessarily
    $content = preg_replace('/<span class=' . $quote_chars . '[^' . $quote_chars . ']*' . $quote_chars . '[^>]*>(.*?)<\/span>/i', '$1', $content);
    
    // Broader pattern to catch any class="p1" or class="s1" variations
    $content = preg_replace('/<p class=' . $quote_chars . '[ps]\d+' . $quote_chars . '[^>]*>/i', '<p>', $content);
    $content = preg_replace('/<span class=' . $quote_chars . '[ps]\d+' . $quote_chars . '[^>]*>(.*?)<\/span>/i', '$1', $content);
    
    // Remove empty class attributes
    $content = preg_replace('/\s+class=' . $quote_chars . $quote_chars . '\s*/', ' ', $content);
    
    // Convert curly quotes and other Word characters using hex codes
    $word_chars = [
        "\xE2\x80\x9C" => '"', // Left double quote (U+201C)
        "\xE2\x80\x9D" => '"', // Right double quote (U+201D)
        "\xE2\x80\x98" => "'", // Left single quote (U+2018)
        "\xE2\x80\x99" => "'", // Right single quote (U+2019)
        "\xE2\x80\x93" => '-', // En dash (U+2013)
        "\xE2\x80\x94" => '-', // Em dash (U+2014)
        "\xE2\x80\xA6" => '...', // Ellipsis (U+2026)
        "\xE2\x80\xB3" => '"', // Double prime (U+2033)
        "\xE2\x80\x9F" => '"'  // Double high-reversed-9 quotation mark (U+201F)
    ];
    
    if (!empty($content) && is_string($content)) {
        foreach ($word_chars as $word_char => $replacement) {
            $content = str_replace($word_char, $replacement, $content);
        }
    }
    
    // Clean up multiple spaces and line breaks
    if (!empty($content) && is_string($content)) {
        $content = preg_replace('/\s+/', ' ', $content);
        $content = preg_replace('/(<\/p>)\s*(<p>)/i', '$1$2', $content);
        $content = trim($content);
    }
    
    return $content;
}

/**
 * Apply Apple/Word markup cleanup to bbPress content
 */
function ec_clean_bbpress_content($content) {
    // Check for various indicators of Apple/Word markup
    $has_markup = (
        strpos($content, 'class="') !== false ||
        strpos($content, 'class="') !== false || // Check for HTML entity quotes
        strpos($content, 'Apple-converted-space') !== false ||
        strpos($content, 'class="p1') !== false ||
        strpos($content, 'class="s1') !== false
    );
    
    if ($has_markup) {
        $original_length = strlen($content);
        $content = ec_clean_apple_word_markup($content);
        $new_length = strlen($content);
        
        // Debug logging (remove after testing)
        if ($original_length !== $new_length) {
            error_log('EC Content Filter: Cleaned content, length changed from ' . $original_length . ' to ' . $new_length);
        }
    }
    return $content;
}

// Apply cleanup to bbPress content with higher priority to run after other filters
add_filter('bbp_get_reply_content', 'ec_clean_bbpress_content', 25);
add_filter('bbp_get_topic_content', 'ec_clean_bbpress_content', 25);

/**
 * Clean content before saving to prevent raw HTML storage
 * This runs during the save process to clean content at the source
 */
function ec_clean_content_before_save($content) {
    return ec_clean_apple_word_markup($content);
}

// Apply to content before it's saved to database
add_filter('bbp_new_topic_pre_content', 'ec_clean_content_before_save');
add_filter('bbp_new_reply_pre_content', 'ec_clean_content_before_save');
add_filter('bbp_edit_topic_pre_content', 'ec_clean_content_before_save'); 
add_filter('bbp_edit_reply_pre_content', 'ec_clean_content_before_save');

