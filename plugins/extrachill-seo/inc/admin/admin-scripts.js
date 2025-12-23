/**
 * Extra Chill SEO Admin Scripts
 *
 * Vanilla JS for REST API-driven SEO audit functionality.
 */

( function () {
	'use strict';

	const apiBase = ecSeoAdmin.restUrl;
	const nonce = ecSeoAdmin.nonce;

	const elements = {
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
				<div class="extrachill-seo-card">
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
	 * Initializes event listeners.
	 */
	function init() {
		if ( elements.fullAuditBtn ) {
			elements.fullAuditBtn.addEventListener( 'click', handleFullAudit );
		}

		if ( elements.batchAuditBtn ) {
			elements.batchAuditBtn.addEventListener( 'click', handleBatchAudit );
		}

		if ( elements.continueAuditBtn ) {
			elements.continueAuditBtn.addEventListener( 'click', handleContinueAudit );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
