<?php
/**
 * OG Card Regeneration Ability
 *
 * Operator-facing regeneration for Open Graph cards. Before this ability
 * existed, the only way to force a card to re-render was `wp eval` against
 * OgCardGenerationTask::render_for_post() directly — fine for a one-off
 * debugging session, wrong as the only interface, especially for a bulk
 * operation.
 *
 * Why "force" is the point of this command: card invalidation keys on a
 * content signature derived from post data (see og-card-task.php). A code
 * deploy that fixes rendering (clipping, glyph fallback, layout) does not
 * change the underlying event data, so the signature does not change, so
 * the existing cache is still considered "current" and is never touched by
 * the normal lazy-render path. Every card generated before a rendering fix
 * ships keeps its old, wrong rendering forever unless something bypasses
 * the signature check. That's `force`.
 *
 * This is a THIN adapter: it selects candidate posts, gates on permission,
 * and calls OgCardGenerationTask::render_for_post() / inspect_for_post()
 * for the actual work. It does not change how cards are rendered or how
 * signatures are computed — see inc/og-cards/og-card-task.php, which this
 * file only calls into.
 *
 * Scope: this ability only touches posts that already HAVE a card (an
 * `_ec_og_card_url` meta value) — it regenerates, it does not generate.
 * Producing a first card for an eligible post that has never been shared
 * is the existing lazy-render path (extrachill_seo_singular_og_image_url).
 * That scope boundary, combined with per-call limit/offset bounding, is
 * what keeps a bulk pass safe on a site the size of the events site
 * (~132k published posts): the `_ec_og_card_url` meta key lookup is an
 * indexed postmeta key scan, not a table scan of every post of that type,
 * and only however many posts actually have a card are ever candidates.
 *
 * @package ExtraChillNetwork\Abilities
 * @since 2.17.0
 */

namespace ExtraChillNetwork\Abilities;

use ExtraChillNetwork\OgCards\OgCardGenerationTask;

defined( 'ABSPATH' ) || exit;

class OgCardRegenerationAbility {

