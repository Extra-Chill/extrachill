<?php
/**
 * Content Filters
 *
 * bbPress content transformation: Twitter/X embed handling, inline style stripping.
 */

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
    $html = preg_replace('/^<\?xml[^>]*\?>/', '', $html);
    $html = preg_replace('/<!--\?xml[^>]*\?-->/', '', $html);
    $html = preg_replace(array('/^<!DOCTYPE.+?>/', '/<html>/i', '/<\/html>/i', '/<body>/i', '/<\/body>/i'), array('', '', '', '', ''), $html);
    return trim($html);
}
add_filter('the_content', 'strip_img_inline_styles', 20);
add_filter('bbp_get_reply_content', 'strip_img_inline_styles', 20);
add_filter('bbp_get_topic_content', 'strip_img_inline_styles', 20);

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

/**
 * Gets raw Unix timestamp for a forum's last activity.
 * Checks _bbp_last_active_time meta, falls back to last reply/topic post_date.
 */
function ec_get_raw_forum_timestamp($forum_id) {
    $last_active = get_post_meta($forum_id, '_bbp_last_active_time', true);
    
    if (empty($last_active)) {
        $reply_id = bbp_get_forum_last_reply_id($forum_id);
        if (!empty($reply_id)) {
            $last_active = get_post_field('post_date', $reply_id);
        } else {
            $topic_id = bbp_get_forum_last_topic_id($forum_id);
            if (!empty($topic_id)) {
                $last_active = get_post_meta($topic_id, '_bbp_last_active_time', true);
                if (empty($last_active)) {
                    $last_active = get_post_field('post_date', $topic_id);
                }
            }
        }
    }
    
    return !empty($last_active) ? strtotime($last_active) : 0;
}

/**
 * Recursively finds the latest timestamp across a forum and all its subforums.
 */
function ec_get_forum_freshness_timestamp_recursive($forum_id) {
    $latest_timestamp = ec_get_raw_forum_timestamp($forum_id);
    
    $subforums = bbp_forum_get_subforums($forum_id);
    foreach ($subforums as $subforum) {
        $subforum_timestamp = ec_get_forum_freshness_timestamp_recursive($subforum->ID);
        if ($subforum_timestamp > $latest_timestamp) {
            $latest_timestamp = $subforum_timestamp;
        }
    }
    
    return $latest_timestamp;
}

function ec_get_forum_freshness_with_subforums($forum_id) {
    $latest_timestamp = ec_get_forum_freshness_timestamp_recursive($forum_id);
    
    if ($latest_timestamp > 0) {
        return bbp_get_time_since(bbp_convert_date(gmdate('Y-m-d H:i:s', $latest_timestamp)));
    }
    return '';
}

function ec_get_forum_last_active_id_with_subforums($forum_id) {
    remove_filter('bbp_get_forum_last_active_id', 'ec_get_forum_last_active_id_with_subforums_filter', 10);
    $forum_last_active_id = bbp_get_forum_last_active_id($forum_id);
    add_filter('bbp_get_forum_last_active_id', 'ec_get_forum_last_active_id_with_subforums_filter', 10, 2);

    $latest_timestamp = $forum_last_active_id ? strtotime(get_post_field('post_date', $forum_last_active_id)) : 0;

    $subforums = bbp_forum_get_subforums($forum_id);
    foreach ($subforums as $subforum) {
        $subforum_last_active_id = ec_get_forum_last_active_id_with_subforums($subforum->ID);
        if ($subforum_last_active_id) {
            $subforum_time = strtotime(get_post_field('post_date', $subforum_last_active_id));
            if ($subforum_time > $latest_timestamp) {
                $latest_timestamp = $subforum_time;
                $forum_last_active_id = $subforum_last_active_id;
            }
        }
    }

    return $forum_last_active_id;
}

add_filter('bbp_get_forum_last_active_time', 'ec_get_forum_last_active_time_with_subforums', 10, 2);
function ec_get_forum_last_active_time_with_subforums($time, $forum_id) {
    return ec_get_forum_freshness_with_subforums($forum_id);
}

add_filter('bbp_get_forum_last_active_id', 'ec_get_forum_last_active_id_with_subforums_filter', 10, 2);
function ec_get_forum_last_active_id_with_subforums_filter($id, $forum_id) {
    return ec_get_forum_last_active_id_with_subforums($forum_id);
}


