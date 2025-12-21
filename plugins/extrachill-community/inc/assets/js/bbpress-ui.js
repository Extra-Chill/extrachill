/**
 * bbPress UI Handlers
 *
 * Shared logic for bbPress pages:
 * - Jump to latest button
 * - Reply link click handling (inline form for reply cards, scroll for lead topic)
 * - Inline reply: moves the existing Gutenberg editor to inline position (only one editor instance)
 */

document.addEventListener( 'DOMContentLoaded', function () {
	// Jump to latest reply button
	const jumpButton = document.querySelector( '#jump-to-latest' );
	if ( jumpButton ) {
		jumpButton.addEventListener( 'click', function () {
			const latestReplyUrl = this.getAttribute( 'data-latest-reply-url' );
			if ( latestReplyUrl ) {
				window.location.href = latestReplyUrl;
			}
		} );
	}

	// Get the bottom form wrapper (contains the Gutenberg editor)
	const bottomForm = document.getElementById( 'new-post' );
	const bottomFormWrapper = bottomForm ? bottomForm.closest( '.bbp-reply-form' ) : null;
	const bottomFormLegend = bottomFormWrapper ? bottomFormWrapper.querySelector( '.bbp-form > legend' ) : null;
	const originalLegendText = bottomFormLegend ? bottomFormLegend.textContent : null;
	let originalFormLocation = null;
	let activeReplyCard = null;
	let activeReplyId = null;

	// Create a placeholder to mark where the form was
	const placeholder = document.createElement( 'div' );
	placeholder.id = 'ec-form-placeholder';
	placeholder.style.display = 'none';

	// Create cancel button to add when form is moved inline
	function createCancelButton() {
		const btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'button-3 button-large ec-inline-reply-cancel';
		btn.textContent = 'Cancel';
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			restoreFormToBottom();
		} );
		return btn;
	}

	let cancelButton = null;

	function emitReplyDraftContextChange( previousReplyTo, nextReplyTo ) {
		if ( ! bottomForm ) {
			return;
		}

		const topicIdField = bottomForm.querySelector( 'input[name="bbp_topic_id"]' );
		const topicId = topicIdField ? parseInt( topicIdField.value, 10 ) : 0;
		if ( ! topicId ) {
			return;
		}

		document.dispatchEvent(
			new CustomEvent( 'extrachill:bbpressDraftContextChange', {
				detail: {
					type: 'reply',
					topicId,
					previousReplyTo,
					nextReplyTo,
				},
			} )
		);
	}

	function getReplyToFieldValue() {
		const replyToField = bottomForm ? bottomForm.querySelector( 'input[name="bbp_reply_to"]' ) : null;
		if ( ! replyToField ) {
			return 0;
		}

		const value = parseInt( replyToField.value, 10 );
		return Number.isFinite( value ) && value >= 0 ? value : 0;
	}

	function restoreFormToBottom() {
		if ( ! bottomFormWrapper || ! originalFormLocation ) {
			return;
		}

		const previousReplyTo = getReplyToFieldValue();

		if ( bottomFormLegend && originalLegendText ) {
			bottomFormLegend.textContent = originalLegendText;
		}

		// Remove cancel button
		if ( cancelButton && cancelButton.parentNode ) {
			cancelButton.remove();
		}

		// Reset the reply_to field to 0 (top-level reply)
		const replyToField = bottomForm ? bottomForm.querySelector( 'input[name="bbp_reply_to"]' ) : null;
		if ( replyToField ) {
			replyToField.value = '0';
		}

		emitReplyDraftContextChange( previousReplyTo, 0 );

		// Move form back to original location (replace placeholder)
		placeholder.parentNode.insertBefore( bottomFormWrapper, placeholder );
		placeholder.remove();

		// Remove inline styling class
		bottomFormWrapper.classList.remove( 'ec-inline-mode' );

		originalFormLocation = null;
		activeReplyCard = null;
		activeReplyId = null;
	}

	function moveFormInline( replyId, replyCard, replySlug ) {
		if ( ! bottomFormWrapper || ! bottomForm ) {
			return;
		}

		// If already at this reply, just focus the editor
		if ( activeReplyId === replyId && activeReplyCard === replyCard ) {
			const editor = bottomFormWrapper.querySelector( '.iso-editor' );
			if ( editor ) {
				editor.focus();
			}
			return;
		}

		const previousReplyTo = getReplyToFieldValue();

		// If form is already moved somewhere, restore it first
		if ( originalFormLocation ) {
			restoreFormToBottom();
		}

		// Mark the original location with placeholder
		bottomFormWrapper.parentNode.insertBefore( placeholder, bottomFormWrapper );
		originalFormLocation = placeholder;

		// Set the reply_to field to the parent reply ID
		const replyToField = bottomForm ? bottomForm.querySelector( 'input[name="bbp_reply_to"]' ) : null;
		if ( replyToField ) {
			replyToField.value = String( replyId );
		}

		emitReplyDraftContextChange( previousReplyTo, replyId );

		// Move the form after the reply card
		replyCard.insertAdjacentElement( 'afterend', bottomFormWrapper );

		// Add inline styling class
		bottomFormWrapper.classList.add( 'ec-inline-mode' );

		if ( bottomFormLegend && replySlug ) {
			bottomFormLegend.textContent = `Reply to @${ replySlug }`;
		}

		// Add cancel button if not already present
		if ( ! cancelButton ) {
			cancelButton = createCancelButton();
		}
		const submitButton = bottomForm ? bottomForm.querySelector( '.bbp-submit-button' ) : null;
		const actionsContainer = bottomForm ? bottomForm.querySelector( '.ec-reply-actions' ) : null;
		if ( submitButton ) {
			submitButton.classList.add( 'button-large' );
			if ( actionsContainer && cancelButton && ! actionsContainer.contains( cancelButton ) ) {
				actionsContainer.appendChild( cancelButton );
			}
		}

		// Track state
		activeReplyCard = replyCard;
		activeReplyId = replyId;

		// Scroll to the form
		bottomFormWrapper.scrollIntoView( { behavior: 'smooth', block: 'start' } );

		// Focus the editor
		setTimeout( function () {
			const editor = bottomFormWrapper.querySelector( '.iso-editor' );
			if ( editor ) {
				editor.focus();
			}
		}, 100 );
	}

	function scrollToBottomForm() {
		// If form is inline, restore it first
		if ( originalFormLocation ) {
			restoreFormToBottom();
		}

		if ( bottomForm ) {
			bottomForm.scrollIntoView( { behavior: 'smooth', block: 'start' } );

			// Focus the editor
			setTimeout( function () {
				const editor = bottomFormWrapper ? bottomFormWrapper.querySelector( '.iso-editor' ) : null;
				if ( editor ) {
					editor.focus();
				}
			}, 100 );
		}
	}

	// Unified click handler for all reply links
	document.addEventListener( 'click', function ( e ) {
		const replyLink = e.target.closest( '.bbp-reply-to-link' );
		if ( ! replyLink ) {
			return;
		}

		e.preventDefault();
		e.stopPropagation();

		// Check if inside a reply card
		const replyCard = replyLink.closest( '.bbp-reply-card' );

		// Lead topic has .is-lead-topic class - should use bottom form, not inline
		if ( replyCard && ! replyCard.classList.contains( 'is-lead-topic' ) ) {
			// Regular reply card → move form inline
			const replyIdString = replyCard.getAttribute( 'data-reply-id' );
			const replyId = replyIdString ? parseInt( replyIdString, 10 ) : 0;

			if ( replyId ) {
				const replySlug = replyLink.getAttribute( 'data-reply-slug' );
				moveFormInline( replyId, replyCard, replySlug );
			}
		} else {
			// Lead topic or no card → scroll to bottom form
			scrollToBottomForm();
		}
	} );
} );
