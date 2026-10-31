const assert = require( 'node:assert/strict' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const createLoader = require( './helpers/load-typescript' );
const source = ( name ) =>
	path.join( __dirname, '../src/blocks/photo-contest', name );

test( 'schedule handles unset, invalid, past, future and exact boundaries', () => {
	const now = Date.parse( '2026-05-01T12:00:00Z' );
	class Clock extends Date {
		static now() { return now; }
	}
	const { isClosed, hasOpened } = createLoader( { Date: Clock } )(
		source( 'schedule.ts' )
	);
	for ( const value of [ '', 'invalid' ] ) {
		assert.equal( isClosed( value ), false );
		assert.equal( hasOpened( value ), true );
	}
	for ( const value of [ '2026-05-01T11:59:59Z', '2026-05-01T12:00:00Z' ] ) {
		assert.equal( isClosed( value ), true );
		assert.equal( hasOpened( value ), true );
	}
	assert.equal( isClosed( '2026-05-01T12:00:01Z' ), false );
	assert.equal( hasOpened( '2026-05-01T12:00:01Z' ), false );
} );

function storageHarness() {
	const values = new Map();
	const localStorage = {
		getItem: ( key ) => values.get( key ) ?? null,
		setItem: ( key, value ) => values.set( key, value ),
		removeItem: ( key ) => values.delete( key ),
	};
	return {
		values,
		api: createLoader( { localStorage } )( source( 'storage.ts' ) ),
	};
}

test( 'storage preserves shared email and contest-specific entrant and vote keys', () => {
	const { api, values } = storageHarness();
	assert.equal( api.getSavedEmail(), '' );
	api.saveEmail( 'entrant@example.com' );
	assert.equal( values.get( 'extrachill_voter_email' ), 'entrant@example.com' );
	assert.equal( api.getSavedEmail(), 'entrant@example.com' );
	assert.equal( api.entryStorageKey( 'one' ), 'extrachill_pc_entry_one' );
	assert.equal( api.votedStorageKey( 'one' ), 'extrachill_pc_voted_one' );
	assert.equal( api.getSavedEntryId( 'one' ), null );
	api.saveEntryId( 'one', 7 );
	assert.equal( values.get( 'extrachill_pc_entry_one' ), '{"entryId":7}' );
	assert.equal( api.getSavedEntryId( 'one' ), 7 );
	assert.equal( api.getSavedEntryId( 'two' ), null );
	api.saveEntryId( 'one', null );
	assert.equal( values.has( 'extrachill_pc_entry_one' ), false );
	api.rememberVote( 'one', 7 );
	api.rememberVote( 'one', 7 );
	api.rememberVote( 'one', 8 );
	assert.equal( values.get( 'extrachill_pc_voted_one' ), '[7,8]' );
	assert.deepEqual( Array.from( api.getVotedEntries( 'one' ) ), [ 7, 8 ] );
	assert.deepEqual( Array.from( api.getVotedEntries( 'two' ) ), [] );
} );

test( 'storage tolerates corrupt values and rejects invalid saved entry ids', () => {
	const { api, values } = storageHarness();
	for ( const raw of [ 'broken', 'null', '{}', '{"entryId":0}', '{"entryId":-1}', '{"entryId":1.5}', '{"entryId":"7"}', '{"entryId":9007199254740992}' ] ) {
		values.set( 'extrachill_pc_entry_one', raw );
		assert.equal( api.getSavedEntryId( 'one' ), null );
	}
	values.set( 'extrachill_pc_voted_one', 'broken' );
	assert.deepEqual( Array.from( api.getVotedEntries( 'one' ) ), [] );
} );

test( 'unavailable storage is optional for all reads and writes', () => {
	for ( const globals of [ {}, {
		localStorage: {
			getItem() { throw new Error( 'blocked' ); },
			setItem() { throw new Error( 'blocked' ); },
			removeItem() { throw new Error( 'blocked' ); },
		},
	} ] ) {
		const api = createLoader( globals )( source( 'storage.ts' ) );
		assert.equal( api.getSavedEmail(), '' );
		assert.equal( api.getSavedEntryId( 'one' ), null );
		assert.deepEqual( Array.from( api.getVotedEntries( 'one' ) ), [] );
		assert.doesNotThrow( () => api.saveEmail( 'entrant@example.com' ) );
		assert.doesNotThrow( () => api.saveEntryId( 'one', 7 ) );
		assert.doesNotThrow( () => api.saveEntryId( 'one', null ) );
		assert.doesNotThrow( () => api.rememberVote( 'one', 7 ) );
	}
} );

test( 'selectHotEntries returns top 3 visible entries with votes, ties by oldest', () => {
	const { selectHotEntries } = createLoader()( source( 'utils.ts' ) );
	const e = ( id, vote_count, hidden = false ) => ( { id, vote_count, hidden } );
	const hot = selectHotEntries( [ e( 1, 2 ), e( 2, 9, true ), e( 3, 5 ), e( 4, 0 ), e( 5, 5 ), e( 6, 1 ) ] );
	assert.deepEqual( hot.map( ( x ) => x.id ), [ 3, 5, 1 ] );
	assert.deepEqual( selectHotEntries( [ e( 1, 0 ), e( 2, 3, true ) ] ), [] );
} );
