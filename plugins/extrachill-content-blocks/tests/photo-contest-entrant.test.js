const assert = require( 'node:assert/strict' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const createLoader = require( './helpers/load-typescript' );

function harness() {
	const storage = new Map();
	let poll;
	let state = [],
		cursor = 0,
		effects = [],
		response;
	const element = {
		createElement: ( type, props, ...children ) => ( {
			type,
			props: props || {},
			children,
		} ),
		useState: ( initial ) => {
			const index = cursor++;
			const slots = state;
			if ( ! ( index in slots ) )
				slots[ index ] =
					typeof initial === 'function' ? initial() : initial;
			return [
				slots[ index ],
				( value ) => {
					slots[ index ] =
						typeof value === 'function'
							? value( slots[ index ] )
							: value;
				},
			];
		},
		useRef: ( value ) => element.useState( { current: value } )[ 0 ],
		useEffect: ( effect, deps ) => {
			const index = cursor++;
			if (
				! state[ index ] ||
				deps.some( ( dep, i ) => dep !== state[ index ][ i ] )
			)
				effects.push( effect );
			state[ index ] = deps;
		},
	};
	const load = createLoader( {
		React: { Fragment: 'fragment' },
		document: { readyState: 'loading', addEventListener() {} },
		window: {
			location: { search: '' },
			setInterval( callback, delay ) {
				if ( delay === 15000 ) poll = callback;
			},
			clearInterval() {},
		},
		localStorage: {
			getItem: ( key ) => storage.get( key ) || null,
			setItem: ( key, value ) => storage.set( key, value ),
			removeItem: ( key ) => storage.delete( key ),
		},
		FormData: class {
			append() {}
		},
	}, {
		'@wordpress/element': element,
		'../focused-entry': { selectFocusedEntry: () => null },
		'@wordpress/api-fetch': {
			default: async () => {
				if ( response instanceof Error ) throw response;
				return response;
			},
		},
	} );
	const components = {
		...load( path.join( __dirname, '../src/blocks/photo-contest/components/PhotoContest.tsx' ) ),
		...load( path.join( __dirname, '../src/blocks/photo-contest/components/SubmissionForm.tsx' ) ),
	};
	return {
		storage,
		poll: () => poll(),
		components,
		response: ( value ) => {
			response = value;
		},
		render: ( component, props ) => {
			cursor = 0;
			effects = [];
			const tree = component( props );
			effects.forEach( ( effect ) => effect() );
			return tree;
		},
		reset: () => {
			state = [];
		},
	};
}
const config = {
	contestId: 'test',
	postId: 1,
	turnstileHtml: '',
	entriesOpenAt: '',
	entriesCloseAt: '',
	votingClosesAt: '',
	votingOpensAt: '',
};
const entry = {
	id: 7,
	display_name: 'Entrant',
	image_url: '/photo.jpg',
	vote_count: 12,
};
const key = 'extrachill_pc_entry_test';
function nodes( tree ) {
	if ( ! tree || typeof tree !== 'object' ) return [];
	if ( Array.isArray( tree ) ) return tree.flatMap( nodes );
	return [ tree, ...nodes( tree.children ) ];
}
const tick = () => new Promise( ( resolve ) => setImmediate( resolve ) );

for ( const duplicate of [ false, true ] ) {
	test( `remembers entry after ${
		duplicate ? 'already_entered' : 'successful submit'
	}`, async () => {
		const h = harness();
		h.response( { entries: [] } );
		h.render( h.components.PhotoContest, config );
		await tick();
		const parent = h.render( h.components.PhotoContest, config );
		const formProps = nodes( parent ).find(
			( node ) => node.type === h.components.SubmissionForm
		).props;
		h.reset();
		let form = h.render( h.components.SubmissionForm, formProps );
		nodes( form )
			.find( ( node ) => node.props.type === 'email' )
			.props.onChange( { target: { value: 'entrant@example.com' } } );
		form = h.render( h.components.SubmissionForm, formProps );
		nodes( form )
			.find( ( node ) => node.type === 'button' )
			.props.onClick();
		form = h.render( h.components.SubmissionForm, formProps );
		nodes( form )
			.find( ( node ) => node.props.placeholder === 'Display name' )
			.props.onChange( { target: { value: 'Entrant' } } );
		nodes( form )
			.find( ( node ) => node.props.type === 'file' )
			.props.onChange( { target: { files: [ {} ] } } );
		form = h.render( h.components.SubmissionForm, formProps );
		h.response(
			duplicate
				? Object.assign( new Error(), {
						code: 'already_entered',
						data: { entry },
				  } )
				: { entry }
		);
		await nodes( form )
			.find( ( node ) => node.type === 'button' )
			.props.onClick();
		assert.deepEqual( JSON.parse( h.storage.get( key ) ), { entryId: 7 } );
	} );
}

test( 'reload shows own photo and live count, hides form, and clears a stale or hidden entry', async () => {
	const h = harness();
	h.storage.set( key, JSON.stringify( { entryId: 7 } ) );
	h.response( { entries: [ entry ] } );
	h.render( h.components.PhotoContest, config );
	await tick();
	let tree = h.render( h.components.PhotoContest, config );
	assert.ok(
		nodes( tree ).some( ( node ) =>
			node.children.some(
				( c ) => typeof c === 'string' && c.startsWith( 'Your entry' )
			)
		)
	);
	assert.ok(
		nodes( tree ).some(
			( node ) =>
				node.props.className === 'vote-number' &&
				node.children.includes( 12 )
		)
	);
	assert.ok(
		nodes( tree ).some( ( node ) => node.props.src === '/photo.jpg' )
	);
	assert.ok(
		! nodes( tree ).some(
			( node ) => node.type === h.components.SubmissionForm
		)
	);
	h.response( { entries: [ { ...entry, vote_count: 19 } ] } );
	await h.poll();
	tree = h.render( h.components.PhotoContest, config );
	assert.ok(
		nodes( tree ).some(
			( node ) =>
				node.props.className === 'vote-number' &&
				node.children.includes( 19 )
		)
	);
	for ( const entries of [ [], [ { ...entry, hidden: true } ] ] ) {
		h.reset();
		h.storage.set( key, JSON.stringify( { entryId: 7 } ) );
		h.response( { entries } );
		h.render( h.components.PhotoContest, config );
		await tick();
		h.render( h.components.PhotoContest, config );
		assert.equal( h.storage.has( key ), false );
		tree = h.render( h.components.PhotoContest, config );
		assert.ok(
			nodes( tree ).some(
				( node ) => node.type === h.components.SubmissionForm
			)
		);
	}
} );
