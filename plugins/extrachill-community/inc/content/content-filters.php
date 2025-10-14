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
 * Removes Apple/Word-specific classes and curly quotes from pasted content
 */
function ec_clean_apple_word_markup($content) {
    if (empty($content) || !is_string($content) || stripos($content, 'class=') === false) {
        return $content;
    }

    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if (empty($content) || !is_string($content)) {
        return $content;
    }

    $quote_chars = '["' . "\xE2\x80\x9C" . "\xE2\x80\x9D" . "\xE2\x80\xB3" . "\xE2\x80\x9F" . ']';

    $content = preg_replace('/<span class=' . $quote_chars . 'Apple-converted-space' . $quote_chars . '[^>]*>(\s*)<\/span>/i', '$1', $content);
    $content = preg_replace('/<span class=' . $quote_chars . 's\d+' . $quote_chars . '[^>]*>(.*?)<\/span>/i', '$1', $content);
    $content = preg_replace('/<p class=' . $quote_chars . 'p\d+' . $quote_chars . '[^>]*>/i', '<p>', $content);
    $content = preg_replace('/<(p|span|div) class=' . $quote_chars . '[^' . $quote_chars . ']*Apple[^' . $quote_chars . ']*' . $quote_chars . '[^>]*>/i', '<$1>', $content);
    $content = preg_replace('/<span class=' . $quote_chars . '[^' . $quote_chars . ']*' . $quote_chars . '[^>]*>(.*?)<\/span>/i', '$1', $content);
    $content = preg_replace('/<p class=' . $quote_chars . '[ps]\d+' . $quote_chars . '[^>]*>/i', '<p>', $content);
    $content = preg_replace('/<span class=' . $quote_chars . '[ps]\d+' . $quote_chars . '[^>]*>(.*?)<\/span>/i', '$1', $content);
    $content = preg_replace('/\s+class=' . $quote_chars . $quote_chars . '\s*/', ' ', $content);

    $word_chars = [
        "\xE2\x80\x9C" => '"',
        "\xE2\x80\x9D" => '"',
        "\xE2\x80\x98" => "'",
        "\xE2\x80\x99" => "'",
        "\xE2\x80\x93" => '-',
        "\xE2\x80\x94" => '-',
        "\xE2\x80\xA6" => '...',
        "\xE2\x80\xB3" => '"',
        "\xE2\x80\x9F" => '"'
    ];

    if (!empty($content) && is_string($content)) {
        foreach ($word_chars as $word_char => $replacement) {
            $content = str_replace($word_char, $replacement, $content);
        }
        $content = preg_replace('/\s+/', ' ', $content);
        $content = preg_replace('/(<\/p>)\s*(<p>)/i', '$1$2', $content);
        $content = trim($content);
    }

    return $content;
}

function ec_clean_bbpress_content($content) {
    $has_markup = (
        strpos($content, 'class="') !== false ||
        strpos($content, 'class="') !== false ||
        strpos($content, 'Apple-converted-space') !== false ||
        strpos($content, 'class="p1') !== false ||
        strpos($content, 'class="s1') !== false
    );

    if ($has_markup) {
        $content = ec_clean_apple_word_markup($content);
    }
    return $content;
}

add_filter('bbp_get_reply_content', 'ec_clean_bbpress_content', 25);
add_filter('bbp_get_topic_content', 'ec_clean_bbpress_content', 25);

function ec_clean_content_before_save($content) {
    return ec_clean_apple_word_markup($content);
}

add_filter('bbp_new_topic_pre_content', 'ec_clean_content_before_save');
add_filter('bbp_new_reply_pre_content', 'ec_clean_content_before_save');
add_filter('bbp_edit_topic_pre_content', 'ec_clean_content_before_save');
add_filter('bbp_edit_reply_pre_content', 'ec_clean_content_before_save');


/**
 * Truncates HTML at character limit while preserving tags and word boundaries
 */
function extrachill_truncate_html_content($content, $length = 500, $ellipsis = '...') {
    if (empty($content) || !is_string($content)) {
        return $content;
    }

    $plain_text = strip_tags($content);
    if (strlen($plain_text) <= $length) {
        return $content;
    }

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

    foreach ($div->childNodes as $node) {
        $node_text = $node->textContent;
        $node_length = strlen($node_text);

        if ($current_length + $node_length <= $length) {
            $imported_node = $truncated_dom->importNode($node, true);
            $truncated_div->appendChild($imported_node);
            $current_length += $node_length;
        } else {
            $remaining_length = $length - $current_length;

            if ($remaining_length > 0) {
                $truncated_node = $node->cloneNode(true);

                $text_nodes = [];
                $xpath = new DOMXPath($dom);
                $text_elements = $xpath->query('.//text()', $truncated_node);

                foreach ($text_elements as $text_element) {
                    $text_nodes[] = $text_element;
                }

                if (!empty($text_nodes)) {
                    $last_text_node = end($text_nodes);
                    $text_content = $last_text_node->textContent;

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
            break;
        }
    }

    $html = $truncated_dom->saveHTML($truncated_div);
    $html = preg_replace('/^<div>/', '', $html);
    $html = preg_replace('/<\/div>$/', '', $html);

    return trim($html);
}

function ec_display_forum_description() {
    if ( $description = bbp_get_forum_content() ) {
        echo '<div class="bbp-forum-description">' . $description . '</div>';
    }
}
add_action( 'bbp_template_before_single_forum', 'ec_display_forum_description' );

