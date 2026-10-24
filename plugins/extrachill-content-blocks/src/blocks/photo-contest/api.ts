import apiFetch from '@wordpress/api-fetch';

/**
 * Run a write ability through core's Abilities REST runner (POST), sending
 * the moderator's REST nonce from the render config.
 * @param ability Namespaced ability name, e.g. "extrachill/photo-contest-hide".
 * @param input   Ability input matching its input schema.
 * @param nonce   REST nonce from the moderator config.
 */
export async function runAbility< T >(
	ability: string,
	input: Record< string, unknown >,
	nonce: string
): Promise< T > {
	return ( await apiFetch( {
		path: `/wp-abilities/v1/abilities/${ ability }/run`,
		method: 'POST',
		headers: { 'X-WP-Nonce': nonce },
		data: { input },
	} ) ) as T;
}

/**
 * Run a read-only ability through core's Abilities REST runner. Readonly
 * abilities execute over GET with the input as `input[...]` query params.
 * @param ability Namespaced ability name, e.g. "extrachill/photo-contest-list".
 * @param input   Ability input matching its input schema.
 * @param nonce   REST nonce from the moderator config.
 */
export async function runReadonlyAbility< T >(
	ability: string,
	input: Record< string, string | number | boolean >,
	nonce: string
): Promise< T > {
	const query = Object.entries( input )
		.map(
			( [ key, value ] ) =>
				`input%5B${ encodeURIComponent(
					key
				) }%5D=${ encodeURIComponent( String( value ) ) }`
		)
		.join( '&' );
	return ( await apiFetch( {
		path: `/wp-abilities/v1/abilities/${ ability }/run?${ query }`,
		headers: { 'X-WP-Nonce': nonce },
	} ) ) as T;
}

