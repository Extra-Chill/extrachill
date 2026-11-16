import {
	buildSubscribersCsv,
	csvCell,
	downloadSubscribersCsv,
	subscribersCsvFilename,
} from '../../src/blocks/artist-manager/subscribers-csv';

const rows = [
	{
		email: 'fan@example.com',
		username: '',
		subscribed_at: '2026-11-14 04:01:11',
		exported: false,
		unsubscribe_url:
			'https://artist.example/wp-json/extrachill/v1/artists/subscribers/unsubscribe?token=abc.def',
	},
	{
		email: 'second@example.com',
		username: 'Say "hi"',
		subscribed_at: '2026-11-15 10:00:00',
		exported: true,
		unsubscribe_url:
			'https://artist.example/wp-json/extrachill/v1/artists/subscribers/unsubscribe?token=ghi.jkl',
	},
];

describe( 'subscriber CSV export', () => {
	test( 'includes a personal unsubscribe_url column for every subscriber', () => {
		const lines = buildSubscribersCsv( rows ).trim().split( '\r\n' );

		expect( lines[ 0 ] ).toBe(
			'email,username,subscribed_at,unsubscribe_url'
		);
		expect( lines ).toHaveLength( 3 );
		expect( lines[ 1 ] ).toBe(
			'"fan@example.com","","2026-11-14 04:01:11","https://artist.example/wp-json/extrachill/v1/artists/subscribers/unsubscribe?token=abc.def"'
		);
		expect( lines[ 2 ] ).toContain( '"Say ""hi"""' );
		expect( lines[ 2 ] ).toContain( 'token=ghi.jkl' );
	} );

	test( 'neutralizes cells a spreadsheet would run as formulas', () => {
		expect( csvCell( '=HYPERLINK("x")' ) ).toBe( '"\'=HYPERLINK(""x"")"' );
		expect( csvCell( null ) ).toBe( '""' );
	} );

	test( 'names the file after the artist and export date', () => {
		expect(
			subscribersCsvFilename( {
				artist_name: 'Futurebirds & Friends',
				export_date: '2026-11-20',
			} )
		).toBe( 'futurebirds-friends-subscribers-2026-11-20.csv' );
	} );

	test( 'downloads the rows from the export response', () => {
		const createObjectURL = jest.fn( () => 'blob:csv' );
		const revokeObjectURL = jest.fn();
		window.URL.createObjectURL = createObjectURL;
		window.URL.revokeObjectURL = revokeObjectURL;
		const click = jest
			.spyOn( HTMLAnchorElement.prototype, 'click' )
			.mockImplementation( () => {} );

		const count = downloadSubscribersCsv( {
			subscribers: rows,
			artist_name: 'Futurebirds',
			export_date: '2026-11-20',
		} );

		expect( count ).toBe( 2 );
		expect( createObjectURL ).toHaveBeenCalledWith( expect.any( Blob ) );
		expect( click ).toHaveBeenCalled();
		expect( revokeObjectURL ).toHaveBeenCalledWith( 'blob:csv' );
		click.mockRestore();
	} );
} );
