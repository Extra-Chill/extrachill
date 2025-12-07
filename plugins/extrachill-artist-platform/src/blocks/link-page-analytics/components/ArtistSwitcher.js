/**
 * ArtistSwitcher Component
 *
 * Dropdown for switching between user's artists.
 */

import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function ArtistSwitcher( { artistId, userArtists, onSwitch } ) {
	const handleChange = useCallback(
		( e ) => {
			const newId = parseInt( e.target.value, 10 );
			if ( newId && newId !== artistId ) {
				onSwitch( newId );
			}
		},
		[ artistId, onSwitch ]
	);

	if ( ! userArtists || userArtists.length <= 1 ) {
		return null;
	}

	return (
		<select
			className="ec-artist-switcher"
			value={ artistId }
			onChange={ handleChange }
			aria-label={ __( 'Select artist', 'extrachill-artist-platform' ) }
		>
			{ userArtists.map( ( artist ) => (
				<option key={ artist.id } value={ artist.id }>
					{ artist.name }
				</option>
			) ) }
		</select>
	);
}
