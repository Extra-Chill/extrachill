/**
 * Content Expansion for Truncated Content
 *
 * Provides Show More/Less toggle functionality for long content in:
 * - Recent activity feed (replies)
 * - Main blog comments feed
 */

document.addEventListener( 'DOMContentLoaded', function () {
	document.addEventListener( 'click', function ( e ) {
		const button = e.target.closest( '.read-more-toggle' );
		if ( ! button ) {
			return;
		}

		const replyId = button.dataset.replyId;
		const container = document.getElementById( `content-${ replyId }` );
		if ( ! container ) {
			return;
		}

		const preview = container.querySelector( '.content-preview' );
		const fullContent = container.querySelector( '.content-full' );
		const readMoreText = button.querySelector( '.read-more-text' );
		const readLessText = button.querySelector( '.read-less-text' );

		if ( ! preview || ! fullContent || ! readMoreText || ! readLessText ) {
			return;
		}

		if ( fullContent.classList.contains( 'collapsed' ) ) {
			preview.style.display = 'none';
			fullContent.style.height = `${ fullContent.scrollHeight }px`;
			fullContent.classList.remove( 'collapsed' );
			fullContent.classList.add( 'expanded' );
			readMoreText.style.display = 'none';
			readLessText.style.display = 'inline';
			return;
		}

		fullContent.style.height = '0';
		fullContent.classList.add( 'collapsed' );
		fullContent.classList.remove( 'expanded' );
		preview.style.display = 'block';
		readMoreText.style.display = 'inline';
		readLessText.style.display = 'none';
	} );
} );
