<?php
/**
 * Email-Funnel Analytics Ability
 *
 * Surfaces the email funnel as a measurable channel alongside the platform's
 * other metrics: per-list active subscribers + unsubscribe rate, per-campaign
 * open/click rates, and aggregate engagement.
 *
 * This is a thin composition layer. The Sendy MECHANICS (DB aggregates + API
 * reads) live in the generic data-machine-business Sendy primitive
 * (`datamachine/sendy-metrics`). This ability owns the policy: it supplies the
 * EC Sendy connection config and caches the result for surfacing in the
 * analytics / platform-health world. When data-machine-business is not active
 * it returns a WP_Error explaining the dependency.
 *
 * @package ExtraChillNewsletter
 * @since 0.4.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_newsletter_register_analytics_ability' );

/**
 * Register the email-funnel analytics ability.
 */
function extrachill_newsletter_register_analytics_ability() {
	wp_register_ability(
		'extrachill/newsletter-funnel-metrics',
		array(
			'label'               => __( 'Newsletter Funnel Metrics', 'extrachill-newsletter' ),
			'description'         => __( 'Email-funnel metrics for the Extra Chill newsletter: per-list active subscribers + unsubscribe rate, per-campaign open/click rates, and aggregate engagement. Cached for 1 hour.', 'extrachill-newsletter' ),
			'category'            => 'extrachill-newsletter',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'force_refresh'  => array(
						'type'        => 'boolean',
						'description' => __( 'Bypass the 1-hour cache and read fresh.', 'extrachill-newsletter' ),
					),
					'campaign_limit' => array(
						'type'        => 'integer',
						'description' => __( 'Number of recent sent campaigns to analyse. Default 25.', 'extrachill-newsletter' ),
					),
				),
			),
			'output_schema'       => array(
				'type' => 'object',
			),
			'execute_callback'    => 'extrachill_newsletter_ability_funnel_metrics',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Cache key for the funnel-metrics payload.
 */
const EXTRACHILL_NEWSLETTER_FUNNEL_CACHE_KEY = 'extrachill_newsletter_funnel_metrics';

/**
 * Execute the email-funnel analytics ability.
 *
 * @param array $input {force_refresh, campaign_limit}.
 * @return array|WP_Error Funnel metrics payload or error.
 */
function extrachill_newsletter_ability_funnel_metrics( $input = array() ) {
	$force_refresh  = ! empty( $input['force_refresh'] );
	$campaign_limit = isset( $input['campaign_limit'] ) ? absint( $input['campaign_limit'] ) : 25;

	if ( ! $force_refresh ) {
		$cached = get_transient( EXTRACHILL_NEWSLETTER_FUNNEL_CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['total_active'] ) ) {
			$cached['cached'] = true;
			return $cached;
		}
	}

	$dmb = function_exists( 'extrachill_newsletter_get_sendy_ability' )
		? extrachill_newsletter_get_sendy_ability( 'datamachine/sendy-metrics' )
		: null;

	if ( ! $dmb ) {
		return new WP_Error(
			'sendy_primitive_unavailable',
			__( 'Email-funnel metrics require the Data Machine Business Sendy integration to be active.', 'extrachill-newsletter' )
		);
	}

	$result = $dmb->execute(
		array(
			'config'         => extrachill_newsletter_sendy_dmb_config(),
			'metric'         => 'funnel',
			'campaign_limit' => $campaign_limit,
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$result['cached'] = false;

	set_transient(
		EXTRACHILL_NEWSLETTER_FUNNEL_CACHE_KEY,
		$result,
		HOUR_IN_SECONDS
	);

	return $result;
}

/**
 * Invalidate the cached funnel-metrics payload.
 *
 * Safe no-op when the transient is absent.
 */
function extrachill_newsletter_invalidate_funnel_metrics_cache() {
	delete_transient( EXTRACHILL_NEWSLETTER_FUNNEL_CACHE_KEY );
}
