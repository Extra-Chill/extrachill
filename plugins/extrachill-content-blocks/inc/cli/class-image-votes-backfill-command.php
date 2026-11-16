<?php
declare(strict_types=1);
/**
 * WP-CLI command: backfill image votes into image_vote comments.
 *
 * One-time migration that reads the legacy `voters[]` / `voteCount` block
 * attributes out of post_content and recreates each vote as an anonymous
 * image_vote comment (see src/blocks/image-voting/index.php and issue #13).
 *
 * DRY-RUN BY DEFAULT. Pass --execute to actually insert comments. The command
 * is idempotent: it never inserts more votes for a (post, instance) than the
 * gap between the recorded count and the votes already present as comments.
 *
 * The command never strips voters/voteCount from post_content — that is a
 * documented manual follow-up to run after counts are verified in production.
 *
 * OPTIONAL since issue #1541: the read path
 * (extrachill_content_blocks_image_vote_total()) already renders the legacy
 * attribute counts, so historical counts display without running this. Each
 * inserted comment is tagged with the EXTRACHILL_IMAGE_VOTE_BACKFILL_META
 * meta so the read path reconciles it against the legacy attributes instead
 * of double counting. Note that WordPress core counts every approved comment
 * type except 'note' in a post's comment_count, so --execute raises the
 * comment_count of each migrated post by its backfilled vote total.
 *
 * @package ExtraChillContentBlocks
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Backfill legacy image-voting block votes into image_vote comments.
 */
class Extrachill_Content_Blocks_Image_Votes_Backfill_Command {

	/**
	 * Default contest posts known to contain image-voting blocks.
	 *
	 * @var int[]
	 */
	private const DEFAULT_POSTS = array( 103771, 86245, 65106 );

	/**
	 * Backfill image votes from post_content into image_vote comments.
	 *
	 * ## OPTIONS
	 *
	 * [--posts=<ids>]
	 * : Comma-separated post IDs to process. Defaults to the three known
	 *   contest posts (103771, 86245, 65106). Pass --all to scan every post
	 *   containing an extrachill/image-voting block instead.
	 *
	 * [--all]
	 * : Scan every published post for extrachill/image-voting blocks rather
	 *   than using a fixed post list.
	 *
	 * [--execute]
	 * : Actually insert comments. Without this flag the command runs in
	 *   dry-run mode and only reports what it would do.
	 *
	 * ## EXAMPLES
	 *
	 *     # Dry-run the three default posts.
	 *     wp extrachill-content-blocks backfill-image-votes
	 *
	 *     # Dry-run a specific post.
	 *     wp extrachill-content-blocks backfill-image-votes --posts=103771
	 *
	 *     # Actually migrate (run only after verifying the dry-run).
	 *     wp extrachill-content-blocks backfill-image-votes --execute
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 */
	public function __invoke( $args, $assoc_args ) {
		$execute = isset( $assoc_args['execute'] );

		if ( ! function_exists( 'extrachill_content_blocks_image_vote_total' ) ) {
			\WP_CLI::error( 'Image voting business logic is not loaded; activate extrachill-content-blocks first.' );
		}

		$post_ids = $this->resolve_post_ids( $assoc_args );
		if ( empty( $post_ids ) ) {
			\WP_CLI::warning( 'No posts to process.' );
			return;
		}

		if ( $execute ) {
			\WP_CLI::log( 'MODE: EXECUTE — comments will be inserted.' );
		} else {
			\WP_CLI::log( 'MODE: DRY-RUN — no comments will be inserted. Pass --execute to apply.' );
		}
		\WP_CLI::log( '' );

		$grand_emails    = 0;
		$grand_emailless = 0;

		foreach ( $post_ids as $post_id ) {
			$summary = $this->process_post( (int) $post_id, $execute );
			if ( null === $summary ) {
				continue;
			}
			$grand_emails    += $summary['emails_migrated'];
			$grand_emailless += $summary['emailless_synthesized'];
		}

		\WP_CLI::log( '' );
		\WP_CLI::success(
			sprintf(
				'%s: %d email votes + %d emailless votes across %d post(s).',
				$execute ? 'Migrated' : 'Would migrate',
				$grand_emails,
				$grand_emailless,
				count( $post_ids )
			)
		);

		if ( ! $execute ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Re-run with --execute to apply. After verifying counts in production,' );
			\WP_CLI::log( 'manually strip the legacy voters/voteCount attributes from post_content.' );
		}
	}

