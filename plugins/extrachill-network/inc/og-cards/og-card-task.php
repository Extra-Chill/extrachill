<?php
/**
 * OG Card Generation Task.
 *
 * SystemTask that renders an Open Graph card for a single post. Inherits
 * the Data Machine task machinery (jobs table, undo, CLI surface, admin
 * UI, settings toggle) by extending DataMachine\Engine\AI\System\Tasks\SystemTask.
 *
 * Unlike AI-driven tasks, this one is purely synchronous CPU work (GD
 * rasterization, ~50ms). The OG image filter calls render_for_post()
 * directly so the card is ready by the time the meta tag is emitted —
 * the SystemTask shell exists primarily to plug into the rest of DM's
 * task tooling (CLI run, undo, audit) without reinventing it.
 *
 * @package ExtraChillNetwork\OgCards
 * @since 1.11.0
 */

namespace ExtraChillNetwork\OgCards;

use DataMachine\Engine\AI\System\Tasks\SystemTask;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OgCardGenerationTask extends SystemTask {

	/** @var string Post meta key holding the cached card URL. */
	public const META_URL = '_ec_og_card_url';

	/** @var string Post meta key holding the data signature at last render. */
	public const META_SIGNATURE = '_ec_og_card_signature';

	/** @var string Cache bucket directory under uploads/. */
	public const CACHE_BUCKET = 'og-cards';

	public function getTaskType(): string {
		return 'og_card_generation';
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'OG Card Generation',
			'description'     => 'Render branded Open Graph cards for posts that lack a featured image.',
			'setting_key'     => 'og_card_generation_enabled',
			'default_enabled' => true,
			'trigger'         => 'On first share/crawler',
			'trigger_type'    => 'event',
			'supports_run'    => true,
		);
	}

	public function supportsUndo(): bool {
		return true;
	}

	/**
	 * Execute card generation for a single post.
	 *
	 * Called by the DM job runner (e.g. when triggered via `wp datamachine
	 * system run og_card_generation --post_id=...`). The synchronous
	 * filter path bypasses this and calls render_for_post() directly.
	 *
	 * @param int   $jobId  Job ID.
	 * @param array $params { post_id, force? }.
	 */
	public function executeTask( int $jobId, array $params ): void {
		$post_id = absint( $params['post_id'] ?? 0 );
		$force   = ! empty( $params['force'] );

		if ( $post_id <= 0 ) {
			$this->failJob( $jobId, 'Missing or invalid post_id' );
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			$this->failJob( $jobId, "Post #{$post_id} not found" );
			return;
		}

		$result = self::render_for_post( $post, $force );

		if ( ! empty( $result['error'] ) ) {
			$this->failJob( $jobId, $result['error'] );
			return;
		}

		$effects = array();
		if ( ! empty( $result['cached_path'] ) ) {
			// Treat the cached file as a removable side-effect so the
			// generic SystemTask undo flow can clean it up on revert.
			$effects[] = array(
				'type'   => 'cached_file_created',
				'target' => array(
					'post_id'     => $post_id,
					'cached_path' => $result['cached_path'],
					'cached_url'  => $result['cached_url'],
				),
			);
		}

		$this->completeJob( $jobId, array(
			'post_id'      => $post_id,
			'cached_url'   => $result['cached_url'] ?? '',
			'cached_path'  => $result['cached_path'] ?? '',
			'reused_cache' => $result['reused_cache'] ?? false,
			'effects'      => $effects,
			'completed_at' => current_time( 'mysql' ),
		) );
	}

	/**
	 * Render an OG card for a post and cache the result.
	 *
	 * Returns the cached URL and absolute path on success. Reuses the
	 * existing cache when the data signature hasn't changed unless $force
	 * is true.
	 *
	 * @param \WP_Post $post  Post object.
	 * @param bool     $force Force regeneration even if cache is valid.
	 * @return array { cached_url?, cached_path?, reused_cache?, error? }
	 */
	public static function render_for_post( \WP_Post $post, bool $force = false ): array {
		$inspection = self::inspect_for_post( $post );

		if ( null === $inspection['template_id'] ) {
			return array( 'error' => "No OG card template registered for post type '{$post->post_type}'" );
		}

		if ( empty( $inspection['data'] ) ) {
			return array( 'error' => "No data collector returned data for post #{$post->ID}" );
		}

		$signature    = $inspection['signature'];
		$existing_url = $inspection['existing_url'];
		$cached_path  = $inspection['cached_path'];

		if ( ! $force && $inspection['is_current'] ) {
			return array(
				'cached_url'   => $existing_url,
				'cached_path'  => $cached_path,
				'reused_cache' => true,
			);
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return array( 'error' => 'Abilities API not available' );
		}

		$ability = wp_get_ability( 'datamachine/render-image-template' );
		if ( ! $ability ) {
			return array( 'error' => 'datamachine/render-image-template ability not registered' );
		}

		$result = $ability->execute(
			array(
				'template_id' => $inspection['template_id'],
				'data'        => $inspection['data'],
				'preset'      => 'open_graph',
				'format'      => 'png',
				'output'      => 'cached_file',
				'cache'       => array(
					'bucket' => self::CACHE_BUCKET,
					'key'    => self::cache_key_for( $post, $signature ),
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return array( 'error' => 'Ability execution failed: ' . $result->get_error_message() );
		}

		if ( empty( $result['cached_urls'][0] ) ) {
			return array( 'error' => $result['message'] ?? 'Render produced no cached file' );
		}

		$url  = (string) $result['cached_urls'][0];
		$path = (string) ( $result['cached_paths'][0] ?? '' );

		// Content-addressed keys mean a regenerated card lands at a new
		// path. Remove whatever the meta previously pointed at so the
		// bucket holds exactly one live file per post. Derived from the
		// stored URL (not a recomputed key) so this also sweeps up
		// pre-#247 legacy filenames on their first regeneration.
		if ( $existing_url && $existing_url !== $url ) {
			self::delete_cached_file_for_url( $existing_url );
		}

		update_post_meta( $post->ID, self::META_URL, $url );
		update_post_meta( $post->ID, self::META_SIGNATURE, $signature );

		return array(
			'cached_url'   => $url,
			'cached_path'  => $path,
			'reused_cache' => false,
		);
	}

	/**
	 * Read-only inspection of what render_for_post() would find for a post,
	 * without rendering or writing anything.
	 *
	 * Extracted from render_for_post() so callers that need to report on
	 * cards (dry-run reporting, bulk operator tooling) can ask "is this
	 * card current?" and "what would its URL be?" using the exact same
	 * eligibility, data-resolution, and signature logic render_for_post()
	 * itself uses — rather than re-deriving it and risking drift between
	 * what a dry-run reports and what a real run does.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array{
	 *     template_id: string|null,
	 *     data: array,
	 *     signature: string,
	 *     existing_url: string,
	 *     existing_signature: string,
	 *     cached_path: string,
	 *     is_current: bool,
	 * }
	 */
	public static function inspect_for_post( \WP_Post $post ): array {
		$template_id = template_id_for_post( $post );
		$data        = array();
		$signature   = '';

		if ( null !== $template_id ) {
			$data      = resolve_card_data( $post );
			$signature = self::signature_for( $data );
		}

		$existing_url = (string) get_post_meta( $post->ID, self::META_URL, true );
		$existing_sig = (string) get_post_meta( $post->ID, self::META_SIGNATURE, true );
		$cached_path  = self::cached_path_for( $post, $signature );

		$is_current = null !== $template_id
			&& ! empty( $data )
			&& '' !== $existing_url
			&& $existing_sig === $signature
			&& file_exists( $cached_path );

		return array(
			'template_id'        => $template_id,
			'data'               => $data,
			'signature'          => $signature,
			'existing_url'       => $existing_url,
			'existing_signature' => $existing_sig,
			'cached_path'        => $cached_path,
			'is_current'         => $is_current,
		);
	}

	/**
	 * Content-addressed cache key for a post (used as the file stem).
	 *
	 * Includes the blog ID so multisite cards from different sites do
	 * not collide when the bucket is on shared storage, and an 8-char
	 * digest of the data signature so a regenerated card lands at a new
	 * URL — the stable-URL/30-day-CDN-cache combination otherwise makes
	 * regenerated cards unreachable (see extrachill-network#247).
	 *
	 * Reuses `signature_for()`'s existing signature rather than deriving
	 * a second hash; there is exactly one "has this card changed" source
	 * of truth.
	 *
	 * @param \WP_Post $post      Post object.
	 * @param string   $signature Data signature from signature_for(). May be
	 *                            empty if unavailable; the key stays usable
	 *                            either way (falls back to a fixed digest
	 *                            rather than an empty/trailing segment).
	 * @return string
	 */
	public static function cache_key_for( \WP_Post $post, string $signature = '' ): string {
		$base = sprintf( 'b%d-%s-%d', (int) get_current_blog_id(), $post->post_type, $post->ID );
		return $base . '-' . self::signature_digest( $signature );
	}

	/**
	 * Short, filename-safe digest of a data signature.
	 *
	 * Falls back to a fixed placeholder when the signature is empty so
	 * card generation never produces a key with a missing/empty segment
	 * (e.g. `b7-post-123-.png`) and never fatals over a formatting edge
	 * case.
	 *
	 * @param string $signature Full signature from signature_for().
	 * @return string 8-char digest, always non-empty.
	 */
	private static function signature_digest( string $signature ): string {
		$signature = trim( $signature );
		if ( '' === $signature ) {
			return 'nosig000';
		}
		return substr( $signature, 0, 8 );
	}

	/**
	 * Absolute filesystem path the cache file would live at.
	 *
	 * @param \WP_Post $post      Post object.
	 * @param string   $signature Data signature from signature_for().
	 * @return string
	 */
	public static function cached_path_for( \WP_Post $post, string $signature = '' ): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . self::CACHE_BUCKET . '/' . self::cache_key_for( $post, $signature ) . '.png';
	}

	/**
	 * Public URL the cache file would live at — the URL counterpart of
	 * cached_path_for(). Content addressing makes this fully deterministic
	 * from the post and signature alone, so callers (e.g. dry-run reporting)
	 * can predict the post-regeneration URL without rendering anything.
	 *
	 * @param \WP_Post $post      Post object.
	 * @param string   $signature Data signature from signature_for().
	 * @return string
	 */
	public static function cached_url_for( \WP_Post $post, string $signature = '' ): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['baseurl'] ) . self::CACHE_BUCKET . '/' . self::cache_key_for( $post, $signature ) . '.png';
	}

	/**
	 * Delete the cached file a previously stored card URL points at.
	 *
	 * Parses the filename out of the URL itself rather than recomputing
	 * a cache key, so this correctly locates and removes both current
	 * content-addressed files and pre-#247 legacy (non-addressed) files
	 * without needing to know which signature produced them. The lookup
	 * is a direct filename join, not a directory glob.
	 *
	 * @param string $url Previously stored `_ec_og_card_url` value.
	 * @return void
	 */
	private static function delete_cached_file_for_url( string $url ): void {
		$url = trim( $url );
		if ( '' === $url ) {
			return;
		}

		$path     = wp_parse_url( $url, PHP_URL_PATH );
		$filename = is_string( $path ) ? wp_basename( $path ) : '';
		if ( '' === $filename ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		$file       = trailingslashit( $upload_dir['basedir'] ) . self::CACHE_BUCKET . '/' . $filename;

		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Hash the data payload to detect when a regen is needed.
	 *
	 * @param array $data Data array.
	 * @return string
	 */
	public static function signature_for( array $data ): string {
		return md5( wp_json_encode( $data ) );
	}

	/**
	 * Drop the cached card file + meta when post data changes.
	 *
	 * Resolves the file to delete from the stored URL (not a recomputed
	 * key), so this works for both content-addressed and pre-#247
	 * legacy filenames.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function invalidate( int $post_id ): void {
		$existing_url = (string) get_post_meta( $post_id, self::META_URL, true );
		if ( '' !== $existing_url ) {
			self::delete_cached_file_for_url( $existing_url );
		}

		delete_post_meta( $post_id, self::META_URL );
		delete_post_meta( $post_id, self::META_SIGNATURE );
	}
}
