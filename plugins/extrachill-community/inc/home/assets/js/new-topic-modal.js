/**
 * New Topic Modal
 *
 * Handles modal open/close and accessibility.
 */
( function () {
	'use strict';

	const modal = document.getElementById( 'new-topic-modal' );
	const overlay = document.getElementById( 'new-topic-modal-overlay' );
	if ( ! modal || ! overlay ) {
		return;
	}

	const modalTrigger = document.getElementById( 'new-topic-modal-trigger' );
	const closeButton = modal.querySelector( '.new-topic-modal-close' );

	let activeTrigger = null;

	function showModal( triggerEl ) {
		activeTrigger = triggerEl;

		modal.classList.add( 'is-open' );
		overlay.classList.add( 'is-open' );
		document.body.classList.add( 'new-topic-modal-open' );

		const firstInput = modal.querySelector(
			'input[type="text"], textarea'
		);
		if ( firstInput ) {
			firstInput.focus();
		}

		document.addEventListener( 'keydown', trapFocus );
	}

	function openModal( e ) {
		e.preventDefault();
		showModal( e.currentTarget );
	}

	function closeModal() {
		modal.classList.remove( 'is-open' );
		overlay.classList.remove( 'is-open' );
		document.body.classList.remove( 'new-topic-modal-open' );
		document.removeEventListener( 'keydown', trapFocus );

		if ( activeTrigger ) {
			activeTrigger.focus();
		}

		activeTrigger = null;
	}

	function trapFocus( e ) {
		if ( e.key !== 'Tab' ) {
			if (
				e.key === 'Escape' &&
				! document.documentElement.classList.contains(
					'blocks-everywhere-editor-is-fullscreen'
				)
			) {
				closeModal();
			}
			return;
		}

		const focusableElements = modal.querySelectorAll(
			'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
		);
		const firstElement = focusableElements[ 0 ];
		const lastElement = focusableElements[ focusableElements.length - 1 ];

		const { activeElement } = modal.ownerDocument;

		if ( e.shiftKey && activeElement === firstElement ) {
			lastElement.focus();
			e.preventDefault();
			return;
		}

		if ( ! e.shiftKey && activeElement === lastElement ) {
			firstElement.focus();
			e.preventDefault();
		}
	}

	if ( modalTrigger ) {
		modalTrigger.addEventListener( 'click', openModal );
	}

	if ( closeButton ) {
		closeButton.addEventListener( 'click', closeModal );
	}

	overlay.addEventListener( 'click', closeModal );

	if ( modal.dataset.autoOpen === 'true' ) {
		showModal( modalTrigger );
	}
} )();
