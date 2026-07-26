/* global describe, expect, it */

/**
 * Internal dependencies
 */
import {
	editableConfig,
	normalizeKey,
	profileChanges,
	validateConfig,
} from './state';

const validConfig = () => ( {
	version: 1,
	enabled: true,
	intake: { version: 1, fields: [] },
	spaces: [ { key: 'main_room', name: 'Main Room', is_default: true } ],
	default_deal: {
		version: 1,
		type: 'custom',
		guarantee_cents: 0,
		revenue_share_basis_points: 0,
		revenue_share_basis: 'gross_ticket_sales',
		currency: 'USD',
	},
	ticket_provider_reference: null,
	marketing_channels: [],
	hold_ttl_minutes: 1440,
} );

describe( 'venue settings state', () => {
	it( 'removes read metadata before a complete config replacement', () => {
		expect(
			editableConfig( {
				...validConfig(),
				revision: 4,
				updated_at: '2026-07-26 12:00:00',
				updated_by_user_id: 8,
			} )
		).toEqual( validConfig() );
	} );

	it( 'sends only changed canonical profile fields', () => {
		const baseline = {
			term_id: 12,
			name: 'Room',
			city: 'Charleston',
			revision: 'abc',
		};
		expect(
			profileChanges( { ...baseline, name: 'The Room' }, baseline )
		).toEqual( { name: 'The Room' } );
	} );

	it( 'normalizes operator labels into bounded keys', () => {
		expect( normalizeKey( '  Main Stage / Upstairs  ' ) ).toBe(
			'main_stage_upstairs'
		);
	} );

	it( 'reports duplicate, incomplete, and out-of-range configuration', () => {
		const config = validConfig();
		config.spaces.push( { key: 'main_room', name: '', is_default: true } );
		config.intake.fields.push( {
			key: 'genre',
			label: 'Genre',
			type: 'select',
			required: false,
			options: [],
		} );
		config.hold_ttl_minutes = 1;
		expect( validateConfig( config ) ).toEqual(
			expect.arrayContaining( [
				'Each space needs a name and key.',
				'Space keys must be unique.',
				'Choose one default space.',
				'Select fields need at least one option.',
				'Hold duration must be between 5 minutes and 7 days.',
			] )
		);
	} );
} );
