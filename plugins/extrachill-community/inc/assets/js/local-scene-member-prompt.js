( function () {
	'use strict';

	const prompt = document.getElementById( 'ec-local-scene-prompt' );
	const config = window.extrachillLocalScenePrompt;
	if ( ! prompt || ! config ) {
		return;
	}

	const form = prompt.querySelector( 'form' );
	const search = document.getElementById( 'ec-local-scene-search' );
	const slug = document.getElementById( 'ec-local-scene-slug' );
	const results = document.getElementById( 'ec-local-scene-results' );
	const visibility = document.getElementById( 'ec-local-scene-public' );
	const status = prompt.querySelector( '.ec-local-scene-prompt__status' );
	const dismiss = prompt.querySelector( '.ec-local-scene-prompt__dismiss' );
	let options = [];
	let activeIndex = -1;
	let timer;
	let requestId = 0;

	function ability( name, input, readonly = false ) {
		const abilityPath = name
			.split( '/' )
			.map( ( segment ) => encodeURIComponent( segment ) )
			.join( '/' );
		let url = config.abilitiesUrl + abilityPath + '/run';
		if ( readonly ) {
			const query = new URLSearchParams();
			Object.entries( input ).forEach( ( [ key, value ] ) => {
				query.set( `input[${ key }]`, String( value ) );
			} );
			url += '?' + query.toString();
		}

		return fetch( url, {
			method: readonly ? 'GET' : 'POST',
			credentials: 'same-origin',
			headers: {
				...( readonly ? {} : { 'Content-Type': 'application/json' } ),
				'X-WP-Nonce': config.restNonce,
			},
			...( readonly ? {} : { body: JSON.stringify( { input } ) } ),
		} ).then( async ( response ) => {
			const body = await response.json();
			if ( ! response.ok ) {
				throw new Error( body.message || 'Request failed.' );
			}
			return body;
		} );
	}

	function track( outcome ) {
		const data = new URLSearchParams( {
			action: 'extrachill_local_scene_prompt_analytics',
			nonce: config.analyticsNonce,
			outcome,
			visibility: visibility.checked ? '1' : '',
		} );
		fetch( config.analyticsUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: data,
		} ).catch( function () {} );
	}

	function closeResults() {
		results.hidden = true;
		search.setAttribute( 'aria-expanded', 'false' );
		search.removeAttribute( 'aria-activedescendant' );
		activeIndex = -1;
	}

	function choose( option ) {
		search.value = option.label;
		slug.value = option.slug;
		closeResults();
	}

	function setActive( index ) {
		activeIndex = index;
		results
			.querySelectorAll( '[role="option"]' )
			.forEach( ( element, optionIndex ) => {
				const selected = optionIndex === index;
				element.setAttribute(
					'aria-selected',
					selected ? 'true' : 'false'
				);
				if ( selected ) {
					search.setAttribute( 'aria-activedescendant', element.id );
					element.scrollIntoView( { block: 'nearest' } );
				}
			} );
	}

	function renderOptions( locations ) {
		options = locations.map( ( location ) => ( {
			slug: location.slug,
			label: location.hierarchy?.label || location.name,
		} ) );
		results.replaceChildren();
		options.forEach( ( option, index ) => {
			const element = document.createElement( 'div' );
			element.id = 'ec-local-scene-option-' + index;
			element.className = 'ec-local-scene-prompt__option';
			element.setAttribute( 'role', 'option' );
			element.setAttribute( 'aria-selected', 'false' );
			element.textContent = option.label;
			element.addEventListener( 'mousedown', ( event ) => {
				event.preventDefault();
				choose( option );
			} );
			results.appendChild( element );
		} );
		results.hidden = options.length === 0;
		search.setAttribute(
			'aria-expanded',
			options.length ? 'true' : 'false'
		);
	}

	search.addEventListener( 'input', function () {
		slug.value = '';
		window.clearTimeout( timer );
		const query = search.value.trim();
		if ( ! query ) {
			closeResults();
			return;
		}
		const currentRequest = ++requestId;
		timer = window.setTimeout( function () {
			ability(
				'extrachill/user-event-locations',
				{ mode: 'search', search: query, limit: 10 },
				true
			)
				.then( ( response ) => {
					if ( currentRequest === requestId ) {
						renderOptions( response.locations || [] );
					}
				} )
				.catch( () => {
					status.textContent = 'Unable to search Local Scenes.';
				} );
		}, 250 );
	} );

	search.addEventListener( 'keydown', function ( event ) {
		if ( results.hidden || ! options.length ) {
			return;
		}
		if ( event.key === 'ArrowDown' || event.key === 'ArrowUp' ) {
			event.preventDefault();
			const delta = event.key === 'ArrowDown' ? 1 : -1;
			setActive(
				( activeIndex + delta + options.length ) % options.length
			);
		} else if ( event.key === 'Enter' && activeIndex >= 0 ) {
			event.preventDefault();
			choose( options[ activeIndex ] );
		} else if ( event.key === 'Escape' ) {
			closeResults();
		}
	} );

	search.addEventListener( 'blur', () =>
		window.setTimeout( closeResults, 100 )
	);

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		if ( ! slug.value ) {
			status.textContent = 'Choose a Local Scene from the results.';
			search.focus();
			return;
		}
		form.querySelectorAll( 'button' ).forEach(
			( button ) => ( button.disabled = true )
		);
		ability( 'extrachill/update-user-settings', {
			local_scene: slug.value,
			local_scene_visibility: visibility.checked ? 'public' : 'private',
		} )
			.then( function () {
				track( 'completed' );
				prompt.remove();
			} )
			.catch( ( error ) => {
				status.textContent = error.message;
				form.querySelectorAll( 'button' ).forEach(
					( button ) => ( button.disabled = false )
				);
			} );
	} );

	dismiss.addEventListener( 'click', function () {
		dismiss.disabled = true;
		ability( 'extrachill/update-user-settings', {
			local_scene_prompt_dismissed: true,
		} )
			.then( function () {
				track( 'dismissed' );
				prompt.remove();
			} )
			.catch( ( error ) => {
				status.textContent = error.message;
				dismiss.disabled = false;
			} );
	} );
} )();
