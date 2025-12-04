/**
 * Analytics Dashboard JavaScript Module
 * 
 * Handles Chart.js-powered analytics visualization for link page management.
 * Features event-driven initialization, real-time data fetching, and responsive charts.
 * 
 * Dependencies: Chart.js
 * Event Integration: Listens for 'analyticsTabActivated' custom event
 * AJAX Endpoint: 'extrch_fetch_link_page_analytics'
 */

(function() {
    'use strict';

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
        if (!extraChillArtistPlatform.linkPageData?.link_page_id) {
            showError('Configuration error. Cannot fetch analytics.');
            return;
        }

        showLoading(true);
        showError(null); // Clear previous errors

        // --- Real AJAX Call using fetch ---
        const formData = new FormData();
        formData.append('action', 'extrch_fetch_link_page_analytics');
        formData.append('nonce', extraChillArtistPlatform.nonce);
        formData.append('link_page_id', extraChillArtistPlatform.linkPageData.link_page_id);
        formData.append('date_range', dateRangeSelect ? dateRangeSelect.value : '30');

        fetch(extraChillArtistPlatform.ajaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(response => {
            if (response.success) {
                updateUI(response.data);
            } else {
                showError(response?.data?.message || 'Failed to load analytics data.');
            }
        })
        .catch(() => {
            showError('Error communicating with server. See console for details.');
        })
        .finally(() => {
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
            if (data.top_links?.length) {
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
        if (viewsClicksChartCanvas && data.chart_data) {
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
        } else {
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

    // --- Initialize analytics when tab activates ---
    function initAnalytics() {
        if (analyticsInitialized) {
            return; // Already initialized
        }

        // Verify configuration is available
        if (!extraChillArtistPlatform || !extraChillArtistPlatform.linkPageData) {
            showError('Configuration not available. Please refresh the page.');
            return;
        }

        analyticsInitialized = true;
        fetchAnalyticsData();
    }

    // Function to be called when the tab might become visible
    // This will be exposed globally for the main UI script to call
    function handleAnalyticsTabBecameVisible() { // Renamed for clarity
        if (!analyticsTabContent) {
            analyticsTabContent = document.getElementById('manage-link-page-tab-analytics');
            if (!analyticsTabContent) {
                return;
            }
        }

        // Check if the tab content panel is actually visible (style.display is not 'none')
        // This check is important because this function might be called slightly before UI update completes.
        if (analyticsTabContent && analyticsTabContent.style.display !== 'none') {
            initAnalytics();
        } else {
            setTimeout(() => {
                if (analyticsTabContent && analyticsTabContent.style.display !== 'none') {
                    initAnalytics();
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
    // No global exposure - module is self-contained and event-driven

    // Listen for analytics tab activation
    document.addEventListener('sharedTabActivated', function(event) {
        if (event.detail.tabId === 'manage-link-page-tab-analytics') {
            handleAnalyticsTabBecameVisible();
        }
    });

})();