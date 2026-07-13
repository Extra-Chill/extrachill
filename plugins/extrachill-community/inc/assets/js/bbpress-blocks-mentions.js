/**
 * Autocompleter for @-mentions in the Blocks Everywhere (Gutenberg) bbPress editor.
 *
 * Registers a custom completer via the `editor.Autocomplete.completers` filter
 * that hits the canonical `extrachill/users-search` REST endpoint and inserts
 * a link to the user's community profile when selected.
 *
 * Runs inside the BE iframe (where wp.* globals are available because BE
 * loads the editor scripts into the iframe document) and on non-iframe BE
 * renders. Vanilla ES5-compatible — no build step.
 *
 * @package
 */

( function () {
	'use strict';

	if ( ! window.wp || ! wp.hooks || ! wp.apiFetch || ! wp.element ) {
		return;
	}

	if ( window.extrachillBbpressMentionsRegistered ) {
		return;
	}
	window.extrachillBbpressMentionsRegistered = true;

	const apiFetch = wp.apiFetch;
	const element = wp.element;
	const createElement = element.createElement;
	const concatChildren = element.concatChildren;

	const SEARCH_PATH = '/extrachill/v1/users/search';

	function buildPath( term ) {
		return (
			SEARCH_PATH + '?context=mentions&term=' + encodeURIComponent( term )
		);
	}

	function normalizeUsers( users ) {
		if ( ! Array.isArray( users ) ) {
			return [];
		}
		return users
			.filter( function ( user ) {
				return user && user.slug;
			} )
			.map( function ( user ) {
				return {
					id: user.id,
					slug: user.slug,
					username: user.username,
					avatarUrl: user.avatar_url,
					profileUrl: user.profile_url,
				};
			} );
	}

	function getOptionLabel( user ) {
		const avatar = user.avatarUrl
			? createElement( 'img', {
					className: 'editor-autocompleters__user-avatar',
					alt: '',
					src: user.avatarUrl,
			  } )
			: createElement( 'span', {
					className: 'editor-autocompleters__no-avatar',
			  } );

		return concatChildren( [
			avatar,
			createElement(
				'span',
				{ className: 'editor-autocompleters__user-name' },
				'@' + user.slug
			),
			createElement(
				'span',
				{ className: 'editor-autocompleters__user-slug' },
				user.username
			),
		] );
	}

	function getMentionLink( user ) {
		const href = user.profileUrl || '/u/' + user.slug;
		return createElement(
			'a',
			{ href, className: 'ec-mention' },
			'@' + user.slug
		);
	}

	const mentionsCompleter = {
		name: 'extrachill-mentions',
		className: 'editor-autocompleters__user',
		triggerPrefix: '@',
		isDebounced: true,
		options( filterValue ) {
			if ( ! filterValue || filterValue.length < 2 ) {
				return [];
			}

			return apiFetch( { path: buildPath( filterValue ) } )
				.then( normalizeUsers )
				.catch( function () {
					return [];
				} );
		},
		getOptionKeywords( user ) {
			const values = [ user.slug, user.username ].filter( Boolean );
			return values.reduce( function ( acc, value ) {
				return acc.concat( String( value ).split( /\s+/ ) );
			}, [] );
		},
		getOptionLabel,
		getOptionCompletion( user ) {
			return {
				action: 'insert-at-caret',
				value: concatChildren( [ getMentionLink( user ), ' ' ] ),
			};
		},
	};

	wp.hooks.addFilter(
		'editor.Autocomplete.completers',
		'extrachill-community/mentions',
		function ( completers ) {
			completers = completers || [];
			// Avoid double-registration if the filter fires again with our
			// completer already present.
			const hasOurs = completers.some( function ( c ) {
				return c && c.name === 'extrachill-mentions';
			} );
			if ( hasOurs ) {
				return completers;
			}
			return completers.concat( [ mentionsCompleter ] );
		}
	);
} )();
