/* global describe, expect, it */

import {
	linkCollectionValue,
	logicalIntakeRows,
	updateLinkCollection,
	updateLogicalLinkFields,
} from './booking-links';

const fields = [
	{ key: 'genre', type: 'text', label: 'Genre' },
	{ key: 'artist_url', type: 'url', label: 'Artist website' },
	{ key: 'press_links', type: 'url_list', label: 'Press links' },
	{ key: 'draw', type: 'number', label: 'Recent draw' },
];

describe( 'booking link collection compatibility', () => {
	it( 'presents legacy URL fields as one logical row', () => {
		const rows = logicalIntakeRows( fields );
		expect( rows ).toHaveLength( 3 );
		expect( rows[ 1 ].links.map( ( field ) => field.key ) ).toEqual( [
			'artist_url',
			'press_links',
		] );
	} );

	it( 'combines persisted values without discarding or duplicating links', () => {
		expect(
			linkCollectionValue( fields.slice( 1, 3 ), {
				artist_url: 'https://artist.example',
				press_links:
					'https://artist.example\nhttps://press.example/review',
			} )
		).toBe( 'https://artist.example\nhttps://press.example/review' );
	} );

	it( 'keeps every legacy key in the submission payload', () => {
		const value = 'https://artist.example\nhttps://press.example/review';
		expect(
			updateLinkCollection(
				fields.slice( 1, 3 ),
				{ genre: 'Rock' },
				value
			)
		).toEqual( {
			genre: 'Rock',
			artist_url: 'https://artist.example',
			press_links: 'https://press.example/review',
		} );
	} );

	it( 'preserves every legacy definition when the logical row changes', () => {
		const legacyFields = [
			{ key: 'music_links', type: 'url_list', required: true },
			{ key: 'social_links', type: 'url_list', required: false },
			{ key: 'video_links', type: 'url_list', required: false },
			{ key: 'press_links', type: 'url_list', required: false },
		];
		const updated = updateLogicalLinkFields( legacyFields, {
			label: 'Artist links',
		} );
		expect( updated.map( ( field ) => field.key ) ).toEqual(
			legacyFields.map( ( field ) => field.key )
		);
		expect( updated[ 0 ].label ).toBe( 'Artist links' );
		expect( updated.slice( 1 ) ).toEqual( legacyFields.slice( 1 ) );
	} );

	it( 'distributes large collections across legacy list capacity', () => {
		const legacyFields = [ 'music', 'social', 'video', 'press' ].map(
			( key ) => ( { key, type: 'url_list', required: false } )
		);
		const links = Array.from(
			{ length: 65 },
			( _, index ) => `https://example.com/${ index + 1 }`
		);
		const values = updateLinkCollection(
			legacyFields,
			{},
			links.join( '\n' )
		);
		expect( values.music.split( '\n' ) ).toHaveLength( 20 );
		expect( values.social.split( '\n' ) ).toHaveLength( 20 );
		expect( values.video.split( '\n' ) ).toHaveLength( 20 );
		expect( values.press.split( '\n' ) ).toHaveLength( 5 );
	} );
} );
