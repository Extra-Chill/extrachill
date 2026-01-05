<?php
/**
 * Homepage Festival Wire Card
 *
 * @package ExtraChillBlog
 * @since 0.1.0
 */

$festival_counts = extrachill_blog_get_wire_festival_counts();
?>
<div class="home-network-card" aria-labelledby="wire-header">
	<h2 class="home-network-card-header" id="wire-header">Festival Wire</h2>
	<p class="home-network-card-description">
		Automated news feeds covering viral discussions, latest festival announcements, lineups, and music industry updates.
	</p>
	<?php if ( ! empty( $festival_counts ) ) : ?>
		<div class="taxonomy-badges" style="justify-content: center;">
			<?php foreach ( $festival_counts as $festival ) : ?>
				<a href="<?php echo esc_url( $festival['url'] ); ?>" class="taxonomy-badge festival-badge festival-<?php echo esc_attr( $festival['slug'] ); ?>">
					<?php echo esc_html( $festival['name'] ); ?> (<?php echo esc_html( $festival['count'] ); ?>)
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
	<div class="home-network-card-cta">
		<a href="https://wire.extrachill.com" class="button-2 button-medium">View the Wire</a>
	</div>
</div>
