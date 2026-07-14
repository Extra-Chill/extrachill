<?php
/**
 * Logged-out welcome for the feed-first Community homepage.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

$join_url = home_url( '/login/#tab-register' );
?>
<section class="community-newcomer-welcome" aria-labelledby="community-welcome-heading">
	<p class="community-newcomer-welcome__eyebrow"><?php esc_html_e( 'The Online Music Scene', 'extra-chill-community' ); ?></p>
	<h1 id="community-welcome-heading"><?php esc_html_e( 'Music is better when it starts a conversation.', 'extra-chill-community' ); ?></h1>
	<p class="community-newcomer-welcome__lede">
		<?php esc_html_e( 'Meet artists, fans, and industry people without an algorithm deciding who gets heard. Share what you love, find your people, and help shape the scene.', 'extra-chill-community' ); ?>
	</p>
	<div class="community-newcomer-welcome__actions">
		<a class="button-1 button-medium" href="<?php echo esc_url( $join_url ); ?>"><?php esc_html_e( 'Join the scene', 'extra-chill-community' ); ?></a>
		<a class="button-3 button-medium" href="<?php echo esc_url( home_url( '/recent' ) ); ?>"><?php esc_html_e( 'Explore conversations', 'extra-chill-community' ); ?></a>
	</div>
</section>
