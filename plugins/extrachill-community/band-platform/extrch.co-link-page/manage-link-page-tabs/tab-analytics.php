<?php
/**
 * Template Part: Analytics Tab for Manage Link Page
 *
 * Loaded from manage-link-page.php
 */

defined( 'ABSPATH' ) || exit;

// Ensure variables from parent scope are available if needed.
// $link_page_id is likely needed for fetching analytics data.
global $post; // The main post object for the page template
$current_link_page_id = isset($link_page_id) ? $link_page_id : 0; // Get from parent scope if set

// All analytics tab data should be hydrated from $data provided by LinkPageDataProvider.

?>
<div class="link-page-content-card">
    <div class="bp-analytics-controls">
        <label for="bp-analytics-daterange"><?php esc_html_e('Date Range:', 'generatepress_child'); ?></label>
        <select id="bp-analytics-daterange" name="analytics_daterange">
            <option value="7"><?php esc_html_e('Last 7 Days', 'generatepress_child'); ?></option>
            <option value="30" selected><?php esc_html_e('Last 30 Days', 'generatepress_child'); ?></option>
            <option value="90"><?php esc_html_e('Last 90 Days', 'generatepress_child'); ?></option>
            <?php /* <option value="custom"><?php esc_html_e('Custom Range', 'generatepress_child'); ?></option> */ ?>
        </select>
        <?php // Add custom date range pickers here if needed later ?>
        <button type="button" id="bp-refresh-analytics" class="button button-secondary"><?php esc_html_e('Refresh', 'generatepress_child'); ?></button>
    </div>

    <div id="bp-analytics-loading" style="display: none; margin-top: 1em;"><?php esc_html_e('Loading analytics data...', 'generatepress_child'); ?></div>
    <div id="bp-analytics-error" style="display: none; margin-top: 1em; color: red;"></div>

    <div id="bp-analytics-summary" style="margin-top: 1.5em; display: flex; gap: 2em; flex-wrap: wrap;">
        <?php // Placeholder for summary stats (Total Views, Total Clicks, etc.) ?>
        <div class="bp-stat-card">
            <h4><?php esc_html_e('Total Page Views', 'generatepress_child'); ?></h4>
            <p class="bp-stat-value" id="bp-stat-total-views">--</p>
        </div>
        <div class="bp-stat-card">
            <h4><?php esc_html_e('Total Link Clicks', 'generatepress_child'); ?></h4>
            <p class="bp-stat-value" id="bp-stat-total-clicks">--</p>
        </div>
        <?php // Add more summary cards as needed ?>
    </div>
</div>

<div class="link-page-content-card">
    <div id="bp-analytics-charts" style="margin-top: 0;"> <?php // Adjusted margin-top ?>
        <h3><?php esc_html_e('Views & Clicks Over Time', 'generatepress_child'); ?></h3>
        <div class="chart-container" style="position: relative; height:400px; width:100%">
            <canvas id="bp-views-clicks-chart"></canvas>
        </div>
    </div>
</div>

<div class="link-page-content-card">
    <div id="bp-top-links-table-container"> <?php // Added a container for the table and its title ?>
        <h3 style="margin-top: 0;"><?php esc_html_e('Top Links', 'generatepress_child'); ?></h3>
        <table class="wp-list-table widefat striped" id="bp-top-links-table" style="margin-top:1em;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Link Text / URL', 'generatepress_child'); ?></th>
                    <th><?php esc_html_e('Clicks', 'generatepress_child'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php // Placeholder rows - JS will populate this ?>
                <tr><td colspan="2"><?php esc_html_e('No data available.', 'generatepress_child'); ?></td></tr>
            </tbody>
        </table>
    </div>
    <p class="description" style="margin-top: 2em; font-style: italic; color: #888;">
        <?php esc_html_e('Note: Analytics data is updated daily. Data older than 90 days is automatically pruned.', 'generatepress_child'); ?>
    </p>
</div>