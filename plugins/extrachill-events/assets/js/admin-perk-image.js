/**
 * RSVP perk pass artwork picker (extrachill#500).
 *
 * Wires the "Choose image" / "Remove" controls in the RSVP Perk meta box to
 * the core media modal. The chosen attachment ID goes into a hidden input
 * that extrachill_events_save_event_perk() stores on save.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		const box = $( '.extrachill-event-perk-image' );
		if ( ! box.length || ! window.wp || ! window.wp.media ) {
			return;
		}

		const input = box.find( '#extrachill_event_perk_image_id' );
		const preview = box.find( '.extrachill-event-perk-image__preview' );
		const removeButton = box.find( '.extrachill-event-perk-image__remove' );
		const strings = window.ecPerkImage || {};
		let frame = null;

		box.on( 'click', '.extrachill-event-perk-image__choose', function ( event ) {
			event.preventDefault();

			if ( ! frame ) {
				frame = window.wp.media( {
					title: strings.title || 'Choose pass artwork',
					button: { text: strings.button || 'Use this image' },
					library: { type: 'image' },
					multiple: false,
				} );

				frame.on( 'select', function () {
					const attachment = frame.state().get( 'selection' ).first().toJSON();
					const sizes = attachment.sizes || {};
					const url = ( sizes.medium || sizes.full || attachment ).url;

					input.val( attachment.id ).trigger( 'change' );
					preview.attr( 'src', url ).prop( 'hidden', false );
					removeButton.prop( 'hidden', false );
				} );
			}

			frame.open();
		} );

		removeButton.on( 'click', function ( event ) {
			event.preventDefault();
			input.val( '0' ).trigger( 'change' );
			preview.attr( 'src', '' ).prop( 'hidden', true );
			removeButton.prop( 'hidden', true );
		} );
	} );
} )( jQuery );
