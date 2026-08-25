<?php
/**
 * Verified promoter owner adapter for the standalone Link Pages runtime.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Owns promoter authorization, snapshots, lifecycle, and public projection. */
final class PromoterLinkPages {

	public const SNAPSHOT_META_KEY = '_extrachill_events_promoter_link_page_snapshot';
	public const ORPHAN_META_KEY   = '_extrachill_events_promoter_link_page_orphaned';
	public const SNAPSHOT_VERSION  = 1;

	/** @var bool */
	private static $hooks_registered = false;
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

	/** Register canonical metadata and authority lifecycle hooks once. */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;
		self::register_authority_hook();
		add_action( 'edited_promoter', array( self::class, 'refresh_after_profile_update' ), 30, 1 );
		add_action( 'extrachill_events_promoter_profile_updated', array( self::class, 'refresh_after_profile_update' ), 20, 1 );
		add_action( 'extrachill_events_promoter_authority_changed', array( self::class, 'authority_changed' ), 1, 2 );
		add_action( 'pre_delete_term', array( self::class, 'capture_before_term_deletion' ), 10, 2 );
		add_action( 'delete_term', array( self::class, 'orphan_after_term_deletion' ), 20, 4 );
	}

	/** Return the canonical opaque owner reference. */
	public static function owner_reference( int $promoter_term_id ) {
		if ( $promoter_term_id < 1 || self::events_blog_id() < 1 ) {
			return new \WP_Error( 'invalid_promoter_link_page_owner', __( 'A canonical Events promoter is required.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		return ec_normalize_link_page_owner_reference(
			array(
				'kind'      => 'term',
				'blog_id'   => self::events_blog_id(),
				'subtype'   => 'promoter',
				'object_id' => $promoter_term_id,
			)
		);
	}

	/** Expose exact member authorization to ability permissions. */
	public static function authorize_promoter( int $promoter_term_id ) {
		return self::authorize( $promoter_term_id );
	}

	/** Promoters have no legacy ownership aliases. */
	public static function compatibility_provider( $operation, $context ): array {
		unset( $operation, $context );
		return array();
	}

	/** Claim only canonical Events promoter owner references. */
	public static function operation_provider( $resolved ) {
		if ( ! self::is_promoter_owner( $resolved['owner'] ?? array() ) ) {
			return null;
		}
		return array(
			'authorize' => array( self::class, 'operation_authorize' ),
			'read'      => array( self::class, 'operation_read' ),
			'save'      => array( self::class, 'operation_save' ),
		);
	}

	/** Reauthorize generic operations against the lock-current exact member. */
	public static function operation_authorize( $resolved, $operation ) {
		if ( ! in_array( $operation, array( 'read', 'save' ), true ) || ! self::binding_is_exact( $resolved ) ) {
			return false;
		}
		return self::authorize( (int) $resolved['owner']['object_id'] );
	}

	/** Read generic persistence and the stored promoter snapshot. */
	public static function operation_read( $resolved ) {
		$allowed = self::operation_authorize( $resolved, 'read' );
		if ( true !== $allowed ) {
			return is_wp_error( $allowed ) ? $allowed : self::forbidden();
		}
		$snapshot = self::read_snapshot( (int) $resolved['link_page_id'], (string) $resolved['owner_reference'] );
		$data     = is_wp_error( $snapshot ) ? $snapshot : ec_read_link_page_persistence( (int) $resolved['link_page_id'] );
		return is_wp_error( $data ) ? $data : self::compose_response( $data, $snapshot );
	}

	/** Save generic data and the fresh identity snapshot in one compensatable lock. */
	public static function operation_save( $resolved, $data ) {
		$allowed = self::operation_authorize( $resolved, 'save' );
		if ( true !== $allowed ) {
			return is_wp_error( $allowed ) ? $allowed : self::forbidden();
		}
		$promoter_term_id = (int) $resolved['owner']['object_id'];
		return self::with_authority_lock(
			$promoter_term_id,
			false,
			static function () use ( $resolved, $data ) {
				return ec_with_link_page_storage_blog(
					static function () use ( $resolved, $data ) {
						return self::operation_save_locked( $resolved, $data );
					}
				);
			}
		);
	}

	/** Save generic persistence and promoter state through one composed mutation. */
	private static function operation_save_locked( array $resolved, $data ) {
		$allowed_keys = array( 'links', 'css_vars', 'bio', 'link_expiration_enabled', 'redirect_enabled', 'redirect_target_url', 'youtube_embed_enabled', 'meta_pixel_id', 'google_tag_id', 'google_tag_manager_id', 'social_icons_position', 'profile_image_shape', 'background_image_id' );
		if ( ! is_array( $data ) || array_diff( array_keys( $data ), $allowed_keys ) ) {
			return new \WP_Error( 'invalid_promoter_link_page_save', __( 'The promoter Link Page save contains unsupported fields.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$link_page_id = (int) $resolved['link_page_id'];
		$snapshot     = self::build_snapshot( (int) $resolved['owner']['object_id'], (string) $resolved['owner_reference'] );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$saved = ec_save_link_page_persistence_composed(
			$link_page_id,
			$data,
			static function ( $finalized_link_page_id, $persistence ) use ( $snapshot, $resolved ) {
				unset( $persistence );
				return self::finalize_owner_state( (int) $finalized_link_page_id, (string) $resolved['owner_reference'], $snapshot, 'save' );
			}
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return self::compose_response( $saved, $snapshot );
	}

	/** Provision one page under the generic owner/page locks. */
	public static function provision( int $promoter_term_id ) {
		$allowed = self::authorize( $promoter_term_id );
		if ( true !== $allowed ) {
			return $allowed;
		}
		return self::with_authority_lock(
			$promoter_term_id,
			false,
			static function () use ( $promoter_term_id ) {
				return self::provision_locked( $promoter_term_id );
			}
		);
	}

	/** Provision generic ownership and promoter state through one composed mutation. */
	private static function provision_locked( int $promoter_term_id ) {
		$reference = self::owner_reference( $promoter_term_id );
		if ( is_wp_error( $reference ) ) {
			return $reference;
		}
		return ec_with_link_page_storage_blog(
			static function () use ( $promoter_term_id, $reference ) {
				$source = self::read_live_promoter( $promoter_term_id );
				if ( is_wp_error( $source ) ) {
					return $source;
				}
				$provisioned = null;
				$snapshot    = self::snapshot_from_source( $source, $reference );
				foreach ( self::slug_candidates( $source ) as $slug ) {
					$provisioned = ec_provision_owned_link_page_composed(
						$reference,
						$source['name'],
						$slug,
						static function ( $link_page_id, $owner_reference ) use ( $snapshot ) {
							return self::finalize_owner_state( (int) $link_page_id, (string) $owner_reference, $snapshot, 'provision' );
						},
						false,
						static function () use ( $promoter_term_id ) {
							return self::authorize( $promoter_term_id );
						}
					);
					if ( ! is_wp_error( $provisioned ) || 'link_page_slug_conflict' !== $provisioned->get_error_code() ) {
						break;
					}
				}
				if ( is_wp_error( $provisioned ) ) {
					return $provisioned;
				}
				$link_page_id        = (int) $provisioned['link_page_id'];
				$created             = ! empty( $provisioned['created'] );
				$response            = self::compose_response( ec_read_link_page_persistence( $link_page_id ), $snapshot );
				$response['created'] = $created;
				return $response;
			}
		);
	}

	/** Finalize and compensate only promoter-owned state under the composed page lock. */
	private static function finalize_owner_state( int $link_page_id, string $reference, array $snapshot, string $operation ) {
		$before = array(
			self::SNAPSHOT_META_KEY               => ec_snapshot_link_page_meta( $link_page_id, self::SNAPSHOT_META_KEY ),
			EC_LINK_PAGE_PUBLIC_SNAPSHOT_META_KEY => ec_snapshot_link_page_meta( $link_page_id, EC_LINK_PAGE_PUBLIC_SNAPSHOT_META_KEY ),
		);
		if ( ! ec_write_link_page_meta( $link_page_id, self::SNAPSHOT_META_KEY, $snapshot ) ) {
			$error = new \WP_Error( 'promoter_link_page_snapshot_save_failed', __( 'The promoter Link Page snapshot could not be saved.', 'extrachill-events' ) );
			return ec_restore_link_page_meta_snapshots( $link_page_id, $before ) ? $error : self::compensation_failed( $error, $operation );
		}
		$public = ec_save_link_page_public_projection_snapshot( $link_page_id, $reference, self::projection_from_snapshot( $snapshot, $link_page_id ) );
		if ( is_wp_error( $public ) ) {
			return ec_restore_link_page_meta_snapshots( $link_page_id, $before ) ? $public : self::compensation_failed( $public, $operation );
		}
		try {
			do_action( 'ec_link_page_save', $link_page_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			$error = new \WP_Error( 'promoter_link_page_final_hook_failed', __( 'The promoter Link Page final mutation hook failed.', 'extrachill-events' ) );
			return ec_restore_link_page_meta_snapshots( $link_page_id, $before ) ? $error : self::compensation_failed( $error, $operation );
		}
		return true;
	}

	/** Refresh a page snapshot through explicit member authorization. */
	public static function refresh_snapshot( int $promoter_term_id ) {
		return self::refresh_snapshot_mutation( $promoter_term_id, false );
	}

	/** Refresh after a trusted canonical mutation, without user authority. */
	private static function refresh_snapshot_trusted( int $promoter_term_id ) {
		return self::refresh_snapshot_mutation( $promoter_term_id, true );
	}

	/** Execute an authorized or trusted snapshot refresh. */
	private static function refresh_snapshot_mutation( int $promoter_term_id, bool $trusted ) {
		if ( ! $trusted ) {
			$allowed = self::authorize( $promoter_term_id );
			if ( true !== $allowed ) {
				return $allowed;
			}
		}
		return self::with_authority_lock(
			$promoter_term_id,
			$trusted,
			static function () use ( $promoter_term_id, $trusted ) {
				return self::refresh_snapshot_locked( $promoter_term_id, $trusted );
			}
		);
	}

	/** Refresh while promoter authority remains lock-current. */
	private static function refresh_snapshot_locked( int $promoter_term_id, bool $trusted ) {
		$reference    = self::owner_reference( $promoter_term_id );
		$link_page_id = is_wp_error( $reference ) ? $reference : ec_get_link_page_id_for_owner( $reference );
		if ( is_wp_error( $link_page_id ) || ! $link_page_id ) {
			return is_wp_error( $link_page_id ) ? $link_page_id : new \WP_Error( 'promoter_link_page_not_found', __( 'No Link Page exists for this promoter.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		$snapshot = self::build_snapshot( $promoter_term_id, $reference );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		return ec_with_link_page_storage_blog(
			static function () use ( $link_page_id, $snapshot, $trusted, $promoter_term_id ) {
				return ec_with_link_page_lock_scope(
					(int) $link_page_id,
					static function () use ( $link_page_id, $snapshot, $trusted, $promoter_term_id ) {
						if ( ! $trusted ) {
							$allowed = self::authorize( $promoter_term_id );
							if ( true !== $allowed ) {
								return $allowed;
							}
						}
						$before = array(
							self::SNAPSHOT_META_KEY => ec_snapshot_link_page_meta( (int) $link_page_id, self::SNAPSHOT_META_KEY ),
							EC_LINK_PAGE_PUBLIC_SNAPSHOT_META_KEY => ec_snapshot_link_page_meta( (int) $link_page_id, EC_LINK_PAGE_PUBLIC_SNAPSHOT_META_KEY ),
						);
						if ( ! ec_write_link_page_meta( (int) $link_page_id, self::SNAPSHOT_META_KEY, $snapshot ) ) {
							$error = new \WP_Error( 'promoter_link_page_snapshot_save_failed', __( 'The promoter Link Page snapshot could not be refreshed.', 'extrachill-events' ) );
							return ec_restore_link_page_meta_snapshots( (int) $link_page_id, $before ) ? $error : self::compensation_failed( $error, 'refresh' );
						}
						$public = ec_save_link_page_public_projection_snapshot( (int) $link_page_id, $snapshot['owner_reference'], self::projection_from_snapshot( $snapshot, (int) $link_page_id ) );
						if ( is_wp_error( $public ) ) {
							return ec_restore_link_page_meta_snapshots( (int) $link_page_id, $before ) ? $public : self::compensation_failed( $public, 'refresh' );
						}
						try {
							do_action( 'ec_link_page_save', (int) $link_page_id );
						} catch ( \Throwable $throwable ) {
							unset( $throwable );
							$error = new \WP_Error( 'promoter_link_page_final_hook_failed', __( 'The promoter Link Page final mutation hook failed.', 'extrachill-events' ) );
							return ec_restore_link_page_meta_snapshots( (int) $link_page_id, $before ) ? $error : self::compensation_failed( $error, 'refresh' );
						}
						return self::compose_response( ec_read_link_page_persistence( (int) $link_page_id ), $snapshot );
					}
				);
			}
		);
	}

	/** Trusted canonical metadata refresh callback. */
	public static function refresh_after_profile_update( $promoter_term_id ): void {
		if ( get_current_blog_id() !== self::events_blog_id() ) {
			return;
		}
		$result = self::refresh_snapshot_trusted( absint( $promoter_term_id ) );
		if ( is_wp_error( $result ) && ! in_array( $result->get_error_code(), array( 'promoter_link_page_not_found', 'promoter_link_page_forbidden' ), true ) ) {
			do_action( 'extrachill_events_promoter_link_page_snapshot_refresh_failed', absint( $promoter_term_id ), $result );
		}
	}

	/** Withdraw a promoter page inside the authority revocation transaction. */
	public static function authority_precommit( $allowed, $promoter_term_id, $change ) {
		if ( true !== $allowed || 'organization_revoked' !== $change ) {
			return $allowed;
		}
		return self::withdraw_for_revocation( absint( $promoter_term_id ) );
	}

	/** Purge the withdrawn page only after authority commits. */
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

	/** Capture exact owner/page binding before promoter term deletion. */
	public static function capture_before_term_deletion( $term_id, $taxonomy ): void {
		if ( 'promoter' !== $taxonomy || get_current_blog_id() !== self::events_blog_id() ) {
			return;
		}
		$reference = self::owner_reference( absint( $term_id ) );
		$page_id   = is_wp_error( $reference ) ? $reference : ec_get_link_page_id_for_owner( $reference );
		if ( is_wp_error( $page_id ) ) {
			do_action( 'extrachill_events_promoter_link_page_orphan_capture_failed', absint( $term_id ), $page_id );
		} elseif ( $page_id ) {
			$GLOBALS['extrachill_events_deleting_promoter_link_pages'][ absint( $term_id ) ] = array(
				'link_page_id'    => (int) $page_id,
				'owner_reference' => $reference,
			);
		}
	}

	/** Draft and preserve audit metadata after successful promoter term deletion. */
	public static function orphan_after_term_deletion( $term_id, $term_taxonomy_id, $taxonomy, $deleted_term ): void {
		unset( $term_taxonomy_id );
		if ( 'promoter' !== $taxonomy || get_current_blog_id() !== self::events_blog_id() ) {
			return;
		}
		$capture = $GLOBALS['extrachill_events_deleting_promoter_link_pages'][ absint( $term_id ) ] ?? null;
		unset( $GLOBALS['extrachill_events_deleting_promoter_link_pages'][ absint( $term_id ) ] );
		if ( ! is_array( $capture ) ) {
			$reference = sprintf( 'term:%d:promoter:%d', self::events_blog_id(), absint( $term_id ) );
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
				do_action( 'extrachill_events_promoter_link_page_orphan_capture_failed', absint( $term_id ), new \WP_Error( 'promoter_link_page_orphan_recovery_failed', __( 'The deleted promoter Link Page binding could not be recovered.', 'extrachill-events' ) ) );
				return;
			}
		}
		$result = self::draft_captured_page( $capture, absint( $term_id ), 'term_deleted', sanitize_text_field( (string) ( $deleted_term->name ?? '' ) ) );
		if ( is_wp_error( $result ) ) {
			do_action( 'extrachill_events_promoter_link_page_orphan_failed', absint( $term_id ), $capture, $result );
		}
	}

	/** Public rendering uses only the stored storage-blog snapshot. */
	public static function public_projection_provider( $context ) {
		if ( ! self::is_promoter_owner( $context['owner'] ?? array() ) ) {
			return null;
		}
		$snapshot = self::read_snapshot( (int) $context['link_page_id'], (string) $context['owner_reference'] );
		return is_wp_error( $snapshot ) ? $snapshot : self::projection_from_snapshot( $snapshot, (int) $context['link_page_id'] );
	}

	/** Build the promoter-only schema and render contract. */
	private static function projection_from_snapshot( array $snapshot, int $link_page_id ): array {
		$canonical = ec_get_link_page_public_url( $link_page_id );
		$entity_id = $canonical . '#promoter';
		$entity    = array(
			'@type'       => 'Organization',
			'@id'         => $entity_id,
			'name'        => $snapshot['title'],
			'url'         => $canonical,
			'description' => $snapshot['description'],
		);
		$same_as   = array_values( array_unique( array_filter( array_merge( array( $snapshot['website'] ), array_column( $snapshot['social_links'], 'url' ) ) ) ) );
		if ( $snapshot['image_url'] ) {
			$entity['image'] = $snapshot['image_url'];
		}
		if ( $same_as ) {
			$entity['sameAs'] = $same_as;
		}
		return array(
			'display_title'   => $snapshot['title'],
			'bio'             => $snapshot['description'],
			'profile_img_url' => $snapshot['image_url'],
			'social_links'    => $snapshot['social_links'],
			'social_renderer' => array( self::class, 'render_social_links' ),
			'management_url'  => self::management_url( (int) $snapshot['source']['promoter_term_id'] ),
			'body_attributes' => array(
				'data-extrch-owner-type'  => 'promoter',
				'data-extrch-promoter-id' => (string) $snapshot['source']['promoter_term_id'],
			),
			'seo'             => array(
				'title'       => $snapshot['title'] . ' | extrachill.link',
				'description' => $snapshot['description'],
				'canonical'   => $canonical,
				'image'       => $snapshot['image_url'],
				'image_alt'   => $snapshot['image_alt'] ? $snapshot['image_alt'] : $snapshot['title'],
				'og_type'     => 'profile',
				'schema'      => array(
					$entity,
					array(
						'@type'      => 'ProfilePage',
						'@id'        => $canonical . '#profilepage',
						'url'        => $canonical,
						'name'       => $snapshot['title'],
						'mainEntity' => array( '@id' => $entity_id ),
					),
				),
			),
			'tracking_url'    => trailingslashit( get_home_url( ec_get_link_page_storage_blog_id(), '/' ) ) . 'wp-json/extrachill/v1/analytics/click',
		);
	}

	/** Render only public promoter links; no artist modules are involved. */
	public static function render_social_links( $social_links ): string {
		if ( ! is_array( $social_links ) || ! $social_links ) {
			return '';
		}
		$html = '<nav class="extrch-link-page-socials extrch-link-page-promoter-socials" aria-label="Promoter links">';
		foreach ( $social_links as $social ) {
			$label = ucfirst( (string) ( $social['type'] ?? 'website' ) );
			$html .= '<a href="' . esc_url( $social['url'] ?? '' ) . '" rel="noopener noreferrer" aria-label="' . esc_attr( $label ) . '">' . esc_html( $label ) . '</a>';
		}
		return $html . '</nav>';
	}

	/** Delegate analytics in canonical storage context after exact authorization. */
	public static function analytics( int $promoter_term_id, int $date_range = 30, string $start_date = '', string $end_date = '' ) {
		$allowed = self::authorize( $promoter_term_id );
		if ( true !== $allowed ) {
			return $allowed;
		}
		$reference    = self::owner_reference( $promoter_term_id );
		$link_page_id = is_wp_error( $reference ) ? $reference : ec_get_link_page_id_for_owner( $reference );
		if ( is_wp_error( $link_page_id ) || ! $link_page_id ) {
			return is_wp_error( $link_page_id ) ? $link_page_id : new \WP_Error( 'promoter_link_page_not_found', __( 'No Link Page exists for this promoter.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		$result = ec_with_link_page_storage_blog(
			static function () use ( $link_page_id, $date_range, $start_date, $end_date ) {
				return apply_filters( 'extrachill_get_link_page_analytics', null, (int) $link_page_id, max( 1, min( 90, $date_range ) ), $start_date ? $start_date : null, $end_date ? $end_date : null );
			}
		);
		return is_array( $result ) || is_wp_error( $result ) ? $result : new \WP_Error( 'promoter_link_page_analytics_unavailable', __( 'Link Page analytics are unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
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
					$term = get_term( (int) $organization['promoter_term_id'], 'promoter' );
					if ( ! $term || is_wp_error( $term ) || 'promoter' !== $term->taxonomy ) {
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

	/** Build a fresh snapshot from canonical Events data without HTTP. */
	private static function build_snapshot( int $promoter_term_id, string $reference ) {
		$source = self::read_live_promoter( $promoter_term_id );
		return is_wp_error( $source ) ? $source : self::snapshot_from_source( $source, $reference );
	}

	/** Read only an active verified canonical promoter. */
	private static function read_live_promoter( int $promoter_term_id ) {
		return self::with_events_blog(
			static function () use ( $promoter_term_id ) {
				$organization = ( new PromoterAuthorityRepository() )->get_organization( $promoter_term_id );
				if ( is_wp_error( $organization ) ) {
					return $organization;
				}
				if ( ! is_array( $organization ) || PromoterAuthorityRepository::STATUS_ACTIVE !== $organization['status'] ) {
					return self::forbidden();
				}
				$term = get_term( $promoter_term_id, 'promoter' );
				if ( ! $term || is_wp_error( $term ) || 'promoter' !== $term->taxonomy ) {
					return new \WP_Error( 'promoter_link_page_owner_not_found', __( 'The canonical promoter no longer exists.', 'extrachill-events' ), array( 'status' => 404 ) );
				}
				$data = function_exists( 'data_machine_events_get_promoter_data' ) ? data_machine_events_get_promoter_data( $promoter_term_id ) : array();
				return array(
					'term_id'      => $promoter_term_id,
					'name'         => (string) ( $data['name'] ?? $term->name ),
					'slug'         => (string) $term->slug,
					'description'  => (string) ( $data['description'] ?? $term->description ),
					'website'      => (string) ( $data['url'] ?? get_term_meta( $promoter_term_id, '_promoter_url', true ) ),
					'image_url'    => '',
					'image_alt'    => '',
					'social_links' => array(),
					'source_url'   => get_term_link( $term ),
					'revision'     => (string) $organization['version'] . ':' . (string) $organization['updated_at'],
				);
			}
		);
	}

	/** Convert canonical promoter data into the immutable public snapshot. */
	private static function snapshot_from_source( array $source, string $reference ): array {
		$canonical = array_intersect_key( $source, array_flip( array( 'term_id', 'name', 'slug', 'description', 'website', 'image_url', 'image_alt', 'social_links' ) ) );
		ksort( $canonical );
		$encoded = wp_json_encode( $canonical, JSON_INVALID_UTF8_SUBSTITUTE );
		return array(
			'version'         => self::SNAPSHOT_VERSION,
			'owner_reference' => $reference,
			'title'           => sanitize_text_field( (string) $source['name'] ),
			'description'     => sanitize_textarea_field( (string) $source['description'] ),
			'image_url'       => esc_url_raw( (string) $source['image_url'], array( 'http', 'https' ) ),
			'image_alt'       => sanitize_text_field( (string) $source['image_alt'] ),
			'website'         => esc_url_raw( (string) $source['website'], array( 'http', 'https' ) ),
			'social_links'    => array_values( (array) $source['social_links'] ),
			'entity_type'     => 'Organization',
			'source'          => array(
				'blog_id'          => self::events_blog_id(),
				'taxonomy'         => 'promoter',
				'promoter_term_id' => (int) $source['term_id'],
				'version'          => ! empty( $source['revision'] ) ? (string) $source['revision'] : hash( 'sha256', false === $encoded ? 'promoter-source-json-encoding-failed' : $encoded ),
				'refreshed_at'     => gmdate( 'c' ),
				'public_url'       => is_wp_error( $source['source_url'] ) ? '' : esc_url_raw( (string) $source['source_url'], array( 'http', 'https' ) ),
			),
		);
	}

	/** Validate the complete stored snapshot against its immutable owner. */
	private static function read_snapshot( int $link_page_id, string $reference ) {
		$snapshot = get_post_meta( $link_page_id, self::SNAPSHOT_META_KEY, true );
		$source   = is_array( $snapshot ) ? (array) ( $snapshot['source'] ?? array() ) : array();
		$owner    = ec_parse_link_page_owner_reference( $reference );
		$required = array( 'version', 'owner_reference', 'title', 'description', 'image_url', 'image_alt', 'website', 'social_links', 'entity_type', 'source' );
		if ( is_wp_error( $owner ) || ! is_array( $snapshot ) || array_diff( $required, array_keys( $snapshot ) ) || self::SNAPSHOT_VERSION !== (int) $snapshot['version'] || $reference !== $snapshot['owner_reference'] || 'Organization' !== $snapshot['entity_type'] || self::events_blog_id() !== (int) ( $source['blog_id'] ?? 0 ) || 'promoter' !== ( $source['taxonomy'] ?? '' ) || (int) ( $source['promoter_term_id'] ?? 0 ) !== (int) $owner['object_id'] || empty( $source['version'] ) || empty( $source['refreshed_at'] ) || ! is_array( $snapshot['social_links'] ) ) {
			return new \WP_Error( 'promoter_link_page_snapshot_invalid', __( 'The promoter Link Page public snapshot is missing, stale, or corrupt.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		return $snapshot;
	}

	/** Compose promoter identity with owner-neutral persistence. */
	private static function compose_response( $data, array $snapshot ) {
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return array(
			'promoter'  => array(
				'term_id'         => (int) $snapshot['source']['promoter_term_id'],
				'owner_reference' => $snapshot['owner_reference'],
				'title'           => $snapshot['title'],
				'management_url'  => self::management_url( (int) $snapshot['source']['promoter_term_id'] ),
				'snapshot'        => $snapshot,
			),
			'link_page' => array_merge( $data, array( 'public_url' => ec_get_link_page_public_url( (int) $data['link_page_id'] ) ) ),
		);
	}

	/** Deterministic base, optional canonical qualifier, and stable ID suffix. */
	private static function slug_candidates( array $source ): array {
		$base       = sanitize_title( (string) $source['slug'] );
		$qualifier  = sanitize_title( (string) ( $source['qualifier'] ?? '' ) );
		$candidates = array( $base );
		if ( $qualifier ) {
			$candidates[] = $base . '-' . $qualifier;
		}
		$candidates[] = $base . '-promoter-' . (int) $source['term_id'];
		return array_values( array_unique( array_filter( $candidates ) ) );
	}

	/**
	 * Build the Events-owned promoter management destination.
	 *
	 * @param int $promoter_term_id Exact promoter term ID.
	 */
	public static function management_url( int $promoter_term_id ): string {
		return add_query_arg( 'identity', 'promoter:' . $promoter_term_id, get_home_url( self::events_blog_id(), '/venue-settings/' ) ) . '#promoter-link-page';
	}

	/** Require exact active verified membership plus current product access. */
	private static function authorize( int $promoter_term_id ) {
		return self::with_events_blog(
			static function () use ( $promoter_term_id ) {
				return ( new PromoterAuthorization() )->authorize( PromoterAuthorization::effective_user_id(), $promoter_term_id, PromoterAuthorization::ACTION_ACCESS_PROMOTER );
			}
		);
	}

	/** Serialize a promoter mutation against organization revocation. */
	private static function with_authority_lock( int $promoter_term_id, bool $trusted, callable $callback ) {
		return self::with_events_blog(
			static function () use ( $promoter_term_id, $trusted, $callback ) {
				$repository = new PromoterAuthorityRepository();
				if ( $trusted ) {
					return $repository->with_active_organization_lock( $promoter_term_id, $callback );
				}
				$user_id = PromoterAuthorization::effective_user_id();
				return $repository->with_active_membership_lock(
					$promoter_term_id,
					$user_id,
					static function () use ( $repository, $user_id, $promoter_term_id, $callback ) {
						$allowed = ( new PromoterAuthorization( $repository ) )->authorize( $user_id, $promoter_term_id, PromoterAuthorization::ACTION_ACCESS_PROMOTER );
						return true === $allowed ? $callback() : $allowed;
					}
				);
			}
		);
	}

	/** Run against canonical Events storage and restore the caller exactly. */
	private static function with_events_blog( callable $callback ) {
		$events_blog_id = self::events_blog_id();
		$did_switch     = get_current_blog_id() !== $events_blog_id;
		if ( $did_switch ) {
			switch_to_blog( $events_blog_id );
			if ( get_current_blog_id() !== $events_blog_id ) {
				return new \WP_Error( 'promoter_link_page_events_switch_failed', __( 'The canonical Events site is unavailable.', 'extrachill-events' ) );
			}
		}
		try {
			return $callback();
		} finally {
			if ( $did_switch ) {
				restore_current_blog();
			}
		}
	}

	/** Withdraw the exact promoter page without reversing authority/page lock order. */
	private static function withdraw_for_revocation( int $promoter_term_id ) {
		$defined_functions = get_defined_functions();
		$user_functions    = array_map( 'strtolower', $defined_functions['user'] );
		foreach ( array( 'ec_normalize_link_page_owner_reference', 'ec_get_link_page_id_for_owner', 'ec_with_link_page_storage_blog', 'ec_get_stored_link_page_owner_references', 'ec_write_link_page_meta' ) as $function ) {
			if ( ! in_array( strtolower( $function ), $user_functions, true ) ) {
				return new \WP_Error( 'promoter_link_page_runtime_unavailable', __( 'Promoter authority cannot be revoked while its public projection runtime is unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
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
					return new \WP_Error( 'promoter_link_page_revocation_owner_changed', __( 'The promoter Link Page owner changed before revocation.', 'extrachill-events' ) );
				}
				$before = ec_snapshot_link_page_meta( (int) $page_id, self::ORPHAN_META_KEY );
				$audit  = array(
					'version'          => 1,
					'owner_reference'  => $reference,
					'promoter_term_id' => $promoter_term_id,
					'promoter_name'    => '',
					'orphaned_at'      => gmdate( 'c' ),
					'policy'           => 'draft_on_organization_revoked',
				);
				if ( ! ec_write_link_page_meta( (int) $page_id, self::ORPHAN_META_KEY, $audit ) ) {
					return new \WP_Error( 'promoter_link_page_revocation_audit_failed', __( 'The promoter Link Page revocation audit could not be saved.', 'extrachill-events' ) );
				}
				$updated = wp_update_post(
					array(
						'ID'          => (int) $page_id,
						'post_status' => 'draft',
					),
					true
				);
				if ( is_wp_error( $updated ) || 'draft' !== get_post_field( 'post_status', (int) $page_id ) ) {
					$error = new \WP_Error( 'promoter_link_page_revocation_draft_failed', __( 'The promoter Link Page could not be withdrawn.', 'extrachill-events' ) );
					return ec_restore_link_page_meta_snapshots( (int) $page_id, array( self::ORPHAN_META_KEY => $before ) ) ? $error : new \WP_Error( 'promoter_link_page_revocation_compensation_failed', __( 'The failed promoter Link Page withdrawal audit could not be restored.', 'extrachill-events' ), array( 'cause' => $error->get_error_code() ) );
				}
				return true;
			}
		);
	}

	/** Draft one exact captured page and preserve immutable audit owner metadata. */
	private static function draft_captured_page( array $capture, int $promoter_term_id, string $reason, string $name ) {
		return ec_with_link_page_storage_blog(
			static function () use ( $capture, $promoter_term_id, $reason, $name ) {
				$link_page_id = (int) $capture['link_page_id'];
				return ec_with_link_page_lock_scope(
					$link_page_id,
					static function () use ( $capture, $promoter_term_id, $reason, $name, $link_page_id ) {
						if ( array( $capture['owner_reference'] ) !== ec_get_stored_link_page_owner_references( $link_page_id ) ) {
							return new \WP_Error( 'promoter_link_page_orphan_owner_changed', __( 'The promoter Link Page owner changed before cleanup.', 'extrachill-events' ) );
						}
						$audit = array(
							'version'          => 1,
							'owner_reference'  => $capture['owner_reference'],
							'promoter_term_id' => $promoter_term_id,
							'promoter_name'    => $name,
							'orphaned_at'      => gmdate( 'c' ),
							'policy'           => 'draft_on_' . $reason,
						);
						if ( ! ec_write_link_page_meta( $link_page_id, self::ORPHAN_META_KEY, $audit ) ) {
							ec_purge_link_page_after_mutation( $link_page_id );
							return new \WP_Error( 'promoter_link_page_orphan_audit_failed', __( 'The promoter Link Page audit could not be saved.', 'extrachill-events' ) );
						}
						$updated = wp_update_post(
							array(
								'ID'          => $link_page_id,
								'post_status' => 'draft',
							),
							true
						);
						ec_purge_link_page_after_mutation( $link_page_id );
						return is_wp_error( $updated ) || 'draft' !== get_post_field( 'post_status', $link_page_id ) ? new \WP_Error( 'promoter_link_page_orphan_draft_failed', __( 'The promoter Link Page could not be made non-public.', 'extrachill-events' ) ) : true;
					}
				);
			}
		);
	}

	/** Confirm exact page/owner round-trip binding. */
	private static function binding_is_exact( array $resolved ): bool {
		if ( ! self::is_promoter_owner( $resolved['owner'] ?? array() ) || empty( $resolved['link_page_id'] ) || empty( $resolved['owner_reference'] ) ) {
			return false;
		}
		$owner    = ec_get_link_page_owner( (int) $resolved['link_page_id'] );
		$owner_id = ec_get_link_page_id_for_owner( (string) $resolved['owner_reference'] );
		return ! is_wp_error( $owner ) && ! is_wp_error( $owner_id ) && $owner['reference'] === $resolved['owner_reference'] && (int) $owner_id === (int) $resolved['link_page_id'];
	}

	/** Match only canonical Events promoter terms. */
	private static function is_promoter_owner( array $owner ): bool {
		return 'term' === ( $owner['kind'] ?? '' ) && self::events_blog_id() === (int) ( $owner['blog_id'] ?? 0 ) && 'promoter' === ( $owner['subtype'] ?? '' ) && (int) ( $owner['object_id'] ?? 0 ) > 0;
	}

	/** Resolve the configured Events blog. */
	private static function events_blog_id(): int {
		return function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 7;
	}

	/** Stable non-enumerating management denial. */
	private static function forbidden(): \WP_Error {
		return new \WP_Error( 'promoter_link_page_forbidden', __( 'You are not authorized to manage this promoter Link Page.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	/** Report failed rollback without leaking persistence details. */
	private static function compensation_failed( \WP_Error $cause, string $operation = 'save' ): \WP_Error {
		return new \WP_Error( 'promoter_link_page_' . $operation . '_compensation_failed', __( 'The failed promoter Link Page mutation could not be compensated.', 'extrachill-events' ), array( 'cause' => $cause->get_error_code() ) );
	}
}