	/**
	 * Resolve the set of post IDs to process.
	 *
	 * @param array $assoc_args Associative args.
	 * @return int[]
	 */
	private function resolve_post_ids( array $assoc_args ): array {
		if ( isset( $assoc_args['posts'] ) ) {
			$ids = array_filter( array_map( 'absint', explode( ',', (string) $assoc_args['posts'] ) ) );
			return array_values( array_unique( $ids ) );
		}

		if ( isset( $assoc_args['all'] ) ) {
			return $this->find_posts_with_image_voting_blocks();
		}

		return self::DEFAULT_POSTS;
	}

	/**
	 * Find every published post containing an image-voting block.
	 *
	 * @return int[]
	 */
	private function find_posts_with_image_voting_blocks(): array {
		$query = new \WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				's'              => 'extrachill/image-voting',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$ids = array();
		foreach ( (array) $query->posts as $id ) {
			$post = get_post( (int) $id );
			if ( $post && has_block( 'extrachill/image-voting', $post ) ) {
				$ids[] = (int) $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Plan the backfill for one post without writing anything.
	 *
	 * Walks every extrachill/image-voting block (including nested ones) and,
	 * per instance, works out which legacy voter emails and how many
	 * emailless placeholder votes still need an image_vote comment so the
	 * backfilled comments reach the legacy total. Idempotency is keyed on
	 * comments already tagged as backfilled, so votes cast after the move to
	 * comment storage are never mistaken for migrated legacy votes.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,array{instance_id:string,legacy_count:int,legacy_voters:int,backfilled:int,emails:array<int,string>,emailless:int}>|null
	 *         Per-instance plan, or null when the post does not exist.
	 */
	public static function plan_post( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$legacy = extrachill_content_blocks_image_vote_collect_legacy( parse_blocks( (string) $post->post_content ) );
		$plan   = array();

		foreach ( $legacy as $instance_id => $votes ) {
			$instance_id = (string) $instance_id;
			$backfilled  = extrachill_content_blocks_image_vote_backfilled_count( $post_id, $instance_id );
			$existing    = self::existing_voter_emails( $post_id, $instance_id );

			$emails = array();
			foreach ( $votes['voters'] as $email ) {
				$email = sanitize_email( $email );
				if ( '' === $email || in_array( strtolower( $email ), $existing, true ) ) {
					continue;
				}
				$emails[ strtolower( $email ) ] = $email;
			}

			$plan[] = array(
				'instance_id'   => $instance_id,
				'legacy_count'  => $votes['count'],
				'legacy_voters' => count( $votes['voters'] ),
				'backfilled'    => $backfilled,
				'emails'        => array_values( $emails ),
				'emailless'     => max( 0, $votes['count'] - $backfilled - count( $emails ) ),
			);
		}

		return $plan;
	}

	/**
	 * Insert the comments described by a plan_post() result.
	 *
	 * @param int                                                                                                               $post_id Post ID.
	 * @param array<int,array{instance_id:string,legacy_count:int,legacy_voters:int,backfilled:int,emails:array<int,string>,emailless:int}> $plan    Plan from plan_post().
	 * @return int Number of comments inserted.
	 */
	public static function apply_plan( int $post_id, array $plan ): int {
		$inserted = 0;

		foreach ( $plan as $instance ) {
			foreach ( $instance['emails'] as $email ) {
				$inserted += self::insert_vote_comment( $post_id, $instance['instance_id'], $email ) ? 1 : 0;
			}
			for ( $i = 0; $i < $instance['emailless']; $i++ ) {
				$inserted += self::insert_vote_comment( $post_id, $instance['instance_id'], '' ) ? 1 : 0;
			}
		}

		return $inserted;
	}

	/**
	 * Process a single post, backfilling each image-voting block instance.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $execute Whether to actually insert comments.
	 * @return array{emails_migrated:int,emailless_synthesized:int}|null
	 */
	private function process_post( int $post_id, bool $execute ): ?array {
		$plan = self::plan_post( $post_id );
		if ( null === $plan ) {
			\WP_CLI::warning( sprintf( 'Post %d not found — skipping.', $post_id ) );
			return null;
		}

		\WP_CLI::log( sprintf( '=== Post %d: %s ===', $post_id, get_the_title( $post_id ) ) );

		$emails_migrated       = 0;
		$emailless_synthesized = 0;
		$legacy_total          = 0;

		foreach ( $plan as $instance ) {
			\WP_CLI::log(
				sprintf(
					'  instance %s: legacy=%d voters=%d backfilled=%d -> +%d emails, +%d emailless',
					$instance['instance_id'],
					$instance['legacy_count'],
					$instance['legacy_voters'],
					$instance['backfilled'],
					count( $instance['emails'] ),
					$instance['emailless']
				)
			);

			$emails_migrated       += count( $instance['emails'] );
			$emailless_synthesized += $instance['emailless'];
			$legacy_total          += $instance['legacy_count'];
		}

		if ( $execute ) {
			self::apply_plan( $post_id, $plan );
		}

		$displayed_total = 0;
		foreach ( $plan as $instance ) {
			$displayed_total += extrachill_content_blocks_image_vote_total( $post_id, $instance['instance_id'], $instance['legacy_count'] );
		}

		\WP_CLI::log(
			sprintf(
				'  SUMMARY post %d: blocks=%d, emails=%d, emailless=%d, legacy=%d, displayed=%d',
				$post_id,
				count( $plan ),
				$emails_migrated,
				$emailless_synthesized,
				$legacy_total,
				$displayed_total
			)
		);
		\WP_CLI::log( '' );

		return array(
			'emails_migrated'       => $emails_migrated,
			'emailless_synthesized' => $emailless_synthesized,
		);
	}

	/**
	 * Lowercased list of voter emails already stored as image_vote comments.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $instance_id Block instance ID.
	 * @return string[] Lowercased emails.
	 */
	private static function existing_voter_emails( int $post_id, string $instance_id ): array {
		$comments = get_comments(
			array(
				'post_id'    => $post_id,
				'type'       => EXTRACHILL_IMAGE_VOTE_COMMENT_TYPE,
				'meta_key'   => 'instance_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Single post's votes.
				'meta_value' => $instance_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Single post's votes.
				'status'     => 'approve',
			)
		);

		$emails = array();
		foreach ( (array) $comments as $comment ) {
			if ( ! is_object( $comment ) ) {
				continue;
			}
			$email = strtolower( (string) $comment->comment_author_email );
			if ( '' !== $email ) {
				$emails[] = $email;
			}
		}

		return $emails;
	}

	/**
	 * Insert a single image_vote comment with instance_id meta.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $instance_id Block instance ID.
	 * @param string $email       Voter email (empty for synthesized votes).
	 * @return bool Whether the comment was inserted.
	 */
	private static function insert_vote_comment( int $post_id, string $instance_id, string $email ): bool {
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author_email' => $email,
				'user_id'              => 0,
				'comment_type'         => EXTRACHILL_IMAGE_VOTE_COMMENT_TYPE,
				'comment_approved'     => 1,
				'comment_content'      => '',
			)
		);

		if ( ! $comment_id ) {
			return false;
		}

		add_comment_meta( $comment_id, 'instance_id', $instance_id );
		add_comment_meta( $comment_id, EXTRACHILL_IMAGE_VOTE_BACKFILL_META, '1' );

		return true;
	}
}
