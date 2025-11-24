<?php
/**
 * Social Links Component - Clean Function-Only Pattern
 *
 * Displays ExtraChill social media links with FontAwesome SVG icons.
 * Follows clean sidebar architecture with function-only approach and action hooks.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

if ( ! function_exists( 'extrachill_social_links' ) ) :
    /**
     * Display ExtraChill social media links
     *
     * Centralized social media configuration for consistent display across theme.
     * Uses FontAwesome SVG sprite with cache busting.
     *
     * @since 1.0.0
     */
    function extrachill_social_links() {
        // ExtraChill social media platform configuration
        $social_links = [
            [
                'url'   => 'https://facebook.com/extrachill',
                'icon'  => 'facebook-f',
                'label' => 'Facebook',
            ],
            [
                'url'   => 'https://twitter.com/extra_chill',
                'icon'  => 'x-twitter',
                'label' => 'Twitter',
            ],
            [
                'url'   => 'https://instagram.com/extrachill',
                'icon'  => 'instagram',
                'label' => 'Instagram',
            ],
            [
                'url'   => 'https://youtube.com/@extra-chill',
                'icon'  => 'youtube',
                'label' => 'YouTube',
            ],
            [
                'url'   => 'https://pinterest.com/extrachill',
                'icon'  => 'pinterest',
                'label' => 'Pinterest',
            ],
            [
                'url'   => 'https://github.com/Extra-Chill',
                'icon'  => 'github',
                'label' => 'GitHub',
            ],
        ];

        // FontAwesome SVG sprite with cache busting via file modification time
        $svg_file_path = get_template_directory() . '/assets/fonts/fontawesome.svg';
        $svg_version = file_exists( $svg_file_path ) ? filemtime( $svg_file_path ) : time();
        ?>

        <div class="social-links">
            <ul>
                <?php foreach ( $social_links as $social ): ?>
                    <li>
                        <a href="<?php echo esc_url( $social['url'] ); ?>" target="_blank" aria-label="<?php echo esc_attr( $social['label'] ); ?>">
                            <svg class="social-icon-svg">
                                <use href="<?php echo get_template_directory_uri(); ?>/assets/fonts/fontawesome.svg?v=<?php echo $svg_version; ?>#<?php echo esc_attr( $social['icon'] ); ?>"></use>
                            </svg>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }
endif;

// Hook registration for social links
add_action( 'extrachill_social_links', 'extrachill_social_links', 10 );
