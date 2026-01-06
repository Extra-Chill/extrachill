/**
 * Extra Chill SEO Admin Scripts
 *
 * Vanilla JS for REST API-driven SEO audit functionality.
 */

( function () {
	'use strict';

	const apiBase = ecSeoAdmin.restUrl;
	const nonce = ecSeoAdmin.nonce;

	let elements = {};
	let detailsState = {
		category: null,
		page: 1,
		perPage: 50,
		total: 0,
		totalPages: 0,
	};

	/**
	 * Makes a REST API request.
	 */
	async function apiRequest( endpoint, method = 'GET', body = null ) {
		const options = {
			method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
		};

		if ( body ) {
			options.body = JSON.stringify( body );
		}

		const response = await fetch( apiBase + endpoint, options );
		return response.json();
	}

	/**
	 * Starts an audit in the specified mode.
	 */
	async function runAudit( mode ) {
		return apiRequest( 'seo/audit', 'POST', { mode } );
	}

	/**
	 * Gets current audit status.
	 */
	async function getStatus() {
		return apiRequest( 'seo/audit/status' );
	}

	/**
	 * Continues a batch audit.
	 */
	async function continueAudit() {
		return apiRequest( 'seo/audit/continue', 'POST' );
	}

	/**
	 * Sets loading state for buttons.
	 */
	function setLoading( isLoading ) {
		const buttons = [
			elements.fullAuditBtn,
			elements.batchAuditBtn,
			elements.continueAuditBtn,
		];

		buttons.forEach( ( btn ) => {
			if ( btn ) {
				btn.disabled = isLoading;
			}
		} );

		if ( elements.statusText ) {
			elements.statusText.textContent = isLoading ? 'Running audit...' : '';
			elements.statusText.classList.toggle( 'loading', isLoading );
		}
	}

	/**
	 * Shows/hides progress bar.
	 */
	function showProgress( show, progress = null ) {
		if ( ! elements.progressContainer ) return;

		elements.progressContainer.style.display = show ? 'block' : 'none';

		if ( show && progress ) {
			const percent = progress.urls_total > 0
				? Math.round( ( progress.urls_checked / progress.urls_total ) * 100 )
				: 0;

			const checkName = formatCheckName( progress.checks?.[ progress.current_check_index ] || '' );

			elements.progressText.textContent = `${ checkName }: ${ progress.urls_checked } / ${ progress.urls_total } URLs checked`;
			elements.progressBarFill.style.width = `${ percent }%`;
		}
	}

	/**
	 * Formats check key to readable name.
	 */
	function formatCheckName( key ) {
		const names = {
			missing_excerpts: 'Missing Excerpts',
			missing_alt_text: 'Missing Alt Text',
			missing_featured: 'Missing Featured Images',
			broken_images: 'Broken Images',
			broken_internal_links: 'Broken Internal Links',
			broken_external_links: 'Broken External Links',
		};
		return names[ key ] || key;
	}

	/**
	 * Gets CSS class for count value.
	 */
	function getCountClass( count ) {
		if ( count === 0 ) return 'zero';
		if ( count > 50 ) return 'error';
		if ( count > 10 ) return 'warning';
		return '';
	}

	/**
	 * Updates the dashboard cards with results.
	 */
	function updateCards( results ) {
		if ( ! elements.cardsContainer ) return;

		if ( elements.emptyState ) {
			elements.emptyState.style.display = 'none';
		}

		const metrics = [
			{ key: 'missing_excerpts', label: 'Posts Missing Excerpts' },
			{ key: 'missing_alt_text', label: 'Images Missing Alt Text' },
			{ key: 'missing_featured', label: 'Posts Without Featured Images' },
			{ key: 'broken_images', label: 'Broken Images' },
			{ key: 'broken_internal_links', label: 'Broken Internal Links' },
			{ key: 'broken_external_links', label: 'Broken External Links' },
		];

		let html = '';

		metrics.forEach( ( { key, label } ) => {
			const metric = results[ key ] || { total: 0, by_site: {} };
			const countClass = getCountClass( metric.total );
			const sites = Object.values( metric.by_site || {} );
			const nonZeroSites = sites.filter( ( s ) => s.count > 0 );

			html += `
				<div class="extrachill-seo-card" data-category="${ key }">
					<div class="extrachill-seo-card-count ${ countClass }">
						${ metric.total.toLocaleString() }
					</div>
					<div class="extrachill-seo-card-label">${ label }</div>
					${ nonZeroSites.length > 0 ? `
						<details class="extrachill-seo-card-breakdown">
							<summary>Per-site breakdown</summary>
							<ul>
								${ nonZeroSites.map( ( s ) => `<li>${ s.label }: ${ s.count.toLocaleString() }</li>` ).join( '' ) }
							</ul>
						</details>
					` : '' }
					${ metric.total > 0 ? `
						<button type="button" class="button button-small ec-seo-view-details" data-category="${ key }">
							View Details
						</button>
					` : '' }
				</div>
			`;
		} );

		elements.cardsContainer.innerHTML = html;
		elements.cardsContainer.style.display = 'grid';
	}

	/**
	 * Updates timestamp display.
	 */
	function updateTimestamp( timestamp ) {
		if ( ! elements.timestampContainer || ! timestamp ) return;

		const date = new Date( timestamp * 1000 );
		const formatted = date.toLocaleString();

		elements.timestampContainer.textContent = `Last audit: ${ formatted }`;
		elements.timestampContainer.style.display = 'block';
	}

	/**
	 * Shows/hides continue button based on status.
	 */
	function updateContinueButton( status ) {
		if ( ! elements.continueAuditBtn ) return;

		elements.continueAuditBtn.style.display =
			status === 'in_progress' ? 'inline-block' : 'none';
	}

	/**
	 * Polls for batch audit completion.
	 */
	async function pollForCompletion() {
		try {
			const result = await continueAudit();

			if ( result.status === 'in_progress' ) {
				showProgress( true, result.progress );
				updateCards( result.results );
				setTimeout( pollForCompletion, 100 );
			} else {
				showProgress( false );
				updateCards( result.results );
				updateTimestamp( result.timestamp );
				updateContinueButton( result.status );
				setLoading( false );
			}
		} catch ( error ) {
			console.error( 'Audit error:', error );
			showProgress( false );
			setLoading( false );
			if ( elements.statusText ) {
				elements.statusText.textContent = 'Audit failed. Please try again.';
			}
		}
	}

	/**
	 * Handles full audit button click.
	 */
	async function handleFullAudit( e ) {
		e.preventDefault();
		setLoading( true );
		showProgress( false );

		try {
			const result = await runAudit( 'full' );
			updateCards( result.results );
			updateTimestamp( result.timestamp );
			updateContinueButton( result.status );
		} catch ( error ) {
			console.error( 'Full audit error:', error );
			if ( elements.statusText ) {
				elements.statusText.textContent = 'Audit failed. Please try again.';
			}
		} finally {
			setLoading( false );
		}
	}

	/**
	 * Handles batch audit button click.
	 */
	async function handleBatchAudit( e ) {
		e.preventDefault();
		setLoading( true );

		try {
			const result = await runAudit( 'batch' );

			if ( result.status === 'in_progress' ) {
				showProgress( true, result.progress );
				pollForCompletion();
			} else {
				showProgress( false );
				updateCards( result.results );
				updateTimestamp( result.timestamp );
				updateContinueButton( result.status );
				setLoading( false );
			}
		} catch ( error ) {
			console.error( 'Batch audit error:', error );
			showProgress( false );
			setLoading( false );
			if ( elements.statusText ) {
				elements.statusText.textContent = 'Audit failed. Please try again.';
			}
		}
	}

	/**
	 * Handles continue audit button click.
	 */
	async function handleContinueAudit( e ) {
		e.preventDefault();
		setLoading( true );
		pollForCompletion();
	}

	/**
	 * Fetches details for a category.
	 */
	async function fetchDetails( category, page = 1 ) {
		const params = new URLSearchParams( {
			category,
			page,
			per_page: detailsState.perPage,
		} );
		return apiRequest( `seo/audit/details?${ params }` );
	}

	/**
	 * Fetches all details for export.
	 */
	async function fetchAllDetails( category ) {
		const params = new URLSearchParams( {
			category,
			export: 'true',
		} );
		return apiRequest( `seo/audit/details?${ params }` );
	}

	/**
	 * Gets table columns based on category.
	 */
	function getColumnsForCategory( category ) {
		const columnSets = {
			missing_excerpts: [
				{ key: 'site_label', label: 'Site' },
				{ key: 'title', label: 'Title' },
				{ key: 'post_type', label: 'Type' },
				{ key: 'edit_url', label: 'Action', isLink: true },
			],
			missing_alt_text: [
				{ key: 'site_label', label: 'Site' },
				{ key: 'filename', label: 'Image' },
				{ key: 'parent_title', label: 'Parent Post' },
				{ key: 'edit_url', label: 'Action', isLink: true },
			],
			missing_featured: [
				{ key: 'site_label', label: 'Site' },
				{ key: 'title', label: 'Title' },
				{ key: 'post_type', label: 'Type' },
				{ key: 'edit_url', label: 'Action', isLink: true },
			],
			broken_images: [
				{ key: 'site_label', label: 'Site' },
				{ key: 'image_url', label: 'Image URL' },
				{ key: 'post_title', label: 'Parent Post' },
				{ key: 'edit_url', label: 'Action', isLink: true },
			],
			broken_internal_links: [
				{ key: 'site_label', label: 'Site' },
				{ key: 'link_url', label: 'Link URL' },
				{ key: 'post_title', label: 'Parent Post' },
				{ key: 'edit_url', label: 'Action', isLink: true },
			],
			broken_external_links: [
				{ key: 'site_label', label: 'Site' },
				{ key: 'link_url', label: 'Link URL' },
				{ key: 'post_title', label: 'Parent Post' },
				{ key: 'edit_url', label: 'Action', isLink: true },
			],
		};
		return columnSets[ category ] || [];
	}

	/**
	 * Renders the details table header.
	 */
	function renderDetailsHeader( category ) {
		const columns = getColumnsForCategory( category );
		const thead = elements.detailsThead;
		if ( ! thead ) return;

		thead.innerHTML = `<tr>${ columns.map( ( col ) => `<th>${ col.label }</th>` ).join( '' ) }</tr>`;
	}

	/**
	 * Renders the details table body.
	 */
	function renderDetailsBody( items, category ) {
		const columns = getColumnsForCategory( category );
		const tbody = elements.detailsTbody;
		if ( ! tbody ) return;

		if ( items.length === 0 ) {
			tbody.innerHTML = `<tr><td colspan="${ columns.length }">No items found.</td></tr>`;
			return;
		}

		tbody.innerHTML = items.map( ( item ) => {
			return `<tr>${ columns.map( ( col ) => {
				const value = item[ col.key ] || '';
				if ( col.isLink && value ) {
					return `<td><a href="${ value }" target="_blank">Edit</a></td>`;
				}
				if ( col.key === 'image_url' || col.key === 'link_url' ) {
					const truncated = value.length > 50 ? value.substring( 0, 50 ) + '...' : value;
					return `<td title="${ value }">${ truncated }</td>`;
				}
				return `<td>${ value }</td>`;
			} ).join( '' ) }</tr>`;
		} ).join( '' );
	}

	/**
	 * Updates pagination controls.
	 */
	function updatePagination() {
		if ( elements.prevBtn ) {
			elements.prevBtn.disabled = detailsState.page <= 1;
		}
		if ( elements.nextBtn ) {
			elements.nextBtn.disabled = detailsState.page >= detailsState.totalPages;
		}
		if ( elements.pageInfo ) {
			elements.pageInfo.textContent = `Page ${ detailsState.page } of ${ detailsState.totalPages } (${ detailsState.total } items)`;
		}
	}

	/**
	 * Shows the details section.
	 */
	function showDetails( show ) {
		if ( elements.detailsContainer ) {
			elements.detailsContainer.style.display = show ? 'block' : 'none';
		}
	}

	/**
	 * Sets details loading state.
	 */
	function setDetailsLoading( isLoading ) {
		if ( elements.detailsLoading ) {
			elements.detailsLoading.style.display = isLoading ? 'block' : 'none';
		}
		if ( elements.detailsTable ) {
			elements.detailsTable.style.display = isLoading ? 'none' : 'table';
		}
		if ( elements.detailsPagination ) {
			elements.detailsPagination.style.display = isLoading ? 'none' : 'flex';
		}
	}

	/**
	 * Loads and displays details for a category.
	 */
	async function loadDetails( category, page = 1 ) {
		detailsState.category = category;
		detailsState.page = page;

		showDetails( true );
		setDetailsLoading( true );

		if ( elements.detailsTitle ) {
			elements.detailsTitle.textContent = `Details: ${ formatCheckName( category ) }`;
		}

		try {
			const result = await fetchDetails( category, page );

			detailsState.total = result.total;
			detailsState.totalPages = result.total_pages;

			renderDetailsHeader( category );
			renderDetailsBody( result.items, category );
			updatePagination();
		} catch ( error ) {
			console.error( 'Failed to load details:', error );
			if ( elements.detailsTbody ) {
				elements.detailsTbody.innerHTML = '<tr><td colspan="4">Failed to load details. Please try again.</td></tr>';
			}
		} finally {
			setDetailsLoading( false );
		}
	}

	/**
	 * Handles View Details button click.
	 */
	function handleViewDetails( e ) {
		const btn = e.target.closest( '.ec-seo-view-details' );
		if ( ! btn ) return;

		const category = btn.dataset.category;
		if ( category ) {
			loadDetails( category, 1 );
		}
	}

	/**
	 * Handles pagination button clicks.
	 */
	function handlePagination( direction ) {
		const newPage = direction === 'prev' ? detailsState.page - 1 : detailsState.page + 1;
		if ( newPage >= 1 && newPage <= detailsState.totalPages ) {
			loadDetails( detailsState.category, newPage );
		}
	}

	/**
	 * Handles export button click.
	 */
	async function handleExport() {
		if ( ! detailsState.category ) return;

		try {
			const result = await fetchAllDetails( detailsState.category );

			const dataStr = JSON.stringify( result, null, 2 );
			const blob = new Blob( [ dataStr ], { type: 'application/json' } );
			const url = URL.createObjectURL( blob );

			const date = new Date().toISOString().split( 'T' )[ 0 ];
			const filename = `seo-audit-${ detailsState.category }-${ date }.json`;

			const a = document.createElement( 'a' );
			a.href = url;
			a.download = filename;
			document.body.appendChild( a );
			a.click();
			document.body.removeChild( a );
			URL.revokeObjectURL( url );
		} catch ( error ) {
			console.error( 'Export failed:', error );
			alert( 'Export failed. Please try again.' );
		}
	}

	/**
	 * Initializes element references and event listeners.
	 */
	function init() {
		elements = {
			fullAuditBtn: document.getElementById( 'ec-seo-full-audit' ),
			batchAuditBtn: document.getElementById( 'ec-seo-batch-audit' ),
			continueAuditBtn: document.getElementById( 'ec-seo-continue-audit' ),
			statusText: document.getElementById( 'ec-seo-status-text' ),
			progressContainer: document.getElementById( 'ec-seo-progress' ),
			progressText: document.getElementById( 'ec-seo-progress-text' ),
			progressBarFill: document.getElementById( 'ec-seo-progress-bar-fill' ),
			cardsContainer: document.getElementById( 'ec-seo-cards' ),
			timestampContainer: document.getElementById( 'ec-seo-timestamp' ),
			emptyState: document.getElementById( 'ec-seo-empty' ),
			detailsContainer: document.getElementById( 'ec-seo-details' ),
			detailsTitle: document.getElementById( 'ec-seo-details-title' ),
			detailsLoading: document.getElementById( 'ec-seo-details-loading' ),
			detailsTable: document.getElementById( 'ec-seo-details-table' ),
			detailsThead: document.getElementById( 'ec-seo-details-thead' ),
			detailsTbody: document.getElementById( 'ec-seo-details-tbody' ),
			detailsPagination: document.getElementById( 'ec-seo-details-pagination' ),
			prevBtn: document.getElementById( 'ec-seo-prev' ),
			nextBtn: document.getElementById( 'ec-seo-next' ),
			pageInfo: document.getElementById( 'ec-seo-page-info' ),
			exportBtn: document.getElementById( 'ec-seo-export' ),
		};

		if ( elements.fullAuditBtn ) {
			elements.fullAuditBtn.addEventListener( 'click', handleFullAudit );
		}

		if ( elements.batchAuditBtn ) {
			elements.batchAuditBtn.addEventListener( 'click', handleBatchAudit );
		}

		if ( elements.continueAuditBtn ) {
			elements.continueAuditBtn.addEventListener( 'click', handleContinueAudit );
		}

		if ( elements.cardsContainer ) {
			elements.cardsContainer.addEventListener( 'click', handleViewDetails );
		}

		if ( elements.prevBtn ) {
			elements.prevBtn.addEventListener( 'click', () => handlePagination( 'prev' ) );
		}

		if ( elements.nextBtn ) {
			elements.nextBtn.addEventListener( 'click', () => handlePagination( 'next' ) );
		}

		if ( elements.exportBtn ) {
			elements.exportBtn.addEventListener( 'click', handleExport );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
