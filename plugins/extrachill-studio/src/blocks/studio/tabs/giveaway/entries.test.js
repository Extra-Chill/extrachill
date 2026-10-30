/* global describe, it, expect */
/**
 * Internal dependencies
 */
import { filterEntries, pickWinners, randomIndex } from './entries';

const comment = ( id, username, mentions = [] ) => ( {
	id,
	author_username: username,
	mentions,
} );

const comments = [
	comment( 'c1', 'heavy', [ 'a' ] ),
	comment( 'c2', 'heavy', [ 'b' ] ),
	comment( 'c3', 'heavy', [ 'c' ] ),
	comment( 'c4', 'multi', [ 'a', 'b', 'c', 'd', 'e' ] ),
	comment( 'c5', 'late', [] ),
	comment( 'c6', 'late', [ 'f' ] ),
	comment( 'c7', 'lurker', [] ),
	comment( 'c8', 'Heavy', [ 'g' ] ),
];

const ids = ( entries ) => entries.map( ( entry ) => entry.id );

describe( 'giveaway entries', () => {
	it( 'counts every tagging comment by default', () => {
		const { entries, stats } = filterEntries( comments, {
			requireTag: true,
			minTags: 1,
		} );
		expect( ids( entries ) ).toEqual( [
			'c1',
			'c2',
			'c3',
			'c4',
			'c6',
			'c8',
		] );
		expect( stats ).toEqual( {
			entryMode: 'per_comment',
			totalComments: 8,
			validEntries: 6,
			filteredOut: 2,
			uniqueUsers: 3,
			entriesByUser: { heavy: 4, multi: 1, late: 1 },
		} );
	} );

	it( 'gives each qualifying person one entry in per_user mode', () => {
		const { entries, stats } = filterEntries( comments, {
			requireTag: true,
			minTags: 1,
			entryMode: 'per_user',
		} );
		expect( ids( entries ) ).toEqual( [ 'c1', 'c4', 'c6' ] );
		expect( stats.uniqueUsers ).toBe( 3 );
	} );

	it( 'applies min tags and exclusions per comment', () => {
		expect(
			ids(
				filterEntries( comments, { requireTag: true, minTags: 2 } )
					.entries
			)
		).toEqual( [ 'c4' ] );
		expect(
			ids(
				filterEntries( comments, { requireTag: true, minTags: 1 }, [
					'HEAVY',
				] ).entries
			)
		).toEqual( [ 'c4', 'c6' ] );
	} );

	it( 'draws distinct people and caps at the number of people', () => {
		const { entries } = filterEntries( comments, {
			requireTag: true,
			minTags: 1,
		} );
		for ( let i = 0; i < 50; i++ ) {
			const users = pickWinners( entries, 10 ).map( ( w ) =>
				w.comment.author_username.toLowerCase()
			);
			expect( users ).toHaveLength( 3 );
			expect( new Set( users ).size ).toBe( 3 );
		}
	} );

	it( 'reports the winning entry index in the filtered pool', () => {
		const { entries } = filterEntries( comments, {
			requireTag: true,
			minTags: 1,
		} );
		const [ winner ] = pickWinners( entries, 1, () => 3 );
		expect( winner.index ).toBe( 3 );
		expect( winner.comment.id ).toBe( 'c4' );
	} );

	it( 'returns unbiased indices within range', () => {
		for ( let i = 0; i < 200; i++ ) {
			const value = randomIndex( 37 );
			expect( value ).toBeGreaterThanOrEqual( 0 );
			expect( value ).toBeLessThan( 37 );
		}
	} );
} );
