/**
 * Cache-neutral experiment assignment and exposure lifecycle.
 *
 * Cached HTML contains only control metadata. This script asks the core
 * Abilities API for an assignment after page load, then reports viewport
 * exposure with the server-issued proof. DOM events are presentation notices;
 * trusted persistence consumers use the corresponding server-side hooks.
 */
( function () {
	var config = window.ecExperimentAssignment;
	if (
		! config ||
		! config.assignmentEndpoint ||
		! config.exposureEndpoint ||
		! window.fetch
	) {
		return;
	}

	var elements = document.querySelectorAll(
		'[data-ec-experiment-key][data-ec-experiment-surface]'
	);
	if ( ! elements.length ) {
		return;
	}

	function dispatch( name, element, detail ) {
		element.dispatchEvent(
			new CustomEvent( name, {
				bubbles: true,
				detail: {
					experiment_key: detail.experiment_key,
					definition_version: detail.definition_version,
					assignment_policy: detail.assignment_policy,
					variant: detail.variant,
					surface: detail.surface,
				},
			} )
		);
	}

	function recordExposure( element, detail, context ) {
		return window
			.fetch( config.exposureEndpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
				},
				body: JSON.stringify( {
					input: {
						experiment_key: detail.experiment_key,
						definition_version: detail.definition_version,
						assignment_policy: detail.assignment_policy,
						variant: detail.variant,
						surface: detail.surface,
						context: context,
						exposure_token: detail.exposure_token,
					},
				} ),
			} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Exposure rejected' );
				}
				return response.json();
			} )
			.then( function ( result ) {
				if ( result && result.accepted === true ) {
					dispatch( 'extrachill:experiment-exposure', element, detail );
				}
			} )
			.catch( function () {
				// Only server-validated exposures become trusted hooks or DOM notices.
			} );
	}

	function observeExposure( element, detail, context ) {
		if ( ! ( 'IntersectionObserver' in window ) ) {
			return;
		}

		var observer = new window.IntersectionObserver(
			function ( entries ) {
				if ( entries[ 0 ].isIntersecting && entries[ 0 ].intersectionRatio >= 0.5 ) {
					observer.disconnect();
					recordExposure( element, detail, context );
				}
			},
			{ threshold: [ 0.5 ] }
		);
		observer.observe( element );
	}

	function parseContext( element ) {
		try {
			var context = JSON.parse( element.getAttribute( 'data-ec-experiment-context' ) || '{}' );
			return context && typeof context === 'object' && ! Array.isArray( context )
				? context
				: {};
		} catch ( error ) {
			return {};
		}
	}

	function assign( element ) {
		var experimentKey = element.getAttribute( 'data-ec-experiment-key' ) || '';
		var surface = element.getAttribute( 'data-ec-experiment-surface' ) || '';
		var query = new URLSearchParams();
		query.set( 'input[experiment_key]', experimentKey );
		query.set( 'input[surface]', surface );
		var rawContext = parseContext( element );
		var context = {};
		Object.keys( rawContext ).forEach( function ( key ) {
			if ( [ 'string', 'number', 'boolean' ].includes( typeof rawContext[ key ] ) ) {
				context[ key ] = String( rawContext[ key ] );
				query.set( 'input[context][' + key + ']', context[ key ] );
			}
		} );

		window
			.fetch( config.assignmentEndpoint + '?' + query.toString(), {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' },
			} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Assignment unavailable' );
				}
				return response.json();
			} )
			.then( function ( result ) {
				if (
					! result ||
					result.measurement_eligible !== true ||
					result.experiment_key !== experimentKey ||
					result.surface !== surface ||
					! Number.isInteger( result.definition_version ) ||
					result.definition_version < 1 ||
					result.assignment_policy !== 'weighted_random' ||
					typeof result.variant !== 'string' ||
					typeof result.exposure_token !== 'string' ||
					! result.exposure_token
				) {
					return;
				}

				element.setAttribute( 'data-ec-experiment-variant', result.variant );
				dispatch( 'extrachill:experiment-assignment', element, result );
				observeExposure( element, result, context );
			} )
			.catch( function () {
				// Control markup remains unchanged and unmeasured on every failure.
			} );
	}

	for ( var i = 0; i < elements.length; i++ ) {
		assign( elements[ i ] );
	}
} )();
