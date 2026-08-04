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
				title="Manage venues"
				description="Manage every venue together or filter the workspace to one venue."
			/>
			{ venues.length > 0 && (
				<FieldGroup label="Venue workspace" htmlFor="venue-workspace">
					<select
						id="venue-workspace"
						value={ selected?.id || 0 }
						onChange={ onSwitchVenue }
					>
						<option value="0">My Venues</option>
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
