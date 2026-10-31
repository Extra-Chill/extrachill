const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const root = path.resolve( __dirname, '..' );
const sourceDirectory = path.join( root, 'src/blocks/image-voting' );
const buildDirectory = path.join( root, 'build/blocks/image-voting' );
const metadata = JSON.parse(
	fs.readFileSync( path.join( sourceDirectory, 'block.json' ), 'utf8' )
);

function readAssetDependencies() {
	const asset = fs.readFileSync(
		path.join( buildDirectory, 'index.asset.php' ),
		'utf8'
	);
	const dependencyList = asset.match(
		/'dependencies'\s*=>\s*array\(([^)]*)\)/
	);

	assert.ok(
		dependencyList,
		'index.asset.php must contain a dependencies array'
	);
	return [ ...dependencyList[ 1 ].matchAll( /'([^']+)'/g ) ].map(
		( match ) => match[ 1 ]
	);
}

function createEditorContext() {
	const registrations = new Map();
	let duplicateAttempts = 0;
	let registrationCalls = 0;

	const registerBlockType = ( name, behavior ) => {
		if ( registrations.has( name ) ) {
			duplicateAttempts++;
			return undefined;
		}

		registrationCalls++;
		const registration = { ...metadata, ...behavior };
		registrations.set( name, registration );
		return registration;
	};
	const wp = {
		blockEditor: { MediaUpload() {} },
		blocks: { registerBlockType },
		components: { Button() {}, TextControl() {} },
		element: {
			createElement() {},
			useEffect() {},
		},
	};
	const context = vm.createContext( { window: { wp }, wp } );

	return {
		context,
		registrations,
		getDuplicateAttempts: () => duplicateAttempts,
		getRegistrationCalls: () => registrationCalls,
	};
}

function runEditorBundle( context ) {
	const bundle = fs.readFileSync(
		path.join( buildDirectory, 'index.js' ),
		'utf8'
	);
	vm.runInContext( bundle, context );
}

test( 'build emits the complete editor dependency contract', () => {
	assert.deepEqual( readAssetDependencies(), [
		'wp-block-editor',
		'wp-blocks',
		'wp-components',
		'wp-element',
	] );
} );

test( 'build preserves canonical server metadata', () => {
	const builtMetadata = JSON.parse(
		fs.readFileSync( path.join( buildDirectory, 'block.json' ), 'utf8' )
	);
	assert.deepEqual( builtMetadata, metadata );
} );

test( 'wp-admin composes server metadata with client editor behavior', () => {
	const editor = createEditorContext();
	runEditorBundle( editor.context );

	const registration = editor.registrations.get( metadata.name );
	assert.equal( editor.getRegistrationCalls(), 1 );
	assert.equal( registration.title, metadata.title );
	assert.deepEqual( registration.attributes, metadata.attributes );
	assert.equal( typeof registration.edit, 'function' );
	assert.equal( typeof registration.save, 'function' );
} );

test( 'isolated embedded editors share one idempotent block registration', () => {
	const editor = createEditorContext();
	runEditorBundle( editor.context );

	const firstIsolatedEditor = editor.registrations.get( metadata.name );
	const secondIsolatedEditor = editor.registrations.get( metadata.name );
	assert.equal( firstIsolatedEditor, secondIsolatedEditor );
	assert.equal( editor.getRegistrationCalls(), 1 );

	// Re-evaluating an enqueued asset follows Gutenberg's duplicate guard.
	runEditorBundle( editor.context );
	assert.equal( editor.getRegistrationCalls(), 1 );
	assert.equal( editor.getDuplicateAttempts(), 1 );
} );
