<?php
/**
 * Track CTA Click Ability
 *
 * Write-side ability for the generic, no-per-button `cta_click` event
 * (Extra-Chill/extrachill-analytics#293). A single delegated browser listener
 * (assets/js/outbound-tracking.js) fires this for any click matching the
 * design-system button classes (`.button-1`, `.button-2`, `.button-3`,
 * `.button-danger`), an explicit `[data-ec-track]` element, or a submit
 * control inside a form.
 *
 * REST-visible like `extrachill/track-page-view` (not the flexible, non-REST
 * `extrachill/track-analytics-event` ability): the browser calls this
 * ability directly over the universal wp-abilities transport, so no new
 * `extrachill-api` route is needed and the generic event-write ability never
 * has to accept an arbitrary client-controlled event_type.
 *
 * Naming: `cta_override` (from the element's `data-ec-track` attribute) wins
 * when present; otherwise the final `cta` id is a short, stable hash of
 * route + label + dest computed HERE (not in the browser) so every caller —
 * any future non-JS emitter included — gets the identical id for the same
 * three inputs, and so the same button on the same route always resolves to
 * the same id across page loads and re-renders.
 *
 * @package ExtraChill\Analytics
 * @since   0.38.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Register the track-cta-click ability.
 */
function extrachill_analytics_register_track_cta_click_ability(): void {
	wp_register_ability(
		'extrachill/track-cta-click',
		array(
			'label'               => __( 'Track CTA Click', 'extrachill-analytics' ),
			'description'         => __( 'Record a cta_click event for a design-system button or explicitly-tracked element, with no per-button emit code required.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'cta_override' => array(
						'type'        => 'string',
						'description' => __( 'Explicit CTA id from the clicked element\'s data-ec-track attribute. Overrides the automatic hash when present.', 'extrachill-analytics' ),
						'maxLength'   => 80,
						'default'     => '',
					),
					'label'        => array(
						'type'        => 'string',
						'description' => __( 'The clicked element\'s visible text, trimmed.', 'extrachill-analytics' ),
						'maxLength'   => 80,
					),
					'dest'         => array(
						'type'        => 'string',
						'description' => __( 'Destination host+path (query string and fragment stripped), or the current page when the element has no href.', 'extrachill-analytics' ),
						'maxLength'   => 512,
					),
					'placement'    => array(
						'type'        => 'string',
						'description' => __( 'The closest HTML5 landmark (header, main, footer, nav, aside), or the nearest ancestor data-ec-track-placement override.', 'extrachill-analytics' ),
						'maxLength'   => 40,
					),
					'route'        => array(
						'type'        => 'string',
						'description' => __( 'Route family of the page the click occurred on, classified server-side at enqueue time.', 'extrachill-analytics' ),
						'enum'        => extrachill_analytics_route_families(),
					),
					'source_url'   => array(
						'type'        => 'string',
						'description' => __( 'URL of the page where the click occurred.', 'extrachill-analytics' ),
						'maxLength'   => 2048,
					),
				),
				'required'   => array( 'label', 'dest', 'placement', 'route', 'source_url' ),
			),
			'output_schema'       => array(
				'type'        => 'integer',
				'description' => __( 'Event ID on success, 0 on failure.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_track_cta_click',
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
 * Execute callback for track-cta-click ability.
 *
 * Normalizes and bounds every dimension server-side, resolves the final
 * `cta` id (explicit override, or a short stable hash of route|label|dest),
 * then delegates to the shared public-event write path
 * (`extrachill_analytics_ability_track_event()`) so admission (rate limiting,
 * origin checks, the closed schema gate) and the `is_bot` stamp are the exact
 * same code every other public browser event uses — no duplicated gate.
 *
 * @param array $input Input parameters.
 * @return int|WP_Error Event ID on success, 0 on failure, or an admission error.
 */
function extrachill_analytics_ability_track_cta_click( array $input ) {
	$route = isset( $input['route'] ) ? sanitize_key( (string) $input['route'] ) : '';
	if ( ! in_array( $route, extrachill_analytics_route_families(), true ) ) {
		$route = 'other';
	}

	$label = isset( $input['label'] ) ? (string) $input['label'] : '';
	$label = trim( (string) preg_replace( '/\s+/', ' ', $label ) );
	$label = substr( $label, 0, 80 );

	$dest = isset( $input['dest'] ) ? extrachill_analytics_normalize_cta_dest( (string) $input['dest'] ) : '';

	$placement = isset( $input['placement'] ) ? trim( (string) $input['placement'] ) : '';
	$placement = substr( $placement, 0, 40 );

	$source_url = isset( $input['source_url'] ) ? (string) $input['source_url'] : '';

	if ( '' === $label || '' === $dest || '' === $placement ) {
		return new WP_Error(
			'invalid_cta_click',
			__( 'A CTA click requires a label, destination, and placement.', 'extrachill-analytics' ),
			array( 'status' => 400 )
		);
	}

	$override = isset( $input['cta_override'] ) ? trim( (string) $input['cta_override'] ) : '';
	$cta      = '' !== $override
		? substr( $override, 0, 80 )
		: hash( 'crc32b', $route . '|' . $label . '|' . $dest );

	return extrachill_analytics_ability_track_event(
		array(
			'event_type' => EC_ANALYTICS_EVENT_CTA_CLICK,
			'event_data' => array(
				'cta'       => $cta,
				'label'     => $label,
				'dest'      => $dest,
				'placement' => $placement,
				'route'     => $route,
			),
			'source_url' => $source_url,
		)
	);
}
