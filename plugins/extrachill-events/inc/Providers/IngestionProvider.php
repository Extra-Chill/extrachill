<?php
/**
 * Event ingestion and qualification orchestration.
 *
 * @package ExtraChillEvents\Providers
 */

namespace ExtraChillEvents\Providers;

defined( 'ABSPATH' ) || exit;

/** Registers ingestion hooks and optional Data Machine adapters. */
final class IngestionProvider {

	/**
	 * Whether runtime hooks have been registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/** Register the ingestion feature group once. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyVerdict.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyVerdictsTable.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyCohortDeriver.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/FlowOps.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyRecheckHandler.php';
		\ExtraChillEvents\Core\QualifyRecheckHandler::register();

		add_action( 'init', array( self::class, 'register_data_machine_handlers' ), 20 );
		add_filter( 'datamachine_tasks', array( self::class, 'register_tasks' ) );
		add_filter( 'datamachine_recurring_schedules', array( self::class, 'register_recurring_schedules' ) );
	}

	/**
	 * Add Events system tasks when Data Machine's task base class is available.
	 *
	 * @param array $tasks Registered task classes.
	 * @return array
	 */
	public static function register_tasks( array $tasks ): array {
		if ( ! class_exists( '\\DataMachine\\Engine\\AI\\System\\Tasks\\SystemTask' ) ) {
			return $tasks;
		}

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Steps/QualifyDigest/QualifyDigestSystemTask.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Steps/LocalSceneDigest/LocalSceneDigestSystemTask.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VenueExpansionRunner.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Steps/VenueExpansion/VenueExpansionSystemTask.php';
		$tasks[ \ExtraChillEvents\Steps\QualifyDigest\QualifyDigestSystemTask::TASK_TYPE ]       = \ExtraChillEvents\Steps\QualifyDigest\QualifyDigestSystemTask::class;
		$tasks[ \ExtraChillEvents\Steps\LocalSceneDigest\LocalSceneDigestSystemTask::TASK_TYPE ] = \ExtraChillEvents\Steps\LocalSceneDigest\LocalSceneDigestSystemTask::class;
		$tasks[ \ExtraChillEvents\Steps\VenueExpansion\VenueExpansionSystemTask::TASK_TYPE ]     = \ExtraChillEvents\Steps\VenueExpansion\VenueExpansionSystemTask::class;
		return $tasks;
	}

	/**
	 * Add the existing weekly Events schedules.
	 *
	 * @param array $schedules Registered recurring schedules.
	 * @return array
	 */
	public static function register_recurring_schedules( array $schedules ): array {
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Steps/QualifyDigest/QualifyDigestSystemTask.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Steps/LocalSceneDigest/LocalSceneDigestSystemTask.php';
		$schedules['extrachill_local_scene_digest'] = apply_filters(
			'extrachill_local_scene_digest_schedule',
			array(
				'task_type'          => \ExtraChillEvents\Steps\LocalSceneDigest\LocalSceneDigestSystemTask::TASK_TYPE,
				'interval'           => 'weekly',
				'enabled_setting'    => 'extrachill_local_scene_digest_enabled',
				'default_enabled'    => false,
				'label'              => 'Weekly Local Scene Digest — Thursdays 15:00 UTC',
				'first_run_callback' => 'strtotime',
				'first_run_arg'      => 'next thursday 15:00 UTC',
				'task_params'        => array(
					'days'    => 7,
					'limit'   => 8,
					'dry_run' => false,
				),
			)
		);
		$schedules['extrachill_qualify_digest']     = apply_filters(
			'dme_qualify_digest_schedule',
			array(
				'task_type'          => \ExtraChillEvents\Steps\QualifyDigest\QualifyDigestSystemTask::TASK_TYPE,
				'interval'           => 'weekly',
				'enabled_setting'    => 'dme_qualify_digest_enabled',
				'default_enabled'    => true,
				'label'              => 'Weekly — Mondays 09:00 UTC',
				'first_run_callback' => 'strtotime',
				'first_run_arg'      => 'next monday 09:00 UTC',
				'task_params'        => array(
					'since'   => '1 week ago',
					'format'  => 'html',
					'dry_run' => false,
				),
			)
		);
		return $schedules;
	}

	/** Initialize legacy handler adapters only when their Data Machine base exists. */
	public static function register_data_machine_handlers(): void {
		if ( ! class_exists( 'DataMachine\Core\Steps\Fetch\Handlers\FetchHandler' ) ) {
			return;
		}

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/handlers/event-roundup/EventRoundupSettings.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/handlers/event-roundup/EventRoundupHandler.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/handlers/event-roundup/RoundupPublishSettings.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/handlers/event-roundup/RoundupPublishHandler.php';

		new \ExtraChillEvents\Handlers\EventRoundup\EventRoundupHandler();
		new \ExtraChillEvents\Handlers\EventRoundup\RoundupPublishHandler();

		if ( file_exists( EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Templates/EventRoundupTemplate.php' ) ) {
			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Templates/EventRoundupTemplate.php';
			add_filter( 'datamachine/image_generation/templates', array( self::class, 'register_image_template' ) );
		}
	}

	/**
	 * Add the event roundup image template.
	 *
	 * @param array $templates Registered image templates.
	 * @return array
	 */
	public static function register_image_template( array $templates ): array {
		$templates['event_roundup'] = \ExtraChillEvents\Templates\EventRoundupTemplate::class;
		return $templates;
	}
}
