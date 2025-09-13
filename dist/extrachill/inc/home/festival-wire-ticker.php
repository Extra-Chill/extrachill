<?php
// home/festival-wire-ticker.php - Festival Wire Ticker Section (Cached)

// Use cached ticker data from homepage cache
$items = [];
if (!empty($festival_wire_ticker_items)) {
    foreach ($festival_wire_ticker_items as $ticker_item) {
        $items[] = '<a href="' . esc_url($ticker_item['permalink']) . '" class="festival-wire-ticker-item" title="' . $ticker_item['title_attr'] . '">' . esc_html($ticker_item['title']) . '</a>';
    }
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
<?php endif; 