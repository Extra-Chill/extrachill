<?php
/**
 * Dev-only PHPStan/Homeboy stubs for runtime dependencies.
 */

namespace {
	const EXTRACHILL_USERS_PLUGIN_DIR = '';
	const EXTRACHILL_USERS_PLUGIN_URL = '';

	const EC_ANALYTICS_ARTIST_ACCESS_GRANTED_METHODS           = array( 'artist' );
	const EC_ANALYTICS_ARTIST_ACCESS_GRANTED_SOURCE_ONBOARDING = 'onboarding';
	const EC_ANALYTICS_EVENT_ARTIST_ACCESS_APPROVED            = 'artist_access_approved';
	const EC_ANALYTICS_EVENT_ARTIST_ACCESS_GRANTED             = 'artist_access_granted';
	const EC_ANALYTICS_EVENT_ARTIST_ACCESS_REQUESTED           = 'artist_access_requested';
	const EC_ANALYTICS_EVENT_ONBOARDING_COMPLETED              = 'onboarding_completed';
	const EC_ANALYTICS_EVENT_ONBOARDING_REMINDER_RECOVERED     = 'onboarding_reminder_recovered';
	const EC_ANALYTICS_EVENT_ONBOARDING_REMINDER_SENT          = 'onboarding_reminder_sent';
	const EC_ANALYTICS_EVENT_ONBOARDING_SUBMISSION_FAILED      = 'onboarding_submission_failed';
	const EC_ANALYTICS_EVENT_ONBOARDING_VIEWED                 = 'onboarding_viewed';
	const EC_ANALYTICS_EVENT_TEAM_MEMBER_ADDED                 = 'team_member_added';
	const EC_ANALYTICS_EVENT_TEAM_MEMBER_REMOVED               = 'team_member_removed';
	const EC_ANALYTICS_TEAM_EXPERIENCE_EVENTS                  = array( 'team_member_added', 'team_member_removed' );

	function ec_get_site_url( string $site ): string {}
	function ec_get_blog_id( string $site ): int {}
	function extrachill_set_notice( string $message, string $type = 'info' ): void {}
	function ec_render_turnstile_widget(): string {}
	function ec_verify_turnstile_response( string $token ): bool {}
	function ec_icon( string $name, string $class = '' ): string {}
	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	function ec_send_email( array $args ): array {}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	function ec_send_email_queued( array $args ): array {}

	/** @return array{token:string,expires_at:int} */
	function wp_native_auth_generate_access_token( int $user_id, string $device_id ): array {}

	/** @return array{token:string,expires_at:int} */
	function wp_native_auth_issue_refresh_token( int $user_id, string $device_id, string $device_name = '' ): array {}

	/** @return array<string,mixed>|\WP_Error */
	function wp_native_auth_refresh_tokens( string $refresh_token, string $device_id ): array|\WP_Error {}

	function wp_native_auth_revoke_refresh_token( int $user_id, string $device_id ): bool {}
}

namespace DataMachine\Core\OAuth {
	abstract class BaseAuthProvider {
		public function __construct( string $provider_slug ) {}

		/** @return array<string,mixed> */
		protected function get_config(): array {}
	}
}

namespace DataMachine\Abilities {
	class PermissionHelper {
		public static function run_as_authenticated( callable $callback, int $acting_user_id = 0 ): mixed {}
	}
}
