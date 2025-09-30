<?php
/**
 * Theme Header Section
 *
 * @package ExtraChill
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
  <!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-NXKDLFD');</script>
<!-- End Google Tag Manager -->
  <meta charset="<?php bloginfo('charset'); ?>" />
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="profile" href="http://gmpg.org/xfn/11" />
  
  <link rel="preload" href="<?php echo get_template_directory_uri(); ?>/assets/fonts/WilcoLoftSans-Treble.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="<?php echo get_template_directory_uri(); ?>/assets/fonts/Lobster2.woff2" as="font" type="font/woff2" crossorigin>
  
  <link rel="dns-prefetch" href="//scripts.mediavine.com">
  <link rel="dns-prefetch" href="//www.googletagmanager.com">
  
  <?php wp_head(); ?>
  <?php
  if ( function_exists('is_user_ad_free') && is_user_ad_free() ) {
      ?>
      <div id="mediavine-settings" data-blocklist-all="1"></div>
      <?php
  }
  ?>
</head>
<body <?php body_class(); ?>>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-NXKDLFD"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
<?php if (function_exists('wp_body_open')) { wp_body_open(); } ?>

<?php do_action('extrachill_above_header'); ?>

<header id="masthead" class="site-header" role="banner">
    <div class="site-branding">
        <?php if (is_front_page()): ?>
            <h1 class="site-title"><a href="<?php echo esc_url(home_url('/')); ?>" rel="home">Extra Chill</a></h1>
        <?php else: ?>
            <p class="site-title"><a href="<?php echo esc_url(home_url('/')); ?>" rel="home">Extra Chill</a></p>
        <?php endif; ?>
    </div><!-- .site-branding -->

    <?php do_action('extrachill_header_top_right'); ?>
</header><!-- #masthead -->

<?php do_action('extrachill_after_header'); ?>

<main class="inner-wrap">
