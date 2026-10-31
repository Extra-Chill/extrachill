<?php
/**
 * Check Fatal Alarm Ability
 *
 * On-demand entry point to the PHP fatal-rate alarm. Runs the same evaluation
 * the ~5-min cron runs, with a `dry_run` flag that reports what WOULD alarm
 * without delivering or mutating dedupe state (a safe manual "is anything
 * fataling right now?" probe), and a `test_delivery` flag that sends one
 * labelled synthetic alarm through the configured channels to verify delivery
 * end to end without a real fatal (issue #304).
 *
 * Detection logic lives in inc/core/php-fatal-alarm.php; this ability is a thin
 * wrapper so the check is reachable from REST/CLI/chat like every other surface.
 *
 * @package ExtraChill\Analytics
 * @since 0.15.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_check_fatal_alarm_ability' );

/**
 * Register the check-fatal-alarm ability.
 */
function extrachill_analytics_register_check_fatal_alarm_ability() {
	wp_register_ability(
		'extrachill/check-fatal-alarm',
		array(
			'label'               => __( 'Check Fatal Alarm', 'extrachill-analytics' ),
			'description'         => __( 'Evaluate the live debug.log tail for new or spiking PHP fatal signatures. With dry_run, reports what would alarm without delivering. With test_delivery, sends a labelled synthetic alarm to verify delivery.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'dry_run'       => array(
						'type'        => 'boolean',
						'description' => __( 'Compute and return what would alarm without delivering or mutating dedupe state.', 'extrachill-analytics' ),
						'default'     => true,
					),
					'test_delivery' => array(
						'type'        => 'boolean',
						'description' => __( 'Send one clearly labelled synthetic alarm through the configured delivery channels. Overrides dry_run; never writes dedupe state.', 'extrachill-analytics' ),
						'default'     => false,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Evaluation result: alarms[], candidates[], threshold, window, and beacon delivery status.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_check_fatal_alarm',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
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
 * Execute callback for check-fatal-alarm ability.
 *
 * Defaults to dry_run=true so an accidental/manual call never delivers. Pass
 * dry_run=false to evaluate-and-deliver (what the cron does), or
 * test_delivery=true to send a synthetic alarm and verify delivery.
 *
 * @param array $input Input parameters.
 * @return array Evaluation result from extrachill_analytics_evaluate_fatal_alarm().
 */
function extrachill_analytics_ability_check_fatal_alarm( $input ) {
	$dry_run       = isset( $input['dry_run'] ) ? (bool) $input['dry_run'] : true;
	$test_delivery = ! empty( $input['test_delivery'] );

	return extrachill_analytics_evaluate_fatal_alarm( $dry_run, $test_delivery );
}
