<?php
/**
 * Extra Chill mail helpers.
 *
 * - `extrachill_mail_site_id()` / `ec_mail_site_id()` resolve the closest
 *   SMTP-configured site so outgoing mail does not silently fail from
 *   subsites that lack credentials.
 * - `ec_send_email()` / `ec_send_email_queued()` are thin one-line migration
 *   targets that delegate to the Data Machine `datamachine/send-email` and
 *   `datamachine/send-email-queued` abilities.
 *
 * EC-branded templates (`extrachill/branded`, `extrachill/minimal`) are
 * registered against the DM `datamachine_email_templates` filter — see
 * {@see extrachill_register_email_templates()} below.
 *
 * @package ExtraChillNetwork\Core\Mail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical Easy WP SMTP option key. Stored per-site under `$wpdb->options`.
 */
const EXTRACHILL_EASY_WP_SMTP_OPTION = 'easy_wp_smtp';

/**
 * Transient TTL for the per-site SMTP probe cache (seconds).
 *
 * Short enough that a fresh SMTP configuration is picked up on the next
 * send within 15 minutes even if the `updated_option` bust hook fails to
 * fire (e.g. config written via WP-CLI, direct SQL, or a different code
 * path that bypasses the WP options API).
 */
const EXTRACHILL_SMTP_PROBE_TTL = 15 * MINUTE_IN_SECONDS;

/**
 * Does the given site have Easy WP SMTP configured with a working mailer?
 *
 * Probes the per-site `easy_wp_smtp` option to determine whether the site
 * has a non-default mailer selected and the provider-specific credentials
 * populated. The option lives in `$wpdb->options`, so this performs a
 * `switch_to_blog()` when probing a site other than the current one.
 *
 * Result is cached in a site (network) transient keyed by blog ID for
 * `EXTRACHILL_SMTP_PROBE_TTL` to avoid an options-table hit on every send.
 * The transient is busted automatically when the underlying option is
 * updated — see `extrachill_bust_smtp_probe_cache()`.
 *
 * @param int $blog_id Blog ID to probe.
 * @return bool True if the site has a non-default mailer configured.
 */
function extrachill_site_has_smtp( $blog_id ) {
	$blog_id = (int) $blog_id;
	if ( $blog_id <= 0 ) {
		return false;
	}

	$cache_key = 'ec_site_has_smtp_' . $blog_id;
	$cached    = get_site_transient( $cache_key );
	if ( false !== $cached ) {
		return (bool) $cached;
	}

	$switched = false;
	if ( function_exists( 'get_current_blog_id' ) && (int) get_current_blog_id() !== $blog_id ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	$configured = false;
	$cfg        = get_option( EXTRACHILL_EASY_WP_SMTP_OPTION );

	if ( is_array( $cfg ) ) {
		$mail   = isset( $cfg['mail'] ) && is_array( $cfg['mail'] ) ? $cfg['mail'] : array();
		$mailer = isset( $mail['mailer'] ) ? (string) $mail['mailer'] : '';

		// Default PHP `mail()` is the fall-through state; we only treat
		// the site as "SMTP configured" when a real provider was picked.
		if ( '' !== $mailer && 'mail' !== $mailer ) {
			// Provider block lives at `$cfg[ $mailer ]` (e.g. `$cfg['smtp']`).
			$provider = isset( $cfg[ $mailer ] ) && is_array( $cfg[ $mailer ] ) ? $cfg[ $mailer ] : array();
			// Any non-empty provider block counts — Easy WP SMTP itself
			// has no single canonical "is_complete" flag, so we trust
			// admin intent: if they picked a mailer and saved any keys
			// for it, the site is configured.
			$configured = ! empty( array_filter( $provider, static function ( $v ) {
				return null !== $v && '' !== $v && array() !== $v;
			} ) );
		}
	}

	if ( $switched ) {
		restore_current_blog();
	}

	set_site_transient( $cache_key, $configured ? 1 : 0, EXTRACHILL_SMTP_PROBE_TTL );

	return $configured;
}

/**
 * Return the live list of SMTP-configured site IDs on the network.
 *
 * Walks every active site on the network and probes its `easy_wp_smtp`
 * option via {@see extrachill_site_has_smtp()}. The per-site probe is
 * transient-cached, so this scan is cheap on warm cache.
 *
 * Filter `extrachill_smtp_configured_sites` to force a site into or out
 * of the result (e.g. a site whose creds are known broken at a higher
 * level). The default is the live list from the database — no static
 * allowlist.
 *
 * @return int[] Sorted list of blog IDs that have Easy WP SMTP configured.
 */
function extrachill_smtp_configured_sites() {
	$sites = array();

	if ( function_exists( 'get_sites' ) ) {
		$ids = get_sites(
			array(
				'number'   => 0,
				'fields'   => 'ids',
				'archived' => 0,
				'deleted'  => 0,
				'spam'     => 0,
			)
		);

		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && extrachill_site_has_smtp( $id ) ) {
				$sites[] = $id;
			}
		}
	}

	/**
	 * Filter the resolved SMTP-configured site allowlist.
	 *
	 * Use to force-add a site whose probe is incorrectly false (e.g.
	 * credentials live in `wp-config.php` constants), or to remove a
	 * site whose creds are known broken at a higher level.
	 *
	 * @param int[] $sites Live list produced by probing each site.
	 */
	$sites = apply_filters( 'extrachill_smtp_configured_sites', $sites );

	// Defensive normalization — accept any iterable, coerce to ints, drop
	// zero/negative IDs, de-dupe.
	$out = array();
	foreach ( (array) $sites as $id ) {
		$id = (int) $id;
		if ( $id > 0 ) {
			$out[ $id ] = $id;
		}
	}

	return array_values( $out );
}

