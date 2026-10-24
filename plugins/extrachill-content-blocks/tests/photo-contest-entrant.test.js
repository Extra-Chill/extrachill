const assert = require( 'node:assert/strict' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const createLoader = require( './helpers/load-typescript' );

function harness() {
	const storage = new Map();
	let poll;
	const requests = [];
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
	const load = createLoader(
		{
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
			URL: {
				createObjectURL: () => 'blob:preview',
				revokeObjectURL() {},
			},
			FormData: class {
				constructor() {
					this.fields = {};
				}
				append( key, value ) {
					this.fields[ key ] = value;
				}
			},
		},
		{
			'@wordpress/element': element,
			'../focused-entry': { selectFocusedEntry: () => null },
			'@wordpress/api-fetch': {
				default: async ( request ) => {
					requests.push( request );
					if ( response instanceof Error ) throw response;
					return response;
				},
			},
		}
	);
	const components = {
		...load(
			path.join(
				__dirname,
				'../src/blocks/photo-contest/components/PhotoContest.tsx'
			)
		),
		...load(
			path.join(
				__dirname,
				'../src/blocks/photo-contest/components/SubmissionForm.tsx'
			)
		),
		...load(
			path.join(
				__dirname,
				'../src/blocks/photo-contest/components/EntryCard.tsx'
			)
		),
		...load(
			path.join(
				__dirname,
				'../src/blocks/photo-contest/components/ContestDialog.tsx'
			)
		),
		...load(
			path.join(
				__dirname,
				'../src/blocks/photo-contest/components/SharePanel.tsx'
			)
		),
	};
	return {
		storage,
		requests,
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

for ( const saved of [ false, true ] ) {
	test( `vote ${
		saved ? 'uses saved email immediately' : 'opens email dialog'
	}`, async () => {
		const h = harness();
		if ( saved )
			h.storage.set( 'extrachill_voter_email', 'voter@example.com' );
		h.response( { vote_count: 13 } );
		let count;
		const props = {
			config,
			entry,
			votingClosed: false,
			highlighted: false,
			onVoted: ( id, votes ) => {
				count = votes;
			},
			onHiddenChange() {},
		};
		let tree = h.render( h.components.EntryCard, props );
		nodes( tree )
			.find( ( node ) => node.children.includes( 'Vote' ) )
			.props.onClick();
		await tick();
		tree = h.render( h.components.EntryCard, props );
		let dialog = nodes( tree ).find(
			( node ) => node.type === h.components.ContestDialog
		);
		if ( saved ) {
			assert.equal( dialog, undefined );
		} else {
			assert.equal( dialog.props.open, true );
			assert.ok(
				nodes( dialog ).some( ( node ) =>
					node.children.includes( 'Vote for ' )
				)
			);
			nodes( dialog )
				.find( ( node ) => node.props.type === 'email' )
				.props.onChange( { target: { value: 'voter@example.com' } } );
			tree = h.render( h.components.EntryCard, props );
			nodes( tree )
				.find( ( node ) => node.type === 'form' )
				.props.onSubmit( { preventDefault() {} } );
			await tick();
			tree = h.render( h.components.EntryCard, props );
			assert.equal(
				nodes( tree ).some(
					( node ) => node.type === h.components.ContestDialog
				),
				false
			);
		}
		assert.equal( count, 13 );
		assert.equal( h.requests.length, 1 );
		assert.deepEqual(
			{ ...h.requests[ 0 ].data },
			{
				post_id: 1,
				contest_id: 'test',
				entry_id: 7,
				email_address: 'voter@example.com',
			}
		);
	} );
}
function nodes( tree ) {
	if ( ! tree || typeof tree !== 'object' ) return [];
	if ( Array.isArray( tree ) ) return tree.flatMap( nodes );
	return [ tree, ...nodes( tree.children ) ];
}
const tick = () => new Promise( ( resolve ) => setImmediate( resolve ) );

test( 'shared dialog opens natively and dismisses by close, Esc, or backdrop', () => {
	const h = harness();
	let closed = 0;
	const props = { open: false, onClose: () => closed++, children: 'Content' };
	let tree = h.render( h.components.ContestDialog, props );
	const dialog = {
		open: false,
		showModal() {
			this.open = true;
		},
		close() {
			this.open = false;
		},
	};
	tree.props.ref.current = dialog;
	tree = h.render( h.components.ContestDialog, { ...props, open: true } );
	assert.equal( dialog.open, true );
	nodes( tree )
		.find( ( node ) => node.props[ 'aria-label' ] === 'Close' )
		.props.onClick();
	tree.props.onCancel( { preventDefault() {} } );
	tree.props.onClose();
	const target = {
		getBoundingClientRect: () => ( {
			left: 10,
			right: 90,
			top: 10,
			bottom: 90,
		} ),
	};
	tree.props.onClick( {
		target,
		currentTarget: target,
		clientX: 0,
		clientY: 0,
	} );
	assert.equal( closed, 4 );
	tree.props.onClick( {
		target,
		currentTarget: target,
		clientX: 50,
		clientY: 50,
	} );
	assert.equal( closed, 4 );
	h.render( h.components.ContestDialog, props );
	assert.equal( dialog.open, false );
} );

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
		assert.equal(
			nodes( form ).some( ( node ) => node.props.type === 'email' ),
			false
		);
		const photo = { type: 'image/jpeg', name: 'costume.jpg' };
		nodes( form )
			.find( ( node ) => node.props.type === 'file' )
			.props.onChange( { target: { files: [ photo ] } } );
		form = h.render( h.components.SubmissionForm, formProps );
		assert.equal(
			nodes( form ).find(
				( node ) => node.type === h.components.ContestDialog
			).props.open,
			true
		);
		nodes( form )
			.find( ( node ) => node.props.type === 'email' )
			.props.onChange( { target: { value: 'entrant@example.com' } } );
		nodes( form )
			.find( ( node ) => node.props.placeholder === 'Name' )
			.props.onChange( { target: { value: 'Entrant' } } );
		form = h.render( h.components.SubmissionForm, formProps );
		h.response(
			duplicate
				? Object.assign( new Error(), {
						code: 'already_entered',
						data: { entry },
				  } )
				: { entry }
		);
		nodes( form )
			.find( ( node ) => node.type === 'form' )
			.props.onSubmit( { preventDefault() {} } );
		await tick();
		const request = h.requests.at( -1 );
		assert.equal(
			request.path,
			'/extrachill/v1/content-blocks/photo-contest/submit'
		);
		assert.equal( request.method, 'POST' );
		assert.deepEqual( request.body.fields, {
			post_id: '1',
			contest_id: 'test',
			email: 'entrant@example.com',
			display_name: 'Entrant',
			instagram: '',
			website: '',
			photo,
			turnstile_response: '',
		} );
		form = h.render( h.components.SubmissionForm, formProps );
		const dialog = nodes( form ).find(
			( node ) => node.type === h.components.ContestDialog
		);
		assert.ok(
			nodes( dialog ).some( ( node ) =>
				node.children.includes(
					duplicate
						? "You already entered, here's your entry"
						: "You're in, Entrant! 🎉"
				)
			)
		);
		assert.equal(
			nodes( dialog ).find(
				( node ) => node.type === h.components.SharePanel
			).props.initialShowQr,
			true
		);
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
