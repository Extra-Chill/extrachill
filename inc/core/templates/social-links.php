<?php
/**
 * Social Links Component
 *
 * Displays ExtraChill social media links with SVG sprite icons.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

if ( ! function_exists( 'extrachill_social_links' ) ) :
    /**
     * Display ExtraChill social media links
     */
    function extrachill_social_links() {
        $social_links = [
            [
                'url'   => 'https://facebook.com/extrachill',
                'icon'  => 'facebook',
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

        ?>
        <div class="social-links">
            <ul>
                <?php foreach ( $social_links as $social ): ?>
                    <li>
                        <a href="<?php echo esc_url( $social['url'] ); ?>" target="_blank" aria-label="<?php echo esc_attr( $social['label'] ); ?>">
                            <?php echo ec_icon( $social['icon'], 'social-icon-svg' ); ?>
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
