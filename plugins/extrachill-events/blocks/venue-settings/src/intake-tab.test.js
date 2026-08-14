/* global describe, expect, it */

/**
 * Internal dependencies
 */
import { hasValidFieldOrder } from './intake-field-order';

describe( 'booking custom field order', () => {
	it( 'allows independent fields in any order', () => {
		expect(
			hasValidFieldOrder( [
				{ key: 'draw', visible_when: null },
				{ key: 'genre', visible_when: null },
			] )
		).toBe( true );
	} );

	it( 'requires a controlling field to remain before its dependent field', () => {
		const controller = { key: 'event_type', visible_when: null };
		const dependent = {
			key: 'other_event',
			visible_when: { field: 'event_type', value: 'Other' },
		};
		expect( hasValidFieldOrder( [ controller, dependent ] ) ).toBe( true );
		expect( hasValidFieldOrder( [ dependent, controller ] ) ).toBe( false );
	} );
} );
