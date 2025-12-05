<?php
/**
 * Newsletter Email Template
 *
 * Converts WordPress post content to Sendy-compatible HTML email
 * with responsive styling, image optimization, and template structure.
 *
 * @package ExtraChillNewsletter
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convert WordPress post to Sendy-compatible HTML email
 *
 * Responsive styling, image optimization, YouTube thumbnail conversion.
 */
function prepare_newsletter_email_content($post) {
	$content = apply_filters('the_content', $post->post_content);

	// Ensure images are responsive and add necessary styles
	$content = preg_replace('/<img(.+?)src="(.*?)"(.*?)>/i', '<img$1src="$2"$3 style="height: auto; max-width:100%; object-fit:contain;">', $content);

	// Replace YouTube iframe embeds with clickable thumbnails
	$content = preg_replace_callback(
		'/<figure[^>]*>\s*<div class="wp-block-embed__wrapper">\s*<iframe[^>]+src="https:\/\/www\.youtube\.com\/embed\/([a-zA-Z0-9_\-]+)[^"]*"[^>]*><\/iframe>\s*<\/div>\s*<\/figure>/s',
		function($matches) {
			$videoId = $matches[1];
			$videoUrl = "https://www.youtube.com/watch?v=$videoId";
			$thumbnailUrl = "https://img.youtube.com/vi/$videoId/maxresdefault.jpg";
			return '<a href="' . $videoUrl . '" target="_blank"><img src="' . $thumbnailUrl . '" alt="Watch our video" style="height: auto; max-width: 100%; display: block; margin: 0 auto;"></a>';
		},
		$content
	);

	// Apply responsive email styling
	$content = preg_replace('/<figure([^>]*)>/i', '<figure$1 style="text-align: center; margin: auto;">', $content);
	$content = preg_replace('/<figcaption([^>]*)>/i', '<figcaption$1 style="text-align: center;font-size: 15px;padding:5px;">', $content);
	$content = preg_replace('/<p([^>]*)>/i', '<p$1 style="font-size: 16px; line-height:1.75em;">', $content);
	$content = preg_replace('/<h2([^>]*)>/i', '<h2$1 style="text-align: center;">', $content);
	$content = preg_replace('/<(ol|ul)([^>]*)>/i', '<$1$2 style="font-size: 16px; line-height:1.75em;padding-inline-start:20px;">', $content);
	$content = preg_replace('/<li([^>]*)>/i', '<li$1 style="margin: 10px 0;">', $content);

	// Add Extra Chill logo header
	$logo = '<a href="https://extrachill.com" style="text-align: center; display: block; margin: 20px auto;border-bottom:2px solid #53940b;"><img src="https://extrachill.com/wp-content/uploads/2023/09/extra-chill-logo-no-bg-1.png" alt="Extra Chill Logo" style="padding-bottom:10px;max-width: 60px; height: auto; display: block; margin: 0 auto;"></a>';
	$content = $logo . $content;

	$subject = $post->post_title;
	$unsubscribe_link = '<p style="text-align: center; margin-top: 20px; font-size: 16px;"><unsubscribe style="color: #666666; text-decoration: none;">Unsubscribe here</unsubscribe></p>';

	// Generate complete HTML email template using template below
	$html_template = generate_email_html_template($subject, $content, $unsubscribe_link);

	return array(
		'subject' => $subject,
		'html_template' => $html_template,
		'plain_text' => wp_strip_all_tags($content)
	);
}
function generate_email_html_template($subject, $content, $unsubscribe_link) {
	$html_template = <<<HTML
<html>
<head>
    <title>{$subject}</title>
</head>
<body style="background: #d8d8d8; font-family: Helvetica, sans-serif; padding: 0; margin: 0; width: 100%; display: flex; justify-content: center; align-items: center;">
    <div style="background: #fff; border: 1px solid #000; max-width: 600px; margin: 20px auto; padding: 0 20px; box-sizing: border-box;">
        {$content}
        <footer style="text-align: center; padding-top: 20px; font-size: 16px; line-height: 1.5em;">
            <p>Read this newsletter & all others on the web at <a href="https://newsletter.extrachill.com">newsletter.extrachill.com</a></p>
            <p>You received this email because you've connected with Extra Chill in some way over the years. Thanks for supporting independent music.</p>
            {$unsubscribe_link}
        </footer>
    </div>
</body>
</html>
HTML;

	return $html_template;
}