/**
 * Resolve the blog ID to use for outgoing mail in the current context.
 *
 * Resolution order:
 *   1. If the current site has Easy WP SMTP configured, return its ID
 *      (no switch needed — `wp_mail()` works on this site).
 *   2. Otherwise fall back to `ec_get_blog_id('main')`.
 *
 * Short-circuits on a single per-site probe rather than scanning every
 * site on the network — `extrachill_smtp_configured_sites()` is only
 * needed for callers that want the full list.
 *
 * Safe to call from any subsite context, including before `init`.
 *
 * @return int Blog ID of the SMTP-configured site to send mail through.
 */
function extrachill_mail_site_id() {
	$current = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

	if ( $current > 0 && extrachill_site_has_smtp( $current ) ) {
		return $current;
	}

	$main = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : (int) EC_BLOG_ID_MAIN;

	return $main > 0 ? $main : (int) EC_BLOG_ID_MAIN;
}

/**
 * Bust the SMTP probe transient for the current site when the Easy WP SMTP
 * option is added/updated/deleted on this site.
 *
 * Wired against the standard WP options hooks — they fire inside the
 * blog context that triggered the change, so `get_current_blog_id()`
 * is the correct key.
 *
 * @param string $option Name of the option being mutated.
 * @return void
 */
function extrachill_bust_smtp_probe_cache( $option ) {
	if ( EXTRACHILL_EASY_WP_SMTP_OPTION !== $option ) {
		return;
	}

	$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	if ( $blog_id > 0 ) {
		delete_site_transient( 'ec_site_has_smtp_' . $blog_id );
	}
}
add_action( 'updated_option', 'extrachill_bust_smtp_probe_cache' );
add_action( 'added_option', 'extrachill_bust_smtp_probe_cache' );
add_action( 'deleted_option', 'extrachill_bust_smtp_probe_cache' );

/**
 * Alias matching the shorter `ec_*` naming convention.
 *
 * @return int Blog ID of the SMTP-configured site to send mail through.
 */
function ec_mail_site_id() {
	return extrachill_mail_site_id();
}

