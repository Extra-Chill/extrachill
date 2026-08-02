<?php
/**
 * Deterministic vendor request browser fixture.
 *
 * @package ExtraChillEvents\Tests
 */

$workspace = 'workspace' === ( $_GET['scenario'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Deterministic fixture selector.
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/vendor-request.css"><title>Vendor Request Evidence</title></head><body><main>
<?php if ( $workspace ) : ?>
	<section class="ec-vendor-request"><header class="ec-vendor-request__hero"><p class="ec-vendor-request__eyebrow">Private coordinator workspace</p><h1>Night Market</h1></header><section class="ec-vendor-request__section"><h2>Applicants</h2><div class="ec-vendor-request__cards"><?php foreach ( array( 'Lowcountry Goods', 'Mobile Vinyl', 'Sweetgrass Studio' ) as $name ) : ?><article class="ec-vendor-request__card"><h3><?php echo htmlspecialchars( $name, ENT_QUOTES, 'UTF-8' ); ?></h3><p>Private application</p><form class="ec-vendor-request__form"><label>Review status<select><option>Submitted</option></select></label><button type="button">Save review</button></form></article><?php endforeach; ?></div></section></section>
<?php else : ?>
	<section class="ec-vendor-request" data-vendor-application data-endpoint="/api/vendor-applications"><header class="ec-vendor-request__hero"><p class="ec-vendor-request__eyebrow">Vendor application</p><h1>Night Market</h1></header><div class="ec-vendor-request__notice" role="status" aria-live="polite" hidden data-vendor-application-status></div><section class="ec-vendor-request__section"><form class="ec-vendor-request__form" data-vendor-application-form><input type="hidden" name="event_id" value="900"><input type="hidden" name="idempotency_key" value="browser-key"><label>Business name<input name="business_name" required></label><label>Point-of-contact name<input name="contact_name" required></label><label>Point-of-contact email<input type="email" name="contact_email" required></label><label>Phone (optional, private)<input type="tel" name="contact_phone"></label><label>Vendor category<select name="category" required><option value="Art">Art</option></select></label><label>Website or social link (optional)<input type="url" name="website_url"></label><label>Booth footprint<input name="footprint" required></label><label>Power needs<textarea name="power_needs" required></textarea></label><label>Insurance / permit notes<textarea name="insurance_notes"></textarea></label><label>Message<textarea name="message" required></textarea></label><label class="ec-vendor-request__consent"><input type="checkbox" name="contact_consent" value="1" required> I consent to event-scoped contact sharing.</label><input type="hidden" name="cf-turnstile-response" value="fixture-token"><button type="submit">Submit vendor application</button></form></section></section><script src="/assets/js/vendor-application.js"></script>
<?php endif; ?>
</main></body></html>
