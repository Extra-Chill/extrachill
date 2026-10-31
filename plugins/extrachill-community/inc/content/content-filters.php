<?php
/**
 * Content Filters
 *
 * bbPress content transformation: Twitter/X embed handling, inline style stripping.
 */

/**
 * Cache TTL for a successful tweet oEmbed lookup (the embed HTML is effectively
 * static). Bounded so a deleted/private tweet eventually falls back to the URL.
 */
if ( ! defined( 'EXTRACHILL_COMMUNITY_TWEET_OEMBED_TTL' ) ) {
	define( 'EXTRACHILL_COMMUNITY_TWEET_OEMBED_TTL', 30 * DAY_IN_SECONDS );
}

/**
 * Cache TTL for a failed lookup. Short, so a transient Twitter outage doesn't
 * pin the raw URL in place for long, but long enough to stop repeated renders
 * from hammering the endpoint while it's down.
 */
if ( ! defined( 'EXTRACHILL_COMMUNITY_TWEET_OEMBED_FAIL_TTL' ) ) {
	define( 'EXTRACHILL_COMMUNITY_TWEET_OEMBED_FAIL_TTL', 5 * MINUTE_IN_SECONDS );
}

/**
 * Transform Twitter/X status URLs into cached, latency-bounded oEmbed HTML.
 *
 * Each unique content body is resolved at most once per request via a static
 * memo keyed on the content hash, so overlapping filters (the_content +
 * bbp_get_topic_content + bbp_get_reply_content) never re-process the same
 * body. Per-URL results are cached with the core Transients API: successes are
 * cached long-term and failures short-term, so page latency is never coupled
 * to Twitter's response time on repeat renders.
 */
function embed_tweets($content) {
	static $processed = array();

	$content_hash = md5((string) $content);
	if ( array_key_exists($content_hash, $processed) ) {
		return $processed[$content_hash];
	}

	// Reserve the slot before resolution so a nested filter call can't re-enter.
	$processed[$content_hash] = $content;

	if ( false === stripos($content, 'twitter.com') && false === stripos($content, 'x.com') ) {
		return $content;
	}

	$pattern = '/https?:\/\/(?:www\.)?(twitter\.com|x\.com)\/(?:#!\/)?(\w+)\/status(?:es)?\/(\d+)/i';

	$callback = function($matches) {
		$tweet_url = 'https://' . $matches[1] . '/' . $matches[2] . '/status/' . $matches[3];
		$html      = extrachill_community_get_tweet_oembed($tweet_url);

		if ( false === $html ) {
			return $matches[0];
		}

		return '<div class="twitter-embed">' . $html . '</div>';
	};

	$processed[$content_hash] = preg_replace_callback($pattern, $callback, $content);
	return $processed[$content_hash];
}

/**
 * Resolve a single tweet's oEmbed HTML through the core Transients cache.
 *
 * Uses the core Transients API directly (the standard WP cache primitive) so
 * no wrapper substrate is introduced. Returns the oEmbed HTML on success, or
 * boolean false on any failure (HTTP error, malformed JSON, missing html key);
 * the caller is responsible for the fallback markup.
 *
 * @param string $tweet_url Normalized https://twitter.com/.../status/<id> URL.
 * @return string|false
 */
function extrachill_community_get_tweet_oembed($tweet_url) {
	$cache_key   = 'ec_tweet_oembed_' . md5($tweet_url);
	$cached_html = get_transient($cache_key);

	/*
	 * Sentinel values stored in the transient:
	 *   - false  : cache miss; perform a bounded fetch below.
	 *   - ''     : cached failure; return false so the caller renders the URL.
	 *   - string : cached oEmbed HTML.
	 */
	if ( '' === $cached_html ) {
		return false;
	}
	if ( is_string($cached_html) ) {
		return $cached_html;
	}

	$oembed_endpoint = 'https://publish.twitter.com/oembed?url=' . urlencode($tweet_url);
	$response        = wp_remote_get($oembed_endpoint, array('timeout' => 5));

	$html = false;
	if ( ! is_wp_error($response) && ! empty($response['body']) ) {
		$embed_data = json_decode($response['body'], true);
		if ( is_array($embed_data) && ! empty($embed_data['html']) ) {
			$html = (string) $embed_data['html'];
		}
	}

	if ( false === $html ) {
		set_transient($cache_key, '', EXTRACHILL_COMMUNITY_TWEET_OEMBED_FAIL_TTL);
		return false;
	}

	set_transient($cache_key, $html, EXTRACHILL_COMMUNITY_TWEET_OEMBED_TTL);
	return $html;
}

add_filter('the_content', 'embed_tweets', 9);
add_filter('bbp_get_reply_content', 'embed_tweets', 9);
add_filter('bbp_get_topic_content', 'embed_tweets', 9);

