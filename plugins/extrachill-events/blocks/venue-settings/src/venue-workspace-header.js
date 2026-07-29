/**
 * External dependencies
 */
import {
	ActionRow,
	Badge,
	BlockShellHeader,
	FieldGroup,
	Panel,
} from '@extrachill/components';

export function VenueWorkspaceHeader( { venues, selected, onSwitchVenue } ) {
	return (
		<>
			<BlockShellHeader
				title="Manage venue"
				description="Calendar, booking operations, profile, Local Support, access, and onboarding in one venue-scoped workspace."
			/>
			{ venues.length > 0 && (
				<FieldGroup label="Venue workspace" htmlFor="venue-workspace">
					<select
						id="venue-workspace"
						value={ selected?.id || 0 }
						onChange={ onSwitchVenue }
					>
						<option value="0">Choose a venue</option>
						{ venues.map( ( venue ) => (
							<option key={ venue.id } value={ venue.id }>
								{ venue.name }
								{ venue.status !== 'active'
									? ` (${ venue.status })`
									: '' }
							</option>
						) ) }
					</select>
				</FieldGroup>
			) }
			{ selected && (
				<Panel compact depth={ 2 }>
					<ActionRow align="between">
						<strong>{ selected.name }</strong>
						<span>
							<Badge>{ selected.status }</Badge>{ ' ' }
							{ selected.is_owner && (
								<Badge tone="success">owner</Badge>
							) }
						</span>
					</ActionRow>
				</Panel>
			) }
		</>
	);
}
