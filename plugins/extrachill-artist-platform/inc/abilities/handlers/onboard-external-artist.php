<?php
/**
 * Handler: extrachill/onboard-external-artist
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve canonical artist identity without creating or rebinding anything.
 *
 * @param string $name       Submitted artist name.
 * @param int    $profile_id Optional artist profile ID.
 * @param int    $term_id    Optional main-site artist term ID.
 * @return array|WP_Error Resolved profile and term IDs.
 */
function extrachill_artist_platform_resolve_external_artist( $name, $profile_id = 0, $term_id = 0 ) {
	$blog_ids = ec_artist_binding_blog_ids();
	if ( empty( $blog_ids ) ) {
		return new WP_Error( 'artist_identity_unavailable', __( 'Artist identity resolution is unavailable.', 'extrachill-artist-platform' ) );
	}

	$profile_id = absint( $profile_id );
	$term_id    = absint( $term_id );
	$slug       = sanitize_title( $name );
	$profile    = $profile_id ? ec_artist_binding_read_profile( $profile_id, $blog_ids['artist'] ) : array();
	$term       = $term_id ? ec_artist_binding_read_term( $term_id, $blog_ids['main'] ) : array();
	if ( ( $profile_id && empty( $profile ) ) || ( $term_id && empty( $term ) ) ) {
		return new WP_Error( 'invalid_artist_identity', __( 'The supplied artist identity does not exist.', 'extrachill-artist-platform' ) );
	}
	if ( ( $profile_id && $profile['slug'] !== $slug ) || ( $term_id && $term['slug'] !== $slug ) ) {
		return new WP_Error( 'conflicting_artist_identity', __( 'The supplied artist identity does not match the submitted artist name.', 'extrachill-artist-platform' ) );
	}

	if ( $term_id && ! $profile_id ) {
		$profile_id = (int) $term['profile_id'];
		$profile    = $profile_id ? ec_artist_binding_read_profile( $profile_id, $blog_ids['artist'] ) : array();
		if ( empty( $profile ) ) {
			$profile_id = 0;
		}
	}
	if ( $profile_id && ! $term_id ) {
		$term_id = (int) $profile['term_id'];
		$term    = $term_id ? ec_artist_binding_read_term( $term_id, $blog_ids['main'] ) : array();
		if ( empty( $term ) ) {
			$term_id = 0;
		}
	}

	if ( ! $term_id && $slug ) {
		switch_to_blog( $blog_ids['main'] );
		try {
			$matched_term = get_term_by( 'slug', $slug, 'artist' );
			$term_id      = $matched_term && ! is_wp_error( $matched_term ) ? (int) $matched_term->term_id : 0;
			$term         = $term_id ? ec_artist_binding_read_term( $term_id, $blog_ids['main'] ) : array();
		} finally {
			restore_current_blog();
		}
	}
	if ( $term_id && ! $profile_id && 0 === (int) ( $term['profile_id'] ?? 0 ) && 0 === (int) ( $term['count'] ?? 0 ) ) {
		switch_to_blog( $blog_ids['main'] );
		try {
			$recoverable_slug = (string) get_term_meta( $term_id, '_ec_artist_binding_recoverable', true );
		} finally {
			restore_current_blog();
		}
		if ( $slug === $recoverable_slug ) {
			$term_id = 0;
			$term    = array();
		}
	}

	if ( ! $profile_id && $term_id ) {
		$profile_id = (int) ( $term['profile_id'] ?? 0 );
		$profile    = $profile_id ? ec_artist_binding_read_profile( $profile_id, $blog_ids['artist'] ) : array();
		if ( empty( $profile ) ) {
			$profile_id = 0;
		}
	}
	if ( ! $profile_id && $slug ) {
		switch_to_blog( $blog_ids['artist'] );
		try {
			$matches    = get_posts(
				array(
					'post_type'      => 'artist_profile',
					'name'           => $slug,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			$profile_id = empty( $matches ) ? 0 : (int) $matches[0];
			$profile    = $profile_id ? ec_artist_binding_read_profile( $profile_id, $blog_ids['artist'] ) : array();
		} finally {
			restore_current_blog();
		}
	}

	if ( $profile_id ) {
		if ( empty( $profile ) || $profile['slug'] !== $slug ) {
			return new WP_Error( 'conflicting_artist_identity', __( 'The resolved artist profile does not match the submitted artist name.', 'extrachill-artist-platform' ) );
		}
		$profile_term_id = (int) ( $profile['term_id'] ?? 0 );
		if ( $term_id && $profile_term_id && $term_id !== $profile_term_id ) {
			return new WP_Error( 'conflicting_artist_identity', __( 'The resolved artist profile is bound to a different artist term.', 'extrachill-artist-platform' ) );
		}
		$term_id = $profile_term_id ? $profile_term_id : $term_id;
	}
	if ( $term_id ) {
		$term = empty( $term ) ? ec_artist_binding_read_term( $term_id, $blog_ids['main'] ) : $term;
		if ( empty( $term ) || $term['slug'] !== $slug ) {
			return new WP_Error( 'conflicting_artist_identity', __( 'The resolved artist term does not match the submitted artist name.', 'extrachill-artist-platform' ) );
		}
		$term_profile_id = (int) ( $term['profile_id'] ?? 0 );
		if ( $profile_id && $term_profile_id && $profile_id !== $term_profile_id ) {
			return new WP_Error( 'conflicting_artist_identity', __( 'The resolved artist term is bound to a different artist profile.', 'extrachill-artist-platform' ) );
		}
	}

	return array(
		'profile_id' => $profile_id,
		'term_id'    => $term_id,
	);
}

/**
 * Acquire a named orchestration lock.
 *
 * @param string $lock_name Lock name, limited to 64 characters by MySQL.
 * @return bool Whether the lock was acquired.
 */
function extrachill_artist_platform_acquire_external_lock( $lock_name ) {
	global $wpdb;
	return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 5 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes cross-row orchestration where no single row lock is sufficient.
}

/**
 * Acquire the artist-name lock used around profile and link-page provisioning.
 *
 * @param string $name Artist name.
 * @return string|false Lock name, or false when unavailable.
 */
function extrachill_artist_platform_acquire_external_artist_lock( $name ) {
	$lock_name = 'ec_external_artist_' . md5( sanitize_title( $name ) );
	$acquired  = extrachill_artist_platform_acquire_external_lock( $lock_name );
	return $acquired ? $lock_name : false;
}

/**
 * Release an external artist provisioning lock.
 *
 * @param string $lock_name Lock name returned by the acquire helper.
 * @return void
 */
function extrachill_artist_platform_release_external_artist_lock( $lock_name ) {
	global $wpdb;
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the matching provisioning lock.
}

/**
 * Build a stable external-onboarding response.
 *
 * @param string $outcome Response outcome.
 * @param array  $context Resolved response context.
 * @return array
 */
function extrachill_artist_platform_external_onboarding_response( $outcome, $context ) {
	return array(
		'outcome'     => $outcome,
		'user'        => $context['user'],
		'artist'      => $context['artist'],
		'membership'  => $context['membership'],
		'claim'       => $context['claim'],
		'link_page'   => $context['link_page'],
		'source'      => $context['source'],
		'return_url'  => $context['return_url'],
		'next_action' => $context['next_action'],
	);
}

/**
 * Authorize internal callers of the external onboarding contract.
 *
 * Anonymous product flows must explicitly opt in through the filter after
 * enforcing their own request validation and anti-abuse controls. The ability
 * remains unavailable through the generic REST ability transport.
 *
 * @param array $input Ability input.
 * @return bool
 */
function extrachill_artist_platform_ability_external_onboarding_permission( $input ) {
	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( 'manage_options' ) || current_user_can( 'manage_network_options' ) ) {
		return true;
	}
	if ( class_exists( 'ActionScheduler' ) && did_action( 'action_scheduler_before_execute' ) ) {
		return true;
	}

	return (bool) apply_filters( 'extrachill_allow_external_artist_onboarding', false, $input );
}

/**
 * Provision a claimable artist lead from a generic external product flow.
 *
 * Account creation and claim delivery remain owned by extrachill-users. Artist
 * creation, canonical binding, membership, and link-page creation delegate to
 * their existing Artist Platform contracts.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_onboard_external_artist( $input ) {
	$name            = trim( sanitize_text_field( $input['artist_name'] ?? '' ) );
	$email           = sanitize_email( $input['submitter_email'] ?? '' );
	$user_id         = absint( $input['submitter_user_id'] ?? 0 );
	$source_type     = sanitize_key( $input['source_type'] ?? '' );
	$source_id       = sanitize_text_field( $input['source_id'] ?? '' );
	$return_url      = esc_url_raw( $input['return_url'] ?? '' );
	$consent         = isset( $input['consent'] ) && is_array( $input['consent'] ) ? $input['consent'] : array();
	$profile_consent = ! empty( $consent['profile_creation'] );
	$link_consent    = ! empty( $consent['link_page'] );
	$disclosure      = sanitize_text_field( $consent['disclosure_version'] ?? '' );

	if ( '' === $name || '' === $source_type || '' === $source_id || ( ! $user_id && ! is_email( $email ) ) ) {
		return new WP_Error( 'invalid_external_artist_lead', __( 'Submitter identity, artist name, source type, and source ID are required.', 'extrachill-artist-platform' ) );
	}
	if ( '' === sanitize_title( $name ) ) {
		return new WP_Error( 'invalid_artist_name', __( 'Artist name must contain letters or numbers.', 'extrachill-artist-platform' ) );
	}
	if ( $email && ! is_email( $email ) ) {
		return new WP_Error( 'invalid_submitter_email', __( 'The submitter email address is invalid.', 'extrachill-artist-platform' ) );
	}
	if ( ( $profile_consent || $link_consent ) && '' === $disclosure ) {
		return new WP_Error( 'missing_consent_disclosure', __( 'A disclosure version is required when consent is asserted.', 'extrachill-artist-platform' ) );
	}

	$requested_profile_id = absint( $input['artist_profile_id'] ?? 0 );
	$requested_term_id    = absint( $input['artist_term_id'] ?? 0 );
	$identity             = extrachill_artist_platform_resolve_external_artist( $name, $requested_profile_id, $requested_term_id );
	if ( is_wp_error( $identity ) ) {
		return $identity;
	}

	$existing_user_id = 0;
	$user             = $user_id ? get_userdata( $user_id ) : false;
	if ( $user_id && ! $user ) {
		return new WP_Error( 'invalid_submitter_user', __( 'The supplied submitter user does not exist.', 'extrachill-artist-platform' ) );
	}
	if ( $user && $email && strtolower( $user->user_email ) !== strtolower( $email ) ) {
		return new WP_Error( 'conflicting_submitter_identity', __( 'The supplied user and email address do not match.', 'extrachill-artist-platform' ) );
	}
	if ( ! $user && $email ) {
		$existing_user_id = email_exists( $email );
		$user             = $existing_user_id ? get_userdata( $existing_user_id ) : false;
		$user_id          = $user ? (int) $user->ID : 0;
	}

	$account_created = false;
	$claim_delivery  = 'not_required';
	if ( ! $user ) {
		$create_user = wp_get_ability( 'extrachill/create-user' );
		if ( ! $create_user || ! function_exists( 'ec_generate_username_from_email' ) ) {
			return new WP_Error( 'account_creation_unavailable', __( 'Claimable account creation is unavailable.', 'extrachill-artist-platform' ) );
		}

		$created_user_id = $create_user->execute(
			array(
				'email'               => $email,
				'password'            => wp_generate_password( 24 ),
				'username'            => ec_generate_username_from_email( $email ),
				'role'                => 'subscriber',
				'unclaimed'           => true,
				'registration_page'   => $return_url,
				'registration_source' => $source_type,
				'registration_method' => 'external_artist_onboarding',
			)
		);
		if ( is_wp_error( $created_user_id ) ) {
			$existing_user_id = email_exists( $email );
			if ( ! $existing_user_id ) {
				return $created_user_id;
			}
			$created_user_id = $existing_user_id;
		}

		$user            = get_userdata( $created_user_id );
		$user_id         = $user ? (int) $user->ID : 0;
		$account_created = (bool) $user_id && ! $existing_user_id;
		if ( ! $user ) {
			return new WP_Error( 'account_creation_failed', __( 'The claimable account could not be loaded.', 'extrachill-artist-platform' ) );
		}
	}

	$unclaimed = '1' === (string) get_user_meta( $user_id, 'ec_unclaimed', true );
	if ( $unclaimed ) {
		$claim_lock = 'ec_artist_claim_' . $user_id;
		if ( ! extrachill_artist_platform_acquire_external_lock( $claim_lock ) ) {
			$claim_delivery = 'busy';
		} else {
			try {
				$claim_record = get_user_meta( $user_id, '_ec_artist_onboarding_claim_delivery', true );
				$claim_state  = is_array( $claim_record ) ? ( $claim_record['state'] ?? '' ) : $claim_record;
				$claim_started = is_array( $claim_record ) ? absint( $claim_record['started_at'] ?? 0 ) : 0;
				$claim_sent_at = is_array( $claim_record ) ? absint( $claim_record['sent_at'] ?? 0 ) : 0;
				$claim_lifetime = (int) apply_filters( 'password_reset_expiration', DAY_IN_SECONDS );
				$active_sent = 'sent' === $claim_state && $claim_sent_at > time() - $claim_lifetime;
				if ( $active_sent ) {
					$claim_delivery = 'previously_sent';
				} elseif ( 'pending' === $claim_state && $claim_started > time() - ( 15 * MINUTE_IN_SECONDS ) ) {
					$claim_delivery = 'pending';
				} elseif ( ! update_user_meta( $user_id, '_ec_artist_onboarding_claim_delivery', array( 'state' => 'pending', 'started_at' => time() ) ) ) {
					$claim_delivery = 'failed';
				} else {
					$claim_result = retrieve_password( $user->user_login );
					if ( is_wp_error( $claim_result ) ) {
						update_user_meta( $user_id, '_ec_artist_onboarding_claim_delivery', '' );
						$claim_delivery = 'failed';
					} elseif ( update_user_meta( $user_id, '_ec_artist_onboarding_claim_delivery', array( 'state' => 'sent', 'sent_at' => time() ) ) ) {
						$claim_delivery = 'sent';
					} else {
						$claim_delivery = 'sent_unconfirmed';
					}
				}
			} finally {
				extrachill_artist_platform_release_external_artist_lock( $claim_lock );
			}
		}
	}

	$profile_id = (int) $identity['profile_id'];
	$term_id    = (int) $identity['term_id'];
	$managed    = $profile_id && function_exists( 'ec_can_manage_artist' ) && ec_can_manage_artist( $user_id, $profile_id );
	$trusted    = get_current_user_id() === $user_id
		|| current_user_can( 'manage_options' )
		|| current_user_can( 'manage_network_options' )
		|| ( defined( 'WP_CLI' ) && WP_CLI )
		|| ( class_exists( 'ActionScheduler' ) && did_action( 'action_scheduler_before_execute' ) );

	$context = array(
		'user'        => array(
			'id'      => $user_id,
			'state'   => $unclaimed ? 'unclaimed' : ( $account_created ? 'created' : 'existing' ),
			'created' => $account_created,
		),
		'artist'      => array(
			'name'       => $name,
			'profile_id' => $profile_id ?: null,
			'term_id'    => $term_id ?: null,
			'state'      => $profile_id ? 'existing_profile' : ( $term_id ? 'existing_canonical_identity' : 'new_eligible' ),
		),
		'membership'  => array( 'state' => $managed ? 'managed' : ( $profile_id || $term_id ? 'request_required' : 'not_applicable' ) ),
		'claim'       => array(
			'required' => $unclaimed,
			'delivery' => $unclaimed ? ( 'not_required' === $claim_delivery ? 'previously_provisioned' : $claim_delivery ) : 'not_required',
		),
		'link_page'   => array( 'state' => 'unavailable', 'id' => null ),
		'source'      => array( 'type' => $source_type, 'id' => $source_id ),
		'return_url'  => $return_url,
		'next_action' => 'none',
	);
	if ( $disclosure ) {
		$context['artist']['disclosure_version'] = $disclosure;
	}

	if ( $unclaimed ) {
		$context['artist']['state'] = $profile_id || $term_id ? $context['artist']['state'] : 'eligible_after_claim_and_consent';
		$context['next_action']     = 'claim_account';
		return extrachill_artist_platform_external_onboarding_response( 'account_claim_required', $context );
	}

	if ( ( $profile_id || $term_id ) && ! $managed ) {
		$context['next_action'] = 'request_membership';
		return extrachill_artist_platform_external_onboarding_response( 'membership_request_required', $context );
	}

	if ( ! $trusted ) {
		$context['artist']['state'] = 'eligible_after_authentication_and_consent';
		$context['next_action']     = 'authenticate';
		return extrachill_artist_platform_external_onboarding_response( 'authentication_required', $context );
	}

	if ( ! $profile_id && ! $profile_consent ) {
		$context['artist']['state'] = 'eligible_after_consent';
		$context['next_action']     = 'confirm_profile_creation';
		return extrachill_artist_platform_external_onboarding_response( 'artist_consent_required', $context );
	}

	$lock_name = extrachill_artist_platform_acquire_external_artist_lock( $name );
	if ( ! $lock_name ) {
		return new WP_Error( 'artist_onboarding_busy', __( 'Artist onboarding is already in progress. Retry the operation.', 'extrachill-artist-platform' ), array( 'retryable' => true ) );
	}

	try {
		$locked_identity = extrachill_artist_platform_resolve_external_artist( $name, $requested_profile_id, $requested_term_id );
		if ( is_wp_error( $locked_identity ) ) {
			return $locked_identity;
		}
		$profile_id = (int) $locked_identity['profile_id'];
		$term_id    = (int) $locked_identity['term_id'];
		$managed    = $profile_id && function_exists( 'ec_can_manage_artist' ) && ec_can_manage_artist( $user_id, $profile_id );
		$context['artist'] = array(
			'name'       => $name,
			'profile_id' => $profile_id ?: null,
			'term_id'    => $term_id ?: null,
			'state'      => $profile_id ? 'existing_profile' : ( $term_id ? 'existing_canonical_identity' : 'new_eligible' ),
		);
		if ( $disclosure ) {
			$context['artist']['disclosure_version'] = $disclosure;
		}
		$context['membership']['state'] = $managed ? 'managed' : ( $profile_id || $term_id ? 'request_required' : 'not_applicable' );
		if ( ( $profile_id || $term_id ) && ! $managed ) {
			$context['next_action'] = 'request_membership';
			return extrachill_artist_platform_external_onboarding_response( 'membership_request_required', $context );
		}
		if ( $profile_id && $term_id && ! ec_reconcile_artist_profile_term_pair( $profile_id, $term_id ) ) {
			$binding_failure = ec_get_artist_binding_failure();
			if ( $binding_failure ) {
				return $binding_failure;
			}
			return new WP_Error( 'conflicting_artist_identity', __( 'The artist profile and term could not be safely bound.', 'extrachill-artist-platform' ) );
		}

		$artist_created = false;
		if ( ! $profile_id ) {
			$create_artist = wp_get_ability( 'extrachill/create-artist' );
			if ( ! $create_artist ) {
				return new WP_Error( 'artist_creation_unavailable', __( 'Artist profile creation is unavailable.', 'extrachill-artist-platform' ) );
			}
			$created_artist = $create_artist->execute( array( 'name' => $name, 'user_id' => $user_id ) );
			if ( is_wp_error( $created_artist ) ) {
				return $created_artist;
			}
			$profile_id = absint( is_array( $created_artist ) ? ( $created_artist['id'] ?? 0 ) : $created_artist );
			if ( ! $profile_id ) {
				return new WP_Error( 'artist_creation_failed', __( 'Artist profile creation returned no profile ID.', 'extrachill-artist-platform' ) );
			}
			$artist_created = true;
			$context['artist'] = array(
				'name'       => $name,
				'profile_id' => $profile_id,
				'term_id'    => null,
				'state'      => 'created',
			);
			$context['membership']['state'] = 'managed';
		}

		$artist_blog_id = ec_get_blog_id( 'artist' );
		if ( ! $artist_blog_id ) {
			return new WP_Error( 'artist_site_unavailable', __( 'The artist site is unavailable.', 'extrachill-artist-platform' ) );
		}
		switch_to_blog( $artist_blog_id );
		try {
			$source_key = hash( 'sha256', $source_type . ':' . $source_id );
			$sources    = get_post_meta( $profile_id, '_ec_external_onboarding_sources', true );
			$sources    = is_array( $sources ) ? $sources : array();
			$existing_source = isset( $sources[ $source_key ] ) && is_array( $sources[ $source_key ] ) ? $sources[ $source_key ] : array();
			$disclosures    = isset( $existing_source['disclosure_versions'] ) && is_array( $existing_source['disclosure_versions'] ) ? $existing_source['disclosure_versions'] : array();
			if ( ! empty( $existing_source['disclosure_version'] ) ) {
				$disclosures[] = $existing_source['disclosure_version'];
			}
			if ( $disclosure ) {
				$disclosures[] = $disclosure;
			}
			$disclosures = array_values( array_unique( array_filter( $disclosures ) ) );
			$sources[ $source_key ] = array(
				'type'                => $source_type,
				'id'                  => $source_id,
				'return_url'          => $existing_source['return_url'] ?? $return_url,
				'profile_creation'    => ! empty( $existing_source['profile_creation'] ) || $profile_consent,
				'link_page'           => ! empty( $existing_source['link_page'] ) || $link_consent,
				'disclosure_versions' => $disclosures,
			);
			$source_updated = update_post_meta( $profile_id, '_ec_external_onboarding_sources', $sources );
			if ( ! $source_updated && maybe_serialize( get_post_meta( $profile_id, '_ec_external_onboarding_sources', true ) ) !== maybe_serialize( $sources ) ) {
				if ( $artist_created ) {
					$membership_removed = ec_remove_artist_membership( $user_id, $profile_id );
					$profile_removed    = $membership_removed ? wp_delete_post( $profile_id, true ) : false;
					if ( ! $membership_removed || ! $profile_removed ) {
						return new WP_Error( 'artist_onboarding_rollback_failed', __( 'Artist onboarding provenance and rollback both failed. Manual reconciliation is required.', 'extrachill-artist-platform' ), array( 'retryable' => false ) );
					}
				}
				return new WP_Error( 'artist_onboarding_source_failed', __( 'Artist onboarding provenance could not be stored.', 'extrachill-artist-platform' ), array( 'retryable' => true ) );
			}
			if ( ! $term_id ) {
				$binding_result = ec_sync_artist_profile_term_binding( $profile_id );
				if ( is_wp_error( $binding_result ) ) {
					if ( in_array( $binding_result->get_error_code(), array( 'artist_binding_compensation_failed', 'artist_term_binding_rollback_failed' ), true ) ) {
						return $binding_result;
					}
					if ( $artist_created ) {
						$membership_removed = ec_remove_artist_membership( $user_id, $profile_id );
						$profile_removed    = $membership_removed ? wp_delete_post( $profile_id, true ) : false;
						if ( ! $membership_removed || ! $profile_removed ) {
							return new WP_Error( 'artist_onboarding_rollback_failed', __( 'Canonical artist binding and profile rollback both failed. Manual reconciliation is required.', 'extrachill-artist-platform' ), array( 'retryable' => false ) );
						}
						$main_blog_id = ec_get_blog_id( 'main' );
						switch_to_blog( $main_blog_id );
						try {
							$remaining_terms = get_terms(
								array(
									'taxonomy'   => 'artist',
									'hide_empty' => false,
									'fields'     => 'ids',
									'number'     => 1,
									'meta_query' => array(
										array(
											'key'     => '_artist_profile_id',
											'value'   => $profile_id,
											'compare' => '=',
											'type'    => 'NUMERIC',
										),
									),
								)
							);
						} finally {
							restore_current_blog();
						}
						if ( is_wp_error( $remaining_terms ) || ! empty( $remaining_terms ) ) {
							return new WP_Error(
								'artist_onboarding_rollback_failed',
								__( 'The profile was removed, but canonical artist references remain. Manual reconciliation is required.', 'extrachill-artist-platform' ),
								array( 'profile_id' => $profile_id, 'retryable' => false )
							);
						}
					}
					return $binding_result;
				}
				$term_id                     = (int) $binding_result;
				$context['artist']['term_id'] = $term_id;
			}

			$existing_link_id = function_exists( 'ec_get_reciprocal_link_page_id' ) ? ec_get_reciprocal_link_page_id( $profile_id ) : 0;
			if ( is_wp_error( $existing_link_id ) ) {
				return $existing_link_id;
			}
			if ( $existing_link_id ) {
				$context['link_page'] = array( 'state' => 'existing', 'id' => $existing_link_id );
			} elseif ( $link_consent ) {
				$link_page_id = ec_create_link_page( $profile_id );
				if ( is_wp_error( $link_page_id ) ) {
					return $link_page_id;
				}
				$context['link_page'] = array( 'state' => 'created', 'id' => (int) $link_page_id );
			} else {
				$context['link_page']['state'] = 'offered';
			}
		} finally {
			restore_current_blog();
		}

		$context['next_action'] = 'manage_artist';
		return extrachill_artist_platform_external_onboarding_response( $artist_created ? 'artist_created' : 'managed_artist', $context );
	} finally {
		extrachill_artist_platform_release_external_artist_lock( $lock_name );
	}
}
