<?php
/**
 * Photo Contest block render template.
 *
 * Server renders only the static, cache-safe parts: the event/tickets CTA
 * banner (pure block attributes) and an empty mount root with a JSON-island
 * config. Everything dynamic — the entries grid (shuffled client-side on
 * every load), live vote counts, the submission form, share links, and the
 * finalists view — is fetched and rendered by view.tsx, because contest pages
 * sit behind full-page cache and any server-rendered entry data would freeze.
 *
 * @package ExtraChillContentBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$contest_id  = isset( $attributes['contestId'] ) ? (string) $attributes['contestId'] : '';
$heading     = isset( $attributes['heading'] ) ? (string) $attributes['heading'] : 'Enter the Contest';
$event_title = isset( $attributes['eventTitle'] ) ? (string) $attributes['eventTitle'] : '';
$event_date  = isset( $attributes['eventDate'] ) ? (string) $attributes['eventDate'] : '';
$event_venue = isset( $attributes['eventVenue'] ) ? (string) $attributes['eventVenue'] : '';
$ticket_url  = isset( $attributes['ticketUrl'] ) ? (string) $attributes['ticketUrl'] : '';
$event_url   = isset( $attributes['eventUrl'] ) ? (string) $attributes['eventUrl'] : '';

$contest_post_id = get_the_ID();
if ( ! $contest_post_id && is_admin() ) {
	global $post;
	$contest_post_id = isset( $post->ID ) ? $post->ID : 0;
}

$config = array(
	'postId'         => (int) $contest_post_id,
	'contestId'      => $contest_id,
	'heading'        => $heading,
	'eventTitle'     => $event_title,
	'entriesCloseAt' => isset( $attributes['entriesCloseAt'] ) ? (string) $attributes['entriesCloseAt'] : '',
	'votingClosesAt' => isset( $attributes['votingClosesAt'] ) ? (string) $attributes['votingClosesAt'] : '',
	'showFinalists'  => ! empty( $attributes['showFinalists'] ),
	'finalistCount'  => isset( $attributes['finalistCount'] ) ? max( 1, (int) $attributes['finalistCount'] ) : 10,
	'isAdmin'        => is_admin(),
);

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => 'extrachill-blocks-photo-contest-container',
	)
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( '' !== $event_title ) : ?>
		<div class="extrachill-blocks-photo-contest-cta">
			<div class="extrachill-blocks-photo-contest-cta-details">
				<h3 class="extrachill-blocks-photo-contest-cta-title"><?php echo esc_html( $event_title ); ?></h3>
				<?php if ( '' !== $event_date || '' !== $event_venue ) : ?>
					<p class="extrachill-blocks-photo-contest-cta-meta">
						<?php echo esc_html( trim( $event_date . ( '' !== $event_date && '' !== $event_venue ? ' · ' : '' ) . $event_venue ) ); ?>
					</p>
				<?php endif; ?>
			</div>
			<div class="extrachill-blocks-photo-contest-cta-actions">
				<?php if ( '' !== $ticket_url ) : ?>
					<a class="button-1 button-medium" href="<?php echo esc_url( $ticket_url ); ?>">Get Tickets</a>
				<?php endif; ?>
				<?php if ( '' !== $event_url ) : ?>
					<a class="button-3 button-medium" href="<?php echo esc_url( $event_url ); ?>">Event Details</a>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>
	<div class="extrachill-blocks-photo-contest-app">
		<noscript>
			<div class="notice notice-info">JavaScript is required to view entries and vote in this contest.</div>
		</noscript>
	</div>
	<script type="application/json" class="extrachill-blocks-photo-contest-config">
		<?php echo wp_json_encode( $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</script>
</div>
