<?php
/**
 * Festival Wire Homepage Ticker Template
 *
 * Displays dynamic ticker of latest Festival Wire posts on homepage.
 * Fetches data directly and renders ticker component.
 *
 * @package ExtraChillNewsWire
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Fetch latest Festival Wire posts for ticker
$ticker_query = new WP_Query(array(
    'post_type' => 'festival_wire',
    'posts_per_page' => 8,
    'post_status' => 'publish',
    'orderby' => 'date',
    'order' => 'DESC',
    'meta_query' => array(
        'relation' => 'OR',
        array(
            'key' => '_festival_wire_ticker_exclude',
            'compare' => 'NOT EXISTS'
        ),
        array(
            'key' => '_festival_wire_ticker_exclude',
            'value' => '1',
            'compare' => '!='
        )
    )
));

$items = [];
if ($ticker_query->have_posts()) {
    while ($ticker_query->have_posts()) {
        $ticker_query->the_post();
        $items[] = '<a href="' . esc_url(get_permalink()) . '" class="festival-wire-ticker-item" title="' . esc_attr(get_the_title()) . '">' . esc_html(get_the_title()) . '</a>';
    }
    wp_reset_postdata();
}

if (!empty($items)) : ?>
    <div class="festival-wire-ticker-block">
        <div class="festival-wire-ticker-header">
            <span class="festival-wire-ticker-label">
                <span class="festival-wire-live-dot" aria-hidden="true"></span>
                Festival Wire
            </span>
            <a class="festival-wire-ticker-archive-link" href="<?php echo esc_url( get_post_type_archive_link('festival_wire') ); ?>">View All</a>
        </div>
        <div class="festival-wire-ticker-row">
            <div class="festival-wire-ticker-outer" aria-label="Latest Festival Wire Posts">
                <div class="festival-wire-ticker-track">
                    <?php echo implode("\n", $items); ?>
                    <?php echo implode("\n", $items); // duplicate for seamless loop ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>