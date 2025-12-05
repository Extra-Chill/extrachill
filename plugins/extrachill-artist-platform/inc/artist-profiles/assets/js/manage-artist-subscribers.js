/**
 * Handles REST API fetching and display of the Artist Subscribers list
 * for the Artist Profile management page Subscribers tab.
 */

(function() {
    'use strict';

    const subscribersTab = document.querySelector('.subscribers-tab-content');
    if (!subscribersTab) {
        return;
    }

    const artistId = subscribersTab.dataset.artistId;
    const restUrl = subscribersTab.dataset.restUrl;

    if (!artistId || !restUrl) {
        console.error('manage-artist-subscribers.js: Missing required data attributes (artist-id, rest-url)');
        return;
    }

    const listContainer = subscribersTab.querySelector('.bp-subscribers-list');
    const loadingMsg = listContainer.querySelector('.loading-message');
    const table = listContainer.querySelector('table');
    const tbody = table.querySelector('tbody');
    const noSubscribersMsg = listContainer.querySelector('.no-subscribers-message');
    const errorMsg = listContainer.querySelector('.error-message');

    let currentPage = 1;
    let isLoaded = false;

    function show(el) { el && el.classList.remove('hidden'); }
    function hide(el) { el && el.classList.add('hidden'); }

    function renderPagination(total, perPage, page) {
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
            btn.className = (i === page) ? 'active' : '';
            btn.addEventListener('click', function() {
                fetchSubscribers(i);
            });
            pagination.appendChild(btn);
        }
        listContainer.appendChild(pagination);
    }

    function fetchSubscribers(page = 1) {
        if (isLoaded && page === currentPage) {
            return;
        }

        show(loadingMsg);
        hide(table);
        hide(noSubscribersMsg);
        hide(errorMsg);
        tbody.innerHTML = '';
        currentPage = page;

        const url = new URL(`${restUrl}/artist/subscribers`);
        url.searchParams.set('artist_id', artistId);
        url.searchParams.set('page', page);
        url.searchParams.set('per_page', '20');

        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce': window.wpApiSettings?.nonce || ''
            }
        })
        .then(response => response.json())
        .then(data => {
            hide(loadingMsg);

            if (data.code) {
                console.error('manage-artist-subscribers.js: API error', data);
                show(errorMsg);
                hide(table);
                return;
            }

            const subscribers = data.subscribers || [];
            if (subscribers.length === 0) {
                show(noSubscribersMsg);
                hide(table);
            } else {
                subscribers.forEach(sub => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${sub.subscriber_email || ''}</td>
                        <td>${sub.username || ''}</td>
                        <td>${sub.subscribed_at || ''}</td>
                        <td>${sub.exported == 1 ? 'Yes' : 'No'}</td>
                    `;
                    tbody.appendChild(tr);
                });
                show(table);
                hide(noSubscribersMsg);
            }

            if (data.total && data.per_page) {
                renderPagination(data.total, data.per_page, page);
            }
            isLoaded = true;
        })
        .catch(err => {
            console.error('manage-artist-subscribers.js: fetchSubscribers error', err);
            hide(loadingMsg);
            show(errorMsg);
            hide(table);
        });
    }

    const exportLink = subscribersTab.querySelector('#export-subscribers-link');
    const includeExportedCheckbox = subscribersTab.querySelector('#include-exported-subscribers');

    if (exportLink) {
        exportLink.addEventListener('click', function(e) {
            e.preventDefault();

            const includeExported = includeExportedCheckbox ? includeExportedCheckbox.checked : false;

            const url = new URL(`${restUrl}/artist/subscribers/export`);
            url.searchParams.set('artist_id', artistId);
            if (includeExported) {
                url.searchParams.set('include_exported', '1');
            }

            exportLink.textContent = 'Exporting...';
            exportLink.style.pointerEvents = 'none';

            fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-WP-Nonce': window.wpApiSettings?.nonce || ''
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.code) {
                    throw new Error(data.message || 'Export failed');
                }

                const subscribers = data.subscribers || [];
                const artistName = data.artist_name || 'artist';
                const exportDate = data.export_date || new Date().toISOString().split('T')[0];

                const csvContent = generateCSV(subscribers);
                downloadCSV(csvContent, `${sanitizeFilename(artistName)}-subscribers-${exportDate}.csv`);

                if (data.marked_count > 0 && !includeExported) {
                    fetchSubscribers(currentPage);
                }
            })
            .catch(err => {
                console.error('manage-artist-subscribers.js: CSV export error:', err);
                alert('Error exporting subscribers. Please try again.');
            })
            .finally(() => {
                exportLink.textContent = 'Export';
                exportLink.style.pointerEvents = '';
            });
        });
    }

    function generateCSV(subscribers) {
        const headers = ['Email', 'Username', 'Subscribed At (UTC)', 'Exported'];
        const rows = [headers];

        subscribers.forEach(sub => {
            rows.push([
                sub.email || '',
                sub.username || '',
                sub.subscribed_at || '',
                sub.exported ? 'Yes' : 'No'
            ]);
        });

        return rows.map(row => 
            row.map(cell => {
                const escaped = String(cell).replace(/"/g, '""');
                return `"${escaped}"`;
            }).join(',')
        ).join('\n');
    }

    function downloadCSV(content, filename) {
        const BOM = '\uFEFF';
        const blob = new Blob([BOM + content], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);

        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    function sanitizeFilename(name) {
        return name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    }

    document.addEventListener('artistFollowersTabActivated', function(e) {
        if (
            e.detail &&
            e.detail.tabPaneElement &&
            e.detail.tabPaneElement.classList.contains('subscribers-tab-content')
        ) {
            if (!isLoaded) {
                fetchSubscribers(1);
            }
        }
    });

    setTimeout(() => {
        const tabId = subscribersTab.id;
        const correspondingButton = document.querySelector(`.shared-tab-button[data-tab="${tabId}"]`);
        const isInitiallyActiveButton = correspondingButton && correspondingButton.classList.contains('active');
        const isVisuallyDisplayed = subscribersTab.style.display !== 'none' && window.getComputedStyle(subscribersTab).display !== 'none';

        if ((isInitiallyActiveButton || isVisuallyDisplayed) && !isLoaded) {
            fetchSubscribers(1);
        }
    }, 50);

})();
