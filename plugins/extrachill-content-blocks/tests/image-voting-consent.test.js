const assert = require( 'node:assert/strict' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const createLoader = require( './helpers/load-typescript' );

// Minimal hook runtime: enough to render ImageVoting, run its effects, and
// re-render after state changes (same approach as the photo contest tests).
function harness() {
	const storage = new Map();
	const listeners = new Map();
	const requests = [];
	const state = [];
	let cursor = 0;
	let effects = [];
	const element = {
		createElement: ( type, props, ...children ) => ( {
			type,
			props: props || {},
			children,
		} ),
		createRoot: () => ( { render() {} } ),
		useState: ( initial ) => {
			const index = cursor++;
			if ( ! ( index in state ) ) {
				state[ index ] =
					typeof initial === 'function' ? initial() : initial;
			}
			return [
				state[ index ],
				( value ) => {
					state[ index ] =
						typeof value === 'function'
							? value( state[ index ] )
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
			) {
				effects.push( effect );
			}
			state[ index ] = deps;
		},
	};
	const load = createLoader(
		{
			React: { Fragment: 'fragment' },
			document: { readyState: 'loading', addEventListener() {} },
			Event: class {
				constructor( type ) {
					this.type = type;
				}
			},
			window: {
				addEventListener: ( type, fn ) => listeners.set( type, fn ),
				removeEventListener: ( type ) => listeners.delete( type ),
				dispatchEvent: ( event ) => listeners.get( event.type )?.(),
			},
			localStorage: {
				getItem: ( key ) => storage.get( key ) || null,
				setItem: ( key, value ) => storage.set( key, value ),
				removeItem: ( key ) => storage.delete( key ),
			},
			setTimeout: () => 0,
			clearTimeout() {},
		},
		{
			'@wordpress/element': element,
			'@wordpress/api-fetch': {
				default: async ( request ) => {
					requests.push( request );
					return { vote_count: 4 };
				},
			},
		}
	);
	const view = load(
		path.join( __dirname, '../src/blocks/image-voting/view.tsx' )
	);
	return {
		view,
		storage,
		requests,
		render: ( props ) => {
			cursor = 0;
			effects = [];
			const tree = view.ImageVoting( props );
			effects.forEach( ( effect ) => effect() );
			return tree;
		},
	};
}

const config = {
	title: 'Pick',
	voteCount: 3,
	mediaUrl: '',
	postId: 9,
	blockInstance: 'abc',
	isAdmin: false,
};

function nodes( tree ) {
	if ( ! tree || typeof tree !== 'object' ) {
		return [];
	}
	if ( Array.isArray( tree ) ) {
		return tree.flatMap( nodes );
	}
	return [ tree, ...nodes( tree.children ) ];
}
const tick = () => new Promise( ( resolve ) => setImmediate( resolve ) );
const text = ( node ) => node.children.join( '' );

test( 'image voting email field discloses the mailing list signup', () => {
	const h = harness();
	assert.equal(
		h.view.IMAGE_VOTING_EMAIL_DISCLOSURE,
		"You'll get Extra Chill emails at this address. Unsubscribe anytime."
	);
	let tree = h.render( config );
	nodes( tree )
		.find( ( node ) => node.children.includes( 'Vote' ) )
		.props.onClick();
	tree = h.render( config );
	assert.ok( nodes( tree ).some( ( node ) => node.props.type === 'email' ) );
	assert.ok(
		nodes( tree ).some(
			( node ) =>
				node.type === 'p' &&
				node.children.includes( h.view.IMAGE_VOTING_EMAIL_DISCLOSURE )
		)
	);
} );

test( 'image voting "Not you?" forgets the remembered email and asks again', async () => {
	const h = harness();
	h.storage.set( 'extrachill_voter_email', 'first@example.com' );
	let tree = h.render( config );
	nodes( tree )
		.find( ( node ) => node.children.includes( 'Vote' ) )
		.props.onClick();
	await tick();
	assert.equal( h.requests.length, 1, 'remembered email votes in one tap' );
	tree = h.render( config );
	assert.ok(
		nodes( tree ).some( ( node ) => node.children.includes( 'Voted ✓' ) )
	);
	const line = nodes( tree ).find(
		( node ) => node.type === 'p' && text( node ).startsWith( 'Voting as ' )
	);
	assert.ok( line.children.includes( 'first@example.com' ) );
	const notYou = nodes( line ).find( ( node ) => node.type === 'button' );
	assert.ok( notYou.children.includes( 'Not you?' ) );

	notYou.props.onClick();
	assert.equal( h.storage.has( 'extrachill_voter_email' ), false );
	tree = h.render( config );
	assert.equal(
		nodes( tree ).some(
			( node ) =>
				node.type === 'p' && text( node ).startsWith( 'Voting as ' )
		),
		false
	);
	nodes( tree )
		.find( ( node ) => node.children.includes( 'Vote' ) )
		.props.onClick();
	await tick();
	tree = h.render( config );
	assert.equal( h.requests.length, 1, 'next person is asked for an email' );
	assert.equal(
		nodes( tree ).find( ( node ) => node.props.type === 'email' ).props
			.value,
		''
	);
} );
