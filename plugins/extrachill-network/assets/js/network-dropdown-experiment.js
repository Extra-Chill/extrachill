/** Reveal only measured treatment candidates; hidden controls have no exposure box. */
( function () {
	function update( element ) {
		if ( ! element || ! element.matches || ! element.matches(
			'[data-ec-experiment-key="network-dropdown-everywhere"]' +
			'[data-ec-experiment-surface="breadcrumb-network-dropdown"]'
		) ) {
			return;
		}
		const treatment = element.getAttribute( 'data-ec-experiment-variant' ) === 'treatment';
		element.hidden = ! treatment;
		element.toggleAttribute( 'inert', ! treatment );
		if ( treatment ) {
			element.removeAttribute( 'aria-hidden' );
		} else {
			element.setAttribute( 'aria-hidden', 'true' );
		}
	}

	document.addEventListener( 'extrachill:experiment-assignment', function ( event ) {
		update( event.target );
	} );
	document.querySelectorAll( '.ec-network-dropdown-experiment' ).forEach( update );
} )();
