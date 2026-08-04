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

const STATUS_TONES = {
	administrator: 'info',
	active: 'success',
};

export function VenueWorkspaceHeader( { venues, selected, onSwitchVenue } ) {
	const statusTone = STATUS_TONES[ selected?.status ] || 'warning';
	const statusLabel = selected?.status
		? selected.status.charAt( 0 ).toUpperCase() + selected.status.slice( 1 )
		: '';

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
						<a
							href={ selected.archive_url }
							className={ `taxonomy-badge venue-badge venue-${ selected.slug }` }
						>
							{ selected.name }
						</a>
						<span>
							<Badge tone={ statusTone } variant="solid">
								{ statusLabel }
							</Badge>{ ' ' }
							{ selected.is_owner && (
								<Badge tone="success" variant="solid">
									Owner
								</Badge>
							) }
						</span>
					</ActionRow>
				</Panel>
			) }
		</>
	);
}
