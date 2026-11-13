<?php
/**
 * Shared Events team-term owner adapter for the standalone Link Pages runtime.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Owns authorization, snapshots, operations, provisioning, lifecycle, and
 * projection for one canonical Events taxonomy. Owner kinds (venue, promoter)
 * supply only their taxonomy, membership check, canonical source read, and the
 * few snapshot/schema fields that genuinely differ.
 */
abstract class TeamTermLinkPages {

	/** Taxonomy, owner-reference subtype, and error-code prefix. */
	public const KIND = '';

	/** Stored snapshot meta key on the canonical Link Page. */
	public const SNAPSHOT_META_KEY = '';

	/** Audit meta key written when the owner disappears or is withdrawn. */
	public const ORPHAN_META_KEY = '';

	public const SNAPSHOT_VERSION = 1;

	/** Schema.org entity type for the public projection. */
	protected const SCHEMA_TYPE = '';

	/** Open Graph type for the public projection. */
	protected const OG_TYPE = 'profile';

	/** Audit policy recorded when the owner term is deleted. */
	protected const ORPHAN_POLICY = 'draft_on_term_deleted';

	/**
	 * Whether mutation hooks registered in this request, keyed by owner class.
	 *
	 * @var array<string, bool>
	 */
	private static $hooks_registered = array();

	/** Check the owner's exact membership policy; runs on the Events blog. */
	abstract protected static function authorize_member( int $term_id );

	/**
	 * Read the canonical owner profile; runs on the Events blog.
	 *
	 * Returns term_id, name, slug, description, website, image_url, image_alt,
	 * social_links, source_url, optional revision, and any kind-specific fields.
	 *
	 * @param int $term_id Canonical owner term ID.
	 */
	abstract protected static function read_source( int $term_id );

	/**
	 * Build the owner's management destination.
	 *
	 * @param int $term_id Canonical owner term ID.
	 */
	abstract public static function management_url( int $term_id ): string;

	/**
	 * Kind-specific snapshot fields inserted before `source`.
	 *
	 * @param array $source Canonical source.
	 */
	protected static function snapshot_extras( array $source ): array {
		unset( $source );
		return array();
	}

	/**
	 * Validate the kind-specific snapshot fields.
	 *
	 * @param array $snapshot Stored snapshot.
	 */
	protected static function snapshot_extras_valid( array $snapshot ): bool {
		unset( $snapshot );
		return true;
	}

	/**
	 * Kind-specific schema.org entity properties.
	 *
	 * @param array $snapshot Validated snapshot.
	 */
	protected static function schema_entity_extras( array $snapshot ): array {
		unset( $snapshot );
		return array();
	}

	/**
	 * Optional slug qualifier tried before the stable ID suffix.
	 *
	 * @param array $source Canonical source.
	 */
	protected static function slug_qualifier( array $source ): string {
		unset( $source );
		return '';
	}

	/**
	 * Serialize a mutation against owner authority changes. Kinds without an
	 * authority lifecycle run the callback directly.
	 *
	 * @param int      $term_id  Canonical owner term ID.
	 * @param bool     $trusted  Whether the mutation follows a trusted canonical write.
	 * @param callable $callback Mutation.
	 */
	protected static function with_authority_lock( int $term_id, bool $trusted, callable $callback ) {
		unset( $term_id, $trusted );
		return $callback();
	}

	/** Register snapshot refreshes and deletion lifecycle hooks once. */
	public static function register_hooks(): void {
		if ( ! empty( self::$hooks_registered[ static::class ] ) ) {
			return;
		}
		self::$hooks_registered[ static::class ] = true;
		add_action( 'edited_' . static::KIND, array( static::class, 'refresh_after_profile_update' ), 30, 1 );
		add_action( 'extrachill_events_' . static::KIND . '_profile_updated', array( static::class, 'refresh_after_profile_update' ), 20, 1 );
		add_action( 'pre_delete_term', array( static::class, 'capture_before_term_deletion' ), 10, 2 );
		add_action( 'delete_term', array( static::class, 'orphan_after_term_deletion' ), 20, 4 );
	}

