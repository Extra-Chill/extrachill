<?php
/**
 * Focused behavior tests for canonical Local Scene CLI adapters.
 */

define( 'ABSPATH', __DIR__ . '/' );

class UserLocalSceneCommandTestError extends RuntimeException {}

class WP_CLI {
	public static $messages = array();

	public static function error( $message ) {
		throw new UserLocalSceneCommandTestError( $message );
	}

	public static function success( $message ) {
		self::$messages[] = 'Success: ' . $message;
	}

	public static function warning( $message ) {
		self::$messages[] = 'Warning: ' . $message;
	}

	public static function log( $message ) {
		self::$messages[] = $message;
	}
}

class UserLocalSceneTestAbility {
	public $inputs = array();

	public function execute( $input ) {
		$this->inputs[] = $input;
		return $input;
	}
}

$user_local_scene_abilities = array();

function get_user_by( $field, $value ) {
	return (object) array( 'ID' => 7, 'user_login' => 'chubes' );
}

function is_email( $value ) {
	return false;
}

function wp_get_ability( $name ) {
	global $user_local_scene_abilities;
	return $user_local_scene_abilities[ $name ] ?? null;
}

function is_wp_error( $value ) {
	return false;
}

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}

function user_local_scene_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
	}
}

require_once dirname( __DIR__ ) . '/inc/Commands/Users/SettingsCommand.php';
require_once dirname( __DIR__ ) . '/inc/Commands/Users/ProfileCommand.php';

use ExtraChill\CLI\Commands\Users\ProfileCommand;
use ExtraChill\CLI\Commands\Users\SettingsCommand;

$settings_ability = new UserLocalSceneTestAbility();
$profile_ability  = new UserLocalSceneTestAbility();
$user_local_scene_abilities = array(
	'extrachill/update-user-settings' => $settings_ability,
	'extrachill/update-user-profile'  => $profile_ability,
);

( new SettingsCommand() )->update(
	array( 'chubes' ),
	array(
		'local-scene'            => 'charleston-sc',
		'local-scene-visibility' => 'private',
	)
);
user_local_scene_assert_same(
	array( array( 'user_id' => 7, 'local_scene' => 'charleston-sc', 'local_scene_visibility' => 'private' ) ),
	$settings_ability->inputs,
	'Settings update must pass canonical Local Scene fields to the Users settings ability.'
);

$settings_ability->inputs = array();
WP_CLI::$messages         = array();
( new ProfileCommand() )->update(
	array( 'chubes' ),
	array( 'local-city' => 'austin-tx' )
);
user_local_scene_assert_same(
	array( array( 'user_id' => 7, 'local_scene' => 'austin-tx' ) ),
	$settings_ability->inputs,
	'Legacy local-city must write only canonical Local Scene state through the settings ability.'
);
user_local_scene_assert_same( array(), $profile_ability->inputs, 'Legacy local-city must not write parallel profile state.' );
user_local_scene_assert_same(
	'Warning: --local-city is deprecated; use --local-scene=<location-slug> instead.',
	WP_CLI::$messages[0],
	'Legacy local-city must emit clear deprecation guidance.'
);

fwrite( STDOUT, "UserLocalSceneCommand tests passed.\n" );
