<?php
/**
 * Check Fatal Alarm Ability
 *
 * On-demand entry point to the PHP fatal-rate alarm. Runs the same evaluation
 * the ~5-min cron runs, with a `dry_run` flag that reports what WOULD alarm
 * without sending Discord or mutating dedupe state — the preferred verification
 * path (and a safe manual "is anything fataling right now?" probe).
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
			'description'         => __( 'Evaluate the live debug.log tail for new or spiking PHP fatal signatures. With dry_run, reports what would alarm without sending Discord.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'dry_run' => array(
						'type'        => 'boolean',
						'description' => __( 'Compute and return what would alarm without sending Discord or mutating dedupe state.', 'extrachill-analytics' ),
						'default'     => true,
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
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Execute callback for check-fatal-alarm ability.
 *
 * Defaults to dry_run=true so an accidental/manual call never beacons. Pass
 * dry_run=false to actually evaluate-and-send (what the cron does).
 *
 * @param array $input Input parameters.
 * @return array Evaluation result from extrachill_analytics_evaluate_fatal_alarm().
 */
function extrachill_analytics_ability_check_fatal_alarm( $input ) {
	$dry_run = isset( $input['dry_run'] ) ? (bool) $input['dry_run'] : true;

	return extrachill_analytics_evaluate_fatal_alarm( $dry_run );
}
