/**
 * Discovery Page Scope Tabs
 *
 * Intercepts scope tab clicks and swaps the calendar content via REST API
 * without a full page reload. Updates the URL, title, H1, breadcrumbs,
 * and active tab state client-side.
 *
 * @package
 * @since 0.8.0
 */

( function () {
	'use strict';

	/** Scope labels for title/H1 generation. */
	const SCOPE_LABELS = {
		today: 'Today',
		tonight: 'Tonight',
		'this-weekend': 'This Weekend',
		'this-week': 'This Week',
	};
	const FILTER_QUERY_KEYS = [
		'event_search',
		'date_start',
		'date_end',
		'past',
		'month',
		'lat',
		'lng',
		'radius',
		'radius_unit',
	];
	let activeRequest = null;
	let requestSequence = 0;

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		const nav = document.querySelector( '.discovery-scope-nav' );
		if ( ! nav ) {
			return;
		}

		nav.addEventListener( 'click', function ( e ) {
			const link = e.target.closest( 'a[data-scope]' );
			if ( ! link ) {
				return;
			}

			e.preventDefault();

			const scope = link.getAttribute( 'data-scope' );
			const termId = nav.getAttribute( 'data-term-id' );
			const termName = nav.getAttribute( 'data-term-name' );
			const targetUrl = buildScopeUrl( link.getAttribute( 'href' ) );

			// Update active tab immediately for responsive feel.
			updateActiveTab( nav, link );

			// Update URL without reload.
			window.history.pushState( { scope }, '', targetUrl );

			// Update page title and H1.
			updatePageText( termName, scope );

			// Fetch calendar events for new scope.
			fetchScopedCalendar( scope, termId, targetUrl.searchParams );
		} );

		// Handle browser back/forward navigation.
		window.addEventListener( 'popstate', function () {
			const tabLink = findTabForLocation( nav );
			const scope = tabLink
				? tabLink.getAttribute( 'data-scope' )
				: findScopeForLocation();
			if ( typeof scope !== 'string' ) {
				return;
			}

			const termId = nav.getAttribute( 'data-term-id' );
			const termName = nav.getAttribute( 'data-term-name' );

			if ( tabLink ) {
				updateActiveTab( nav, tabLink );
			} else {
				clearActiveTabs( nav );
			}
			updatePageText( termName, scope );
			fetchScopedCalendar(
				scope,
				termId,
				new URLSearchParams( window.location.search )
			);
		} );

		// Set initial state for popstate support.
		const activeLink = nav.querySelector( 'li.active a[data-scope]' );
		const initialScope = activeLink
			? activeLink.getAttribute( 'data-scope' )
			: findScopeForLocation();
		if ( typeof initialScope === 'string' ) {
			window.history.replaceState(
				{ scope: initialScope },
				'',
				window.location.href
			);
		}
	}

	/**
	 * Read a valid scope slug from the current URL path.
	 * @return {string|undefined} Current scope, if represented by the path.
	 */
	function findScopeForLocation() {
		const parts = normalizePath( window.location.pathname ).split( '/' );
		const candidate = parts[ parts.length - 1 ];

		return Object.prototype.hasOwnProperty.call( SCOPE_LABELS, candidate )
			? candidate
			: undefined;
	}

	/**
	 * Build a scope destination without carrying REST-owned state or pagination.
	 * @param {string} href Scope link destination.
	 * @return {URL} Scope destination with current filters.
	 */
	function buildScopeUrl( href ) {
		const targetUrl = new URL( href, window.location.href );
		const currentParams = new URLSearchParams( window.location.search );

		targetUrl.search = '';
		FILTER_QUERY_KEYS.forEach( function ( key ) {
			const value = currentParams.get( key );
			if ( value ) {
				targetUrl.searchParams.set( key, value );
			}
		} );

		for ( const pair of currentParams.entries() ) {
			if ( pair[ 0 ].indexOf( 'tax_filter[' ) === 0 ) {
				targetUrl.searchParams.append( pair[ 0 ], pair[ 1 ] );
			}
		}

		return targetUrl;
	}

	/**
	 * Find the tab whose generated path represents the current location.
	 * @param {Element} nav Scope-nav container element.
	 * @return {Element|null} Matching tab link.
	 */
	function findTabForLocation( nav ) {
		const links = nav.querySelectorAll( 'a[data-scope]' );
		const currentPath = normalizePath( window.location.pathname );

		for ( let i = 0; i < links.length; i++ ) {
			const linkPath = normalizePath(
				new URL(
					links[ i ].getAttribute( 'href' ),
					window.location.href
				).pathname
			);
			if ( linkPath === currentPath ) {
				return links[ i ];
			}
		}

		return null;
	}

	/**
	 * Normalize trailing slashes for tab path comparisons.
	 * @param {string} path URL path.
	 * @return {string} Normalized path.
	 */
	function normalizePath( path ) {
		return path.replace( /\/+$/, '' ) || '/';
	}

	/**
	 * Update active tab styling.
	 * @param {Element} nav        Scope-nav container element.
	 * @param {Element} activeLink The tab link to mark active.
	 */
	function updateActiveTab( nav, activeLink ) {
		clearActiveTabs( nav );

		// Set new active tab.
		const li = activeLink.closest( 'li' );
		if ( li ) {
			li.classList.add( 'active' );
		}
		activeLink.setAttribute( 'aria-current', 'page' );
	}

	/**
	 * Clear scope tab styling when a valid scope has no corresponding tab.
	 * @param {Element} nav Scope-nav container element.
	 */
	function clearActiveTabs( nav ) {
		const items = nav.querySelectorAll( 'li' );
		for ( let i = 0; i < items.length; i++ ) {
			items[ i ].classList.remove( 'active' );
			const a = items[ i ].querySelector( 'a' );
			if ( a ) {
				a.removeAttribute( 'aria-current' );
			}
		}
	}

	/**
	 * Update H1 and document title for the new scope.
	 * @param {string} termName Location term display name.
	 * @param {string} scope    Scope slug (e.g. 'tonight', 'this-weekend').
	 */
	function updatePageText( termName, scope ) {
		const scopeLabel = SCOPE_LABELS[ scope ] || '';
		const h1Text = scopeLabel
			? 'Live Music in ' + termName + ' ' + scopeLabel
			: 'Live Music in ' + termName;

		// Update H1.
		const h1 = document.querySelector( '.page-title' );
		if ( h1 ) {
			h1.textContent = h1Text;
		}

		// Update document title.
		const siteSuffix = ' – Extra Chill Events';
		const titleBase = scopeLabel
			? 'Live Music in ' + termName + ' ' + scopeLabel
			: 'Live Music in ' + termName + ' Tonight & This Week';
		document.title = titleBase + siteSuffix;
	}

	/**
	 * Fetch scoped calendar events via REST API and swap DOM.
	 * @param {string}          scope     Scope slug (e.g. 'tonight', 'this-weekend').
	 * @param {string}          termId    Location term ID to fetch events for.
	 * @param {URLSearchParams} urlParams Filter state owned by this navigation.
	 */
	function fetchScopedCalendar( scope, termId, urlParams ) {
		const calendar = document.querySelector(
			'.data-machine-events-calendar'
		);
		if ( ! calendar ) {
			return;
		}

		const content = calendar.querySelector(
			'.data-machine-events-content'
		);
		if ( ! content ) {
			return;
		}

		if ( activeRequest ) {
			activeRequest.controller.abort();
		}

		const requestId = ++requestSequence;
		const controller = new AbortController();
		activeRequest = { id: requestId, controller };

		content.classList.add( 'loading' );

		// Update the calendar's data-scope attribute.
		if ( scope ) {
			calendar.setAttribute( 'data-scope', scope );
		} else {
			calendar.removeAttribute( 'data-scope' );
		}

		// Build REST API URL.
		const params = new URLSearchParams();
		params.set( 'archive_taxonomy', 'location' );
		params.set( 'archive_term_id', termId );
		if ( scope ) {
			params.set( 'scope', scope );
		}

		// Preserve user-owned filters from the navigation snapshot.
		FILTER_QUERY_KEYS.forEach( function ( key ) {
			const val = urlParams.get( key );
			if ( val ) {
				params.set( key, val );
			}
		} );

		// Taxonomy filters.
		for ( const pair of urlParams.entries() ) {
			if ( pair[ 0 ].indexOf( 'tax_filter[' ) === 0 ) {
				params.append( pair[ 0 ], pair[ 1 ] );
			}
		}

		// Archive context and opaque tokens are server-owned DOM state. Never
		// trust query-string copies of either value.
		const archiveTaxonomy = calendar.getAttribute(
			'data-archive-taxonomy'
		);
		const archiveTermId = calendar.getAttribute( 'data-archive-term-id' );
		if ( archiveTaxonomy && archiveTermId ) {
			params.set( 'archive_taxonomy', archiveTaxonomy );
			params.set( 'archive_term_id', archiveTermId );
		}

		const scopeToken = calendar.getAttribute( 'data-scope-token' );
		if ( scopeToken ) {
			params.set( 'scope_token', scopeToken );
		}

		const apiUrl =
			'/wp-json/datamachine/v1/events/calendar?' + params.toString();

		fetch( apiUrl, {
			method: 'GET',
			signal: controller.signal,
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				'X-Requested-With': 'XMLHttpRequest',
			},
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Network response was not ok' );
				}
				return response.json();
			} )
			.then( function ( data ) {
				if ( requestId !== requestSequence ) {
					return;
				}

				if ( data.success ) {
					// Swap events content.
					content.innerHTML = data.html;

					// A prior Load More hydration (data-machine-events
					// issue #314) may have converted the numbered
					// `.data-machine-events-pagination` nav into a
					// `.data-machine-events-load-more-nav`. Remove that
					// stale button before re-injecting fresh pagination
					// so the calendar's own `initLoadMore` can
					// re-hydrate it on the `content-updated` event we
					// dispatch below. Without this, a scope-tab switch
					// stacks a Load More button above re-injected
					// numbered pagination on location archive pages.
					const loadMoreNav = calendar.querySelector(
						'.data-machine-events-load-more-nav'
					);
					if ( loadMoreNav ) {
						loadMoreNav.remove();
					}

					// Update pagination.
					const paginationEl = calendar.querySelector(
						'.data-machine-events-pagination'
					);
					if ( data.pagination && data.pagination.html ) {
						if ( paginationEl ) {
							paginationEl.outerHTML = data.pagination.html;
						} else {
							content.insertAdjacentHTML(
								'afterend',
								data.pagination.html
							);
						}
					} else if ( paginationEl ) {
						paginationEl.remove();
					}

					// Update counter.
					const counterEl = calendar.querySelector(
						'.data-machine-events-results-counter'
					);
					if ( data.counter ) {
						if ( counterEl ) {
							counterEl.outerHTML = data.counter;
						} else {
							content.insertAdjacentHTML(
								'afterend',
								data.counter
							);
						}
					} else if ( counterEl ) {
						counterEl.remove();
					}

					// Update navigation.
					const navEl = calendar.querySelector(
						'.data-machine-events-past-navigation'
					);
					if ( data.navigation && data.navigation.html ) {
						if ( navEl ) {
							navEl.outerHTML = data.navigation.html;
						} else {
							calendar.insertAdjacentHTML(
								'beforeend',
								data.navigation.html
							);
						}
					} else if ( navEl ) {
						navEl.remove();
					}

					// Re-trigger lazy render for new content.
					triggerLazyRender( calendar );
				} else {
					renderRequestError( content, scope, termId, urlParams );
				}
			} )
			.catch( function ( error ) {
				if (
					error.name === 'AbortError' ||
					requestId !== requestSequence
				) {
					return;
				}

				renderRequestError( content, scope, termId, urlParams );
			} )
			.finally( function () {
				if ( requestId === requestSequence ) {
					content.classList.remove( 'loading' );
					activeRequest = null;
				}
			} );
	}

	/**
	 * Replace stale results with an explicit, retryable latest-request error.
	 * @param {Element}         content   Calendar content element.
	 * @param {string}          scope     Requested scope.
	 * @param {string}          termId    Location term ID.
	 * @param {URLSearchParams} urlParams Request filter snapshot.
	 */
	function renderRequestError( content, scope, termId, urlParams ) {
		const calendar = content.closest( '.data-machine-events-calendar' );
		if ( calendar ) {
			calendar
				.querySelectorAll(
					'.data-machine-events-pagination, .data-machine-events-load-more-nav, .data-machine-events-results-counter, .data-machine-events-past-navigation'
				)
				.forEach( function ( element ) {
					element.remove();
				} );
		}

		content.innerHTML =
			'<div class="data-machine-events-error"><p>Error loading events. Please try again.</p><button type="button" class="data-machine-events-retry">Try again</button></div>';

		const retry = content.querySelector( '.data-machine-events-retry' );
		if ( retry ) {
			retry.addEventListener( 'click', function () {
				fetchScopedCalendar(
					scope,
					termId,
					new URLSearchParams( urlParams.toString() )
				);
			} );
		}
	}

	/**
	 * Re-trigger lazy render on new content.
	 *
	 * The data-machine-events calendar uses IntersectionObserver-based lazy
	 * rendering. After swapping the DOM, we dispatch a custom event that
	 * the calendar's lazy-render module can listen for, or we simply
	 * re-observe the new elements.
	 * @param {Element} calendar The calendar container element.
	 */
	function triggerLazyRender( calendar ) {
		// The calendar's own JS re-initializes via MutationObserver or
		// we can dispatch a synthetic event for it to pick up.
		const event = new CustomEvent(
			'data-machine-calendar-content-updated',
			{
				bubbles: true,
				detail: { calendar },
			}
		);
		calendar.dispatchEvent( event );
	}
} )();
