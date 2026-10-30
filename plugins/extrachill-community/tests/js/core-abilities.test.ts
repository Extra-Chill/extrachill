jest.mock( '@wordpress/abilities', () => ( {
	executeAbility: jest.fn().mockResolvedValue( { user_id: 7 } ),
} ), { virtual: true } );
jest.mock( '@wordpress/core-abilities', () => ( {
	ready: Promise.resolve(),
} ), { virtual: true } );

import { executeAbility } from '../../src/abilities';
import { executeAbility as executeCoreAbility } from '@wordpress/abilities';

test( 'passes omitted settings input to core for schema defaults', async () => {
	await expect( executeAbility( 'extrachill/get-user-settings' ) ).resolves.toEqual( { user_id: 7 } );
	expect( executeCoreAbility ).toHaveBeenCalledWith( 'extrachill/get-user-settings', undefined );
} );
