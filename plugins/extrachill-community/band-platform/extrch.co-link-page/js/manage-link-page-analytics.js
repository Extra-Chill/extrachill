// JavaScript for Analytics Tab - Manage Link Page

(function(manager) {
    console.log('Analytics tab JS loaded.');

    // Use the config from the manager if available, otherwise fall back to the localized analytics config
    const ajaxConfig = (manager && manager.ajaxConfig) ? manager.ajaxConfig : (window.extrchAnalyticsConfig || {});

    const loadingIndicator = document.getElementById('bp-analytics-loading');
    const errorIndicator = document.getElementById('bp-analytics-error');
    const dateRangeSelect = document.getElementById('bp-analytics-daterange');
    const refreshButton = document.getElementById('bp-refresh-analytics');
    const viewsClicksChartCanvas = document.getElementById('bp-views-clicks-chart');
    const topLinksTableBody = document.querySelector('#bp-top-links-table tbody');
    const totalViewsEl = document.getElementById('bp-stat-total-views');
    const totalClicksEl = document.getElementById('bp-stat-total-clicks');

    let viewsClicksChart = null; // To hold the Chart.js instance
    let analyticsInitialized = false; // Flag to track initialization
    let analyticsTabContent = null; // Reference to the tab content panel

    function fetchAnalyticsData() {
        // Use the explicitly localized data from the PHP template
        if (typeof window.extrchLinkPagePreviewAJAX === 'undefined' || !window.extrchLinkPagePreviewAJAX.link_page_id || !window.extrchLinkPagePreviewAJAX.nonce || !window.extrchLinkPagePreviewAJAX.ajax_url) {
            console.error('Localized AJAX config (extrchLinkPagePreviewAJAX) not available or incomplete.');
            showError('Configuration error. Cannot fetch analytics.');
            return;
        }

        showLoading(true);
        showError(null); // Clear previous errors

        const data = {
            action: 'extrch_fetch_link_page_analytics', // Define this AJAX action
            security_nonce: window.extrchLinkPagePreviewAJAX.nonce, // Use nonce from localized config
            link_page_id: window.extrchLinkPagePreviewAJAX.link_page_id, // Use link_page_id from localized config
            date_range: dateRangeSelect ? dateRangeSelect.value : '30', // Default to 30 days
        };

        // --- Real AJAX Call --- //
        console.log('Fetching analytics with data:', data);
        // Use the ajax_url from the localized config
        jQuery.post(window.extrchLinkPagePreviewAJAX.ajax_url, data, function(response) {
            console.log('AJAX response received:', response); // Log the raw response
            if (response && response.success && response.data) {
                updateUI(response.data);
            } else {
                showError(response?.data?.message || 'Failed to load analytics data.');
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.error('AJAX Error:', textStatus, errorThrown, jqXHR.responseText);
            showError('Error communicating with server. See console for details.');
        }).always(function() {
            showLoading(false);
        });
        // --- End Real AJAX Call --- //
    }

    function updateUI(data) {
        // Update summary stats
        if (totalViewsEl && data.summary?.total_views !== undefined) {
            totalViewsEl.textContent = data.summary.total_views.toLocaleString();
        }
        if (totalClicksEl && data.summary?.total_clicks !== undefined) {
            totalClicksEl.textContent = data.summary.total_clicks.toLocaleString();
        }

        // Update Top Links table
        if (topLinksTableBody && data.top_links) {
            topLinksTableBody.innerHTML = ''; // Clear placeholder
            if (data.top_links.length > 0) {
                data.top_links.forEach(link => {
                    // Skip 'overall' page view pseudo-link if present
                    if (link.identifier === 'overall') return;

                    const row = topLinksTableBody.insertRow();
                    const cell1 = row.insertCell();
                    const cell2 = row.insertCell();
                    cell1.textContent = link.text || link.identifier; // Display text if available, else URL
                    cell2.textContent = link.clicks.toLocaleString();
                });
            } else {
                topLinksTableBody.innerHTML = '<tr><td colspan="2">No link click data available for this period.</td></tr>';
            }
        }

        // Update Chart.js chart
        if (viewsClicksChartCanvas && data.chart_data && typeof Chart !== 'undefined') {
            const ctx = viewsClicksChartCanvas.getContext('2d');
            if (viewsClicksChart) {
                viewsClicksChart.destroy(); // Destroy previous chart instance
            }
            viewsClicksChart = new Chart(ctx, {
                type: 'line',
                data: data.chart_data,
                options: {
                    scales: {
                        y: { beginAtZero: true }
                    },
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
        } else if (typeof Chart === 'undefined') {
            console.warn('Chart.js library not loaded.');
            showError('Charting library not available.');
        }
    }

    function showLoading(isLoading) {
        if (loadingIndicator) {
            loadingIndicator.style.display = isLoading ? 'block' : 'none';
        }
    }

    function showError(message) {
        if (errorIndicator) {
            errorIndicator.textContent = message || '';
            errorIndicator.style.display = message ? 'block' : 'none';
        }
    }

    // --- Initial Load / Tab Activation ---
    function initAnalyticsIfNeeded() {
        if (analyticsInitialized) {
            console.log('Analytics already initialized.');
            return; // Already initialized
        }

        // Check if Chart.js is available
        if (typeof Chart === 'undefined') {
             // Wait a bit for Chart.js to potentially load if enqueued async
             let attempts = 0;
             const checkChartInterval = setInterval(() => {
                 attempts++;
                 if (typeof Chart !== 'undefined') {
                     clearInterval(checkChartInterval);
                     console.log('Chart.js loaded, initializing analytics.');
                     analyticsInitialized = true; // Set flag *before* first fetch
                     fetchAnalyticsData();
                 } else if (attempts > 10) { // Give up after ~2 seconds
                     clearInterval(checkChartInterval);
                     console.error('Chart.js did not load.');
                      showError('Charting library failed to load.');
                 }
             }, 200);
        } else {
            console.log('Chart.js ready, initializing analytics.');
            analyticsInitialized = true; // Set flag *before* first fetch
            fetchAnalyticsData();
        }
    }

    // Function to be called when the tab might become visible
    // This will be exposed globally for the main UI script to call
    function handleAnalyticsTabBecameVisible() { // Renamed for clarity
        if (!analyticsTabContent) {
            analyticsTabContent = document.getElementById('manage-link-page-tab-analytics');
            if (!analyticsTabContent) {
                console.warn('Could not find analytics tab content panel (#manage-link-page-tab-analytics).');
                return;
            }
        }

        // Check if the tab content panel is actually visible (style.display is not 'none')
        // This check is important because this function might be called slightly before UI update completes.
        if (analyticsTabContent && analyticsTabContent.style.display !== 'none') {
            console.log('Analytics tab is confirmed visible, initializing if needed.');
            initAnalyticsIfNeeded();
        } else {
            console.log('Analytics tab reported as visible, but content panel display is still none. Deferring init check.');
            // It might be that the main UI script calls this just before the display style is updated.
            // A small timeout can help ensure the style has been applied.
            setTimeout(() => {
                if (analyticsTabContent && analyticsTabContent.style.display !== 'none') {
                    console.log('Analytics tab (after timeout) is visible, initializing if needed.');
                    initAnalyticsIfNeeded();
                } else {
                    console.log('Analytics tab (after timeout) still not visible.');
                }
            }, 50); // Short delay
        }
    }

    // --- Event Listeners ---
    if (refreshButton) {
        refreshButton.addEventListener('click', fetchAnalyticsData);
    }
    if (dateRangeSelect) {
        dateRangeSelect.addEventListener('change', fetchAnalyticsData);
    }

    // Expose the handler function
    window.ExtrchLinkPageAnalytics = {
        handleTabBecameVisible: handleAnalyticsTabBecameVisible
    };

    // Listen for the sharedTabActivated event
    document.addEventListener('sharedTabActivated', function(event) {
        console.log('sharedTabActivated event received:', event.detail.tabId);
        if (event.detail.tabId === 'manage-link-page-tab-analytics') {
            console.log('Analytics tab activated. Calling handleTabBecameVisible.');
            handleAnalyticsTabBecameVisible();
        }
    });

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {});