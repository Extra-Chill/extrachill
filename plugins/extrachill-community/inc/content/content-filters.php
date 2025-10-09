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

function embed_tweets($content) {
    $pattern = '/https?:\/\/(?:www\.)?(twitter\.com|x\.com)\/(?:#!\/)?(\w+)\/status(?:es)?\/(\d+)/i';

    $callback = function($matches) {
        $tweet_url = 'https://' . $matches[1] . '/' . $matches[2] . '/status/' . $matches[3];
        $oembed_endpoint = 'https://publish.twitter.com/oembed?url=' . urlencode($tweet_url);
        $response = wp_remote_get($oembed_endpoint);

        if (!is_wp_error($response) && isset($response['body'])) {
            $embed_data = json_decode($response['body'], true);
            if ($embed_data && isset($embed_data['html'])) {
                return '<div class="twitter-embed">' . $embed_data['html'] . '</div>';
            }
        }

        return $matches[0];
    };

    return preg_replace_callback($pattern, $callback, $content);
}

add_filter('the_content', 'embed_tweets', 9);
add_filter('bbp_get_reply_content', 'embed_tweets', 9);
add_filter('bbp_get_topic_content', 'embed_tweets', 9);

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


/**
 * Truncate HTML content while preserving structure (Twitter-style truncation)
 * Cuts off at character limit while respecting word boundaries and HTML tags
 *
 * @param string $content The HTML content to truncate
 * @param int $length Maximum character length (default: 500)
 * @param string $ellipsis What to append when content is truncated (default: '...')
 * @return string Truncated HTML content
 */
function extrachill_truncate_html_content($content, $length = 500, $ellipsis = '...') {
    if (empty($content) || !is_string($content)) {
        return $content;
    }

    // Get plain text length to check if truncation is needed
    $plain_text = strip_tags($content);
    if (strlen($plain_text) <= $length) {
        return $content;
    }

    // Use DOMDocument to parse HTML safely
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="UTF-8"><div>' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $div = $dom->getElementsByTagName('div')->item(0);
    if (!$div) {
        return $content;
    }

    $truncated = '';
    $current_length = 0;
    $truncated_dom = new DOMDocument();
    $truncated_div = $truncated_dom->createElement('div');
    $truncated_dom->appendChild($truncated_div);

    // Walk through child nodes and build truncated content
    foreach ($div->childNodes as $node) {
        $node_text = $node->textContent;
        $node_length = strlen($node_text);

        if ($current_length + $node_length <= $length) {
            // Node fits entirely
            $imported_node = $truncated_dom->importNode($node, true);
            $truncated_div->appendChild($imported_node);
            $current_length += $node_length;
        } else {
            // Node needs to be truncated
            $remaining_length = $length - $current_length;

            if ($remaining_length > 0) {
                // Clone the node and truncate its text content
                $truncated_node = $node->cloneNode(true);

                // Find text nodes and truncate them
                $text_nodes = [];
                $xpath = new DOMXPath($dom);
                $text_elements = $xpath->query('.//text()', $truncated_node);

                foreach ($text_elements as $text_element) {
                    $text_nodes[] = $text_element;
                }

                if (!empty($text_nodes)) {
                    // Truncate the last text node
                    $last_text_node = end($text_nodes);
                    $text_content = $last_text_node->textContent;

                    // Find word boundary to truncate at
                    $truncated_text = substr($text_content, 0, $remaining_length);
                    $last_space = strrpos($truncated_text, ' ');

                    if ($last_space !== false && $last_space > $remaining_length * 0.8) {
                        $truncated_text = substr($truncated_text, 0, $last_space);
                    }

                    $last_text_node->textContent = $truncated_text . $ellipsis;
                }

                $imported_node = $truncated_dom->importNode($truncated_node, true);
                $truncated_div->appendChild($imported_node);
            }
            break; // Stop processing further nodes
        }
    }

    // Convert back to HTML string
    $html = $truncated_dom->saveHTML($truncated_div);
    // Remove the wrapper div tags
    $html = preg_replace('/^<div>/', '', $html);
    $html = preg_replace('/<\/div>$/', '', $html);

    return trim($html);
}

/**
 * Display native bbPress forum description (post content)
 */
function ec_display_forum_description() {
    if ( $description = bbp_get_forum_content() ) {
        echo '<div class="bbp-forum-description">' . $description . '</div>';
    }
}
add_action( 'bbp_template_before_single_forum', 'ec_display_forum_description' );

