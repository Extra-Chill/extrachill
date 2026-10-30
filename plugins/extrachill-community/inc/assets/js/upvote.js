/**
 * Upvote System Handler
 *
 * Handles upvoting/downvoting of forum topics and replies with optimistic UI updates
 * and server-side validation. Uses SVG sprite icons from extrachill.svg.
 */

( function () {
	function initUpvotes() {
		const extrachillCommunity = window.extrachillCommunity;
		if (
			! extrachillCommunity?.restNonce ||
			! extrachillCommunity?.restUrl
		) {
			return;
		}

		document.addEventListener( 'click', function ( e ) {
			const upvoteIcon = e.target.closest( '.upvote-icon' );
			if ( ! upvoteIcon ) {
				return;
			}

			e.preventDefault();
			e.stopPropagation();

			const postId = upvoteIcon.dataset.postId;
			const postType = upvoteIcon.dataset.type;
			if ( ! postId || ! postType ) {
				return;
			}

			const useElement = upvoteIcon.querySelector( 'use' );
			const upvoteContainer = upvoteIcon.closest( '.upvote' );
			const countSpan = upvoteContainer?.querySelector( '.upvote-count' );
			if ( ! useElement || ! countSpan ) {
				return;
			}

			const isUpvoted = upvoteIcon.dataset.upvoted === 'true';
			const currentCount = parseInt( countSpan.textContent, 10 ) || 0;

			const currentHref = useElement.getAttribute( 'href' );
			if ( ! currentHref ) {
				return;
			}

			const baseUrl = currentHref.substring(
				0,
				currentHref.lastIndexOf( '#' )
			);

			const updatedCount = isUpvoted
				? currentCount - 1
				: currentCount + 1;
			countSpan.textContent = String( updatedCount );
			upvoteIcon.dataset.upvoted = isUpvoted ? 'false' : 'true';
			useElement.setAttribute(
				'href',
				baseUrl +
					'#' +
					( isUpvoted ? 'circle-up-outline' : 'circle-up' )
			);

			fetch(
				new URL(
					'extrachill/v1/community/upvote',
					extrachillCommunity.restUrl
				).toString(),
				{
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': extrachillCommunity.restNonce,
					},
					body: JSON.stringify( {
						post_id: parseInt( postId, 10 ),
						type: postType,
					} ),
				}
			)
				.then( function ( response ) {
					if ( response.ok ) {
						return response.json();
					}

					return response.json().then( function ( err ) {
						return Promise.reject( err );
					} );
				} )
				.catch( function () {
					countSpan.textContent = String( currentCount );
					upvoteIcon.dataset.upvoted = isUpvoted ? 'true' : 'false';
					useElement.setAttribute(
						'href',
						baseUrl +
							'#' +
							( isUpvoted ? 'circle-up' : 'circle-up-outline' )
					);
				} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initUpvotes );
	} else {
		initUpvotes();
	}
} )();
