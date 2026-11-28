/**
 * External dependencies
 */
import {
	ActionRow,
	FieldGroup,
	Panel,
	PanelHeader,
} from '@extrachill/components';

/**
 * Internal dependencies
 */
import { Status } from './status';
import { sameDocument } from './state';

const PROFILE_FIELDS = [
	[ 'name', 'Venue name', true ],
	[ 'description', 'Description' ],
	[ 'address', 'Street address' ],
	[ 'city', 'City' ],
	[ 'state', 'State / region' ],
	[ 'zip', 'Postal code' ],
	[ 'country', 'Country' ],
	[ 'phone', 'Phone' ],
	[ 'website', 'Website' ],
	[ 'instagram', 'Instagram' ],
	[ 'capacity', 'Capacity' ],
];

// Optional fields render only when the canonical profile document carries
// them, so edits are never offered for a field the owner does not expose.
const OPTIONAL_PROFILE_FIELDS = [ 'instagram' ];

export const visibleProfileFields = ( profile ) =>
	PROFILE_FIELDS.filter(
		( [ key ] ) =>
			! OPTIONAL_PROFILE_FIELDS.includes( key ) ||
			Object.prototype.hasOwnProperty.call( profile || {}, key )
	);

const profileInputType = ( key ) => {
	if ( key === 'website' ) {
		return 'url';
	}
	if ( key === 'phone' ) {
		return 'tel';
	}
	return 'text';
};

export function ProfileTab( {
	profile,
	baseline,
	setProfile,
	onSave,
	saving,
	status,
	idPrefix = '',
} ) {
	const dirty = profile && baseline && ! sameDocument( profile, baseline );
	return (
		<Panel>
			<PanelHeader
				title="Public venue profile"
				description="These fields update the canonical Events venue profile."
			/>
			{ visibleProfileFields( profile ).map(
				( [ key, label, required ] ) => (
					<FieldGroup
						key={ key }
						label={ label }
						htmlFor={ `${ idPrefix }venue-profile-${ key }` }
						required={ required }
					>
						{ key === 'description' ? (
							<textarea
								id={ `${ idPrefix }venue-profile-${ key }` }
								rows="5"
								value={ profile[ key ] }
								onChange={ ( event ) =>
									setProfile( {
										...profile,
										[ key ]: event.target.value,
									} )
								}
							/>
						) : (
							<input
								id={ `${ idPrefix }venue-profile-${ key }` }
								type={ profileInputType( key ) }
								value={ profile[ key ] }
								required={ required }
								onChange={ ( event ) =>
									setProfile( {
										...profile,
										[ key ]: event.target.value,
									} )
								}
							/>
						) }
					</FieldGroup>
				)
			) }
			<Status state={ status } />
			<ActionRow>
				<button
					type="button"
					className="button-1"
					disabled={ ! dirty || saving || ! profile.name.trim() }
					onClick={ onSave }
				>
					{ saving ? 'Saving...' : 'Save profile' }
				</button>
				{ dirty && (
					<span className="ec-venue-settings__dirty">
						Unsaved profile changes
					</span>
				) }
			</ActionRow>
		</Panel>
	);
}
