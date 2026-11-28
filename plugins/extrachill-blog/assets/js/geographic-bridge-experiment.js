/**
 * Reveal inert geographic bridge candidates for measured treatment assignments.
 */
( function () {
	function activate( section ) {
		if ( ! section || ! section.querySelectorAll ) {
			return;
		}

		const candidates = section.querySelectorAll(
			'.extrachill-blog-geographic-bridge-candidates'
		);
		if ( ! candidates.length ) {
			return;
		}

		for ( let i = 0; i < candidates.length; i++ ) {
			candidates[ i ].removeAttribute( 'hidden' );
			candidates[ i ].removeAttribute( 'inert' );
			candidates[ i ].removeAttribute( 'aria-hidden' );
			candidates[ i ].style.display = 'contents';
		}
		section.removeAttribute( 'hidden' );
	}

	document.addEventListener( 'extrachill:experiment-assignment', function ( event ) {
		const detail = event.detail || {};
		if (
			detail.experiment_key !== 'geo-bridge-holdout' ||
			detail.surface !== 'single-post-bridge' ||
			detail.variant !== 'treatment'
		) {
			return;
		}

		activate( event.target );
	} );

	const assigned = document.querySelectorAll(
		'[data-ec-experiment-key="geo-bridge-holdout"]' +
			'[data-ec-experiment-surface="single-post-bridge"]' +
			'[data-ec-experiment-variant="treatment"]'
	);
	for ( let i = 0; i < assigned.length; i++ ) {
		activate( assigned[ i ] );
	}
} )();
