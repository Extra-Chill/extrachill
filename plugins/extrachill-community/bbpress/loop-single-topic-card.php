<?php

/**
 * Topics Loop - Single Topic Card
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

$topic_id = isset( $topic_id ) ? (int) $topic_id : bbp_get_topic_id();

if ( ! $topic_id ) {
	return;
}

?>

<div id="bbp-topic-card-<?php echo esc_attr( $topic_id ); ?>" class="bbp-topic-card ec-surface-card ec-mobile-full-width-panel<?php echo ( bbp_is_topic_sticky( $topic_id ) ? ' bbp-topic-card-sticky' : '' ); ?>">
	<div class="bbp-topic-info">
		<?php do_action( 'bbp_theme_before_topic_title' ); ?>
		<div class="topic-card-header-area">
			<div class="bbp-topic-header-inner">
				<div class="bbp-meta-upvote">
					<div class="upvote-date">
						<?php extrachill_community_render_upvote( $topic_id, 'topic' ); ?>
					</div>
				</div><!-- .bbp-meta-upvote -->
				<?php
				// Optional per-card URL override (e.g. the profile Recent
				// Conversations feed deep-links to the displayed user's
				// latest reply). Falls back to the topic permalink.
				$ec_topic_card_url = (string) get_query_var( 'ec_topic_card_url' );
				$ec_topic_link     = '' !== $ec_topic_card_url ? $ec_topic_card_url : bbp_get_topic_permalink( $topic_id );
				?>
				<a class="bbp-topic-title" href="<?php echo esc_url( $ec_topic_link ); ?>"><?php echo esc_html( bbp_get_topic_title( $topic_id ) ); ?></a>
				<?php
				// Render canonical taxonomy badges (location, etc.) via the
				// theme's single-source-of-truth engine. Guarded so the card
				// still works if the theme helper is unavailable.
				if ( function_exists( 'extrachill_display_taxonomy_badges' ) ) {
					extrachill_display_taxonomy_badges( $topic_id );
				}
				?>
			</div><!-- bbp-topic-header-inner -->
		</div><!-- topic-card-header-area -->
		<?php do_action( 'bbp_theme_after_topic_title' ); ?>
	</div>

	<div class="bbp-topic-stats">
		<span class="topic-views"><?php echo esc_html( number_format( ec_get_post_views( $topic_id ) ) ); ?> views</span>
		<div class="bbp-topic-voice-count">
			<?php echo esc_html( bbp_get_topic_voice_count( $topic_id ) ); ?> Voices
		</div>
		<div class="bbp-topic-reply-count">
			<?php echo esc_html( bbp_show_lead_topic() ? bbp_get_topic_reply_count( $topic_id ) : bbp_get_topic_post_count( $topic_id ) ); ?> Replies
		</div>
	</div>

	<div class="bbp-topic-freshness">
		<?php do_action( 'bbp_theme_before_topic_freshness_link' ); ?>
		<?php bbp_topic_freshness_link( $topic_id ); ?>
		<?php do_action( 'bbp_theme_after_topic_freshness_link' ); ?>
		<div class="bbp-topic-meta">
			<?php do_action( 'bbp_theme_before_topic_author' ); ?>
				<span class="bbp-topic-freshness-author">
				<?php
				bbp_author_link( array(
					'post_id' => bbp_get_topic_last_active_id( $topic_id ),
					'size'    => 24,
				) );
				?>
				</span>
			<?php do_action( 'bbp_theme_after_topic_author' ); ?>
		</div>
		<?php
		// Display forum name if topic has one AND it's a relevant page context
			$forum_id_for_topic = bbp_get_topic_forum_id( $topic_id );

		$show_forum_name_on_card = false;
		if ( is_page_template('page-templates/recent-feed-template.php') ||
			is_front_page() ||
			is_search() ||
			bbp_is_search() ||
			( function_exists('extrachill_is_activity_feed_card') && extrachill_is_activity_feed_card() ) ) {
			$show_forum_name_on_card = true;
		}

		if ( $show_forum_name_on_card && ! empty( $forum_id_for_topic ) ) :
			?>
			<div class="bbp-topic-forum-origin"> 
				<span class="bbp-topic-started-in">
				<?php
					printf(
					/* translators: %1$s: forum link */
					esc_html__( 'in %1$s', 'extra-chill-community' ),
					'<a href="' . esc_url( bbp_get_forum_permalink( $forum_id_for_topic ) ) . '">' . esc_html( bbp_get_forum_title( $forum_id_for_topic ) ) . '</a>'
				);
				?>
				</span>
			</div>
			<?php
		endif;
		?>
	</div>
</div><!-- #bbp-topic-card-<?php echo esc_attr( $topic_id ); ?> -->
