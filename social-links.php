<?php
// File: extrachill-custom/social-links.php

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
    /***** 
    [
        'url'   => 'https://community.extrachill.com',
        'icon'  => 'igloo-solid',
        'label' => 'Community Platform',
    ],
    *******/
];

$svg_file_path = get_template_directory() . '/fonts/fontawesome.svg';
$svg_version = file_exists( $svg_file_path ) ? filemtime( $svg_file_path ) : time();
?>

<div class="social-links">
    <ul>
        <?php foreach ( $social_links as $social ): ?>
            <li>
                <a href="<?php echo esc_url( $social['url'] ); ?>" target="_blank" aria-label="<?php echo esc_attr( $social['label'] ); ?>">
                    <svg class="social-icon-svg">
                        <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg?v=<?php echo $svg_version; ?>#<?php echo esc_attr( $social['icon'] ); ?>"></use>
                    </svg>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
