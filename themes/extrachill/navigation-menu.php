<?php
// Get the file modification time for cache busting
$svg_path = get_template_directory() . '/fonts/fontawesome.svg';
$svg_version = file_exists($svg_path) ? filemtime($svg_path) : '';
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
            <?php get_search_form(); ?>
        </div>

        <!-- Bottom part: Main menu items -->
        <ul class="menu-items">
            <li class="menu-community-link">
                <a href="https://community.extrachill.com">Visit Forum</a>
            </li>
            <?php
                wp_nav_menu(array(
                    'theme_location' => 'primary',
                    'menu_id' => 'primary-menu-items',
                    'walker' => new Custom_Walker_Nav_Menu(),
                ));
            ?>
            <!-- Newsletter Subscription Form -->
            <li class="menu-newsletter">
                <form class="newsletter-form">
                    <label for="newsletter-email" class="sr-only">Get our Newsletter</label>
                    <input type="email" id="newsletter-email" name="email" placeholder="Enter your email" required>
                    <button type="submit">Subscribe</button>
                    <p><a href="/newsletters">See past newsletters</a></p>
                </form>
            </li>
            <li class="menu-social-links">
                <?php include get_template_directory() . '/social-links.php'; ?>
            </li>
            <li class="menu-footer-links">
                <a href="/about">About</a> <a href="/contact">Contact</a> <a href="/shop">Merch Store</a>
            </li>
        </ul>
    </div>
</nav>

<span class="search-cart">
    <div class="search-icon">
        <svg class="search-top">
            <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg<?php echo $svg_version ? '?v=' . $svg_version : ''; ?>#magnifying-glass-solid"></use>
        </svg>
    </div>
    <div class="cart-icon">
        <?php if (function_exists('WC')): ?>
            <?php
            // WooCommerce is active; check cart contents
            $cart_count = WC()->cart->get_cart_contents_count();
            if ($cart_count > 0): ?>
                <a href="<?php echo esc_url(wc_get_cart_url()); ?>" class="cart-link">
                    <svg class="cart-top">
                        <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg<?php echo $svg_version ? '?v=' . $svg_version : ''; ?>#cart-shopping"></use>
                    </svg>
                    <span class="cart-count"><?php echo esc_html($cart_count); ?></span>
                </a>
            <?php else: ?>
                <a href="<?php echo esc_url(home_url('/shop')); ?>" class="cart-link">
                    <svg class="cart-top">
                        <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg<?php echo $svg_version ? '?v=' . $svg_version : ''; ?>#cart-shopping"></use>
                    </svg>
                </a>
            <?php endif; ?>
        <?php else: ?>
            <!-- WooCommerce is not active; link to the shop page -->
            <a href="<?php echo esc_url(home_url('/shop')); ?>" class="cart-link">
                <svg class="cart-top">
                    <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg<?php echo $svg_version ? '?v=' . $svg_version : ''; ?>#cart-shopping"></use>
                </svg>
            </a>
        <?php endif; ?>
    </div>
</span>
