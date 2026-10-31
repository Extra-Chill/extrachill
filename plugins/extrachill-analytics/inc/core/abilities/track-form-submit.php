<?php
/**
 * Track Form Submit Ability
 *
 * Write-side ability for the generic `form_submit` event
 * (Extra-Chill/extrachill-analytics#293). The same delegated browser listener
 * that emits `cta_click` (assets/js/outbound-tracking.js) also listens for
 * `submit` and fires this ability on every form submission it can name.
 *
 * NEVER field values: the closed schema in write-integrity.php admits only
 * `form`, `placement`, and `route` for this event type — no form field, no
 * input value, ever reaches this ability or the events table.
 *
 * `form` is resolved by the browser in priority order: the form's
 * `data-ec-track` attribute, then its `id`, then its `name`. A form with none
 * of the three has no stable identity to report on, so the browser does not
 * emit for it at all (see assets/js/outbound-tracking.js) rather than writing
 * an anonymous, indistinguishable `form_submit` row.
 *
 * REST-visible like `extrachill/track-page-view` and `extrachill/track-cta-
 * click`; see track-cta-click.php for why this is a dedicated REST-visible
 * ability instead of a new `extrachill-api` route or a widened
 * `extrachill/track-analytics-event`.
 *
 * @package ExtraChill\Analytics
 * @since   0.38.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Register the track-form-submit ability.
 */
function extrachill_analytics_register_track_form_submit_ability(): void {
	wp_register_ability(
		'extrachill/track-form-submit',
		array(
			'label'               => __( 'Track Form Submit', 'extrachill-analytics' ),
			'description'         => __( 'Record a form_submit event for a named form. Never carries field values.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'form'       => array(
						'type'        => 'string',
						'description' => __( 'The submitted form\'s identity: data-ec-track, then id, then name.', 'extrachill-analytics' ),
						'maxLength'   => 80,
					),
					'placement'  => array(
						'type'        => 'string',
						'description' => __( 'The closest HTML5 landmark (header, main, footer, nav, aside), or the nearest ancestor data-ec-track-placement override.', 'extrachill-analytics' ),
						'maxLength'   => 40,
					),
					'route'      => array(
						'type'        => 'string',
						'description' => __( 'Route family of the page the submit occurred on, classified server-side at enqueue time.', 'extrachill-analytics' ),
						'enum'        => extrachill_analytics_route_families(),
					),
					'source_url' => array(
						'type'        => 'string',
						'description' => __( 'URL of the page where the submit occurred.', 'extrachill-analytics' ),
						'maxLength'   => 2048,
					),
				),
				'required'   => array( 'form', 'placement', 'route', 'source_url' ),
			),
			'output_schema'       => array(
				'type'        => 'integer',
				'description' => __( 'Event ID on success, 0 on failure.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_track_form_submit',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
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
 * Execute callback for track-form-submit ability.
 *
 * Delegates to `extrachill_analytics_ability_track_event()` for the same
 * reason track-cta-click does: one shared admission gate, one shared
 * `is_bot` stamp, for every public browser event.
 *
 * @param array $input Input parameters.
 * @return int|WP_Error Event ID on success, 0 on failure, or an admission error.
 */
function extrachill_analytics_ability_track_form_submit( array $input ) {
	$route = isset( $input['route'] ) ? sanitize_key( (string) $input['route'] ) : '';
	if ( ! in_array( $route, extrachill_analytics_route_families(), true ) ) {
		$route = 'other';
	}

	$form = isset( $input['form'] ) ? trim( (string) $input['form'] ) : '';
	$form = substr( $form, 0, 80 );

	$placement = isset( $input['placement'] ) ? trim( (string) $input['placement'] ) : '';
	$placement = substr( $placement, 0, 40 );

	$source_url = isset( $input['source_url'] ) ? (string) $input['source_url'] : '';

	if ( '' === $form || '' === $placement ) {
		return new WP_Error(
			'invalid_form_submit',
			__( 'A form_submit requires an identifiable form and placement.', 'extrachill-analytics' ),
			array( 'status' => 400 )
		);
	}

	return extrachill_analytics_ability_track_event(
		array(
			'event_type' => EC_ANALYTICS_EVENT_FORM_SUBMIT,
			'event_data' => array(
				'form'      => $form,
				'placement' => $placement,
				'route'     => $route,
			),
			'source_url' => $source_url,
		)
	);
}
