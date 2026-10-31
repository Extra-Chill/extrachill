<?php
/**
 * EC email header partial.
 *
 * Expected variables (set by the parent template):
 *   - $subject_html string Pre-escaped subject for the <title> tag.
 *   - $preheader    string Hidden preview text.
 *
 * @package ExtraChillNetwork\Templates\Email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$header_main_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'main' ) : 'https://extrachill.com';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="x-apple-disable-message-reformatting">
	<title><?php echo isset( $subject_html ) ? $subject_html : 'Extra Chill'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $subject_html is pre-escaped HTML supplied by caller (the _html suffix is the contract). ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1a1a1a;">
<?php if ( ! empty( $preheader ) ) : ?>
	<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;opacity:0;font-size:1px;line-height:1px;color:#f4f4f6;">
		<?php echo esc_html( $preheader ); ?>
	</div>
<?php endif; ?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f4f4f6;">
	<tr>
		<td align="center" style="padding:24px 12px;">
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e5e7eb;">
				<tr>
					<td align="center" style="background-color:#0a0a0a;padding:20px 24px;">
						<a href="<?php echo esc_url( $header_main_url ); ?>" style="color:#ffffff;text-decoration:none;font-size:22px;font-weight:700;letter-spacing:0.5px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
							Extra Chill
						</a>
					</td>
				</tr>
				<tr>
					<td style="padding:32px 32px 24px 32px;font-size:16px;line-height:1.6;color:#1a1a1a;">
