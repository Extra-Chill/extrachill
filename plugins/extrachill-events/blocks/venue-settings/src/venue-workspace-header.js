/**
 * External dependencies
 */
import {
	ActionRow,
	Badge,
	BlockShellHeader,
	Panel,
} from '@extrachill/components';

const STATUS_TONES = {
	administrator: 'info',
	active: 'success',
};

function VenueIdentityCard( { venue, onOpen } ) {
	const statusTone = STATUS_TONES[ venue.status ] || 'warning';
	const statusLabel = venue.status
		? venue.status.charAt( 0 ).toUpperCase() + venue.status.slice( 1 )
		: '';

	return (
		<Panel compact depth={ 2 }>
			<ActionRow align="between">
				<a
					href={ venue.archive_url }
					className={ `taxonomy-badge venue-badge venue-${ venue.slug }` }
				>
					{ venue.name }
				</a>
				<span>
					<Badge tone={ statusTone } variant="solid">
						{ statusLabel }
					</Badge>{ ' ' }
					{ venue.is_owner && (
						<Badge tone="success" variant="solid">
							Owner
						</Badge>
					) }{ ' ' }
					{ onOpen && (
						<button
							type="button"
							className="button-2"
							onClick={ onOpen }
						>
							Open { venue.name }
						</button>
					) }
				</span>
			</ActionRow>
		</Panel>
	);
}

export function VenueWorkspaceHeader( { venues, selected, onSwitchVenue } ) {
	const visibleVenues = selected ? [ selected ] : venues;

	return (
		<>
			<BlockShellHeader
				title="Manage venues"
				description="Manage every venue together or open one venue."
				actions={
					selected ? (
						<button
							type="button"
							className="button-2"
							onClick={ () => onSwitchVenue( 0 ) }
						>
							My Venues
						</button>
					) : null
				}
			/>
			{ visibleVenues.map( ( venue ) => (
				<VenueIdentityCard
					key={ venue.id }
					venue={ venue }
					onOpen={ selected ? null : () => onSwitchVenue( venue.id ) }
				/>
			) ) }
		</>
	);
}
