<?php
/**
 * Push Campaign Ability
 *
 * Core primitive for creating/updating Sendy campaigns from newsletter posts.
 * Replaces send_newsletter_campaign_to_sendy() and prepare_newsletter_email_content().
 *
 * @package ExtraChillNewsletter
 * @since 0.3.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_newsletter_register_push_campaign_ability' );

/**
 * Register the push-campaign ability.
 */
function extrachill_newsletter_register_push_campaign_ability() {
	wp_register_ability(
		'extrachill/push-campaign',
		array(
			'label'               => __( 'Push Campaign', 'extrachill-newsletter' ),
			'description'         => __( 'Create or update a Sendy email campaign from a newsletter post. Prepares email content and pushes to Sendy.', 'extrachill-newsletter' ),
			'category'            => 'extrachill-newsletter',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'Newsletter post ID to create/update campaign for.', 'extrachill-newsletter' ),
					),
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'campaign_id' => array(
						'type' => array( 'string', 'null' ),
					),
					'message'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_newsletter_ability_push_campaign',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Create or update a Sendy campaign from a newsletter post.
 *
 * Prepares the email content from the post (HTML + plain text), checks for
 * existing campaign, and creates or updates via Sendy API.
 *
 * @param array $input {post_id}.
 * @return array|WP_Error Result with success, campaign_id, message.
 */
function extrachill_newsletter_ability_push_campaign( $input ) {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

	if ( ! $post_id ) {
		return new WP_Error( 'missing_post_id', 'post_id is required.' );
	}

	$post = get_post( $post_id );

	if ( ! $post ) {
		return new WP_Error( 'post_not_found', 'Post not found.' );
	}

	if ( 'newsletter' !== $post->post_type ) {
		return new WP_Error( 'invalid_post_type', 'Post must be of type newsletter.' );
	}

	// Prepare email content.
	$email_data = extrachill_newsletter_prepare_email_content( $post );

	$config      = get_sendy_config();
	$campaign_id = get_post_meta( $post_id, '_sendy_campaign_id', true );
	$campaign_id = $campaign_id ? (string) $campaign_id : null;

	// Check if campaign exists.
	$check_url  = $config['sendy_url'] . '/api/campaigns/status.php';
	$check_data = array(
		'api_key'     => $config['api_key'],
		'campaign_id' => $campaign_id,
	);

	$check_response = wp_remote_post(
		$check_url,
		array(
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'    => $check_data,
			'timeout' => 30,
		)
	);

	if ( is_wp_error( $check_response ) ) {
		error_log( 'Sendy campaign check failed: ' . $check_response->get_error_message() );
		return array(
			'success'     => false,
			'campaign_id' => $campaign_id,
			'message'     => __( 'Failed to check campaign status', 'extrachill-newsletter' ),
		);
	}

	$exists = wp_remote_retrieve_body( $check_response );

	// Determine API endpoint.
	if ( trim( $exists ) === 'Campaign exists' ) {
		$api_endpoint = '/api/campaigns/update.php';
	} else {
		$api_endpoint = '/api/campaigns/create.php';
		$campaign_id  = null;
	}

	// Prepare campaign data.
	$campaign_data = array(
		'api_key'    => $config['api_key'],
		'from_name'  => $config['from_name'],
		'from_email' => $config['from_email'],
		'reply_to'   => $config['reply_to'],
		'subject'    => $email_data['subject'],
		'plain_text' => $email_data['plain_text'],
		'html_text'  => $email_data['html_template'],
		'brand_id'   => $config['brand_id'],
	);

	if ( $campaign_id ) {
		$campaign_data['campaign_id'] = $campaign_id;
	}

	// Send campaign to Sendy.
	$campaign_response = wp_remote_post(
		$config['sendy_url'] . $api_endpoint,
		array(
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'    => $campaign_data,
			'timeout' => 30,
		)
	);

	if ( is_wp_error( $campaign_response ) ) {
		error_log( 'Sendy campaign send/update failed: ' . $campaign_response->get_error_message() );
		return array(
			'success'     => false,
			'campaign_id' => $campaign_id,
			'message'     => __( 'Failed to send campaign to Sendy', 'extrachill-newsletter' ),
		);
	}

	$response_body = wp_remote_retrieve_body( $campaign_response );

	// Save new campaign ID if created.
	if ( ! $campaign_id && is_numeric( $response_body ) ) {
		update_post_meta( $post_id, '_sendy_campaign_id', $response_body );
		$campaign_id = (string) $response_body;

		error_log( sprintf( 'Newsletter campaign created for post %d with campaign ID: %s', $post_id, $response_body ) );
	}

	return array(
		'success'     => true,
		'campaign_id' => $campaign_id,
		'message'     => __( 'Campaign pushed to Sendy successfully', 'extrachill-newsletter' ),
	);
}

/**
 * Convert WordPress post to Sendy-compatible email content.
 *
 * Handles responsive images, YouTube thumbnail conversion, and email styling.
 * Moved from prepare_newsletter_email_content() in email-template.php.
 *
 * @param WP_Post $post Newsletter post object.
 * @return array Email data with subject, html_template, plain_text keys.
 */
function extrachill_newsletter_prepare_email_content( $post ) {
	$content = apply_filters( 'the_content', $post->post_content );

	// Ensure images are responsive.
	$content = preg_replace( '/<img(.+?)src="(.*?)"(.*?)>/i', '<img$1src="$2"$3 style="height: auto; max-width: 100%;">', $content );

	// Replace YouTube iframe embeds with clickable thumbnails.
	$content = preg_replace_callback(
		'/<figure[^>]*>\s*<div class="wp-block-embed__wrapper">\s*<iframe[^>]+src="https:\/\/www\.youtube\.com\/embed\/([a-zA-Z0-9_\-]+)[^"]*"[^>]*><\/iframe>\s*<\/div>\s*<\/figure>/s',
		function ( $matches ) {
			$video_id     = $matches[1];
			$video_url    = "https://www.youtube.com/watch?v={$video_id}";
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

	$main_site_url      = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'main' ) : home_url();
	$subject            = $post->post_title;
	$read_on_web_url    = get_permalink( $post->ID );
	$newsletter_site_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'newsletter' ) : home_url();

	$navbar_links = array(
		__( 'Main', 'extrachill-newsletter' )           => $main_site_url,
		__( 'Community', 'extrachill-newsletter' )      => function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : '',
		__( 'Events', 'extrachill-newsletter' )         => function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'events' ) : '',
		__( 'Shop', 'extrachill-newsletter' )           => function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'shop' ) : '',
		__( 'Artist Platform', 'extrachill-newsletter' ) => function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'artist' ) : '',
		__( 'Documentation', 'extrachill-newsletter' )  => function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'docs' ) : '',
		__( 'Newsletters', 'extrachill-newsletter' )    => $newsletter_site_url,
	);

	/** This filter is documented in inc/core/templates/email-template.php */
	$navbar_links = apply_filters( 'extrachill_newsletter_email_footer_links', $navbar_links, $post );

	$logo = '<a href="' . esc_url( $main_site_url ) . '" style="text-align: center; display: block; margin: 20px auto; border-bottom: 2px solid #53940b;"><img src="https://newsletter.extrachill.com/wp-content/uploads/sites/9/2026/01/Extra-Chill-Main-Logo-2026.png" alt="Extra Chill" width="60" style="padding-bottom: 10px; max-width: 60px; height: auto; display: block; margin: 0 auto; border: 0;"></a>';
	$content = $logo . $content;

	$unsubscribe_link = '<p style="text-align: center; margin: 18px 0 0; font-size: 14px; line-height: 1.5em;"><unsubscribe style="color: #6b7280; text-decoration: none;">Unsubscribe</unsubscribe></p>';

	$html_template = extrachill_newsletter_generate_email_html( $subject, $content, $unsubscribe_link, $read_on_web_url, $navbar_links );

	return array(
		'subject'      => $subject,
		'html_template' => $html_template,
		'plain_text'   => wp_strip_all_tags( $content ),
	);
}

/**
 * Generate full HTML email template.
 *
 * Moved from generate_email_html_template() in email-template.php.
 *
 * @param string $subject         Email subject.
 * @param string $content         Email body HTML.
 * @param string $unsubscribe_link Unsubscribe HTML.
 * @param string $read_on_web_url  URL to read on web.
 * @param array  $navbar_links     Footer navigation links.
 * @return string Complete HTML email.
 */
function extrachill_newsletter_generate_email_html( $subject, $content, $unsubscribe_link, $read_on_web_url, $navbar_links ) {
	$preheader_text = wp_strip_all_tags( $subject );

	$footer_links_html = '';
	$footer_separator   = ' &nbsp;|&nbsp; ';
	foreach ( $navbar_links as $label => $url ) {
		if ( empty( $url ) ) {
			continue;
		}

		$link             = '<a href="' . esc_url( $url ) . '" style="color: #0b5394; text-decoration: none;">' . esc_html( $label ) . '</a>';
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
