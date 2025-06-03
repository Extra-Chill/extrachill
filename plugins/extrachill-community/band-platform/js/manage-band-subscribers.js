/**
 * Handles AJAX fetching and display of the Band Subscribers list
 * for the Band Profile management page Subscribers tab.
 */

(function() {
    // Only run if the subscribers tab is present
    const subscribersTab = document.querySelector('.subscribers-tab-content');
    if (!subscribersTab) {
        return;
    }

    const bandId = window.targetBandId || (typeof target_band_id !== 'undefined' ? target_band_id : null);
    // Try to get band ID from a data attribute if not available globally
    const bandIdFromDom = document.querySelector('[name="band_id"]');
    const band_id = bandId || (bandIdFromDom ? bandIdFromDom.value : null);
    if (!band_id) {
        return;
    }

    // Nonce for AJAX fetch (should be output in the PHP template)
    const fetchNonce = subscribersTab.getAttribute('data-fetch-subscribers-nonce') || null;
    // Fallback: try to find a hidden input with the nonce
    let nonceInput = subscribersTab.querySelector('input[name="_ajax_nonce"]');
    const ajaxNonce = fetchNonce || (nonceInput ? nonceInput.value : null);
    if (!ajaxNonce) {
        return;
    }

    const listContainer = subscribersTab.querySelector('.bp-subscribers-list');
    const loadingMsg = listContainer.querySelector('.loading-message');
    const table = listContainer.querySelector('table');
    const tbody = table.querySelector('tbody');
    const noSubscribersMsg = listContainer.querySelector('.no-subscribers-message');
    const errorMsg = listContainer.querySelector('.error-message');

    let currentPage = 1;
    let isLoaded = false; // Track if subscribers have been loaded at least once

    // Helper to show/hide elements
    function show(el) { el && el.classList.remove('hidden'); }
    function hide(el) { el && el.classList.add('hidden'); }

    // Render pagination controls (placeholder, to be implemented with backend support)
    function renderPagination(total, perPage, currentPage) {
        // Remove existing pagination
        let existing = listContainer.querySelector('.subscribers-pagination');
        if (existing) existing.remove();
        if (!total || !perPage || total <= perPage) return;
        const totalPages = Math.ceil(total / perPage);
        const pagination = document.createElement('div');
        pagination.className = 'subscribers-pagination';
        for (let i = 1; i <= totalPages; i++) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = i;
            btn.className = (i === currentPage) ? 'active' : '';
            btn.addEventListener('click', function() {
                fetchSubscribers(i);
            });
            pagination.appendChild(btn);
        }
        listContainer.appendChild(pagination);
    }

    // Fetch subscribers via AJAX
    function fetchSubscribers(page = 1) {
        if (isLoaded && page === currentPage) {
            return; // Prevent re-fetching the same page if already loaded
        }

        show(loadingMsg);
        hide(table);
        hide(noSubscribersMsg);
        hide(errorMsg);
        tbody.innerHTML = '';
        currentPage = page;

        const formData = new FormData();
        formData.append('action', 'extrch_fetch_band_subscribers');
        formData.append('band_id', band_id);
        formData.append('_ajax_nonce', ajaxNonce);
        formData.append('page', page);
        formData.append('per_page', 20); // Explicitly send per_page

        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            hide(loadingMsg);
            if (data.success && Array.isArray(data.data.subscribers)) {
                const subscribers = data.data.subscribers;
                if (subscribers.length === 0) {
                    show(noSubscribersMsg);
                    hide(table);
                } else {
                    subscribers.forEach(sub => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${sub.subscriber_email ? sub.subscriber_email : ''}</td>
                            <td>${sub.username ? sub.username : ''}</td>
                            <td>${sub.subscribed_at ? sub.subscribed_at : ''}</td>
                            <td>${sub.exported == 1 ? 'Yes' : 'No'}</td>
                        `;
                        tbody.appendChild(tr);
                    });
                    show(table);
                    hide(noSubscribersMsg);
                }
                // If backend returns total and per_page, render pagination
                if (data.data.total && data.data.per_page) {
                    renderPagination(data.data.total, data.data.per_page, page);
                }
                isLoaded = true; // Mark as loaded successfully

            } else {
                console.error('manage-band-subscribers.js: API returned an error or unexpected data structure.', data);
                show(errorMsg);
                hide(table);
            }
        })
        .catch(err => {
            console.error('manage-band-subscribers.js: fetchSubscribers fetch error', err);
            hide(loadingMsg);
            show(errorMsg);
            hide(table);
        });
    }

    // --- Handle CSV Export Anchor Link and Checkbox ---
    const exportLink = subscribersTab.querySelector('#export-subscribers-link');
    const includeExportedCheckbox = subscribersTab.querySelector('#include-exported-subscribers');

    // Handle CSV Export Link Click
    if (exportLink) {
        exportLink.addEventListener('click', function(e) {
            e.preventDefault(); // Prevent the default link behavior

            // Get export parameters from the link's href or data attributes
            // Parsing from href is simple since we constructed it in PHP
            const exportUrl = new URL(exportLink.href);
            const exportNonce = exportUrl.searchParams.get('_wpnonce');
            const includeExported = includeExportedCheckbox ? includeExportedCheckbox.checked : false;

            if (!band_id || !exportNonce) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'extrch_export_subscribers_csv'); // The wp_ajax_ hook action
            formData.append('band_id', band_id);
            formData.append('_wpnonce', exportNonce); // Send the export nonce
            if (includeExported) {
                formData.append('include_exported', '1');
            }

            // Make the AJAX request to fetch the CSV data
            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(response => {
                 // Check if the response is actually a file (text/csv)
                 const contentType = response.headers.get("content-type");
                 if (contentType && contentType.includes("text/csv")) {
                      return response.blob().then(blob => ({ response, blob })); // Get the response as a Blob and return it along with the response object
                 } else {
                      // Handle cases where the server might return an error (not CSV)
                      // Read as text to see potential error messages
                      return response.text().then(text => {
                          console.error('manage-band-subscribers.js: Unexpected response type from export AJAX:', contentType, 'Response body:', text);
                           throw new Error('Export failed: Unexpected server response.');
                      });
                 }
            })
            .then(({ response, blob }) => { // Destructure to get both response and blob
                // Create a temporary link to trigger the download
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.style.display = 'none';
                a.href = url;
                // Try to get filename from headers (if server sends it)
                const contentDisposition = response.headers.get('content-disposition');
                let filename = 'subscribers.csv'; // Default filename
                if (contentDisposition) {
                     const filenameMatch = contentDisposition.match(/filename="(.+)"/);
                     if (filenameMatch && filenameMatch[1]) {
                          filename = filenameMatch[1];
                     }
                }
                a.download = filename;
                document.body.appendChild(a);
                a.click(); // Trigger the download
                window.URL.revokeObjectURL(url); // Clean up
                a.remove();
            })
            .catch(err => {
                console.error('manage-band-subscribers.js: CSV export fetch error:', err);
                // Optionally show an error message to the user on the page
                alert('Error exporting subscribers. Please try again.');
            });
        });
    }

    // Listen for tab activation
    document.addEventListener('sharedTabActivated', function(e) {
        if (
            e.detail &&
            e.detail.tabPaneElement &&
            e.detail.tabPaneElement.classList.contains('subscribers-tab-content')
        ) {
            // Fetch only if not already loaded
            if (!isLoaded) {
                 fetchSubscribers(1); // Fetch the first page on activation
            }
        }
    });

    // Check if this tab is the *initially* active tab and load subscribers
    // Use a slightly delayed check to ensure shared-tabs.js has finished its initial layout
    setTimeout(() => {
        const tabId = subscribersTab.id;
        const correspondingButton = document.querySelector(`.shared-tab-button[data-tab="${tabId}"]`);

        const isInitiallyActiveButton = correspondingButton && correspondingButton.classList.contains('active');

        // Also check if the pane is visually displayed (fallback)
        // Check against computed style to be sure
        const isVisuallyDisplayed = subscribersTab.style.display !== 'none' && window.getComputedStyle(subscribersTab).display !== 'none';

        if ((isInitiallyActiveButton || isVisuallyDisplayed) && !isLoaded) {
            fetchSubscribers(1); // Fetch the first page on initial load
        }
    }, 50); // Small delay to allow other DOMContentLoaded scripts to run

})(); 