/**
 * Send an EC-branded email via the `datamachine/send-email` ability.
 *
 * One-line migration target for plugins moving off raw `wp_mail()`. Wraps
 * the DM ability with sensible Extra Chill defaults:
 *   - `template`     => `extrachill/branded` (full link grid + footer)
 *   - `mail_site_id` => `extrachill_mail_site_id()` (auto-resolves SMTP site)
 *
 * Caller can override either default by passing them in `$args`.
 *
 * The underlying ability handles `switch_to_blog()` plumbing internally
 * when `mail_site_id` is provided — callers must NOT wrap this in their
 * own `switch_to_blog()`.
 *
 * Every send runs as the system through extrachill_mail_run_as_system(), so
 * it works the same whoever owns the request (visitor, customer, contributor,
 * background worker). WP_Error results are normalized to the array envelope.
 *
 * @see datamachine/send-email
 *
 * @param array $args Arguments forwarded to the ability. Required keys
 *                    documented by the ability: `to`, `subject`. When using
 *                    a template, pass `context` (array) instead of `body`.
 * @return array Result array as returned by the ability:
 *               `[ 'success' => bool, 'message' => string, ... ]`.
 *               On bootstrap failure: `[ 'success' => false, 'error' => string ]`.
 */
function ec_send_email( array $args ) {
	$defaults = array(
		'template'     => 'extrachill/branded',
		'mail_site_id' => extrachill_mail_site_id(),
	);

	$args = wp_parse_args( $args, $defaults );

	if ( ! function_exists( 'wp_get_ability' ) ) {
		return array(
			'success' => false,
			'error'   => 'WordPress Abilities API not available — datamachine/send-email cannot be resolved.',
		);
	}

	$ability = wp_get_ability( 'datamachine/send-email' );
	if ( ! $ability ) {
		return array(
			'success' => false,
			'error'   => 'Ability datamachine/send-email is not registered. Is the Data Machine plugin active?',
		);
	}

	return extrachill_mail_normalize_result( extrachill_mail_run_as_system( static fn() => $ability->execute( $args ) ) );
}

/**
 * Queue an EC-branded email via the `datamachine/send-email-queued` ability.
 *
 * Identical shape to {@see ec_send_email()} but routes through the
 * Action Scheduler-backed queued variant for non-blocking sends.
 * Supports an optional `send_at` (ISO8601 string) for delayed delivery.
 *
 * @see datamachine/send-email-queued
 *
 * @param array $args Arguments forwarded to the ability.
 * @return array Result array as returned by the ability.
 */
function ec_send_email_queued( array $args ) {
	$defaults = array(
		'template'     => 'extrachill/branded',
		'mail_site_id' => extrachill_mail_site_id(),
	);

	$args = wp_parse_args( $args, $defaults );

	if ( ! function_exists( 'wp_get_ability' ) ) {
		return array(
			'success' => false,
			'error'   => 'WordPress Abilities API not available — datamachine/send-email-queued cannot be resolved.',
		);
	}

	$ability = wp_get_ability( 'datamachine/send-email-queued' );
	if ( ! $ability ) {
		return array(
			'success' => false,
			'error'   => 'Ability datamachine/send-email-queued is not registered. Is the Data Machine plugin active?',
		);
	}

	return extrachill_mail_normalize_result( extrachill_mail_run_as_system( static fn() => $ability->execute( $args ) ) );
}

/**
 * Run a platform mail send as the system.
 *
 * Extra Chill product mail (RSVP passes, order notices, editorial alerts,
 * digests, contact-form and registration mail) is sent by trusted server code
 * on the platform's behalf, not by whichever user owns the current request.
 * Data Machine's mail abilities gate the site default sender on the acting
 * principal: without this, a send during a contributor's or customer's request
 * is refused, a queued send records that user as issuer and is denied at the
 * worker, and a no-user send only passes under WP-CLI.
 *
 * PermissionHelper::run_as_system() (data-machine#3572) is a PHP-only context,
 * unreachable from REST or MCP. Callers must NOT wrap ec_send_email() or
 * ec_send_email_queued() in their own PermissionHelper context.
 *
 * @param callable $send Send callback.
 * @return mixed Ability result.
 */
