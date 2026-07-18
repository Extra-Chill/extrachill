'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );

const dashboardSource = fs.readFileSync( require.resolve( '../assets/js/artist-dispatch.js' ), 'utf8' );
const editorSource = fs.readFileSync( require.resolve( '../assets/js/artist-dispatch-editor.js' ), 'utf8' );

let clickHandler;
let requests = 0;
let destination = '';
let createRequest;
const message = { textContent: '' };
const button = {
	dataset: { artist: '42' },
	disabled: false,
	addEventListener( event, callback ) {
		if ( event === 'click' ) {
			clickHandler = callback;
		}
	},
};
const apiFetch = options => {
	requests++;
	createRequest = options;
	return Promise.resolve( { id: 91 } );
};
apiFetch.use = () => {};
apiFetch.createNonceMiddleware = () => () => {};

const context = {
	window: {
		wp: { apiFetch },
		ecArtistDispatch: { nonce: 'nonce', termsVersion: 'v1', writeUrl: '/submit/write/' },
		location: { assign: value => { destination = value; } },
	},
	document: {
		getElementById( id ) {
			if ( id === 'artist-dispatch-request' ) return null;
			if ( id === 'artist-dispatch-new' ) return button;
			if ( id === 'artist-dispatch-new-message' ) return message;
			return null;
		},
	},
	console,
};
vm.runInNewContext( dashboardSource, context );
assert.equal( requests, 0, 'reload/mount must not create a post' );
clickHandler();
clickHandler();

setImmediate( () => {
	assert.equal( requests, 1, 'double click shares exactly one in-flight create' );
	assert.equal( createRequest.headers[ 'X-Extra-Chill-Artist-Dispatch' ], 'create', 'create uses explicit validated server intent' );
	assert.equal( Object.hasOwn( createRequest.data, 'meta' ), false, 'browser never authors provenance' );
	assert.equal( destination, '/submit/write/?post=91', 'successful create navigates to the positive draft ID' );
	assert.match( editorSource, /window\.wp\.editor\.PostTitle/, 'title is owned by core/editor' );
	assert.match( editorSource, /savePost\(\)/, 'explicit saves use core/editor savePost' );
	assert.match( editorSource, /status: 'pending'/, 'review submission uses native pending status' );
	assert.doesNotMatch( editorSource, /setInterval|autosaves\//, 'host adds no custom autosave loop or route' );
	console.log( 'All Artist Dispatch JavaScript tests passed.' );
} );
