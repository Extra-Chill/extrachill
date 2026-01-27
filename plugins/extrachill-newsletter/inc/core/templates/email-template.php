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
 * Convert WordPress post to Sendy-compatible HTML email.
 *
 * Responsive styling, image optimization, YouTube thumbnail conversion.
 */
function prepare_newsletter_email_content( $post ) {
	$content = apply_filters( 'the_content', $post->post_content );

	// Ensure images are responsive.
	$content = preg_replace( '/<img(.+?)src="(.*?)"(.*?)>/i', '<img$1src="$2"$3 style="height: auto; max-width: 100%;">', $content );

	// Replace YouTube iframe embeds with clickable thumbnails.
	$content = preg_replace_callback(
		'/<figure[^>]*>\s*<div class="wp-block-embed__wrapper">\s*<iframe[^>]+src="https:\/\/www\.youtube\.com\/embed\/([a-zA-Z0-9_\-]+)[^"]*"[^>]*><\/iframe>\s*<\/div>\s*<\/figure>/s',
		function( $matches ) {
			$video_id = $matches[1];
			$video_url = "https://www.youtube.com/watch?v={$video_id}";
			$thumbnail_url = "https://img.youtube.com/vi/{$video_id}/maxresdefault.jpg";
			return '<a href="' . esc_url( $video_url ) . '" target="_blank"><img src="' . esc_url( $thumbnail_url ) . '" alt="Watch our video" style="height: auto; max-width: 100%; display: block; margin: 0 auto;"></a>';
		},
		$content
	);

	// Apply email styling.
	$content = preg_replace( '/<figure([^>]*)>/i', '<figure$1 style="text-align: center; margin: 0 auto;">', $content );
	$content = preg_replace( '/<figcaption([^>]*)>/i', '<figcaption$1 style="text-align: center; font-size: 15px; padding: 5px;">', $content );
	$content = preg_replace( '/<p([^>]*)>/i', '<p$1 style="font-size: 16px; line-height: 1.75em;">', $content );
	$content = preg_replace( '/<h2([^>]*)>/i', '<h2$1 style="text-align: center;">', $content );
	$content = preg_replace( '/<(ol|ul)([^>]*)>/i', '<$1$2 style="font-size: 16px; line-height: 1.75em; padding-left: 20px;">', $content );
	$content = preg_replace( '/<li([^>]*)>/i', '<li$1 style="margin: 10px 0;">', $content );

	$main_site_url = ec_get_site_url( 'main' );
	$subject = $post->post_title;
	$read_on_web_url = get_permalink( $post->ID );
	$newsletter_site_url = ec_get_site_url( 'newsletter' );

	$navbar_links = array(
		__( 'Main', 'extrachill-newsletter' ) => $main_site_url,
		__( 'Community', 'extrachill-newsletter' ) => ec_get_site_url( 'community' ),
		__( 'Events', 'extrachill-newsletter' ) => ec_get_site_url( 'events' ),
		__( 'Shop', 'extrachill-newsletter' ) => ec_get_site_url( 'shop' ),
		__( 'Artist Platform', 'extrachill-newsletter' ) => ec_get_site_url( 'artist' ),
		__( 'Documentation', 'extrachill-newsletter' ) => ec_get_site_url( 'docs' ),
		__( 'Newsletters', 'extrachill-newsletter' ) => $newsletter_site_url,
	);

	$navbar_links = apply_filters( 'extrachill_newsletter_email_footer_links', $navbar_links, $post );

	$logo = '<a href="' . esc_url( $main_site_url ) . '" style="text-align: center; display: block; margin: 20px auto; border-bottom: 2px solid #53940b;"><img src="https://newsletter.extrachill.com/wp-content/uploads/sites/9/2026/01/Extra-Chill-Main-Logo-2026.png" alt="Extra Chill" width="60" style="padding-bottom: 10px; max-width: 60px; height: auto; display: block; margin: 0 auto; border: 0;"></a>';
	$content = $logo . $content;

	$unsubscribe_link = '<p style="text-align: center; margin: 18px 0 0; font-size: 14px; line-height: 1.5em;"><unsubscribe style="color: #6b7280; text-decoration: none;">Unsubscribe</unsubscribe></p>';

	$html_template = generate_email_html_template( $subject, $content, $unsubscribe_link, $read_on_web_url, $navbar_links );

	return array(
		'subject' => $subject,
		'html_template' => $html_template,
		'plain_text' => wp_strip_all_tags( $content ),
	);
}

function generate_email_html_template( $subject, $content, $unsubscribe_link, $read_on_web_url, $navbar_links ) {
	$preheader_text = wp_strip_all_tags( $subject );

	$footer_links_html = '';
	$footer_separator = ' &nbsp;|&nbsp; ';
	foreach ( $navbar_links as $label => $url ) {
		if ( empty( $url ) ) {
			continue;
		}

		$link = '<a href="' . esc_url( $url ) . '" style="color: #0b5394; text-decoration: none;">' . esc_html( $label ) . '</a>';
		$footer_links_html = $footer_links_html ? $footer_links_html . $footer_separator . $link : $link;
	}

	$read_on_web_url = esc_url( $read_on_web_url );

	$html_template = <<<HTML
<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>{$subject}</title>
	<style>
		a { color: #0b5394; }
	</style>
</head>
<body style="margin: 0; padding: 0; background: #f1f5f9; font-family: Helvetica, Arial, sans-serif; color: #000;">
	<div style="display: none; font-size: 1px; line-height: 1px; max-height: 0; max-width: 0; opacity: 0; overflow: hidden;">
		{$preheader_text}
	</div>
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background: #f1f5f9; padding: 20px 0;">
		<tr>
			<td align="center" style="padding: 0 12px;">
				<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" class="email-container" style="width: 100%; max-width: 600px; background: #ffffff; border: 1px solid #ddd;">
					<tr>
						<td style="padding: 0 20px;">
							{$content}
							<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top: 20px; border-top: 1px solid #ddd;">
								<tr>
									<td style="padding-top: 16px; text-align: center; font-size: 14px; line-height: 1.5em;">
										<p style="margin: 0 0 10px;"><a href="{$read_on_web_url}" style="color: #0b5394; text-decoration: none;">Read this newsletter on the web</a></p>
										<p style="margin: 0 0 10px;" class="muted">{$footer_links_html}</p>
										{$unsubscribe_link}
									</td>
								</tr>
							</table>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
HTML;

	return $html_template;
}
