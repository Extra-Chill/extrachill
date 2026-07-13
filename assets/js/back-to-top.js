( function () {
	const button = document.querySelector( '.back-to-top' );
	const target = document.getElementById( 'masthead' );

	if ( ! button || ! target ) {
		return;
	}

	let ticking = false;

	function updateVisibility() {
		const isVisible = window.scrollY > Math.max( 600, window.innerHeight );
		button.classList.toggle( 'is-visible', isVisible );
		button.setAttribute( 'aria-hidden', String( ! isVisible ) );
		button.tabIndex = isVisible ? 0 : -1;
		ticking = false;
	}

	window.addEventListener(
		'scroll',
		function () {
			if ( ! ticking ) {
				window.requestAnimationFrame( updateVisibility );
				ticking = true;
			}
		},
		{ passive: true }
	);

	button.addEventListener( 'click', function () {
		const reduceMotion = window.matchMedia(
			'(prefers-reduced-motion: reduce)'
		).matches;

		window.scrollTo( {
			top: 0,
			behavior: reduceMotion ? 'auto' : 'smooth',
		} );

		target.tabIndex = -1;
		target.focus( { preventScroll: true } );
		target.addEventListener(
			'blur',
			function () {
				target.removeAttribute( 'tabindex' );
			},
			{ once: true }
		);
	} );

	updateVisibility();
} )();
