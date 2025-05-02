<?php
// home/festival-wire-ticker.php - Festival Wire Ticker Section

// Query the latest 8 Festival Wire posts
$festival_wire_query = new WP_Query([
    'post_type'      => 'festival_wire',
    'posts_per_page' => 8,
    'post_status'    => 'publish',
    'orderby'        => 'date',
    'order'          => 'DESC',
    'no_found_rows'  => true,
]);

$items = [];
if ($festival_wire_query->have_posts()) {
    while ($festival_wire_query->have_posts()) {
        $festival_wire_query->the_post();
        $items[] = '<a href="' . get_permalink() . '" class="festival-wire-ticker-item" title="' . esc_attr(get_the_title()) . '">' . esc_html(get_the_title()) . '</a>';
    }
    wp_reset_postdata();
}

if (!empty($items)) : ?>
    <div class="festival-wire-ticker-block">
        <div class="festival-wire-ticker-header">
            <span class="festival-wire-ticker-label">
                <span class="festival-wire-live-dot" aria-label="Live"></span>
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
<?php endif; 