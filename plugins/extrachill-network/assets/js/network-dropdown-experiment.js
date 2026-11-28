/**
 * Swap breadcrumb arms after cache-neutral assignment.
 *
 * The measured wrapper holds the shared root prefix (visible in both arms), so
 * the shared runtime records exposure under an identical condition. Its
 * following siblings hold the control site crumb (visible by default) and the
 * hidden treatment dropdown; exactly one of them is shown per assigned arm.
 */
( function () {
	function setVisible( element, visible ) {
		element.hidden = ! visible;
		element.toggleAttribute( 'inert', ! visible );
		if ( visible ) {
			element.removeAttribute( 'aria-hidden' );
		} else {
			element.setAttribute( 'aria-hidden', 'true' );
		}
	}

	function update( element ) {
		if ( ! element || ! element.matches || ! element.matches(
			'[data-ec-experiment-key="network-dropdown-everywhere"]' +
			'[data-ec-experiment-surface="breadcrumb-network-dropdown"]'
		) ) {
			return;
		}
		const treatment = element.getAttribute( 'data-ec-experiment-variant' ) === 'treatment';
		let sibling = element.nextElementSibling;
		if ( sibling && sibling.matches( '.ec-network-dropdown-experiment-control' ) ) {
			setVisible( sibling, ! treatment );
			sibling = sibling.nextElementSibling;
		}
		if ( sibling && sibling.matches( '.ec-network-dropdown-experiment-treatment' ) ) {
			setVisible( sibling, treatment );
		}
	}

	document.addEventListener( 'extrachill:experiment-assignment', function ( event ) {
		update( event.target );
	} );
	document.querySelectorAll( '.ec-network-dropdown-experiment' ).forEach( update );
} )();