	/**
	 * Return the canonical opaque owner reference.
	 *
	 * @param int $term_id Canonical owner term ID.
	 */
	public static function owner_reference( int $term_id ) {
		if ( $term_id < 1 || static::events_blog_id() < 1 ) {
			return new \WP_Error( 'invalid_' . static::KIND . '_link_page_owner', __( 'A canonical Events Link Page owner is required.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		return ec_normalize_link_page_owner_reference(
			array(
				'kind'      => 'term',
				'blog_id'   => static::events_blog_id(),
				'subtype'   => static::KIND,
				'object_id' => $term_id,
			)
		);
	}

	/**
	 * Require exact current membership, evaluated on the canonical Events blog.
	 *
	 * @param int $term_id Canonical owner term ID.
	 */
	public static function authorize( int $term_id ) {
		return static::with_events_blog(
			static function () use ( $term_id ) {
				return static::authorize_member( $term_id );
			}
		);
	}

	/**
	 * Canonical ownership metadata needs no legacy claims.
	 *
	 * @param mixed $operation Runtime operation.
	 * @param mixed $context   Runtime context.
	 */
	public static function compatibility_provider( $operation, $context ): array {
		unset( $operation, $context );
		return array();
	}

	/**
	 * Claim only exact canonical owners of this kind.
	 *
	 * @param array $resolved Resolved runtime owner binding.
	 */
	public static function operation_provider( $resolved ) {
		if ( ! static::is_owner( $resolved['owner'] ?? array() ) ) {
			return null;
		}
		return array(
			'authorize' => array( static::class, 'operation_authorize' ),
			'read'      => array( static::class, 'operation_read' ),
			'save'      => array( static::class, 'operation_save' ),
		);
	}

	/**
	 * Reauthorize every generic operation against exact membership.
	 *
	 * @param array  $resolved  Resolved runtime owner binding.
	 * @param string $operation Runtime operation.
	 */
	public static function operation_authorize( $resolved, $operation ) {
		if ( ! in_array( $operation, array( 'read', 'save' ), true ) || ! static::binding_is_exact( $resolved ) ) {
			return false;
		}
		return static::authorize( (int) $resolved['owner']['object_id'] );
	}

	/**
	 * Read generic persistence and the stored identity snapshot.
	 *
	 * @param array $resolved Resolved runtime owner binding.
	 */
	public static function operation_read( $resolved ) {
		$allowed = static::operation_authorize( $resolved, 'read' );
		if ( true !== $allowed ) {
			return is_wp_error( $allowed ) ? $allowed : static::forbidden();
		}
		$snapshot = static::read_snapshot( (int) $resolved['link_page_id'], (string) $resolved['owner_reference'] );
		$data     = is_wp_error( $snapshot ) ? $snapshot : ec_read_link_page_persistence( (int) $resolved['link_page_id'] );
		return is_wp_error( $data ) ? $data : static::compose_response( $data, $snapshot );
	}

	/**
	 * Save generic data and a fresh identity snapshot in one compensatable lock.
	 *
	 * @param array $resolved Resolved runtime owner binding.
	 * @param mixed $data     Generic save payload.
	 */
	public static function operation_save( $resolved, $data ) {
		$allowed = static::operation_authorize( $resolved, 'save' );
		if ( true !== $allowed ) {
			return is_wp_error( $allowed ) ? $allowed : static::forbidden();
		}
		return static::with_authority_lock(
			(int) $resolved['owner']['object_id'],
			false,
			static function () use ( $resolved, $data ) {
				return ec_with_link_page_storage_blog(
					static function () use ( $resolved, $data ) {
						return static::operation_save_locked( $resolved, $data );
					}
				);
			}
		);
	}

	/**
	 * Save generic persistence and owner state through one composed mutation.
	 *
	 * @param array $resolved Resolved runtime owner binding.
	 * @param mixed $data     Generic save payload.
	 */
	private static function operation_save_locked( array $resolved, $data ) {
		$allowed_keys = array( 'links', 'css_vars', 'bio', 'link_expiration_enabled', 'redirect_enabled', 'redirect_target_url', 'youtube_embed_enabled', 'meta_pixel_id', 'google_tag_id', 'google_tag_manager_id', 'social_icons_position', 'profile_image_shape', 'background_image_id', 'expected_revision' );
		if ( ! is_array( $data ) || array_diff( array_keys( $data ), $allowed_keys ) ) {
			return new \WP_Error( 'invalid_' . static::KIND . '_link_page_save', __( 'The Link Page save contains unsupported fields.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$link_page_id = (int) $resolved['link_page_id'];
		$current      = ec_read_link_page_persistence( $link_page_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$expected_revision = isset( $data['expected_revision'] ) ? (string) $data['expected_revision'] : '';
		$lock_revision     = static::persistence_revision( $current );
		unset( $data['expected_revision'] );
		$snapshot = static::build_snapshot( (int) $resolved['owner']['object_id'], (string) $resolved['owner_reference'] );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$saved = ec_save_link_page_persistence_composed(
			$link_page_id,
			$data,
			static function ( $finalized_link_page_id, $persistence ) use ( $snapshot, $resolved, $expected_revision, $lock_revision ) {
				unset( $persistence );
				if ( $expected_revision && ! hash_equals( $lock_revision, $expected_revision ) ) {
					return static::error( 'revision_conflict', __( 'The Link Page changed before this save could be applied.', 'extrachill-events' ), array( 'status' => 409 ) );
				}
				return static::finalize_owner_state( (int) $finalized_link_page_id, (string) $resolved['owner_reference'], $snapshot, 'save' );
			}
		);
		return is_wp_error( $saved ) ? $saved : static::compose_response( $saved, $snapshot );
	}

	/**
	 * Provision one page under the generic owner/page locks and slug tiers.
	 *
	 * @param int $term_id Canonical owner term ID.
	 */
	public static function provision( int $term_id ) {
		$allowed = static::authorize( $term_id );
		if ( true !== $allowed ) {
			return $allowed;
		}
		return static::with_authority_lock(
			$term_id,
			false,
			static function () use ( $term_id ) {
				return static::provision_locked( $term_id );
			}
		);
	}

	/**
	 * Provision generic ownership and owner state through one composed mutation.
	 *
	 * @param int $term_id Canonical owner term ID.
	 */
	private static function provision_locked( int $term_id ) {
		$reference = static::owner_reference( $term_id );
		if ( is_wp_error( $reference ) ) {
			return $reference;
		}
		return ec_with_link_page_storage_blog(
			static function () use ( $term_id, $reference ) {
				$source = static::read_live_source( $term_id );
				if ( is_wp_error( $source ) ) {
					return $source;
				}
				$provisioned = null;
				$snapshot    = static::snapshot_from_source( $source, $reference );
				foreach ( static::slug_candidates( $source ) as $slug ) {
					$provisioned = ec_provision_owned_link_page_composed(
						$reference,
						$source['name'],
						$slug,
						static function ( $link_page_id, $owner_reference ) use ( $snapshot ) {
							return static::finalize_owner_state( (int) $link_page_id, (string) $owner_reference, $snapshot, 'provision' );
						},
						false,
						static function () use ( $term_id ) {
							return static::authorize( $term_id );
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
				$response            = static::compose_response( ec_read_link_page_persistence( $link_page_id ), $snapshot );
				$response['created'] = ! empty( $provisioned['created'] );
				return $response;
			}
		);
	}

	/**
	 * Write owner-owned state under the composed page lock, compensating on failure.
	 *
	 * @param int    $link_page_id Canonical Link Page ID.
	 * @param string $reference    Owner reference.
	 * @param array  $snapshot     Fresh snapshot.
	 * @param string $operation    save, provision, or refresh.
	 */
	private static function finalize_owner_state( int $link_page_id, string $reference, array $snapshot, string $operation ) {
		$before = array(
			static::SNAPSHOT_META_KEY             => ec_snapshot_link_page_meta( $link_page_id, static::SNAPSHOT_META_KEY ),
			EC_LINK_PAGE_PUBLIC_SNAPSHOT_META_KEY => ec_snapshot_link_page_meta( $link_page_id, EC_LINK_PAGE_PUBLIC_SNAPSHOT_META_KEY ),
		);
		if ( ! ec_write_link_page_meta( $link_page_id, static::SNAPSHOT_META_KEY, $snapshot ) ) {
			$error = static::error( 'snapshot_save_failed', __( 'The Link Page snapshot could not be saved.', 'extrachill-events' ) );
			return ec_restore_link_page_meta_snapshots( $link_page_id, $before ) ? $error : static::compensation_failed( $error, $operation );
		}
		$public = ec_save_link_page_public_projection_snapshot( $link_page_id, $reference, static::projection_from_snapshot( $snapshot, $link_page_id ) );
		if ( is_wp_error( $public ) ) {
			return ec_restore_link_page_meta_snapshots( $link_page_id, $before ) ? $public : static::compensation_failed( $public, $operation );
		}
		try {
			do_action( 'ec_link_page_save', $link_page_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			$error = static::error( 'final_hook_failed', __( 'The Link Page final mutation hook failed.', 'extrachill-events' ) );
			return ec_restore_link_page_meta_snapshots( $link_page_id, $before ) ? $error : static::compensation_failed( $error, $operation );
		}
		return true;
	}

	/**
	 * Refresh the public snapshot without provisioning a missing page.
	 *
	 * @param int $term_id Canonical owner term ID.
	 */
	public static function refresh_snapshot( int $term_id ) {
		return static::refresh_snapshot_mutation( $term_id, false );
	}

	/**
	 * Execute an authorized or trusted snapshot refresh.
	 *
	 * @param int  $term_id Canonical owner term ID.
	 * @param bool $trusted Whether a trusted canonical mutation already persisted.
	 */
	private static function refresh_snapshot_mutation( int $term_id, bool $trusted ) {
		if ( ! $trusted ) {
			$allowed = static::authorize( $term_id );
			if ( true !== $allowed ) {
				return $allowed;
			}
		}
		return static::with_authority_lock(
			$term_id,
			$trusted,
			static function () use ( $term_id, $trusted ) {
				return static::refresh_snapshot_locked( $term_id, $trusted );
			}
		);
	}

	/**
	 * Refresh while owner authority remains lock-current.
	 *
	 * @param int  $term_id Canonical owner term ID.
	 * @param bool $trusted Whether a trusted canonical mutation already persisted.
	 */
	private static function refresh_snapshot_locked( int $term_id, bool $trusted ) {
		$reference    = static::owner_reference( $term_id );
		$link_page_id = is_wp_error( $reference ) ? $reference : ec_get_link_page_id_for_owner( $reference );
		if ( is_wp_error( $link_page_id ) || ! $link_page_id ) {
			return is_wp_error( $link_page_id ) ? $link_page_id : static::not_found();
		}
		$snapshot = static::build_snapshot( $term_id, $reference );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		return ec_with_link_page_storage_blog(
			static function () use ( $link_page_id, $snapshot, $trusted, $term_id ) {
				return ec_with_link_page_lock_scope(
					(int) $link_page_id,
					static function () use ( $link_page_id, $snapshot, $trusted, $term_id ) {
						if ( ! $trusted ) {
							$allowed = static::authorize( $term_id );
							if ( true !== $allowed ) {
								return $allowed;
							}
						}
						$finalized = static::finalize_owner_state( (int) $link_page_id, (string) $snapshot['owner_reference'], $snapshot, 'refresh' );
						return true === $finalized ? static::compose_response( ec_read_link_page_persistence( (int) $link_page_id ), $snapshot ) : $finalized;
					}
				);
			}
		);
	}

	/**
	 * Trusted refresh after an already-authorized canonical profile mutation.
	 *
	 * @param int $term_id Canonical owner term ID.
	 */
	public static function refresh_after_profile_update( $term_id ): void {
		if ( get_current_blog_id() !== static::events_blog_id() ) {
			return;
		}
		$result = static::refresh_snapshot_mutation( absint( $term_id ), true );
		if ( is_wp_error( $result ) && ! in_array( $result->get_error_code(), array( static::KIND . '_link_page_not_found', static::KIND . '_link_page_forbidden' ), true ) ) {
			do_action( 'extrachill_events_' . static::KIND . '_link_page_snapshot_refresh_failed', absint( $term_id ), $result );
		}
	}

	/**
	 * Capture the exact owner/page binding while the owner term still exists.
	 *
	 * @param int    $term_id  Term being deleted.
	 * @param string $taxonomy Term taxonomy.
	 */
	public static function capture_before_term_deletion( $term_id, $taxonomy ): void {
		if ( static::KIND !== $taxonomy || get_current_blog_id() !== static::events_blog_id() ) {
			return;
		}
		$reference = static::owner_reference( absint( $term_id ) );
		$page_id   = is_wp_error( $reference ) ? $reference : ec_get_link_page_id_for_owner( $reference );
		if ( is_wp_error( $page_id ) ) {
			do_action( 'extrachill_events_' . static::KIND . '_link_page_orphan_capture_failed', absint( $term_id ), $page_id );
		} elseif ( $page_id ) {
			$GLOBALS[ 'extrachill_events_deleting_' . static::KIND . '_link_pages' ][ absint( $term_id ) ] = array(
				'link_page_id'    => (int) $page_id,
				'owner_reference' => $reference,
			);
		}
	}

	/**
	 * Draft the public page and preserve audit metadata after owner deletion.
	 *
	 * @param int     $term_id          Deleted term ID.
	 * @param int     $term_taxonomy_id Deleted term taxonomy ID.
	 * @param string  $taxonomy         Term taxonomy.
	 * @param WP_Term $deleted_term     Deleted term copy.
	 */
	public static function orphan_after_term_deletion( $term_id, $term_taxonomy_id, $taxonomy, $deleted_term ): void {
		unset( $term_taxonomy_id );
		if ( static::KIND !== $taxonomy || get_current_blog_id() !== static::events_blog_id() ) {
			return;
		}
		$global  = 'extrachill_events_deleting_' . static::KIND . '_link_pages';
		$capture = $GLOBALS[ $global ][ absint( $term_id ) ] ?? null;
		unset( $GLOBALS[ $global ][ absint( $term_id ) ] );
		if ( ! is_array( $capture ) ) {
			$reference = sprintf( 'term:%d:%s:%d', static::events_blog_id(), static::KIND, absint( $term_id ) );
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
				do_action( 'extrachill_events_' . static::KIND . '_link_page_orphan_capture_failed', absint( $term_id ), static::error( 'orphan_recovery_failed', __( 'The deleted Link Page owner binding could not be recovered.', 'extrachill-events' ) ) );
				return;
			}
		}
		$result = static::draft_captured_page( $capture, absint( $term_id ), static::ORPHAN_POLICY, sanitize_text_field( (string) ( $deleted_term->name ?? '' ) ) );
		if ( is_wp_error( $result ) ) {
			do_action( 'extrachill_events_' . static::KIND . '_link_page_orphan_failed', absint( $term_id ), $capture, $result );
		}
	}

	/**
	 * Draft one exact captured page and preserve immutable audit owner metadata.
	 *
	 * @param array  $capture Captured page binding.
	 * @param int    $term_id Owner term ID.
	 * @param string $policy  Audit policy.
	 * @param string $name    Owner name at deletion time.
	 */
	protected static function draft_captured_page( array $capture, int $term_id, string $policy, string $name ) {
		return ec_with_link_page_storage_blog(
			static function () use ( $capture, $term_id, $policy, $name ) {
				$link_page_id = (int) $capture['link_page_id'];
				return ec_with_link_page_lock_scope(
					$link_page_id,
					static function () use ( $capture, $term_id, $policy, $name, $link_page_id ) {
						if ( array( $capture['owner_reference'] ) !== ec_get_stored_link_page_owner_references( $link_page_id ) ) {
							return static::error( 'orphan_owner_changed', __( 'The Link Page owner changed before cleanup.', 'extrachill-events' ) );
						}
						if ( ! ec_write_link_page_meta( $link_page_id, static::ORPHAN_META_KEY, static::orphan_audit( (string) $capture['owner_reference'], $term_id, $name, $policy ) ) ) {
							ec_purge_link_page_after_mutation( $link_page_id );
							return static::error( 'orphan_audit_failed', __( 'The Link Page audit could not be saved.', 'extrachill-events' ) );
						}
						$updated = wp_update_post(
							array(
								'ID'          => $link_page_id,
								'post_status' => 'draft',
							),
							true
						);
						ec_purge_link_page_after_mutation( $link_page_id );
						return is_wp_error( $updated ) || 'draft' !== get_post_field( 'post_status', $link_page_id ) ? static::error( 'orphan_draft_failed', __( 'The Link Page could not be made non-public.', 'extrachill-events' ) ) : true;
					}
				);
			}
		);
	}

	/**
	 * Build the immutable owner audit record.
	 *
	 * @param string $reference Owner reference.
	 * @param int    $term_id   Owner term ID.
	 * @param string $name      Owner name.
	 * @param string $policy    Audit policy.
	 */
	protected static function orphan_audit( string $reference, int $term_id, string $name, string $policy ): array {
		return array(
			'version'                 => 1,
			'owner_reference'         => $reference,
			static::KIND . '_term_id' => $term_id,
			static::KIND . '_name'    => $name,
			'orphaned_at'             => gmdate( 'c' ),
			'policy'                  => $policy,
		);
	}

	/**
	 * Project public output from the canonical-storage snapshot only.
	 *
	 * @param array $context Runtime projection context.
	 */
	public static function public_projection_provider( $context ) {
		if ( ! static::is_owner( $context['owner'] ?? array() ) ) {
			return null;
		}
		$snapshot = static::read_snapshot( (int) $context['link_page_id'], (string) $context['owner_reference'] );
		return is_wp_error( $snapshot ) ? $snapshot : static::projection_from_snapshot( $snapshot, (int) $context['link_page_id'] );
	}

	/**
	 * Build the serializable public projection shared by live and stored paths.
	 *
	 * @param array $snapshot     Validated snapshot.
	 * @param int   $link_page_id Canonical Link Page ID.
	 */
	private static function projection_from_snapshot( array $snapshot, int $link_page_id ): array {
		$canonical = ec_get_link_page_public_url( $link_page_id );
		$entity_id = $canonical . '#' . static::KIND;
		$term_id   = (int) $snapshot['source'][ static::KIND . '_term_id' ];
		$entity    = array(
			'@type'       => static::SCHEMA_TYPE,
			'@id'         => $entity_id,
			'name'        => $snapshot['title'],
			'url'         => $canonical,
			'description' => $snapshot['description'],
		);
		if ( $snapshot['image_url'] ) {
			$entity['image'] = $snapshot['image_url'];
		}
		$entity  = array_merge( $entity, static::schema_entity_extras( $snapshot ) );
		$same_as = array_values( array_unique( array_filter( array_merge( array( $snapshot['website'] ), array_column( $snapshot['social_links'], 'url' ) ) ) ) );
		if ( $same_as ) {
			$entity['sameAs'] = $same_as;
		}
		return array(
			'display_title'   => $snapshot['title'],
			'bio'             => $snapshot['description'],
			'profile_img_url' => $snapshot['image_url'],
			'social_links'    => $snapshot['social_links'],
			'social_renderer' => array( static::class, 'render_social_links' ),
			'management_url'  => static::management_url( $term_id ),
			'body_attributes' => array(
				'data-extrch-owner-type'              => static::KIND,
				'data-extrch-' . static::KIND . '-id' => (string) $term_id,
			),
			'seo'             => array(
				'title'       => $snapshot['title'] . ' | extrachill.link',
				'description' => $snapshot['description'],
				'canonical'   => $canonical,
				'image'       => $snapshot['image_url'],
				'image_alt'   => $snapshot['image_alt'] ? $snapshot['image_alt'] : $snapshot['title'],
				'og_type'     => static::OG_TYPE,
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

	/**
	 * Render public owner links without artist social storage or components.
	 *
	 * @param mixed $social_links Snapshot social links.
	 */
	public static function render_social_links( $social_links ): string {
		if ( ! is_array( $social_links ) || ! $social_links ) {
			return '';
		}
		$html = '<nav class="extrch-link-page-socials extrch-link-page-' . static::KIND . '-socials" aria-label="' . esc_attr( ucfirst( static::KIND ) ) . ' links">';
		foreach ( $social_links as $social ) {
			$label = ucfirst( (string) ( $social['type'] ?? 'website' ) );
			$html .= '<a href="' . esc_url( $social['url'] ?? '' ) . '" rel="noopener noreferrer" aria-label="' . esc_attr( $label ) . '">' . esc_html( $label ) . '</a>';
		}
		return $html . '</nav>';
	}

	/**
	 * Delegate analytics in canonical storage context after exact authorization.
	 *
	 * @param int    $term_id    Canonical owner term ID.
	 * @param int    $date_range Days, clamped to 1-90.
	 * @param string $start_date Optional Y-m-d start.
	 * @param string $end_date   Optional Y-m-d end.
	 */
	public static function analytics( int $term_id, int $date_range = 30, string $start_date = '', string $end_date = '' ) {
		$allowed = static::authorize( $term_id );
		if ( true !== $allowed ) {
			return $allowed;
		}
		$reference    = static::owner_reference( $term_id );
		$link_page_id = is_wp_error( $reference ) ? $reference : ec_get_link_page_id_for_owner( $reference );
		if ( is_wp_error( $link_page_id ) || ! $link_page_id ) {
			return is_wp_error( $link_page_id ) ? $link_page_id : static::not_found();
		}
		$result = ec_with_link_page_storage_blog(
			static function () use ( $link_page_id, $date_range, $start_date, $end_date ) {
				return apply_filters( 'extrachill_get_link_page_analytics', null, (int) $link_page_id, max( 1, min( 90, $date_range ) ), '' !== $start_date ? $start_date : null, '' !== $end_date ? $end_date : null );
			}
		);
		return is_array( $result ) || is_wp_error( $result ) ? $result : static::error( 'analytics_unavailable', __( 'Link Page analytics are unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
	}

	/**
	 * Build a fresh snapshot from canonical Events data without HTTP.
	 *
	 * @param int    $term_id   Canonical owner term ID.
	 * @param string $reference Owner reference.
	 */
	private static function build_snapshot( int $term_id, string $reference ) {
		$source = static::read_live_source( $term_id );
		return is_wp_error( $source ) ? $source : static::snapshot_from_source( $source, $reference );
	}

	/**
	 * Read the canonical source on the Events blog, restoring the caller exactly.
	 *
	 * @param int $term_id Canonical owner term ID.
	 */
	private static function read_live_source( int $term_id ) {
		return static::with_events_blog(
			static function () use ( $term_id ) {
				$term = get_term( $term_id, static::KIND );
				if ( ! $term || is_wp_error( $term ) || static::KIND !== $term->taxonomy ) {
					return static::error( 'owner_not_found', __( 'The canonical Link Page owner no longer exists.', 'extrachill-events' ), array( 'status' => 404 ) );
				}
				return static::read_source( $term_id );
			}
		);
	}

	/**
	 * Convert canonical source data into the immutable public snapshot.
	 *
	 * @param array  $source    Canonical source.
	 * @param string $reference Owner reference.
	 */
	private static function snapshot_from_source( array $source, string $reference ): array {
		$extras      = static::snapshot_extras( $source );
		$fingerprint = array_intersect_key( $source, array_flip( array( 'term_id', 'name', 'slug', 'description', 'website', 'image_url', 'image_alt', 'social_links' ) ) ) + $extras;
		ksort( $fingerprint );
		$encoded = wp_json_encode( $fingerprint, JSON_INVALID_UTF8_SUBSTITUTE );
		return array(
			'version'         => static::SNAPSHOT_VERSION,
			'owner_reference' => $reference,
			'title'           => sanitize_text_field( (string) $source['name'] ),
			'description'     => sanitize_textarea_field( (string) $source['description'] ),
			'image_url'       => esc_url_raw( (string) ( $source['image_url'] ?? '' ), array( 'http', 'https' ) ),
			'image_alt'       => sanitize_text_field( (string) ( $source['image_alt'] ?? '' ) ),
			'website'         => esc_url_raw( (string) ( $source['website'] ?? '' ), array( 'http', 'https' ) ),
			'social_links'    => array_values( (array) ( $source['social_links'] ?? array() ) ),
		) + $extras + array(
			'source' => array(
				'blog_id'                 => static::events_blog_id(),
				'taxonomy'                => static::KIND,
				static::KIND . '_term_id' => (int) $source['term_id'],
				'version'                 => ! empty( $source['revision'] ) ? (string) $source['revision'] : hash( 'sha256', false === $encoded ? static::KIND . '-source-json-encoding-failed' : $encoded ),
				'refreshed_at'            => gmdate( 'c' ),
				'public_url'              => is_wp_error( $source['source_url'] ?? null ) ? '' : esc_url_raw( (string) ( $source['source_url'] ?? '' ), array( 'http', 'https' ) ),
			),
		);
	}

	/**
	 * Validate a stored snapshot against the exact immutable owner binding.
	 *
	 * @param int    $link_page_id Canonical Link Page ID.
	 * @param string $reference    Owner reference.
	 */
	private static function read_snapshot( int $link_page_id, string $reference ) {
		$snapshot = get_post_meta( $link_page_id, static::SNAPSHOT_META_KEY, true );
		$source   = is_array( $snapshot ) ? (array) ( $snapshot['source'] ?? array() ) : array();
		$owner    = ec_parse_link_page_owner_reference( $reference );
		$required = array( 'version', 'owner_reference', 'title', 'description', 'image_url', 'image_alt', 'website', 'social_links', 'source' );
		if ( is_wp_error( $owner ) || ! is_array( $snapshot ) || array_diff( $required, array_keys( $snapshot ) ) || static::SNAPSHOT_VERSION !== (int) $snapshot['version'] || $reference !== $snapshot['owner_reference'] || static::events_blog_id() !== (int) ( $source['blog_id'] ?? 0 ) || static::KIND !== ( $source['taxonomy'] ?? '' ) || (int) ( $source[ static::KIND . '_term_id' ] ?? 0 ) !== (int) $owner['object_id'] || empty( $source['version'] ) || empty( $source['refreshed_at'] ) || ! is_array( $snapshot['social_links'] ) || ! static::snapshot_extras_valid( $snapshot ) ) {
			return static::error( 'snapshot_invalid', __( 'The Link Page public snapshot is missing, stale, or corrupt.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		return $snapshot;
	}

	/**
	 * Compose owner identity with owner-neutral persistence.
	 *
	 * @param array|\WP_Error $data     Generic persistence.
	 * @param array           $snapshot Validated snapshot.
	 */
	private static function compose_response( $data, array $snapshot ) {
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$term_id          = (int) $snapshot['source'][ static::KIND . '_term_id' ];
		$data['revision'] = static::persistence_revision( $data );
		return array(
			static::KIND => array(
				'term_id'         => $term_id,
				'owner_reference' => $snapshot['owner_reference'],
				'title'           => $snapshot['title'],
				'management_url'  => static::management_url( $term_id ),
				'snapshot'        => $snapshot,
			),
			'link_page'  => array_merge( $data, array( 'public_url' => ec_get_link_page_public_url( (int) $data['link_page_id'] ) ) ),
		);
	}

	/**
	 * Hash only canonical generic persistence fields for optimistic concurrency.
	 *
	 * @param array $data Generic persistence.
	 */
	private static function persistence_revision( array $data ): string {
		$encoded = wp_json_encode( array_intersect_key( $data, array_flip( array( 'links', 'css_vars', 'bio', 'settings', 'background_image_id' ) ) ) );
		return hash( 'sha256', false === $encoded ? '{}' : $encoded );
	}

	/**
	 * Deterministic base, optional qualifier, and stable ID suffix.
	 *
	 * @param array $source Canonical source.
	 */
	private static function slug_candidates( array $source ): array {
		$base       = sanitize_title( (string) $source['slug'] );
		$qualifier  = sanitize_title( static::slug_qualifier( $source ) );
		$candidates = array( $base );
		if ( $qualifier ) {
			$candidates[] = $base . '-' . $qualifier;
		}
		$candidates[] = $base . '-' . static::KIND . '-' . (int) $source['term_id'];
		return array_values( array_unique( array_filter( $candidates ) ) );
	}

	/**
	 * Run against canonical Events storage and restore the caller exactly.
	 *
	 * @param callable $callback Work to run on the Events blog.
	 */
	protected static function with_events_blog( callable $callback ) {
		$events_blog_id = static::events_blog_id();
		$did_switch     = get_current_blog_id() !== $events_blog_id;
		if ( $did_switch ) {
			switch_to_blog( $events_blog_id );
			if ( get_current_blog_id() !== $events_blog_id ) {
				return static::error( 'events_switch_failed', __( 'The canonical Events site is unavailable.', 'extrachill-events' ) );
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

	/**
	 * Confirm the page and owner form an exact round-trip binding.
	 *
	 * @param array $resolved Resolved runtime owner binding.
	 */
	private static function binding_is_exact( array $resolved ): bool {
		if ( ! static::is_owner( $resolved['owner'] ?? array() ) || empty( $resolved['link_page_id'] ) || empty( $resolved['owner_reference'] ) ) {
			return false;
		}
		$owner    = ec_get_link_page_owner( (int) $resolved['link_page_id'] );
		$owner_id = ec_get_link_page_id_for_owner( (string) $resolved['owner_reference'] );
		return ! is_wp_error( $owner ) && ! is_wp_error( $owner_id ) && $owner['reference'] === $resolved['owner_reference'] && (int) $owner_id === (int) $resolved['link_page_id'];
	}

	/**
	 * Match only canonical Events terms of this kind.
	 *
	 * @param array $owner Parsed owner.
	 */
	private static function is_owner( array $owner ): bool {
		return 'term' === ( $owner['kind'] ?? '' ) && static::events_blog_id() === (int) ( $owner['blog_id'] ?? 0 ) && static::KIND === ( $owner['subtype'] ?? '' ) && (int) ( $owner['object_id'] ?? 0 ) > 0;
	}

	/** Resolve the configured canonical Events blog. */
	protected static function events_blog_id(): int {
		return (int) ec_get_blog_id( 'events' );
	}

	/**
	 * Build a kind-prefixed error.
	 *
	 * @param string $code    Code suffix after `{kind}_link_page_`.
	 * @param string $message Translated message.
	 * @param array  $data    Optional error data.
	 */
	protected static function error( string $code, string $message, array $data = array() ): \WP_Error {
		return new \WP_Error( static::KIND . '_link_page_' . $code, $message, $data ? $data : null );
	}

	/** Return a stable non-enumerating denial. */
	protected static function forbidden(): \WP_Error {
		return static::error( 'forbidden', __( 'You are not authorized to manage this Link Page.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	/** Report a missing page for this owner. */
	private static function not_found(): \WP_Error {
		return static::error( 'not_found', __( 'No Link Page exists for this owner.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	/**
	 * Report failed rollback without leaking persistence details.
	 *
	 * @param \WP_Error $cause     Original failure.
	 * @param string    $operation save, provision, refresh, or revocation.
	 */
	protected static function compensation_failed( \WP_Error $cause, string $operation ): \WP_Error {
		return static::error( $operation . '_compensation_failed', __( 'The failed Link Page mutation could not be compensated.', 'extrachill-events' ), array( 'cause' => $cause->get_error_code() ) );
	}
}
