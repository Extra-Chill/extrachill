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

		if ( ! function_exists( 'extrachill_content_blocks_image_vote_count' ) ) {
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
	 * Process a single post, backfilling each image-voting block instance.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $execute Whether to actually insert comments.
	 * @return array{emails_migrated:int,emailless_synthesized:int}|null
	 */
	private function process_post( int $post_id, bool $execute ): ?array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			\WP_CLI::warning( sprintf( 'Post %d not found — skipping.', $post_id ) );
			return null;
		}

		$blocks = parse_blocks( $post->post_content );

		$block_count            = 0;
		$emails_migrated        = 0;
		$emailless_synthesized  = 0;
		$expected_total         = 0;
		$actual_total_after     = 0;

		\WP_CLI::log( sprintf( '=== Post %d: %s ===', $post_id, get_the_title( $post_id ) ) );

		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] ?? '' ) !== 'extrachill/image-voting' ) {
				continue;
			}

			$instance_id = (string) ( $block['attrs']['uniqueBlockId'] ?? '' );
			if ( '' === $instance_id ) {
				\WP_CLI::warning( '  Block with empty uniqueBlockId — skipping (cannot key votes).' );
				continue;
			}

			++$block_count;

			$voters         = array_values( (array) ( $block['attrs']['voters'] ?? array() ) );
			$voters         = array_filter( array_map( 'strval', $voters ) );
			$recorded_count = (int) ( $block['attrs']['voteCount'] ?? 0 );
			$expected_total += max( $recorded_count, count( $voters ) );

			// Existing image_vote comments for this instance (idempotency).
			$already_present = extrachill_content_blocks_image_vote_count( $post_id, $instance_id );

			// Which emails already have an image_vote comment for this instance.
			$existing_emails = $this->existing_voter_emails( $post_id, $instance_id );

			// Emails to migrate that are not already present.
			$emails_to_insert = array();
			foreach ( $voters as $email ) {
				$email = sanitize_email( $email );
				if ( '' === $email ) {
					continue;
				}
				if ( in_array( strtolower( $email ), $existing_emails, true ) ) {
					continue;
				}
				$emails_to_insert[ strtolower( $email ) ] = $email; // dedupe within the array too.
			}

			// Emailless votes to synthesize so the historical COUNT survives.
			// Total target = max(recorded_count, distinct voter emails).
			$target_total      = max( $recorded_count, count( $voters ) );
			$projected_present = $already_present + count( $emails_to_insert );
			$emailless_needed  = max( 0, $target_total - $projected_present );

			\WP_CLI::log(
				sprintf(
					'  instance %s: voteCount=%d voters=%d present=%d -> +%d emails, +%d emailless',
					$instance_id,
					$recorded_count,
					count( $voters ),
					$already_present,
					count( $emails_to_insert ),
					$emailless_needed
				)
			);

			if ( $execute ) {
				foreach ( $emails_to_insert as $email ) {
					$this->insert_vote_comment( $post_id, $instance_id, $email );
				}
				for ( $i = 0; $i < $emailless_needed; $i++ ) {
					$this->insert_vote_comment( $post_id, $instance_id, '' );
				}
			}

			$emails_migrated       += count( $emails_to_insert );
			$emailless_synthesized += $emailless_needed;

			$actual_total_after += $execute
				? extrachill_content_blocks_image_vote_count( $post_id, $instance_id )
				: $projected_present + $emailless_needed;
		}

		\WP_CLI::log(
			sprintf(
				'  SUMMARY post %d: blocks=%d, emails=%d, emailless=%d, expected=%d, %s=%d',
				$post_id,
				$block_count,
				$emails_migrated,
				$emailless_synthesized,
				$expected_total,
				$execute ? 'actual' : 'projected',
				$actual_total_after
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
	private function existing_voter_emails( int $post_id, string $instance_id ): array {
		$comments = get_comments(
			array(
				'post_id'    => $post_id,
				'type'       => EXTRACHILL_IMAGE_VOTE_COMMENT_TYPE,
				'meta_key'   => 'instance_id',
				'meta_value' => $instance_id,
				'status'     => 'approve',
			)
		);

		$emails = array();
		foreach ( (array) $comments as $comment ) {
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
	 * @return void
	 */
	private function insert_vote_comment( int $post_id, string $instance_id, string $email ): void {
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

		if ( $comment_id ) {
			add_comment_meta( $comment_id, 'instance_id', $instance_id );
		}
	}
}
