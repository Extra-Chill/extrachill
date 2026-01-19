<?php
/**
 * Theme Header Section
 *
 * @package ExtraChill
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="http://gmpg.org/xfn/11" />
  
	<?php
	$preload_fonts = apply_filters( 'extrachill_preload_fonts', array() );
	foreach ( $preload_fonts as $font ) :
		?>
	<link rel="preload" href="<?php echo esc_url( $font['url'] ); ?>" as="<?php echo esc_attr( $font['as'] ); ?>" type="<?php echo esc_attr( $font['type'] ); ?>" crossorigin>
		<?php
	endforeach;
	?>
  
	<link rel="dns-prefetch" href="//scripts.mediavine.com">
  
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php
if ( function_exists( 'wp_body_open' ) ) {
	wp_body_open(); }
?>

<?php do_action( 'extrachill_above_header' ); ?>

<header id="masthead" class="site-header" role="banner">
	<div class="site-branding">
		<?php if ( is_front_page() ) : ?>
			<h1 class="site-title"><a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php echo esc_html( extrachill_get_site_title() ); ?></a></h1>
		<?php else : ?>
			<p class="site-title"><a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php echo esc_html( extrachill_get_site_title() ); ?></a></p>
		<?php endif; ?>
	</div><!-- .site-branding -->

	<?php do_action( 'extrachill_header_top_right' ); ?>
</header><!-- #masthead -->

<?php do_action( 'extrachill_after_header' ); ?>

<main class="extrachill-content">
<?php do_action( 'extrachill_notices' ); ?>
