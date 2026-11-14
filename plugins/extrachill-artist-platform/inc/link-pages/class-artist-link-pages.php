<?php
/**
 * Artist owner definition for the shared Link Pages engine.
 *
 * @package ExtraChillArtistPlatform
 */

namespace ExtraChillArtistPlatform;

defined( 'ABSPATH' ) || exit;

/**
 * Binds artist profiles (posts on the Artist site) to the owned Link Page
 * engine. The artist supplies identity through a stored snapshot; the page
 * owns its content. The public page renders from that snapshot plus
 * page-owned content without switching to the Artist site per request.
 */
final class Artist_Link_Pages extends \ExtraChill\LinkPages\Owned_Link_Pages {

	public const OWNER_KIND        = 'post';
	public const KIND              = 'artist_profile';
	public const SNAPSHOT_META_KEY = '_extrachill_artist_link_page_snapshot';
	public const ORPHAN_META_KEY   = '_extrachill_artist_link_page_orphaned';
	protected const HOOK_PREFIX    = 'extrachill_artist_platform_';
	protected const SCHEMA_TYPE    = 'MusicGroup';
	protected const OG_TYPE        = 'profile';

	/**
	 * Artist IDs whose snapshot refresh waits for the end of the request.
	 *
	 * @var array<int, true>
	 */
	private static $pending_refreshes = array();

	/**
	 * Whether a snapshot refresh is being written right now. The refresh
	 * fires the artist save hooks itself; those must not queue another.
	 *
	 * @var bool
	 */
	private static $refreshing = false;

	/**
	 * Whether the profile image hooks are registered.
	 *
	 * @var bool
	 */
	private static $thumbnail_hooks_registered = false;

	/** Resolve the configured Artist site. */
	protected static function owner_blog_id(): int {
		return (int) ec_get_blog_id( 'artist' );
	}

	/**
	 * Apply the existing artist membership authority.
	 *
	 * Uses the same policy as every artist ability: an active membership or
	 * `manage_options` for people, and the trusted system actor for WP-CLI
	 * and Action Scheduler.
	 *
	 * @param int $owner_id Artist profile ID.
	 */
	protected static function authorize_owner( int $owner_id ) {
		return extrachill_artist_platform_ability_artist_permission( array( 'artist_id' => $owner_id ) ) ? true : self::forbidden();
	}

	/**
	 * Read the artist profile identity.
	 *
	 * @param \WP_Post $owner Artist profile.
	 */
	protected static function read_source( $owner ) {
		$artist_id    = (int) $owner->ID;
		$description  = wp_strip_all_tags( $owner->post_excerpt ? $owner->post_excerpt : $owner->post_content );
		$thumbnail_id = (int) get_post_thumbnail_id( $artist_id );
		$image_url    = $thumbnail_id ? get_the_post_thumbnail_url( $artist_id, 'large' ) : '';
		$image_alt    = $thumbnail_id ? (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) : '';
		$social_links = extrachill_artist_platform_social_links()->get( $artist_id );
		$genres       = function_exists( 'ec_artist_get_genre_labels' ) ? ec_artist_get_genre_labels( $artist_id ) : array();
		return array(
			'id'           => $artist_id,
			'name'         => (string) $owner->post_title,
			'slug'         => (string) $owner->post_name,
			'description'  => $description,
			'website'      => (string) get_post_meta( $artist_id, '_website_url', true ),
			'image_url'    => $image_url ? (string) $image_url : '',
			'image_alt'    => $image_alt,
			'social_links' => self::identity_social_links( $social_links ),
			'source_url'   => get_permalink( $artist_id ),
			'status'       => (string) $owner->post_status,
			'genres'       => is_array( $genres ) ? array_values( $genres ) : array(),
		);
	}

	/**
	 * Reduce stored artist socials to the identity shape snapshots accept.
	 *
	 * @param mixed $social_links Artist social links.
	 * @return array<int, array{type: string, url: string, custom_label?: string}>
	 */
	private static function identity_social_links( $social_links ): array {
		$identity = array();
		foreach ( is_array( $social_links ) ? $social_links : array() as $social ) {
			if ( is_array( $social ) && ! empty( $social['type'] ) && ! empty( $social['url'] ) ) {
				$link = array(
					'type' => (string) $social['type'],
					'url'  => (string) $social['url'],
				);
				if ( ! empty( $social['custom_label'] ) ) {
					$link['custom_label'] = (string) $social['custom_label'];
				}
				$identity[] = $link;
			}
		}
		return array_slice( $identity, 0, 20 );
	}