function extrachill_mail_run_as_system( callable $send ) {
	$helper = '\\DataMachine\\Abilities\\PermissionHelper';
	if ( class_exists( $helper ) && method_exists( $helper, 'run_as_system' ) ) {
		return $helper::run_as_system( $send );
	}
	return $send();
}

/**
 * Normalize an ability result into the documented array envelope.
 *
 * Abilities return WP_Error on permission or validation failure. These
 * wrappers document an array return and callers index into it, so a WP_Error
 * becomes `[ 'success' => false, 'error' => ..., 'error_code' => ... ]`.
 *
 * @param mixed $result Ability result.
 * @return array
 */
function extrachill_mail_normalize_result( $result ) {
	if ( is_wp_error( $result ) ) {
		return array(
			'success'    => false,
			'error'      => $result->get_error_message(),
			'error_code' => $result->get_error_code(),
		);
	}
	if ( ! is_array( $result ) ) {
		return array(
			'success' => false,
			'error'   => 'Mail ability returned ' . gettype( $result ) . ' instead of an array.',
		);
	}
	return $result;
}

/**
 * Register EC-branded email templates against the DM template filter.
 *
 * Templates are PHP partials under `templates/email/`. Each callable
 * receives `array $context` and returns the rendered HTML string.
 *
 * The template file path itself is filterable via
 * `extrachill_email_template_path` so consumers can override markup
 * without forking this plugin (e.g. a child plugin can return a
 * different absolute path for `extrachill/branded`).
 *
 * Documented context keys (all optional, partials must provide defaults):
 *   - `subject_html`    Pre-escaped subject for the `<title>` tag.
 *   - `body_html`       Main message HTML, already sanitized.
 *   - `recipient_name`  Greeting personalization.
 *   - `cta_url`         Optional call-to-action URL.
 *   - `cta_label`       Optional call-to-action label.
 *   - `preheader`       Preview text shown by mail clients.
 *
 * @param array $templates Existing template map keyed by template ID.
 * @return array Modified template map.
 */
function extrachill_register_email_templates( $templates ) {
	// Defensive: other filter hooks may have returned a non-array.
	// PHPStan narrows `$templates` from the docblock, but at runtime
	// `apply_filters` makes no such guarantee.
	if ( ! is_array( $templates ) ) { // @phpstan-ignore-line
		$templates = array();
	}

	$templates['extrachill/branded'] = function ( array $context ) {
		return extrachill_render_email_template( 'branded', $context );
	};

	$templates['extrachill/minimal'] = function ( array $context ) {
		return extrachill_render_email_template( 'minimal', $context );
	};

	return $templates;
}
add_filter( 'datamachine_email_templates', 'extrachill_register_email_templates' );

/**
 * Render a template partial with output buffering.
 *
 * Resolves the template path via the `extrachill_email_template_path`
 * filter so a child plugin can swap markup without forking.
 *
 * @param string $template_id Template ID (e.g. `branded`, `minimal`).
 * @param array  $context     Template variables.
 * @return string Rendered HTML. Empty string if the partial is missing.
 */
function extrachill_render_email_template( $template_id, array $context ) {
	$default_path = EXTRACHILL_NETWORK_PLUGIN_DIR . 'templates/email/' . $template_id . '.php';

	/**
	 * Filter the resolved absolute path to an EC email template partial.
	 *
	 * @param string $default_path Absolute path to the bundled partial.
	 * @param string $template_id  Template ID being rendered.
	 * @param array  $context      Context array passed to the template.
	 */
	$path = apply_filters( 'extrachill_email_template_path', $default_path, $template_id, $context );

	// Defensive: filter may have returned a non-string.
	if ( ! is_string( $path ) || ! file_exists( $path ) ) { // @phpstan-ignore-line
		return '';
	}

	ob_start();
	include $path;
	return (string) ob_get_clean();
}
