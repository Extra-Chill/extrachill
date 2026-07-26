<?php
/**
 * Homepage Recently Shipped Card
 *
 * Surfaces recent GitHub releases across the whole Extra-Chill org. Renders
 * nothing when the scheduled payload is empty or unavailable.
 *
 * @package ExtraChillBlog
 * @since 0.1.0
 */

$recently_shipped = extrachill_blog_get_recently_shipped();

if ( empty( $recently_shipped['releases'] ) ) {
	return;
}

$releases                = $recently_shipped['releases'];
$repos_active_this_month = $recently_shipped['repos_active_this_month'];
$repos_total             = $recently_shipped['repos_total'];
?>
<div class="home-network-card home-shipped-card" aria-labelledby="recently-shipped-header">
	<h2 class="home-network-card-header" id="recently-shipped-header"><?php esc_html_e( 'Recently Shipped', 'extrachill-blog' ); ?></h2>
	<p class="home-network-card-description home-shipped-kicker"><?php esc_html_e( 'Recent releases from our public GitHub organization.', 'extrachill-blog' ); ?></p>
	<div class="home-shipped-list">
		<?php foreach ( $releases as $release ) : ?>
			<a
				href="<?php echo esc_url( $release['url'] ); ?>"
				class="home-shipped-row"
				target="_blank"
				rel="noopener"
				aria-label="<?php echo esc_attr( sprintf( '%s %s', $release['repo'], $release['tag'] ) ); ?>"
			>
				<span class="home-shipped-main">
					<span class="home-shipped-repo"><?php echo esc_html( $release['repo'] ); ?></span>
					<span class="home-shipped-tag"><?php echo esc_html( $release['tag'] ); ?></span>
					<?php if ( ! empty( $release['summary'] ) ) : ?>
						<span class="home-shipped-summary"><?php echo esc_html( $release['summary'] ); ?></span>
					<?php endif; ?>
				</span>
				<span class="home-shipped-meta">
					<?php
					printf(
						/* translators: %s: human-readable time difference, e.g. "3 hours" */
						esc_html__( '%s ago', 'extrachill-blog' ),
						esc_html( human_time_diff( $release['published_at'], time() ) )
					);
					?>
				</span>
			</a>
		<?php endforeach; ?>
	</div>
	<?php if ( $repos_total > 0 ) : ?>
		<div class="home-shipped-footer">
			<?php
			printf(
				/* translators: 1: number of repos active this month, 2: total repo count */
				esc_html__( '%1$d of %2$d repos active this month', 'extrachill-blog' ),
				absint( $repos_active_this_month ),
				absint( $repos_total )
			);
			?>
			&middot;
			<a href="https://github.com/Extra-Chill" target="_blank" rel="noopener"><?php esc_html_e( 'See it all on GitHub', 'extrachill-blog' ); ?></a>
		</div>
	<?php endif; ?>
</div>
