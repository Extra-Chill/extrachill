<?php
/**
 * Generic Link Page body.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

$shape         = in_array( $data['settings']['profile_image_shape'], array( 'circle', 'rectangle', 'square' ), true ) ? $data['settings']['profile_image_shape'] : 'square';
$wrapper_class = 'extrch-link-page-content-wrapper' . ( empty( $data['settings']['overlay_enabled'] ) ? ' no-overlay' : '' );
?>
<div class="extrch-link-page-container" data-bg-type="<?php echo esc_attr( $data['css_vars']['--link-page-background-type'] ?? 'color' ); ?>">
	<div class="<?php echo esc_attr( $wrapper_class ); ?>" style="flex-grow:1;">
		<?php echo $projection['_rendered_components']['before_header']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated provider output. ?>
		<div class="extrch-link-page-header-content">
			<?php echo $projection['_rendered_components']['header_actions']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated provider output. ?>
			<button class="extrch-share-trigger extrch-share-page-trigger" aria-label="Share this page" data-share-type="page" data-share-url="<?php echo esc_url( untrailingslashit( ec_get_link_page_public_url( $link_page_id ) ) ); ?>" data-share-title="<?php echo esc_attr( $projection['display_title'] ); ?>"><i class="fas fa-ellipsis-h"></i></button>
			<?php
			if ( $projection['profile_img_url'] ) :
				?>
				<div class="extrch-link-page-profile-img shape-<?php echo esc_attr( $shape ); ?>"><img src="<?php echo esc_url( $projection['profile_img_url'] ); ?>" alt="<?php echo esc_attr( $projection['display_title'] ); ?>"></div><?php endif; ?>
			<h1 class="extrch-link-page-title"><?php echo esc_html( $projection['display_title'] ); ?></h1>
			<?php
			if ( $projection['bio'] || $data['bio'] ) :
				?>
				<div class="extrch-link-page-bio"><?php echo esc_html( $data['bio'] ? $data['bio'] : $projection['bio'] ); ?></div><?php endif; ?>
		</div>
		<?php echo $projection['_rendered_components']['after_header']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated provider output. ?>
		<?php
		echo $projection['_rendered_social_above']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated provider output.
		foreach ( $data['link_sections'] as $section ) {
			echo ec_render_link_page_section( $section, $link_page_id, ! empty( $data['settings']['youtube_embed_enabled'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by renderer.
		}
		echo $projection['_rendered_components']['after_links']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated provider output.
		echo $projection['_rendered_social_below']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated provider output.
		?>
		<div class="extrch-link-page-powered" style="margin-top:auto; padding-top:1em; padding-bottom:1em;"><a href="https://extrachill.com/power/?utm_source=linkpage&amp;utm_medium=footer&amp;utm_campaign=power" rel="noopener">Powered by Extra Chill</a></div>
		<?php echo $projection['_rendered_components']['footer']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated provider output. ?>
	</div>
	<?php require EXTRACHILL_LINK_PAGES_PLUGIN_DIR . 'templates/share-modal.php'; ?>
</div>
