<?php
/**
 * Theme Header Section for our forum.
 *
 * This header is nearly identical to the blog header except that it omits
 * the newsletter subscription form and replaces the shopping cart icon with
 * the logged in user profile image and notification bell.
 *
 * @package ExtraChill
 */
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
  <!-- Google Tag Manager -->
  <script>
    (function(w,d,s,l,i){
      w[l]=w[l]||[];
      w[l].push({'gtm.start': new Date().getTime(), event:'gtm.js'});
      var f=d.getElementsByTagName(s)[0],
          j=d.createElement(s),
          dl=l!='dataLayer'?'&l='+l:'';
      j.async=true;
      j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;
      f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer','GTM-NXKDLFD');
  </script>
  <!-- End Google Tag Manager -->

  <meta charset="<?php bloginfo('charset'); ?>" />
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="profile" href="http://gmpg.org/xfn/11" />
  <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
  <!-- Google Tag Manager (noscript) -->
  <noscript>
    <iframe src="https://www.googletagmanager.com/ns.html?id=GTM-NXKDLFD"
            height="0" width="0" style="display:none;visibility:hidden"></iframe>
  </noscript>
  <!-- End Google Tag Manager (noscript) -->
  
  <?php if ( function_exists('wp_body_open') ) { wp_body_open(); } ?>

  <header id="masthead" class="site-header" role="banner">
    <div class="site-branding">
      <?php if ( is_front_page() ) : ?>
        <h1 class="site-title">
          <a href="<?php echo esc_url( home_url('/') ); ?>" rel="home">
            <?php bloginfo( 'name' ); ?>
          </a>
        </h1>
      <?php else : ?>
        <p class="site-title">
          <a href="<?php echo esc_url( home_url('/') ); ?>" rel="home">
            <?php bloginfo( 'name' ); ?>
          </a>
        </p>
      <?php endif; ?>
    </div><!-- .site-branding -->

    <?php
    // Get the file modification time for cache busting of SVG assets.
    $svg_path    = get_template_directory() . '/fonts/fontawesome.svg';
    $svg_version = file_exists( $svg_path ) ? filemtime( $svg_path ) : '';
    ?>
    <nav id="site-navigation" class="main-navigation" role="navigation" aria-label="Main Navigation">
      <button class="menu-toggle-container" role="button" aria-expanded="false" tabindex="0" aria-label="Toggle Menu">
        <span class="menu-line top"></span>
        <span class="menu-line middle"></span>
        <span class="menu-line bottom"></span>
      </button>

      <div id="primary-menu" class="flyout-menu">
        <!-- Top part: Search section -->
        <div class="search-section">
        <form action="<?php echo esc_url( home_url( '/' ) ); ?>" class="search-form searchform clearfix" method="get">
	<div class="search-wrap">
		<input type="text" placeholder="<?php esc_attr_e( 'Enter search terms...', 'generatepress_child' ); ?>" class="s field" name="s">
		<button class="search" type="submit"><svg class="search-top">
    <use href="/wp-content/themes/generatepress_child/fonts/fontawesome.svg#magnifying-glass-solid"></use>
</svg></button>
	</div>
</form><!-- .searchform -->        </div>

        <!-- Bottom part: Main menu items -->
        <ul class="menu-items">
          <li class="menu-blog-link">
            <a href="https://extrachill.com">Visit Blog</a>
          </li>
          <li class="menu-calendar-link">
            <a href="https://extrachill.com/calendar">Live Music Calendar</a>
          </li>
          <?php
            wp_nav_menu( array(
              'theme_location' => 'primary',
              'menu_id'        => 'primary-menu-items',
              'walker'         => new Custom_Walker_Nav_Menu(),
            ) );
          ?>
          <!-- Note: Newsletter Subscription Form omitted for the forum -->

          <li class="menu-social-links">
          <?php include get_stylesheet_directory() . '/social-links.php'; ?>
          </li>
          <li class="menu-footer-links">
            <a href="https://extrachill.com/about">About</a>
            <a href="https://extrachill.com/contact">Contact</a>
            <a href="https://extrachill.com/shop">Merch Store</a>
          </li>
        </ul>
      </div>
    </nav>

<!-- Search and User Profile Section -->
<div class="search-user">
  <div class="search-icon">
    <svg class="search-top">
      <use href="/wp-content/themes/generatepress_child/fonts/fontawesome.svg<?php echo $svg_version ? '?v=' . $svg_version : ''; ?>#magnifying-glass-solid"></use>
    </svg>
  </div>
  <?php
    if ( is_user_logged_in() ) {
      // Logged-in: output notification bell and user avatar.
      wp_surgeon_add_notification_bell_icon();
    } else {
      // Not logged in: output login and join buttons.
      echo '<div class="auth-buttons">';
      echo '<a href="/login" class="login-button">Login</a>';
      // If your site supports registration, you can use wp_registration_url()
      // Otherwise, link to your custom registration page.
      echo '<span style="color: #fff;"> / </span>';
      echo '<a href="/register" class="join-button">Join</a>';
      echo '</div>';
    }
  ?>
</div>

  </header><!-- #masthead -->

  <div <?php generate_do_attr( 'page' ); ?>>
    <?php
    /**
     * generate_inside_site_container hook.
     *
     * @since 2.4
     */
    do_action( 'generate_inside_site_container' );
    ?>
    <div <?php generate_do_attr( 'site-content' ); ?>>
      <?php
      /**
       * generate_inside_container hook.
       *
       * @since 0.1
       */
      do_action( 'generate_inside_container' );
      ?>
