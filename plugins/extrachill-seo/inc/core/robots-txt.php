<?php
/**
 * Robots.txt Management
 *
 * Enhances WordPress core virtual robots.txt with Extra Chill rules.
 * WordPress core provides the base (User-agent, Disallow wp-admin, Sitemap per-subsite).
 * This filter adds AI crawler blocking, query parameter blocking, and wp-json blocking.
 *
 * Replaces the static robots.txt file that served the same content to every subsite
 * with a dynamic per-subsite output via WordPress's `robots_txt` filter.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter the virtual robots.txt output.
 *
 * WordPress core generates:
 *   User-agent: *
 *   Disallow: /wp-admin/
 *   Allow: /wp-admin/admin-ajax.php
 *   Sitemap: {site_url}/wp-sitemap.xml
 *
 * We append additional rules after the core output.
 */
add_filter(
	'robots_txt',
	function ( $output, $public ) {
		// If the site is set to discourage indexing, WordPress core already
		// outputs Disallow: / — don't add anything else.
		if ( '0' === (string) $public ) {
			return $output;
		}

		$extra_rules = '';

		// Block AI training crawlers that scrape content for model training.
		// These crawlers do not drive traffic — they only extract content.
		$ai_crawlers = array(
			'GPTBot'           => 'OpenAI training crawler',
			'ChatGPT-User'     => 'ChatGPT browsing feature',
			'CCBot'            => 'Common Crawl (used for AI training)',
			'Google-Extended'  => 'Google AI training (Gemini/Bard)',
			'anthropic-ai'     => 'Anthropic training crawler',
			'ClaudeBot'        => 'Anthropic Claude crawler',
			'Bytespider'       => 'ByteDance/TikTok crawler',
			'FacebookBot'      => 'Meta AI training crawler',
			'Applebot-Extended' => 'Apple AI training crawler',
		);

		$extra_rules .= "\n# Block AI training crawlers\n";
		foreach ( $ai_crawlers as $bot => $description ) {
			$extra_rules .= "User-agent: {$bot}\n";
			$extra_rules .= "Disallow: /\n\n";
		}

		// Block junk query parameter pages from being crawled.
		$extra_rules .= "# Block junk query parameter URLs\n";
		$extra_rules .= "User-agent: *\n";
		$extra_rules .= "Disallow: /*?replytocom=\n";
		$extra_rules .= "Disallow: /*?s=\n";

		// Block WP REST API from indexing (content is served via HTML pages).
		$extra_rules .= "Disallow: /wp-json/\n";

		// Allow specific wp-content directories for assets.
		$extra_rules .= "\n# Allow assets\n";
		$extra_rules .= "Allow: /wp-content/uploads/\n";
		$extra_rules .= "Allow: /wp-content/themes/\n";
		$extra_rules .= "Allow: /wp-content/plugins/\n";

		$output .= $extra_rules;

		return $output;
	},
	10,
	2
);