	/** Default and maximum number of candidates processed per call, for bulk modes. */
	private const DEFAULT_LIMIT = 25;
	private const MAX_LIMIT     = 200;

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	public function register(): void {
		wp_register_ability(
			'extrachill/regenerate-og-card',
			array(
				'label'               => __( 'Regenerate OG Card', 'extrachill-network' ),
				'description'         => __(
					'Force one or more existing Open Graph cards to re-render, bypassing the content-signature cache so a rendering fix (not a data change) can reach cards generated before the fix shipped. Only touches posts that already have a card. Supports a single post, a bulk pass by post type, blog, or every card on disk, and dry-run reporting.',
					'extrachill-network'
				),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'       => 'object',
					'default'    => array(),
					'properties' => array(
						'post_id'   => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Regenerate a single post by ID. Takes priority over every other selector.', 'extrachill-network' ),
						),
						'post_type' => array(
							'type'        => 'string',
							'description' => __( 'Bulk mode: regenerate every existing card whose post is of this post type.', 'extrachill-network' ),
						),
						'on_disk'   => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Bulk mode: regenerate by scanning the og-cards cache bucket on disk directly, rather than by post meta. Use this to reconcile the bucket when a file and its post meta may have drifted.', 'extrachill-network' ),
						),
						'blog_id'   => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Target blog. Defaults to the current site. With no other selector, targets every existing card on this blog across all eligible post types. Combines with post_id or post_type to scope those selectors to a specific blog.', 'extrachill-network' ),
						),
						'force'     => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Bypass the content-signature check and re-render even when the existing card is considered current. This is the reason this ability exists: without it, a rendering-only fix never reaches an existing card.', 'extrachill-network' ),
						),
						'dry_run'   => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Report what would regenerate — including the predicted new URL — without writing or deleting anything.', 'extrachill-network' ),
						),
						'limit'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => self::MAX_LIMIT,
							'default'     => self::DEFAULT_LIMIT,
							'description' => __( 'Maximum number of candidates processed by this call, for bulk modes. Bounds memory/time on large sites; page through with offset.', 'extrachill-network' ),
						),
						'offset'    => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'default'     => 0,
							'description' => __( 'Candidate offset, for bulk modes. Use with the has_more/next_offset in the response to page through a large result set.', 'extrachill-network' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'dry_run'            => array( 'type' => 'boolean' ),
						'force'              => array( 'type' => 'boolean' ),
						'mode'               => array( 'type' => 'string' ),
						'blog_id'            => array( 'type' => 'integer' ),
						'requested'          => array( 'type' => 'integer' ),
						'regenerated'        => array( 'type' => 'integer' ),
						'reused'             => array( 'type' => 'integer' ),
						'skipped_ineligible' => array( 'type' => 'integer' ),
						'has_more'           => array( 'type' => 'boolean' ),
						'next_offset'        => array( 'type' => 'integer' ),
						'cards'              => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'post_id'     => array( 'type' => 'integer' ),
									'post_type'   => array( 'type' => 'string' ),
									'old_url'     => array( 'type' => 'string' ),
									'new_url'     => array( 'type' => 'string' ),
									'regenerated' => array( 'type' => 'boolean' ),
									'reused'      => array( 'type' => 'boolean' ),
									'skipped'     => array( 'type' => 'boolean' ),
									'reason'      => array( 'type' => 'string' ),
								),
							),
						),
						'errors'             => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'post_id' => array( 'type' => 'integer' ),
									'error'   => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'permissionCallback' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'destructive' => true,
					),
				),
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * Regenerating cards writes files and deletes the previous ones (and a
	 * bulk pass is not free), so this is gated as a mutating network
	 * operation — the same standard as extrachill/migrate-post — not as a
	 * public read.
	 *
	 * @return bool
	 */
	public function permissionCallback(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( is_multisite() && current_user_can( 'manage_network' ) ) {
			return true;
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Execute extrachill/regenerate-og-card.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute( array $input ) {
		if ( ! class_exists( OgCardGenerationTask::class ) ) {
			return new \WP_Error(
				'og_cards_unavailable',
				__( 'OG card generation is not loaded on this site.', 'extrachill-network' ),
				array( 'status' => 500 )
			);
		}

		$force   = ! empty( $input['force'] );
		$dry_run = ! empty( $input['dry_run'] );
		$limit   = isset( $input['limit'] ) ? max( 1, min( self::MAX_LIMIT, (int) $input['limit'] ) ) : self::DEFAULT_LIMIT;
		$offset  = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;

		$post_id   = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
		$on_disk   = ! empty( $input['on_disk'] );
		$blog_id   = isset( $input['blog_id'] ) ? absint( $input['blog_id'] ) : 0;

		if ( $post_id <= 0 && '' === $post_type && ! $on_disk && $blog_id <= 0 ) {
			return new \WP_Error(
				'missing_target',
				__( 'Specify post_id, post_type, on_disk, or blog_id.', 'extrachill-network' ),
				array( 'status' => 400 )
			);
		}

		$target_blog_id = $blog_id > 0 ? $blog_id : get_current_blog_id();
		$switch_blog    = is_multisite() && get_current_blog_id() !== $target_blog_id;

		if ( $switch_blog ) {
			switch_to_blog( $target_blog_id );
		}

		try {
			if ( $post_id > 0 ) {
				return $this->executeSingle( $post_id, $force, $dry_run, $target_blog_id );
			}

			if ( $on_disk ) {
				return $this->executeBulk(
					$this->diskCandidates( $limit, $offset ),
					$force,
					$dry_run,
					$target_blog_id,
					'on_disk'
				);
			}

			if ( '' !== $post_type ) {
				return $this->executeBulk(
					$this->metaCandidates( array( $post_type ), $limit, $offset ),
					$force,
					$dry_run,
					$target_blog_id,
					'post_type'
				);
			}

			return $this->executeBulk(
				$this->metaCandidates( $this->eligiblePostTypes(), $limit, $offset ),
				$force,
				$dry_run,
				$target_blog_id,
				'blog'
			);
		} finally {
			if ( $switch_blog ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Single-post mode.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $force   Bypass the signature check.
	 * @param bool $dry_run Report only, write nothing.
	 * @param int  $blog_id Blog id being operated on (for the response).
	 * @return array|\WP_Error
	 */
	private function executeSingle( int $post_id, bool $force, bool $dry_run, int $blog_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post #%d not found.', 'extrachill-network' ),
					$post_id
				),
				array( 'status' => 404 )
			);
		}

		$card = $this->processPost( $post, $force, $dry_run );

		return $this->buildResponse( array( $card ), $force, $dry_run, $blog_id, 'single', false, 0 );
	}

	/**
	 * Bulk mode: process a bounded candidate list.
	 *
	 * @param array{ids: int[], has_more: bool} $candidates Candidate set for this call.
	 * @param bool                              $force      Bypass the signature check.
	 * @param bool                              $dry_run    Report only, write nothing.
	 * @param int                                $blog_id    Blog id being operated on.
	 * @param string                             $mode       Bulk mode label for the response.
	 * @return array
	 */
	private function executeBulk( array $candidates, bool $force, bool $dry_run, int $blog_id, string $mode ): array {
		$cards = array();

		foreach ( $candidates['ids'] as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				$cards[] = array(
					'post_id' => $post_id,
					'error'   => 'Post not found (orphaned card reference).',
				);
				continue;
			}
			$cards[] = $this->processPost( $post, $force, $dry_run );
		}

		// next_offset is declared as a plain integer in the output schema (no
		// null variant — see #253), so "no next page" is 0, not null. 0 is
		// unambiguous here: it is only ever meaningful together with
		// has_more, and callers must not resume paging on has_more === false
		// regardless of what next_offset holds. See RegenerateOgCardCommand
		// in extrachill-cli, which only reads next_offset when has_more is
		// truthy.
		$next_offset = $candidates['has_more'] ? ( $candidates['offset'] + count( $candidates['ids'] ) ) : 0;

		return $this->buildResponse( $cards, $force, $dry_run, $blog_id, $mode, $candidates['has_more'], $next_offset );
	}

	/**
	 * Process a single post: inspect, then (unless dry-run) delegate the
	 * actual render decision entirely to OgCardGenerationTask::render_for_post().
	 *
	 * @param \WP_Post $post    Post object.
	 * @param bool     $force   Bypass the signature check.
	 * @param bool     $dry_run Report only, write nothing.
	 * @return array Card report entry.
	 */
	private function processPost( \WP_Post $post, bool $force, bool $dry_run ): array {
		$inspection = OgCardGenerationTask::inspect_for_post( $post );

		if ( null === $inspection['template_id'] ) {
			return array(
				'post_id'   => $post->ID,
				'post_type' => $post->post_type,
				'old_url'   => $inspection['existing_url'],
				'skipped'   => true,
				'reason'    => 'ineligible_post_type',
			);
		}

		if ( empty( $inspection['data'] ) ) {
			return array(
				'post_id'   => $post->ID,
				'post_type' => $post->post_type,
				'old_url'   => $inspection['existing_url'],
				'skipped'   => true,
				'reason'    => 'no_data',
			);
		}

		$would_regenerate = $force || ! $inspection['is_current'];

		if ( $dry_run ) {
			$new_url = $would_regenerate
				? OgCardGenerationTask::cached_url_for( $post, $inspection['signature'] )
				: $inspection['existing_url'];

			return array(
				'post_id'     => $post->ID,
				'post_type'   => $post->post_type,
				'old_url'     => $inspection['existing_url'],
				'new_url'     => $new_url,
				'regenerated' => $would_regenerate,
				'reused'      => ! $would_regenerate,
			);
		}

		$result = OgCardGenerationTask::render_for_post( $post, $force );

		if ( ! empty( $result['error'] ) ) {
			return array(
				'post_id' => $post->ID,
				'error'   => $result['error'],
			);
		}

		$reused = ! empty( $result['reused_cache'] );

		return array(
			'post_id'     => $post->ID,
			'post_type'   => $post->post_type,
			'old_url'     => $inspection['existing_url'],
			'new_url'     => $result['cached_url'] ?? '',
			'regenerated' => ! $reused,
			'reused'      => $reused,
		);
	}

	/**
	 * Candidate post IDs that already have an OG card, restricted to the
	 * given post types, bounded to $limit with a $limit+1 has_more probe
	 * so a large candidate pool never gets counted with SQL_CALC_FOUND_ROWS.
	 *
	 * The `_ec_og_card_url` EXISTS meta_query is what keeps this bounded on
	 * a large site: it is an indexed postmeta key lookup, not a scan of
	 * every post of the given type(s). Only posts that already have a card
	 * are ever candidates for regeneration.
	 *
	 * @param string[] $post_types Eligible post types to search within.
	 * @param int      $limit      Max candidates to return.
	 * @param int      $offset     Candidate offset.
	 * @return array{ids: int[], has_more: bool, offset: int}
	 */
	private function metaCandidates( array $post_types, int $limit, int $offset ): array {
		$post_types = array_values( array_filter( $post_types ) );
		if ( empty( $post_types ) ) {
			return array(
				'ids'      => array(),
				'has_more' => false,
				'offset'   => $offset,
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'posts_per_page' => $limit + 1,
				'offset'         => $offset,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- indexed EXISTS lookup on meta_key, intentionally bounded (see method docblock).
					array(
						'key'     => OgCardGenerationTask::META_URL,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$ids      = array_map( 'absint', (array) $query->posts );
		$has_more = count( $ids ) > $limit;

		return array(
			'ids'      => array_slice( $ids, 0, $limit ),
			'has_more' => $has_more,
			'offset'   => $offset,
		);
	}

	/**
	 * Candidate post IDs discovered by scanning the og-cards cache bucket
	 * on disk, for reconciliation when a file and its post meta may have
	 * drifted (e.g. meta lost, or a card orphaned by a deleted post).
	 *
	 * Filenames follow OgCardGenerationTask::cache_key_for()'s format:
	 * `b{blog}-{post_type}-{post_id}-{sig8|nosig000}.png`.
	 *
	 * @param int $limit  Max candidates to return.
	 * @param int $offset Candidate offset.
	 * @return array{ids: int[], has_more: bool, offset: int, orphans: array}
	 */
	private function diskCandidates( int $limit, int $offset ): array {
		$upload_dir = wp_upload_dir();
		$bucket_dir = trailingslashit( $upload_dir['basedir'] ) . OgCardGenerationTask::CACHE_BUCKET;

		$files = glob( trailingslashit( $bucket_dir ) . '*.png' );
		$files = false === $files ? array() : $files;
		sort( $files, SORT_STRING );

		$page = array_slice( $files, $offset, $limit + 1 );

		$ids = array();
		foreach ( array_slice( $page, 0, $limit ) as $file ) {
			$post_id = $this->parsePostIdFromCacheFilename( wp_basename( $file ) );
			if ( null !== $post_id ) {
				$ids[] = $post_id;
			}
		}

		return array(
			'ids'      => $ids,
			'has_more' => count( $page ) > $limit,
			'offset'   => $offset,
		);
	}

	/**
	 * Parse the post ID out of a cache filename produced by
	 * OgCardGenerationTask::cache_key_for(): `b{blog}-{type}-{id}-{sig}.png`.
	 *
	 * @param string $filename Basename of a cache file.
	 * @return int|null Post ID, or null if the filename doesn't match the scheme.
	 */
	private function parsePostIdFromCacheFilename( string $filename ): ?int {
		if ( ! preg_match( '/^b\d+-.+-(\d+)-(?:[a-f0-9]{8}|nosig000)\.png$/', $filename, $matches ) ) {
			return null;
		}
		return (int) $matches[1];
	}

	/**
	 * Every post type currently mapped to an OG card template.
	 *
	 * Reads the same `extrachill_og_card_template_map` filter that
	 * template_id_for_post() consults (inc/og-cards/data-resolver.php) so
	 * there is exactly one source of truth for "which post types get
	 * cards" — this does not hand-maintain a second copy of the map.
	 *
	 * @return string[]
	 */
	private function eligiblePostTypes(): array {
		$default_map = array( 'data_machine_events' => 'event_og_card' );
		/** This filter is documented in inc/og-cards/data-resolver.php. */
		$map = (array) apply_filters( 'extrachill_og_card_template_map', $default_map );

		return array_keys( $map );
	}

	/**
	 * Assemble the ability's response shape from a list of per-post card entries.
	 *
	 * @param array       $cards       Card entries from processPost()/orphan reports.
	 * @param bool        $force       Echoed input.
	 * @param bool        $dry_run     Echoed input.
	 * @param int         $blog_id     Blog id operated on.
	 * @param string      $mode        'single'|'post_type'|'blog'|'on_disk'.
	 * @param bool        $has_more    Whether more candidates remain beyond this call.
	 * @param int         $next_offset Offset to resume from. Meaningless when $has_more is false — see the
	 *                                 next_offset assignment in executeBulk()/executeSingle() for why this is
	 *                                 0, not null, in that case.
	 * @return array
	 */
	private function buildResponse( array $cards, bool $force, bool $dry_run, int $blog_id, string $mode, bool $has_more, int $next_offset ): array {
		$regenerated = 0;
		$reused      = 0;
		$skipped     = 0;
		$errors      = array();

		foreach ( $cards as $card ) {
			if ( ! empty( $card['error'] ) ) {
				$errors[] = array(
					'post_id' => $card['post_id'] ?? 0,
					'error'   => $card['error'],
				);
				continue;
			}
			if ( ! empty( $card['skipped'] ) ) {
				++$skipped;
				continue;
			}
			if ( ! empty( $card['regenerated'] ) ) {
				++$regenerated;
			} elseif ( ! empty( $card['reused'] ) ) {
				++$reused;
			}
		}

		return array(
			'dry_run'            => $dry_run,
			'force'              => $force,
			'mode'               => $mode,
			'blog_id'            => $blog_id,
			'requested'          => count( $cards ),
			'regenerated'        => $regenerated,
			'reused'             => $reused,
			'skipped_ineligible' => $skipped,
			'has_more'           => $has_more,
			'next_offset'        => $next_offset,
			'cards'              => array_values(
				array_filter(
					$cards,
					static function ( array $card ): bool {
						return empty( $card['error'] );
					}
				)
			),
			'errors'             => $errors,
		);
	}
}