	/**
	 * Carry visibility and genres in the snapshot.
	 *
	 * @param array $source Canonical source.
	 */
	protected static function snapshot_extras( array $source ): array {
		return array(
			'published' => 'publish' === ( $source['status'] ?? '' ),
			'genres'    => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $source['genres'] ?? array() ) ) ) ),
		);
	}

	/**
	 * Require the artist snapshot fields.
	 *
	 * @param array $snapshot Stored snapshot.
	 */
	protected static function snapshot_extras_valid( array $snapshot ): bool {
		return isset( $snapshot['published'], $snapshot['genres'] ) && is_bool( $snapshot['published'] ) && is_array( $snapshot['genres'] );
	}

	/**
	 * Only a published artist profile renders publicly.
	 *
	 * @param array $snapshot Validated snapshot.
	 */
	protected static function snapshot_is_public( array $snapshot ): bool {
		return true === $snapshot['published'];
	}

	/**
	 * Add genres to the MusicGroup entity.
	 *
	 * @param array $snapshot Validated snapshot.
	 */
	protected static function schema_entity_extras( array $snapshot ): array {
		return $snapshot['genres'] ? array( 'genre' => $snapshot['genres'] ) : array();
	}

	/**
	 * Reference the MusicGroup node the artist profile page publishes
	 * (`<profile permalink>#musicgroup`), so the Link Page and the profile
	 * describe one entity.
	 *
	 * @param array  $snapshot  Validated snapshot.
	 * @param string $canonical Canonical Link Page URL.
	 * @return array{@id: string, url: string}
	 */
	protected static function schema_entity_node( array $snapshot, string $canonical ): array {
		$profile_url = (string) ( $snapshot['source']['public_url'] ?? '' );
		if ( '' === $profile_url ) {
			return parent::schema_entity_node( $snapshot, $canonical );
		}
		return array(
			'@id' => $profile_url . '#musicgroup',
			'url' => $profile_url,
		);
	}

	/**
	 * Keep the historical artist body attribute, asset extensions and head
	 * hook arguments. The click tracking endpoint stays with the host
	 * integration (`ec_link_page_click_tracking_url`), which targets the
	 * serving site, as it did before the owner definition.
	 *
	 * @param array $snapshot     Validated snapshot.
	 * @param int   $link_page_id Canonical Link Page ID.
	 */
	protected static function projection_extras( array $snapshot, int $link_page_id ): array {
		unset( $link_page_id );
		$artist_id = (int) $snapshot['source'][ self::source_id_key() ];
		return array(
			'body_attributes'       => array( 'data-extrch-artist-id' => (string) $artist_id ),
			'assets'                => 'ec_artist_link_page_enqueue_asset_extensions',
			'legacy_head_arguments' => array( $artist_id ),
			'tracking_url'          => '',
		);
	}

	/**
	 * Pages that predate this definition have no snapshot until the backfill
	 * migration runs; they keep rendering from a live source read.
	 *
	 * Remove this override once `extrachill-artist-platform.link-page-snapshots`
	 * reports complete in production.
	 */
	protected static function live_snapshot_fallback(): bool {
		return true;
	}

	/**
	 * Build the artist management destination.
	 *
	 * @param int $owner_id Artist profile ID.
	 */
	public static function management_url( int $owner_id ): string {
		return ec_get_site_url( 'artist' ) . '/manage-link-page/?artist_id=' . $owner_id;
	}

	/**
	 * Artist profile saves, social-link updates and completed artist Link
	 * Page saves all change snapshot inputs.
	 *
	 * @return array<string,int>
	 */
	protected static function refresh_hooks(): array {
		return array(
			'save_post_artist_profile' => 30,
			'extrachill_artist_platform_social_links_updated' => 20,
			'ec_artist_platform_sync'  => 20,
		);
	}

	/** Also refresh when the artist profile image changes. */
	public static function register_hooks(): void {
		parent::register_hooks();
		if ( self::$thumbnail_hooks_registered ) {
			return;
		}
		self::$thumbnail_hooks_registered = true;
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'refresh_after_thumbnail_change' ), 30, 3 );
		}
	}

	/**
	 * Refresh after the artist thumbnail meta changes.
	 *
	 * @param mixed  $meta_id   Meta ID(s).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 */
	public static function refresh_after_thumbnail_change( $meta_id, $object_id, $meta_key ): void {
		unset( $meta_id );
		if ( '_thumbnail_id' === $meta_key && self::KIND === get_post_type( $object_id ) ) {
			self::refresh_after_profile_update( $object_id );
		}
	}

	/**
	 * Refresh the snapshot after a canonical artist change.
	 *
	 * Artist mutations often fire the refresh hooks while they still hold
	 * their separate Link Page lock, where the engine's snapshot write cannot
	 * compose. Those refreshes are queued and run once per artist at the end
	 * of the request; with no lock held the refresh runs immediately.
	 *
	 * @param int $owner_id Artist profile ID.
	 */
	public static function refresh_after_profile_update( $owner_id ): void {
		$owner_id = absint( $owner_id );
		if ( ! $owner_id || self::$refreshing ) {
			return;
		}
		if ( ! self::$pending_refreshes ) {
			add_action( 'shutdown', array( self::class, 'flush_refreshes' ), 5 );
		}
		self::$pending_refreshes[ $owner_id ] = true;
		if ( empty( $GLOBALS['ec_link_page_lock_scope'] ) ) {
			self::flush_refreshes();
		}
	}

	/** Write every queued artist snapshot through the trusted engine refresh. */
	public static function flush_refreshes(): void {
		$pending                 = array_keys( self::$pending_refreshes );
		self::$pending_refreshes = array();
		if ( ! $pending ) {
			return;
		}
		$was_refreshing   = self::$refreshing;
		self::$refreshing = true;
		try {
			self::with_owner_blog(
				static function () use ( $pending ) {
					foreach ( $pending as $artist_id ) {
						parent::refresh_after_profile_update( $artist_id );
					}
					return true;
				}
			);
		} finally {
			self::$refreshing = $was_refreshing;
		}
	}

	/**
	 * Authorized refresh (editor, backfill migration) without re-queueing
	 * from the hooks the refresh itself fires.
	 *
	 * @param int $owner_id Artist profile ID.
	 */
	public static function refresh_snapshot( int $owner_id ) {
		$was_refreshing   = self::$refreshing;
		self::$refreshing = true;
		try {
			return parent::refresh_snapshot( $owner_id );
		} finally {
			self::$refreshing = $was_refreshing;
		}
	}

	/**
	 * Report a denied operation as a plain refusal so the runtime answers
	 * with its generic forbidden error, as artist operations always have.
	 *
	 * @param array  $resolved  Resolved runtime owner binding.
	 * @param string $operation Operation name.
	 */
	public static function operation_authorize( $resolved, $operation ) {
		return true === parent::operation_authorize( $resolved, $operation );
	}

	/**
	 * Read the editor's artist-shaped Link Page data.
	 *
	 * The artist editor and its abilities consume this shape, so the
	 * definition keeps it instead of the engine's generic response.
	 *
	 * @param array $resolved Resolved runtime owner binding.
	 */
	public static function operation_read( $resolved ) {
		$allowed = self::operation_authorize( $resolved, 'read' );
		if ( true !== $allowed ) {
			return is_wp_error( $allowed ) ? $allowed : self::forbidden();
		}
		return ec_get_link_page_data( (int) $resolved['owner']['object_id'], (int) $resolved['link_page_id'] );
	}

	/**
	 * Save through the artist editor save path and return the editor shape.
	 *
	 * The artist save also writes artist-owned fields (socials, subscription
	 * settings, profile image), which the generic engine save rejects.
	 *
	 * @param array $resolved Resolved runtime owner binding.
	 * @param mixed $data     Prepared save data.
	 */
	public static function operation_save( $resolved, $data ) {
		$allowed = self::operation_authorize( $resolved, 'save' );
		if ( true !== $allowed ) {
			return is_wp_error( $allowed ) ? $allowed : self::forbidden();
		}
		if ( ! is_array( $data ) ) {
			return self::error( 'invalid_save', __( 'The Link Page save data must be an array.', 'extrachill-artist-platform' ), array( 'status' => 400 ) );
		}
		$result = ec_handle_link_page_save( (int) $resolved['link_page_id'], $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return ec_get_link_page_data( (int) $resolved['owner']['object_id'], (int) $resolved['link_page_id'] );
	}

	/**
	 * Run an artist-owned mutation under the artist's exact Link Page lock.
	 *
	 * Resolves the page from the owner reference, takes the separate lock on
	 * the storage blog, and re-checks the binding inside it. With no page the
	 * callback receives 0, or `no_link_page` is returned when one is required.
	 *
	 * @param int      $artist_id         Artist profile ID.
	 * @param callable $callback          Mutation receiving the Link Page ID.
	 * @param bool     $require_link_page Whether a missing page is an error.
	 * @return mixed|\WP_Error
	 */
	public static function with_link_page_lock( int $artist_id, callable $callback, bool $require_link_page = false ) {
		return ec_with_link_page_storage_blog(
			static function () use ( $artist_id, $callback, $require_link_page ) {
				$reference = self::owner_reference( $artist_id );
				if ( is_wp_error( $reference ) ) {
					return $reference;
				}
				$link_page_id = ec_get_link_page_id_for_owner( $reference );
				if ( is_wp_error( $link_page_id ) ) {
					return $link_page_id;
				}
				$link_page_id = (int) $link_page_id;
				if ( ! $link_page_id ) {
					return $require_link_page ? new \WP_Error( 'no_link_page', 'No link page exists for this artist.' ) : call_user_func( $callback, 0 );
				}
				return ec_with_link_page_lock_scope(
					$link_page_id,
					static function () use ( $link_page_id, $reference, $callback ) {
						$owner = ec_get_link_page_owner( $link_page_id );
						if ( is_wp_error( $owner ) || $owner['reference'] !== $reference ) {
							return new \WP_Error( 'artist_link_page_owner_mismatch', 'The canonical Link Page owner changed before the mutation.' );
						}
						return call_user_func( $callback, $link_page_id );
					},
					'separate'
				);
			}
		);
	}
}
