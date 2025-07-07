<?php
/**
 * Theme Header Section for our theme.
 *
 * @package ExtraChill
 */

global $header_user_details; // Declare as global
// Fetch user details and output them for JavaScript usage - Moved to top for scope
if (isset($_COOKIE['ecc_user_session_token'])) {
    $header_user_details = get_user_details_directly($_COOKIE['ecc_user_session_token']); // Fetch user details once
} else {
    $header_user_details = false; // Set to false if no session token
}
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
  <?php wp_head(); ?>
  <?php
  // Conditionally include Mediavine ad blocklist based on ad-free status, passing pre-fetched user details
  if ( is_user_ad_free( $header_user_details ) ) {
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

<header id="masthead" class="site-header" role="banner">
    <div class="site-branding">
        <?php if (is_front_page()): ?>
            <h1 class="site-title"><a href="<?php echo esc_url(home_url('/')); ?>" rel="home"><?php bloginfo('name'); ?></a></h1>
        <?php else: ?>
            <p class="site-title"><a href="<?php echo esc_url(home_url('/')); ?>" rel="home"><?php bloginfo('name'); ?></a></p>
        <?php endif; ?>
    </div><!-- .site-branding -->

    <?php get_template_part('navigation-menu'); ?> <!-- Include the navigation here -->
</header><!-- #masthead -->


<script>
// Embed user details into a JavaScript variable for global access
window.userDetails = <?php echo json_encode($header_user_details); ?>;
</script>

<?php
// Header image functionality removed - not used in current theme
?>

<?php do_action('extrachill_after_header'); ?>
<?php do_action('extrachill_before_main'); ?>

<main class="inner-wrap">
