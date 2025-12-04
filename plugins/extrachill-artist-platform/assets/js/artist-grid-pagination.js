/**
 * Artist Grid Pagination
 *
 * Handles AJAX pagination for artist grid on homepage.
 * Uses fetch API with smooth transitions.
 *
 * @package ExtraChillArtistPlatform
 */

(function() {
	'use strict';

	const ArtistGridPagination = {
		init: function() {
			this.bindEvents();
		},

		bindEvents: function() {
			// Delegate pagination click events to document
			document.addEventListener('click', function(e) {
				const paginationLink = e.target.closest('.artist-grid-pagination .page-numbers');
				if (paginationLink && !paginationLink.classList.contains('current') && !paginationLink.classList.contains('dots')) {
					e.preventDefault();
					ArtistGridPagination.handlePaginationClick(paginationLink);
				}
			});
		},

		handlePaginationClick: function(link) {
			// Extract page number from URL
			const url = new URL(link.href);
			const page = url.searchParams.get('paged') || 1;

			// Find container and context data
			const paginationDiv = link.closest('.artist-grid-pagination');
			if (!paginationDiv) return;

			const featuredSection = paginationDiv.closest('.featured-artists-section');
			if (!featuredSection) return;

			const excludeUser = paginationDiv.dataset.excludeUser === 'true';

			this.loadPage(page, excludeUser, featuredSection, paginationDiv);
		},

		loadPage: function(page, excludeUser, featuredSection, paginationDiv) {
			const gridContainer = featuredSection.querySelector('.artist-cards-grid');
			if (!gridContainer) return;

			// Add loading state
			gridContainer.style.opacity = '0.5';
			gridContainer.style.pointerEvents = 'none';
			paginationDiv.style.opacity = '0.5';

			// Build form data for POST request
			const formData = new FormData();
			formData.append('action', 'ec_load_artist_page');
			formData.append('nonce', extraChillArtistPlatform.nonce);
			formData.append('page', page);
			formData.append('exclude_user_artists', excludeUser);

			fetch(extraChillArtistPlatform.ajaxUrl, {
				method: 'POST',
				body: formData
			})
			.then(response => response.json())
			.then(response => {
				if (response.success && response.data) {
					// Replace grid content
					gridContainer.outerHTML = response.data.html;

					// Replace pagination
					paginationDiv.innerHTML = response.data.pagination_html;

					// Smooth scroll to top of section
					featuredSection.scrollIntoView({
						behavior: 'smooth',
						block: 'start'
					});

					// Remove loading state from new elements
					const newGridContainer = featuredSection.querySelector('.artist-cards-grid');
					const newPaginationDiv = featuredSection.querySelector('.artist-grid-pagination');

					if (newGridContainer) {
						newGridContainer.style.opacity = '1';
						newGridContainer.style.pointerEvents = 'auto';
					}
					if (newPaginationDiv) {
						newPaginationDiv.style.opacity = '1';
					}
				} else {
					// Error handling - restore state
					gridContainer.style.opacity = '1';
					gridContainer.style.pointerEvents = 'auto';
					paginationDiv.style.opacity = '1';
				}
			})
			.catch(() => {
				// Fetch error - restore state
				gridContainer.style.opacity = '1';
				gridContainer.style.pointerEvents = 'auto';
				paginationDiv.style.opacity = '1';
			});
		}
	};

	// Initialize on DOM ready
	document.addEventListener('DOMContentLoaded', ArtistGridPagination.init.bind(ArtistGridPagination));

})();
