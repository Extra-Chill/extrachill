<?php
/**
 * Theme Header Section for our theme.
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
  <?php wp_head(); ?>
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

    <nav id="site-navigation" class="main-navigation" role="navigation" aria-label="Main Navigation">
        <button class="menu-toggle-container" role="button" aria-expanded="false" tabindex="0" aria-label="Toggle Menu">
            <svg class="menu-toggle" xmlns="http://www.w3.org/2000/svg">
                <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg?v1.3#bars-solid"></use>
            </svg>
        </button>
        <?php
            wp_nav_menu(array(
                'theme_location' => 'primary',
                'menu_id' => 'primary-menu',
                'walker' => new Custom_Walker_Nav_Menu(), // Ensure this line matches your theme's code
            ));
        ?>
    </nav><!-- #site-navigation -->

    <svg class="search-top">
        <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg#magnifying-glass-solid"></use>
    </svg>
    <div class="header-search">
        <?php get_search_form(); ?>
    </div><!-- .header-search -->
</header><!-- #masthead -->

<?php
// Fetch user details and output them for JavaScript usage
if (isset($_COOKIE['ecc_user_session_token'])) {
    $sessionToken = $_COOKIE['ecc_user_session_token'];
    $userDetails = get_user_details_directly($sessionToken);
} else {
    $userDetails = false;
}
?>

<script>
// Embed user details into a JavaScript variable for global access
window.userDetails = <?php echo json_encode($userDetails); ?>;
</script>

<?php
// Render header image if necessary
if ((get_theme_mod('colormag_header_image_position', 'position_two') == 'position_three') &&
    (in_array($main_total_header_option_layout_class, ['type_one', 'type_two', 'type_three']))) {
    colormag_render_header_image();
}
?>

<?php do_action('colormag_after_header'); ?>
<?php do_action('colormag_before_main'); ?>

<main class="inner-wrap" <?php echo colormag_schema_markup('content'); ?>>