function strip_img_inline_styles($content) {
	if ( stripos($content, '<img') === false ) {
		return $content;
	}
	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	@$dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
	libxml_clear_errors();
	$imgs = $dom->getElementsByTagName('img');
	foreach ( $imgs as $img ) {
		$img->removeAttribute('style');
	}
	$html = $dom->saveHTML();
	$html = preg_replace('/^<\?xml[^>]*\?>/', '', $html);
	$html = preg_replace('/<!--\?xml[^>]*\?-->/', '', $html);
	$html = preg_replace(array( '/^<!DOCTYPE.+?>/', '/<html>/i', '/<\/html>/i', '/<body>/i', '/<\/body>/i' ), array( '', '', '', '', '' ), $html);
	return trim($html);
}
add_filter('the_content', 'strip_img_inline_styles', 20);
add_filter('bbp_get_reply_content', 'strip_img_inline_styles', 20);
add_filter('bbp_get_topic_content', 'strip_img_inline_styles', 20);

/**
 * Truncates HTML at character limit while preserving tags and word boundaries
 */
function extrachill_truncate_html_content($content, $length = 500, $ellipsis = '...') {
	if ( empty($content) || ! is_string($content) ) {
		return $content;
	}

	$plain_text = wp_strip_all_tags( $content);
	if ( strlen($plain_text) <= $length ) {
		return $content;
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	@$dom->loadHTML('<?xml encoding="UTF-8"><div>' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
	libxml_clear_errors();

	$div = $dom->getElementsByTagName('div')->item(0);
	if ( ! $div ) {
		return $content;
	}

	$truncated      = '';
	$current_length = 0;
	$truncated_dom  = new DOMDocument();
	$truncated_div  = $truncated_dom->createElement('div');
	$truncated_dom->appendChild($truncated_div);

	foreach ( $div->childNodes as $node ) {
		$node_text   = $node->textContent;
		$node_length = strlen($node_text);

		if ( $current_length + $node_length <= $length ) {
			$imported_node = $truncated_dom->importNode($node, true);
			$truncated_div->appendChild($imported_node);
			$current_length += $node_length;
		} else {
			$remaining_length = $length - $current_length;

			if ( $remaining_length > 0 ) {
				$truncated_node = $node->cloneNode(true);

				$text_nodes    = array();
				$xpath         = new DOMXPath($dom);
				$text_elements = $xpath->query('.//text()', $truncated_node);

				foreach ( $text_elements as $text_element ) {
					$text_nodes[] = $text_element;
				}

				if ( ! empty($text_nodes) ) {
					$last_text_node = end($text_nodes);
					$text_content   = $last_text_node->textContent;

					$truncated_text = substr($text_content, 0, $remaining_length);
					$last_space     = strrpos($truncated_text, ' ');

					if ( false !== $last_space && $last_space > $remaining_length * 0.8 ) {
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
	$description = bbp_get_forum_content();
	if ( $description ) {
		echo '<div class="bbp-forum-description">' . wp_kses_post( $description ) . '</div>';
	}
}
add_action( 'bbp_template_before_single_forum', 'ec_display_forum_description' );

/**
 * Restrict the revision log to edits the author explicitly chose to log.
 *
 * This is an Extra Chill editorial policy, not a bbPress bug fix. bbPress core
 * (bbp_get_topic_revision_log(), includes/topics/template.php) hides the log
 * entirely when no edit is logged, but once at least one edit is logged it
 * lists every WordPress revision — rendering un-opted-in ones with a generic
 * "modified by X" line and no reason. We want only opted-in edits visible, so
 * we intersect the revision list against the _bbp_revision_log opt-in map.
 */
add_filter('bbp_get_topic_revisions', 'extrachill_filter_logged_revisions', 10, 2);
function extrachill_filter_logged_revisions($revisions, $topic_id) {
	$revision_log = get_post_meta($topic_id, '_bbp_revision_log', true);

	if ( empty($revision_log) || ! is_array($revision_log) ) {
		return array();
	}

	return array_filter($revisions, function($revision) use ($revision_log) {
		return isset($revision_log[ $revision->ID ]);
	});
}

add_filter('bbp_get_reply_revisions', 'extrachill_filter_logged_reply_revisions', 10, 2);
function extrachill_filter_logged_reply_revisions($revisions, $reply_id) {
	$revision_log = get_post_meta($reply_id, '_bbp_revision_log', true);

	if ( empty($revision_log) || ! is_array($revision_log) ) {
		return array();
	}

	return array_filter($revisions, function($revision) use ($revision_log) {
		return isset($revision_log[ $revision->ID ]);
	});
}
