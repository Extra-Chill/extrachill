/** Core owns discovery, schema validation, defaults and transport. */
import { executeAbility as executeCoreAbility } from '@wordpress/abilities';
import { ready } from '@wordpress/core-abilities';

export async function executeAbility< T = unknown >(
	name: string,
	input?: unknown
): Promise< T > {
	await ready;
	return ( await executeCoreAbility( name, input ) ) as T;
}
