<?php
/**
 * Venue owner adapter for the standalone Link Pages runtime.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Owns venue authorization, snapshots, operations, provisioning, and projection. */
final class VenueLinkPages {

	public const SNAPSHOT_META_KEY = '_extrachill_events_venue_link_page_snapshot';
	public const SNAPSHOT_VERSION  = 1;
	public const ORPHAN_META_KEY   = '_extrachill_events_venue_link_page_orphaned';

	/**
	 * Whether mutation hooks registered in this request.
	 *
	 * @var bool
	 */
	private static $hooks_registered = false;

	/** Register snapshot refreshes on canonical public profile mutations. */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;
		add_action( 'extrachill_events_venue_profile_updated', array( self::class, 'refresh_after_profile_update' ), 20, 1 );
		add_action( 'edited_venue', array( self::class, 'refresh_after_profile_update' ), 20, 1 );
		add_action( 'pre_delete_term', array( self::class, 'capture_before_venue_deletion' ), 10, 2 );
		add_action( 'delete_term', array( self::class, 'orphan_after_venue_deletion' ), 20, 4 );
	}

	/** Return the canonical opaque owner reference. */
	public static function owner_reference( int $venue_term_id ) {
		$events_blog_id = self::events_blog_id();
		if ( $events_blog_id < 1 || $venue_term_id < 1 ) {
			return new \WP_Error( 'invalid_venue_link_page_owner', __( 'A canonical Events venue is required.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		return ec_normalize_link_page_owner_reference(
			array(
				'kind'      => 'term',
				'blog_id'   => $events_blog_id,
				'subtype'   => 'venue',
				'object_id' => $venue_term_id,
			)
		);
	}

	/** Expose the exact venue authorization contract to ability permissions. */
	public static function authorize_venue( int $venue_term_id ) {
		return self::authorize( $venue_term_id );
	}

	/** Canonical ownership metadata needs no venue-specific legacy claims. */
	public static function compatibility_provider( $operation, $context ): array {
		unset( $operation, $context );
		return array();
	}

	/** Claim only exact canonical Events venue owners. */
	public static function operation_provider( $resolved ) {
		if ( ! self::is_venue_owner( $resolved['owner'] ?? array() ) ) {
			return null;
		}
		return array(
			'authorize' => array( self::class, 'operation_authorize' ),
			'read'      => array( self::class, 'operation_read' ),
			'save'      => array( self::class, 'operation_save' ),
		);
	}

	/** Reauthorize every generic operation against exact direct membership. */
	public static function operation_authorize( $resolved, $operation ) {
		if ( ! in_array( $operation, array( 'read', 'save' ), true ) || ! self::binding_is_exact( $resolved ) ) {
			return false;
		}
		return self::authorize( (int) $resolved['owner']['object_id'] );
	}

	/** Read shared persistence and the stored venue identity projection. */
	public static function operation_read( $resolved ) {
		$allowed = self::operation_authorize( $resolved, 'read' );
		if ( true !== $allowed ) {
			return is_wp_error( $allowed ) ? $allowed : self::forbidden();
		}
		$snapshot = self::read_snapshot( (int) $resolved['link_page_id'], $resolved['owner_reference'] );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$data = ec_read_link_page_persistence( (int) $resolved['link_page_id'] );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return self::compose_response( $data, $snapshot );
	}

	/** Save generic fields and a fresh venue snapshot in one compensatable lock. */
	public static function operation_save( $resolved, $data ) {
		$allowed = self::operation_authorize( $resolved, 'save' );
		if ( true !== $allowed ) {
			return is_wp_error( $allowed ) ? $allowed : self::forbidden();
		}
		$allowed_keys = array( 'links', 'css_vars', 'bio', 'link_expiration_enabled', 'redirect_enabled', 'redirect_target_url', 'youtube_embed_enabled', 'meta_pixel_id', 'google_tag_id', 'google_tag_manager_id', 'social_icons_position', 'profile_image_shape', 'background_image_id', 'expected_revision' );
		if ( ! is_array( $data ) || array_diff( array_keys( $data ), $allowed_keys ) ) {
			return new \WP_Error( 'invalid_venue_link_page_save', __( 'The venue Link Page save contains unsupported fields.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$link_page_id = (int) $resolved['link_page_id'];
		$current      = ec_read_link_page_persistence( $link_page_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$expected_revision = isset( $data['expected_revision'] ) ? (string) $data['expected_revision'] : '';
		$lock_revision     = self::persistence_revision( $current );
		unset( $data['expected_revision'] );
		$snapshot = self::build_snapshot( (int) $resolved['owner']['object_id'], $resolved['owner_reference'] );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$saved = ec_save_link_page_persistence_composed(
			$link_page_id,
			$data,
			static function ( $finalized_link_page_id, $persistence ) use ( $snapshot, $resolved, $expected_revision, $lock_revision ) {
				unset( $persistence );
				if ( $expected_revision && ! hash_equals( $lock_revision, $expected_revision ) ) {
					return new \WP_Error( 'venue_link_page_revision_conflict', __( 'The Link Page changed before this save could be applied.', 'extrachill-events' ), array( 'status' => 409 ) );
				}
				return self::finalize_owner_state( (int) $finalized_link_page_id, (string) $resolved['owner_reference'], $snapshot );
			}
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return self::compose_response( $saved, $snapshot );
	}

	/** Finalize and compensate venue-owned projections inside the composed save. */
	private static function finalize_owner_state( int $link_page_id, string $reference, array $snapshot ) {
		$before = array(
			self::SNAPSHOT_META_KEY               => ec_snapshot_link_page_meta( $link_page_id, self::SNAPSHOT_META_KEY ),
			EC_LINK_PAGE_PUBLIC_SNAPSHOT_META_KEY => ec_snapshot_link_page_meta( $link_page_id, EC_LINK_PAGE_PUBLIC_SNAPSHOT_META_KEY ),
		);
		if ( ! ec_write_link_page_meta( $link_page_id, self::SNAPSHOT_META_KEY, $snapshot ) ) {
			$error = new \WP_Error( 'venue_link_page_snapshot_save_failed', __( 'The venue Link Page snapshot could not be saved.', 'extrachill-events' ) );
			return ec_restore_link_page_meta_snapshots( $link_page_id, $before ) ? $error : new \WP_Error( 'venue_link_page_save_compensation_failed', __( 'The failed venue Link Page save could not be compensated.', 'extrachill-events' ), array( 'cause' => $error->get_error_code() ) );
		}
		$public = ec_save_link_page_public_projection_snapshot( $link_page_id, $reference, self::projection_from_snapshot( $snapshot, $link_page_id ) );
		if ( is_wp_error( $public ) ) {
			return ec_restore_link_page_meta_snapshots( $link_page_id, $before ) ? $public : new \WP_Error( 'venue_link_page_save_compensation_failed', __( 'The failed venue Link Page save could not be compensated.', 'extrachill-events' ), array( 'cause' => $public->get_error_code() ) );
		}
		try {
			do_action( 'ec_link_page_save', $link_page_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			$error = new \WP_Error( 'venue_link_page_final_hook_failed', __( 'The venue Link Page final mutation hook failed.', 'extrachill-events' ) );
			return ec_restore_link_page_meta_snapshots( $link_page_id, $before ) ? $error : new \WP_Error( 'venue_link_page_save_compensation_failed', __( 'The failed venue Link Page save could not be compensated.', 'extrachill-events' ), array( 'cause' => $error->get_error_code() ) );
		}
		return true;
	}

	/** Provision one page under an owner-level lock and deterministic slug tiers. */
	public static function provision( int $venue_term_id ) {
		$allowed = self::authorize( $venue_term_id );
		if ( true !== $allowed ) {
			return $allowed;
		}
		$reference = self::owner_reference( $venue_term_id );
		if ( is_wp_error( $reference ) ) {
			return $reference;
		}
		return ec_with_link_page_storage_blog(
			static function () use ( $venue_term_id, $reference ) {
				$source = self::read_live_venue( $venue_term_id );
				if ( is_wp_error( $source ) ) {
					return $source;
				}
				$provisioned = null;
				foreach ( self::slug_candidates( $source ) as $slug ) {
					$provisioned = ec_provision_owned_link_page(
						$reference,
						$source['name'],
						$slug,
						false,
						static function () use ( $venue_term_id ) {
							return self::authorize( $venue_term_id );
						}
					);
					if ( ! is_wp_error( $provisioned ) || 'link_page_slug_conflict' !== $provisioned->get_error_code() ) {
						break;
					}
				}
				if ( is_wp_error( $provisioned ) ) {
					return $provisioned;
				}
				$link_page_id = (int) $provisioned['link_page_id'];
				$created      = ! empty( $provisioned['created'] );
				$snapshot     = self::build_snapshot_from_source( $source, $reference );
				$result       = ec_with_link_page_lock_scope(
					$link_page_id,
					static function () use ( $venue_term_id, $link_page_id, $reference, $snapshot, $created ) {
							$before  = $created ? array() : array(
								self::SNAPSHOT_META_KEY => ec_snapshot_link_page_meta( $link_page_id, self::SNAPSHOT_META_KEY ),
								EC_LINK_PAGE_PUBLIC_SNAPSHOT_META_KEY => ec_snapshot_link_page_meta( $link_page_id, EC_LINK_PAGE_PUBLIC_SNAPSHOT_META_KEY ),
							);
							$allowed = self::authorize( $venue_term_id );
						if ( true !== $allowed ) {
							return $allowed;
						}
						if ( ! ec_write_link_page_meta( $link_page_id, self::SNAPSHOT_META_KEY, $snapshot ) ) {
							$error = new \WP_Error( 'venue_link_page_snapshot_save_failed', __( 'The venue Link Page snapshot could not be saved.', 'extrachill-events' ) );
							return $created || ec_restore_link_page_meta_snapshots( $link_page_id, $before ) ? $error : new \WP_Error( 'venue_link_page_snapshot_restore_failed', __( 'The existing venue Link Page snapshots could not be restored.', 'extrachill-events' ) );
						}
							$public = ec_save_link_page_public_projection_snapshot( $link_page_id, $reference, self::projection_from_snapshot( $snapshot, $link_page_id ) );
						if ( is_wp_error( $public ) ) {
							return $created || ec_restore_link_page_meta_snapshots( $link_page_id, $before ) ? $public : new \WP_Error( 'venue_link_page_snapshot_restore_failed', __( 'The existing venue Link Page snapshots could not be restored.', 'extrachill-events' ) );
						}
						try {
							do_action( 'ec_link_page_save', $link_page_id );
						} catch ( \Throwable $throwable ) {
							$error = new \WP_Error( 'venue_link_page_final_hook_failed', __( 'The venue Link Page final mutation hook failed.', 'extrachill-events' ) );
							return $created || ec_restore_link_page_meta_snapshots( $link_page_id, $before ) ? $error : new \WP_Error( 'venue_link_page_snapshot_restore_failed', __( 'The existing venue Link Page snapshots could not be restored.', 'extrachill-events' ) );
						}
							return true;
					}
				);
				if ( is_wp_error( $result ) ) {
					if ( $created ) {
						$compensated = ec_compensate_created_link_page( $link_page_id );
						if ( is_wp_error( $compensated ) ) {
							return $compensated;
						}
					}
					return $result;
				}
				$response            = self::compose_response( ec_read_link_page_persistence( $link_page_id ), $snapshot );
				$response['created'] = $created;
				return $response;
			}
		);
	}

	/** Refresh the public snapshot without provisioning a missing page. */
	public static function refresh_snapshot( int $venue_term_id ) {
		return self::refresh_snapshot_mutation( $venue_term_id, false );
	}

	/** Refresh after a trusted canonical mutation that already persisted. */
	private static function refresh_snapshot_trusted( int $venue_term_id ) {
		return self::refresh_snapshot_mutation( $venue_term_id, true );
	}

	/** Execute the shared refresh with explicit trust provenance. */
	private static function refresh_snapshot_mutation( int $venue_term_id, bool $trusted ) {
		if ( ! $trusted ) {
			$allowed = self::authorize( $venue_term_id );
			if ( true !== $allowed ) {
				return $allowed;
			}
		}
		$reference = self::owner_reference( $venue_term_id );
		if ( is_wp_error( $reference ) ) {
			return $reference;
		}
		$link_page_id = ec_get_link_page_id_for_owner( $reference );
		if ( is_wp_error( $link_page_id ) || ! $link_page_id ) {
			return is_wp_error( $link_page_id ) ? $link_page_id : new \WP_Error( 'venue_link_page_not_found', __( 'No Link Page exists for this venue.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		$snapshot = self::build_snapshot( $venue_term_id, $reference );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$result = ec_with_link_page_storage_blog(
			static function () use ( $link_page_id, $snapshot, $trusted ) {
				return ec_with_link_page_lock_scope(
					(int) $link_page_id,
					static function () use ( $link_page_id, $snapshot, $trusted ) {
						if ( ! $trusted ) {
							$allowed = self::authorize( (int) $snapshot['source']['venue_term_id'] );
							if ( true !== $allowed ) {
								return $allowed;
							}
						}
						$before = ec_snapshot_link_page_meta( (int) $link_page_id, self::SNAPSHOT_META_KEY );
						if ( ! ec_write_link_page_meta( (int) $link_page_id, self::SNAPSHOT_META_KEY, $snapshot ) ) {
							ec_restore_link_page_meta_snapshots( (int) $link_page_id, array( self::SNAPSHOT_META_KEY => $before ) );
							return new \WP_Error( 'venue_link_page_snapshot_save_failed', __( 'The venue Link Page snapshot could not be refreshed.', 'extrachill-events' ) );
						}
						$public = ec_save_link_page_public_projection_snapshot( (int) $link_page_id, $snapshot['owner_reference'], self::projection_from_snapshot( $snapshot, (int) $link_page_id ) );
						if ( is_wp_error( $public ) ) {
							ec_restore_link_page_meta_snapshots( (int) $link_page_id, array( self::SNAPSHOT_META_KEY => $before ) );
							return $public;
						}
						do_action( 'ec_link_page_save', (int) $link_page_id );
						return self::compose_response( ec_read_link_page_persistence( (int) $link_page_id ), $snapshot );
					}
				);
			}
		);
		return $result;
	}

	/** Refresh after an already-authorized canonical profile mutation. */
	public static function refresh_after_profile_update( $venue_term_id ): void {
		$result = self::refresh_snapshot_trusted( absint( $venue_term_id ) );
		if ( is_wp_error( $result ) && 'venue_link_page_not_found' !== $result->get_error_code() ) {
			do_action( 'extrachill_events_venue_link_page_snapshot_refresh_failed', absint( $venue_term_id ), $result );
		}
	}

	/** Capture the exact owner/page binding while the venue term still exists. */
	public static function capture_before_venue_deletion( $term_id, $taxonomy ): void {
		if ( 'venue' !== $taxonomy || get_current_blog_id() !== self::events_blog_id() ) {
			return;
		}
		$reference = self::owner_reference( absint( $term_id ) );
		$page_id   = is_wp_error( $reference ) ? $reference : ec_get_link_page_id_for_owner( $reference );
		if ( is_wp_error( $page_id ) ) {
			do_action( 'extrachill_events_venue_link_page_orphan_capture_failed', absint( $term_id ), $page_id );
			return;
		}
		if ( $page_id ) {
			$GLOBALS['extrachill_events_deleting_venue_link_pages'][ absint( $term_id ) ] = array(
				'link_page_id'    => (int) $page_id,
				'owner_reference' => $reference,
			);
		}
	}

	/** Draft the public page after successful canonical venue deletion. */
	public static function orphan_after_venue_deletion( $term_id, $term_taxonomy_id, $taxonomy, $deleted_term ): void {
		unset( $term_taxonomy_id );
		if ( 'venue' !== $taxonomy ) {
			return;
		}
		$capture = $GLOBALS['extrachill_events_deleting_venue_link_pages'][ absint( $term_id ) ] ?? null;
		unset( $GLOBALS['extrachill_events_deleting_venue_link_pages'][ absint( $term_id ) ] );
		if ( ! is_array( $capture ) ) {
			$reference = sprintf( 'term:%d:venue:%d', self::events_blog_id(), absint( $term_id ) );
			$capture   = ec_with_link_page_storage_blog(
				static function () use ( $reference ) {
					$ids = get_posts(
						array(
							'post_type'      => EC_LINK_PAGE_POST_TYPE,
							'post_status'    => 'any',
							'meta_key'       => EC_LINK_PAGE_OWNER_META_KEY,
							'meta_value'     => $reference,
							'posts_per_page' => 2,
							'fields'         => 'ids',
						)
					);
					return 1 === count( $ids ) ? array(
						'link_page_id'    => (int) $ids[0],
						'owner_reference' => $reference,
					) : null;
				}
			);
			if ( ! is_array( $capture ) ) {
				do_action( 'extrachill_events_venue_link_page_orphan_capture_failed', absint( $term_id ), new \WP_Error( 'venue_link_page_orphan_recovery_failed', __( 'The deleted venue Link Page could not be recovered.', 'extrachill-events' ) ) );
				return;
			}
		}
		$result = ec_with_link_page_storage_blog(
			static function () use ( $capture, $term_id, $deleted_term ) {
				$link_page_id = (int) $capture['link_page_id'];
				$stored       = ec_get_stored_link_page_owner_references( $link_page_id );
				if ( array( $capture['owner_reference'] ) !== $stored ) {
					return new \WP_Error( 'venue_link_page_orphan_owner_changed', __( 'The deleted venue Link Page owner changed before cleanup.', 'extrachill-events' ) );
				}
				$audit = array(
					'version'         => 1,
					'owner_reference' => $capture['owner_reference'],
					'venue_term_id'   => absint( $term_id ),
					'venue_name'      => sanitize_text_field( (string) ( $deleted_term->name ?? '' ) ),
					'orphaned_at'     => gmdate( 'c' ),
					'policy'          => 'draft_on_owner_deletion',
				);
				if ( ! ec_write_link_page_meta( $link_page_id, self::ORPHAN_META_KEY, $audit ) ) {
					ec_purge_link_page_after_mutation( $link_page_id );
					return new \WP_Error( 'venue_link_page_orphan_audit_failed', __( 'The deleted venue Link Page audit could not be saved.', 'extrachill-events' ) );
				}
				$updated = wp_update_post(
					array(
						'ID'          => $link_page_id,
						'post_status' => 'draft',
					),
					true
				);
				ec_purge_link_page_after_mutation( $link_page_id );
				if ( is_wp_error( $updated ) || 'draft' !== get_post_field( 'post_status', $link_page_id ) ) {
					return is_wp_error( $updated ) ? $updated : new \WP_Error( 'venue_link_page_orphan_draft_failed', __( 'The deleted venue Link Page could not be made non-public.', 'extrachill-events' ) );
				}
				return true;
			}
		);
		if ( is_wp_error( $result ) ) {
			do_action( 'extrachill_events_venue_link_page_orphan_failed', absint( $term_id ), $capture, $result );
		}
	}

	/** Project public output from canonical-storage snapshot only. */
	public static function public_projection_provider( $context ) {
		if ( ! self::is_venue_owner( $context['owner'] ?? array() ) ) {
			return null;
		}
		$snapshot = self::read_snapshot( (int) $context['link_page_id'], $context['owner_reference'] );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		return self::projection_from_snapshot( $snapshot, (int) $context['link_page_id'] );
	}

	/** Build the serializable public projection shared by live and stored paths. */
	private static function projection_from_snapshot( array $snapshot, int $link_page_id ): array {
		$canonical = ec_get_link_page_public_url( $link_page_id );
		$entity_id = $canonical . '#venue';
		$schema    = array(
			'@type'       => 'MusicVenue',
			'@id'         => $entity_id,
			'name'        => $snapshot['title'],
			'url'         => $canonical,
			'description' => $snapshot['description'],
		);
		if ( $snapshot['image_url'] ) {
			$schema['image'] = $snapshot['image_url'];
		}
		if ( $snapshot['location']['address'] || $snapshot['location']['city'] ) {
			$schema['address'] = array_filter(
				array(
					'@type'           => 'PostalAddress',
					'streetAddress'   => $snapshot['location']['address'],
					'addressLocality' => $snapshot['location']['city'],
					'addressRegion'   => $snapshot['location']['state'],
					'postalCode'      => $snapshot['location']['zip'],
					'addressCountry'  => $snapshot['location']['country'],
				)
			);
		}
		$same_as = array_values( array_unique( array_filter( array_merge( array( $snapshot['website'] ), array_column( $snapshot['social_links'], 'url' ) ) ) ) );
		if ( $same_as ) {
			$schema['sameAs'] = $same_as;
		}
		$storage_url = get_home_url( ec_get_link_page_storage_blog_id(), '/' );
		return array(
			'display_title'   => $snapshot['title'],
			'bio'             => $snapshot['description'],
			'profile_img_url' => $snapshot['image_url'],
			'social_links'    => $snapshot['social_links'],
			'social_renderer' => array( self::class, 'render_social_links' ),
			'management_url'  => self::management_url( (int) $snapshot['source']['venue_term_id'] ),
			'body_attributes' => array(
				'data-extrch-owner-type' => 'venue',
				'data-extrch-venue-id'   => (string) $snapshot['source']['venue_term_id'],
			),
			'seo'             => array(
				'title'       => $snapshot['title'] . ' | extrachill.link',
				'description' => $snapshot['description'],
				'canonical'   => $canonical,
				'image'       => $snapshot['image_url'],
				'image_alt'   => $snapshot['image_alt'] ? $snapshot['image_alt'] : $snapshot['title'],
				'og_type'     => 'place',
				'schema'      => array(
					$schema,
					array(
						'@type'      => 'ProfilePage',
						'@id'        => $canonical . '#profilepage',
						'url'        => $canonical,
						'name'       => $snapshot['title'],
						'mainEntity' => array( '@id' => $entity_id ),
					),
				),
			),
			'tracking_url'    => trailingslashit( $storage_url ) . 'wp-json/extrachill/v1/analytics/click',
		);
	}

	/** Render venue public links without artist social storage or components. */
	public static function render_social_links( $social_links ): string {
		if ( ! is_array( $social_links ) || empty( $social_links ) ) {
			return '';
		}
		$html = '<nav class="extrch-link-page-socials extrch-link-page-venue-socials" aria-label="Venue links">';
		foreach ( $social_links as $social ) {
			$html .= '<a href="' . esc_url( $social['url'] ?? '' ) . '" rel="noopener noreferrer" aria-label="' . esc_attr( ucfirst( (string) ( $social['type'] ?? 'website' ) ) ) . '">' . esc_html( ucfirst( (string) ( $social['type'] ?? 'website' ) ) ) . '</a>';
		}
		return $html . '</nav>';
	}

	/** Delegate analytics to the established provider after exact authorization. */
	public static function analytics( int $venue_term_id, int $date_range = 30, string $start_date = '', string $end_date = '' ) {
		$allowed = self::authorize( $venue_term_id );
		if ( true !== $allowed ) {
			return $allowed;
		}
		$reference    = self::owner_reference( $venue_term_id );
		$link_page_id = is_wp_error( $reference ) ? $reference : ec_get_link_page_id_for_owner( $reference );
		if ( is_wp_error( $link_page_id ) ) {
			return $link_page_id;
		}
		if ( ! $link_page_id ) {
			return new \WP_Error( 'venue_link_page_not_found', __( 'No Link Page exists for this venue.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		$result = ec_with_link_page_storage_blog(
			static function () use ( $link_page_id, $date_range, $start_date, $end_date ) {
				return apply_filters( 'extrachill_get_link_page_analytics', null, (int) $link_page_id, max( 1, min( 90, $date_range ) ), '' !== $start_date ? $start_date : null, '' !== $end_date ? $end_date : null );
			}
		);
		return is_array( $result ) || is_wp_error( $result ) ? $result : new \WP_Error( 'venue_link_page_analytics_unavailable', __( 'Link Page analytics are unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
	}

	/** Build a fresh versioned snapshot through a local multisite switch, never HTTP. */
	private static function build_snapshot( int $venue_term_id, string $reference ) {
		$source = self::read_live_venue( $venue_term_id );
		return is_wp_error( $source ) ? $source : self::build_snapshot_from_source( $source, $reference );
	}

	/** Read the canonical profile while restoring the exact caller blog. */
	private static function read_live_venue( int $venue_term_id ) {
		$events_blog_id = self::events_blog_id();
		$did_switch     = get_current_blog_id() !== $events_blog_id;
		if ( $did_switch ) {
			switch_to_blog( $events_blog_id );
			if ( get_current_blog_id() !== $events_blog_id ) {
				return new \WP_Error( 'venue_link_page_events_switch_failed', __( 'The canonical Events site is unavailable.', 'extrachill-events' ) );
			}
		}
		try {
			$term = get_term( $venue_term_id, 'venue' );
			if ( ! $term || is_wp_error( $term ) || 'venue' !== $term->taxonomy ) {
				return new \WP_Error( 'venue_link_page_owner_not_found', __( 'The canonical venue no longer exists.', 'extrachill-events' ), array( 'status' => 404 ) );
			}
			$profile = function_exists( 'data_machine_events_get_venue_profile' ) ? data_machine_events_get_venue_profile( $venue_term_id ) : array();
			if ( is_wp_error( $profile ) ) {
				return $profile;
			}
			$profile['term_id']     = $venue_term_id;
			$profile['name']        = (string) ( $profile['name'] ?? $term->name );
			$profile['slug']        = (string) $term->slug;
			$profile['description'] = (string) ( $profile['description'] ?? $term->description );
			$profile['source_url']  = get_term_link( $term );
			foreach ( array( 'address', 'city', 'state', 'zip', 'country', 'website' ) as $field ) {
				$profile[ $field ] = (string) ( $profile[ $field ] ?? get_term_meta( $venue_term_id, '_venue_' . $field, true ) );
			}
			$profile['social_links'] = array();
			foreach ( array( 'instagram', 'facebook', 'youtube', 'tiktok', 'twitter' ) as $network ) {
				$url = esc_url_raw( (string) get_term_meta( $venue_term_id, '_venue_' . $network, true ), array( 'http', 'https' ) );
				if ( $url ) {
					$profile['social_links'][] = array(
						'type' => $network,
						'url'  => $url,
					);
				}
			}
			return $profile;
		} finally {
			if ( $did_switch ) {
				restore_current_blog();
			}
		}
	}

	/** Convert canonical profile data into the minimum immutable public record. */
	private static function build_snapshot_from_source( array $source, string $reference ): array {
		$logo      = is_array( $source['logo'] ?? null ) ? $source['logo'] : array();
		$canonical = array(
			'address'     => (string) ( $source['address'] ?? '' ),
			'city'        => (string) ( $source['city'] ?? '' ),
			'country'     => (string) ( $source['country'] ?? '' ),
			'description' => (string) ( $source['description'] ?? '' ),
			'logo_alt'    => (string) ( $logo['alt'] ?? '' ),
			'logo_url'    => (string) ( $logo['url'] ?? '' ),
			'name'        => (string) ( $source['name'] ?? '' ),
			'slug'        => (string) ( $source['slug'] ?? '' ),
			'state'       => (string) ( $source['state'] ?? '' ),
			'term_id'     => (int) ( $source['term_id'] ?? 0 ),
			'website'     => (string) ( $source['website'] ?? '' ),
			'zip'         => (string) ( $source['zip'] ?? '' ),
		);
		ksort( $canonical );
		$encoded_source = wp_json_encode( $canonical, JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $encoded_source ) {
			$encoded_source = 'venue-source-json-encoding-failed';
		}
		$source_version = isset( $source['revision'] ) ? (string) $source['revision'] : hash( 'sha256', $encoded_source );
		return array(
			'version'         => self::SNAPSHOT_VERSION,
			'owner_reference' => $reference,
			'title'           => sanitize_text_field( (string) $source['name'] ),
			'description'     => sanitize_textarea_field( (string) $source['description'] ),
			'image_url'       => esc_url_raw( (string) ( $logo['url'] ?? '' ), array( 'http', 'https' ) ),
			'image_alt'       => sanitize_text_field( (string) ( $logo['alt'] ?? '' ) ),
			'website'         => esc_url_raw( (string) ( $source['website'] ?? '' ), array( 'http', 'https' ) ),
			'social_links'    => array_values( (array) ( $source['social_links'] ?? array() ) ),
			'location'        => array(
				'address' => sanitize_text_field( (string) ( $source['address'] ?? '' ) ),
				'city'    => sanitize_text_field( (string) ( $source['city'] ?? '' ) ),
				'state'   => sanitize_text_field( (string) ( $source['state'] ?? '' ) ),
				'zip'     => sanitize_text_field( (string) ( $source['zip'] ?? '' ) ),
				'country' => sanitize_text_field( (string) ( $source['country'] ?? '' ) ),
			),
			'source'          => array(
				'blog_id'       => self::events_blog_id(),
				'taxonomy'      => 'venue',
				'venue_term_id' => (int) $source['term_id'],
				'version'       => $source_version,
				'refreshed_at'  => gmdate( 'c' ),
				'public_url'    => is_wp_error( $source['source_url'] ?? null ) ? '' : esc_url_raw( (string) ( $source['source_url'] ?? '' ) ),
			),
		);
	}

	/** Validate a stored snapshot against the exact immutable owner binding. */
	private static function read_snapshot( int $link_page_id, string $reference ) {
		$snapshot = get_post_meta( $link_page_id, self::SNAPSHOT_META_KEY, true );
		$source   = is_array( $snapshot ) ? ( $snapshot['source'] ?? array() ) : array();
		$owner    = ec_parse_link_page_owner_reference( $reference );
		$required = array( 'version', 'owner_reference', 'title', 'description', 'image_url', 'image_alt', 'website', 'social_links', 'location', 'source' );
		if ( is_wp_error( $owner ) || ! is_array( $snapshot ) || array_diff( $required, array_keys( $snapshot ) ) || self::SNAPSHOT_VERSION !== (int) $snapshot['version'] || $reference !== $snapshot['owner_reference'] || self::events_blog_id() !== (int) ( $source['blog_id'] ?? 0 ) || 'venue' !== ( $source['taxonomy'] ?? '' ) || (int) ( $source['venue_term_id'] ?? 0 ) !== (int) $owner['object_id'] || empty( $source['version'] ) || empty( $source['refreshed_at'] ) || ! is_array( $snapshot['social_links'] ) || ! is_array( $snapshot['location'] ) ) {
			return new \WP_Error( 'venue_link_page_snapshot_invalid', __( 'The venue Link Page public snapshot is missing, stale, or corrupt.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		return $snapshot;
	}

	/** Compose shared runtime data with venue identity without artist response aliases. */
	private static function compose_response( $data, array $snapshot ) {
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$link_page_id     = (int) $data['link_page_id'];
		$data['revision'] = self::persistence_revision( $data );
		return array(
			'venue'     => array(
				'term_id'         => (int) $snapshot['source']['venue_term_id'],
				'owner_reference' => $snapshot['owner_reference'],
				'title'           => $snapshot['title'],
				'management_url'  => self::management_url( (int) $snapshot['source']['venue_term_id'] ),
				'snapshot'        => $snapshot,
			),
			'link_page' => array_merge(
				$data,
				array(
					'public_url' => ec_get_link_page_public_url( $link_page_id ),
				)
			),
		);
	}

	/** Hash only canonical generic persistence fields for optimistic concurrency. */
	private static function persistence_revision( array $data ): string {
		$encoded = wp_json_encode( array_intersect_key( $data, array_flip( array( 'links', 'css_vars', 'bio', 'settings', 'background_image_id' ) ) ) );
		return hash( 'sha256', false === $encoded ? '{}' : $encoded );
	}

	/** Return deterministic unique candidates from least to most qualified. */
	private static function slug_candidates( array $source ): array {
		$base       = sanitize_title( (string) $source['slug'] );
		$geography  = sanitize_title( implode( '-', array_filter( array( $source['city'] ?? '', $source['state'] ?? '' ) ) ) );
		$candidates = array( $base );
		if ( $geography ) {
			$candidates[] = $base . '-' . $geography;
		}
		$candidates[] = $base . '-venue-' . (int) $source['term_id'];
		return array_values( array_unique( array_filter( $candidates ) ) );
	}

	/** Build the venue workspace tab contract owned by #324. */
	private static function management_url( int $venue_term_id ): string {
		if ( function_exists( 'ec_events_get_booking_console_url' ) ) {
			return str_replace( '#tab-calendar', '#tab-link-page', ec_events_get_booking_console_url( $venue_term_id ) );
		}
		return add_query_arg( 'venue_id', $venue_term_id, get_home_url( self::events_blog_id(), '/venue-settings/' ) ) . '#tab-link-page';
	}

	/** Require the canonical feature gate plus exact active direct membership. */
	private static function authorize( int $venue_term_id ) {
		$events_blog_id = self::events_blog_id();
		$did_switch     = get_current_blog_id() !== $events_blog_id;
		if ( $did_switch ) {
			switch_to_blog( $events_blog_id );
			if ( get_current_blog_id() !== $events_blog_id ) {
				return new \WP_Error( 'venue_link_page_events_switch_failed', __( 'The canonical Events site is unavailable.', 'extrachill-events' ) );
			}
		}
		try {
			return ( new VenueAuthorization() )->authorize( get_current_user_id(), $venue_term_id, VenueAuthorization::ACTION_ACCESS_VENUE );
		} finally {
			if ( $did_switch ) {
				restore_current_blog();
			}
		}
	}

	/** Confirm the page and owner form an exact round-trip binding. */
	private static function binding_is_exact( array $resolved ): bool {
		if ( ! self::is_venue_owner( $resolved['owner'] ?? array() ) || empty( $resolved['link_page_id'] ) || empty( $resolved['owner_reference'] ) ) {
			return false;
		}
		$owner    = ec_get_link_page_owner( (int) $resolved['link_page_id'] );
		$owner_id = ec_get_link_page_id_for_owner( $resolved['owner_reference'] );
		return ! is_wp_error( $owner ) && ! is_wp_error( $owner_id ) && $owner['reference'] === $resolved['owner_reference'] && (int) $owner_id === (int) $resolved['link_page_id'];
	}

	/** Match only canonical blog-7 venue terms. */
	private static function is_venue_owner( array $owner ): bool {
		return 'term' === ( $owner['kind'] ?? '' ) && self::events_blog_id() === (int) ( $owner['blog_id'] ?? 0 ) && 'venue' === ( $owner['subtype'] ?? '' ) && (int) ( $owner['object_id'] ?? 0 ) > 0;
	}

	/** Resolve the configured canonical Events blog. */
	private static function events_blog_id(): int {
		return function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 7;
	}

	/** Return a stable non-enumerating denial. */
	private static function forbidden(): \WP_Error {
		return new \WP_Error( 'venue_link_page_forbidden', __( 'You are not authorized to manage this venue Link Page.', 'extrachill-events' ), array( 'status' => 403 ) );
	}
}
