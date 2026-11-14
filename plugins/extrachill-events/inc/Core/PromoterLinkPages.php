<?php
/**
 * Verified promoter owner definition for the standalone Link Pages runtime.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/TeamTermLinkPages.php';

/** Supplies promoter membership, authority lifecycle, and discovery. */
final class PromoterLinkPages extends TeamTermLinkPages {

	public const KIND              = 'promoter';
	public const SNAPSHOT_META_KEY = '_extrachill_events_promoter_link_page_snapshot';
	public const ORPHAN_META_KEY   = '_extrachill_events_promoter_link_page_orphaned';
	protected const SCHEMA_TYPE    = 'Organization';

	/** @var bool */
	private static $authority_hook_registered = false;

	/** Register the revocation veto even when the optional runtime is unavailable. */
	public static function register_authority_hook(): void {
		if ( self::$authority_hook_registered ) {
			return;
		}
		self::$authority_hook_registered = true;
		add_filter( 'extrachill_events_promoter_authority_precommit', array( self::class, 'authority_precommit' ), 20, 3 );
	}

	/** Register shared lifecycle hooks plus the authority lifecycle. */
	public static function register_hooks(): void {
		parent::register_hooks();
		self::register_authority_hook();
		add_action( 'extrachill_events_promoter_authority_changed', array( self::class, 'authority_changed' ), 1, 2 );
	}

	/**
	 * Require exact active verified membership plus current product access.
	 *
	 * @param int $term_id Promoter term ID.
	 */
	protected static function authorize_member( int $term_id ) {
		return ( new PromoterAuthorization() )->authorize( PromoterAuthorization::effective_user_id(), $term_id, PromoterAuthorization::ACTION_ACCESS_PROMOTER );
	}

	/**
	 * Read only an active verified canonical promoter.
	 *
	 * @param \WP_Term $term Promoter term.
	 */
	protected static function read_source( $term ) {
		$term_id      = (int) $term->term_id;
		$organization = ( new PromoterAuthorityRepository() )->get_organization( $term_id );
		if ( is_wp_error( $organization ) ) {
			return $organization;
		}
		if ( ! is_array( $organization ) || PromoterAuthorityRepository::STATUS_ACTIVE !== $organization['status'] ) {
			return self::forbidden();
		}
		$data = function_exists( 'data_machine_events_get_promoter_data' ) ? data_machine_events_get_promoter_data( $term_id ) : array();
		return array(
			'term_id'      => $term_id,
			'name'         => (string) ( $data['name'] ?? $term->name ),
			'slug'         => (string) $term->slug,
			'description'  => (string) ( $data['description'] ?? $term->description ),
			'website'      => (string) ( $data['url'] ?? get_term_meta( $term_id, '_promoter_url', true ) ),
			'image_url'    => '',
			'image_alt'    => '',
			'social_links' => array(),
			'source_url'   => get_term_link( $term ),
			'revision'     => (string) $organization['version'] . ':' . (string) $organization['updated_at'],
		);
	}

	/**
	 * Record the schema entity type in the snapshot.
	 *
	 * @param array $source Canonical source.
	 */
	protected static function snapshot_extras( array $source ): array {
		unset( $source );
		return array( 'entity_type' => self::SCHEMA_TYPE );
	}

	/**
	 * Require the recorded entity type.
	 *
	 * @param array $snapshot Stored snapshot.
	 */
	protected static function snapshot_extras_valid( array $snapshot ): bool {
		return self::SCHEMA_TYPE === ( $snapshot['entity_type'] ?? '' );
	}

	/**
	 * Build the Events-owned promoter management destination.
	 *
	 * @param int $term_id Promoter term ID.
	 */
	public static function management_url( int $term_id ): string {
		return add_query_arg( 'identity', 'promoter:' . $term_id, get_home_url( self::events_blog_id(), '/venue-settings/' ) ) . '#promoter-link-page';
	}

	/**
	 * Serialize a promoter mutation against organization revocation.
	 *
	 * @param int      $term_id  Promoter term ID.
	 * @param bool     $trusted  Whether the mutation follows a trusted canonical write.
	 * @param callable $callback Mutation.
	 */
	protected static function with_authority_lock( int $term_id, bool $trusted, callable $callback ) {
		return self::with_events_blog(
			static function () use ( $term_id, $trusted, $callback ) {
				$repository = new PromoterAuthorityRepository();
				if ( $trusted ) {
					return $repository->with_active_organization_lock( $term_id, $callback );
				}
				$user_id = PromoterAuthorization::effective_user_id();
				return $repository->with_active_membership_lock(
					$term_id,
					$user_id,
					static function () use ( $repository, $user_id, $term_id, $callback ) {
						$allowed = ( new PromoterAuthorization( $repository ) )->authorize( $user_id, $term_id, PromoterAuthorization::ACTION_ACCESS_PROMOTER );
						return true === $allowed ? $callback() : $allowed;
					}
				);
			}
		);
	}

	/**
	 * Withdraw a promoter page inside the authority revocation transaction.
	 *
	 * @param mixed  $allowed          Prior precommit decision.
	 * @param int    $promoter_term_id Promoter term ID.
	 * @param string $change           Authority change.
	 */
	public static function authority_precommit( $allowed, $promoter_term_id, $change ) {
		if ( true !== $allowed || 'organization_revoked' !== $change ) {
			return $allowed;
		}
		return self::withdraw_for_revocation( absint( $promoter_term_id ) );
	}

