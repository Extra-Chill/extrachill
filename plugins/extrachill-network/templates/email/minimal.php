<?php
/**
 * Extra Chill minimal email template.
 *
 * Lightweight wrapper for transactional sends — password reset, 2FA codes,
 * settings change notifications, order confirmations. Same context shape
 * as the branded template, but renders without the EC platform link grid
 * so the message stays focused on the single action it carries.
 *
 * Expected `$context` keys (all optional, defaults applied):
 *   - subject_html   string Pre-escaped subject for <title>.
 *   - body_html      string Sanitized HTML body content.
 *   - recipient_name string Greeting personalization.
 *   - cta_url        string Optional primary CTA URL.
 *   - cta_label      string Optional primary CTA label.
 *   - preheader      string Hidden preview text.
 *
 * @package ExtraChillNetwork\Templates\Email
 * @var array $context Template context.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$context = isset( $context ) && is_array( $context ) ? $context : array();

$subject_html   = ! empty( $context['subject_html'] ) ? (string) $context['subject_html'] : 'Extra Chill';
$body_html      = isset( $context['body_html'] ) ? (string) $context['body_html'] : '';
$recipient_name = isset( $context['recipient_name'] ) ? (string) $context['recipient_name'] : '';
$cta_url        = isset( $context['cta_url'] ) ? (string) $context['cta_url'] : '';
$cta_label      = isset( $context['cta_label'] ) ? (string) $context['cta_label'] : '';
$preheader      = isset( $context['preheader'] ) ? (string) $context['preheader'] : '';

require __DIR__ . '/partials/header.php';
?>
<?php if ( '' !== trim( $recipient_name ) ) : ?>
	<p style="margin:0 0 16px 0;font-size:16px;line-height:1.6;">Hey <?php echo esc_html( $recipient_name ); ?>,</p>
<?php endif; ?>

<?php echo $body_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $body_html is pre-escaped HTML supplied by caller (the _html suffix is the contract). ?>

<?php if ( '' !== trim( $cta_url ) && '' !== trim( $cta_label ) ) : ?>
	<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">
		<tr>
			<td style="background-color:#0a0a0a;border-radius:6px;">
				<a href="<?php echo esc_url( $cta_url ); ?>" style="display:inline-block;padding:12px 24px;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;">
					<?php echo esc_html( $cta_label ); ?>
				</a>
			</td>
		</tr>
	</table>
<?php endif; ?>

<p style="margin:24px 0 0 0;font-size:16px;line-height:1.6;">Much love,<br>Extra Chill</p>

<?php
require __DIR__ . '/partials/footer.php';
