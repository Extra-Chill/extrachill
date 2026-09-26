<?php
/**
 * Dev-only PHPStan/Homeboy stubs for runtime dependencies.
 *
 * Sibling Extra Chill plugins and wp-native-auth are not part of this
 * component's source tree, so PHPStan needs their public symbols declared
 * somewhere. This file is scanned (never loaded at runtime).
 */

// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing

const EC_ANALYTICS_EVENT_STUDIO_DRAFT_CREATED = 'studio_draft_created';
const EC_ANALYTICS_EVENT_STUDIO_SUBMITTED     = 'studio_submitted_for_review';
const EC_ANALYTICS_EVENT_STUDIO_TRANSCRIPTION_RUN = 'studio_transcription_run';

/** @return array<string, mixed>|null */
function wp_native_auth_verify_external_token( string $token, string $secret, int $now = 0 ) {}

/** @return string */
function wp_native_auth_sign_external_token( array $payload, string $secret ) {}

function extrachill_breadcrumbs(): void {}

/** @return int|null */
function ec_get_blog_id( string $site ) {}

/** @return array<string, mixed> */
function ec_send_email( array $args ) {}

/** @return array<string, mixed>|WP_Error */
function ec_cross_site_rest_request( string $site_key, string $method, string $path, array $args = array() ) {}

function ec_is_team_member( $user_id = 0 ): bool {}

function ec_feature_available( $feature, $user_id = null ): bool {}

// phpcs:enable