	/**
	 * Purge the withdrawn page only after authority commits.
	 *
	 * @param int    $promoter_term_id Promoter term ID.
	 * @param string $change           Authority change.
	 */
	public static function authority_changed( $promoter_term_id, $change ): void {
		if ( 'organization_revoked' !== $change ) {
			return;
		}
		$reference = self::owner_reference( absint( $promoter_term_id ) );
		$page_id   = is_wp_error( $reference ) ? $reference : ec_get_link_page_id_for_owner( $reference );
		if ( ! is_wp_error( $page_id ) && $page_id ) {
			ec_with_link_page_storage_blog(
				static function () use ( $page_id ) {
					ec_purge_link_page_after_mutation( (int) $page_id );
				}
			);
		}
	}

	/** Return the bounded public projection of verified promoter organizations. */
	public static function approved_promoters() {
		return self::with_events_blog(
			static function () {
				$organizations = ( new PromoterAuthorityRepository() )->list_active_organizations();
				if ( is_wp_error( $organizations ) ) {
					return $organizations;
				}
				$promoters = array();
				foreach ( $organizations as $organization ) {
					$term = get_term( (int) $organization['promoter_term_id'], self::KIND );
					if ( ! $term || is_wp_error( $term ) || self::KIND !== $term->taxonomy ) {
						return new \WP_Error( 'approved_promoter_identity_invalid', __( 'An approved promoter identity is unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
					}
					$reference    = self::owner_reference( (int) $term->term_id );
					$link_page_id = is_wp_error( $reference ) ? $reference : ec_get_link_page_id_for_owner( $reference );
					if ( is_wp_error( $link_page_id ) ) {
						return $link_page_id;
					}
					$link_page_url = '';
					if ( $link_page_id ) {
						$link_page_url = ec_with_link_page_storage_blog(
							static function () use ( $link_page_id ) {
								return 'publish' === get_post_field( 'post_status', (int) $link_page_id ) ? ec_get_link_page_public_url( (int) $link_page_id ) : '';
							}
						);
					}
					$profile_url = get_term_link( $term );
					if ( is_wp_error( $profile_url ) ) {
						return new \WP_Error( 'approved_promoter_identity_invalid', __( 'An approved promoter identity URL is unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
					}
					$promoters[] = array(
						'promoter_term_id' => (int) $term->term_id,
						'name'             => sanitize_text_field( (string) $term->name ),
						'slug'             => sanitize_title( (string) $term->slug ),
						'description'      => sanitize_textarea_field( (string) $term->description ),
						'website'          => esc_url_raw( (string) get_term_meta( (int) $term->term_id, '_promoter_url', true ), array( 'http', 'https' ) ),
						'profile_url'      => esc_url_raw( (string) $profile_url, array( 'http', 'https' ) ),
						'link_page_url'    => (string) $link_page_url,
					);
				}
				return array(
					'promoters' => $promoters,
					'count'     => count( $promoters ),
				);
			}
		);
	}

	/**
	 * Withdraw the exact promoter page without reversing authority/page lock order.
	 *
	 * @param int $promoter_term_id Promoter term ID.
	 */
	private static function withdraw_for_revocation( int $promoter_term_id ) {
		$defined_functions = get_defined_functions();
		$user_functions    = array_map( 'strtolower', $defined_functions['user'] );
		foreach ( array( 'ec_normalize_link_page_owner_reference', 'ec_get_link_page_id_for_owner', 'ec_with_link_page_storage_blog', 'ec_get_stored_link_page_owner_references', 'ec_write_link_page_meta' ) as $function ) {
			if ( ! in_array( strtolower( $function ), $user_functions, true ) ) {
				return self::error( 'runtime_unavailable', __( 'Promoter authority cannot be revoked while its public projection runtime is unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
			}
		}
		$reference = self::owner_reference( $promoter_term_id );
		$page_id   = is_wp_error( $reference ) ? $reference : ec_get_link_page_id_for_owner( $reference );
		if ( is_wp_error( $page_id ) || ! $page_id ) {
			return is_wp_error( $page_id ) ? $page_id : true;
		}
		return ec_with_link_page_storage_blog(
			static function () use ( $page_id, $reference, $promoter_term_id ) {
				if ( array( $reference ) !== ec_get_stored_link_page_owner_references( (int) $page_id ) ) {
					return self::error( 'revocation_owner_changed', __( 'The promoter Link Page owner changed before revocation.', 'extrachill-events' ) );
				}
				$before = ec_snapshot_link_page_meta( (int) $page_id, self::ORPHAN_META_KEY );
				if ( ! ec_write_link_page_meta( (int) $page_id, self::ORPHAN_META_KEY, self::orphan_audit( $reference, $promoter_term_id, '', 'draft_on_organization_revoked' ) ) ) {
					return self::error( 'revocation_audit_failed', __( 'The promoter Link Page revocation audit could not be saved.', 'extrachill-events' ) );
				}
				$updated = wp_update_post(
					array(
						'ID'          => (int) $page_id,
						'post_status' => 'draft',
					),
					true
				);
				if ( is_wp_error( $updated ) || 'draft' !== get_post_field( 'post_status', (int) $page_id ) ) {
					$error = self::error( 'revocation_draft_failed', __( 'The promoter Link Page could not be withdrawn.', 'extrachill-events' ) );
					return ec_restore_link_page_meta_snapshots( (int) $page_id, array( self::ORPHAN_META_KEY => $before ) ) ? $error : self::compensation_failed( $error, 'revocation' );
				}
				return true;
			}
		);
	}
}
