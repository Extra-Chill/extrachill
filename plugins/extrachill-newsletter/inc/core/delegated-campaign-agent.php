<?php
/**
 * Stable execution agent for delegated campaigns.
 *
 * @package ExtraChillNewsletter
 */

defined( 'ABSPATH' ) || exit;

/** Register the stable Newsletter-owned execution agent. */
function extrachill_newsletter_register_delegated_campaign_agent() {
	if ( ! function_exists( 'wp_register_agent' ) ) {
		return;
	}

	wp_register_agent(
		EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_AGENT,
		array(
			'label'          => __( 'Newsletter Campaign Owner', 'extrachill-newsletter' ),
			'description'    => __( 'Executes bounded Newsletter-owned delegated campaign operations.', 'extrachill-newsletter' ),
			'owner_resolver' => 'extrachill_newsletter_delegated_campaign_owner_user_id',
		)
	);
}

/** Resolve the stable user that owns the delegated campaign agent. */
function extrachill_newsletter_delegated_campaign_owner_user_id() {
	return absint(
		apply_filters(
			'extrachill_newsletter_delegated_campaign_owner_user_id',
			get_site_option( 'admin_user_id', 0 ),
			array(),
			array()
		)
	);
}
