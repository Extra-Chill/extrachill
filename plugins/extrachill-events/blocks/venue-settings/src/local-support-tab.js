/**
 * External dependencies
 */
import {
	ActionRow,
	Badge,
	Grid,
	InlineStatus,
	Panel,
	PanelHeader,
} from '@extrachill/components';

const STATUS = {
	not_seeking: { label: 'Not seeking', tone: 'muted' },
	open: { label: 'Seeking', tone: 'success' },
	paused: { label: 'Paused', tone: 'warning' },
	filled: { label: 'Filled', tone: 'info' },
	closed: { label: 'Closed', tone: 'muted' },
	cancelled: { label: 'Cancelled', tone: 'error' },
};

const formatDate = ( value ) => {
	const date = new Date( value.replace( ' ', 'T' ) );
	return Number.isNaN( date.getTime() )
		? value
		: new Intl.DateTimeFormat( undefined, {
				dateStyle: 'medium',
				timeStyle: 'short',
		  } ).format( date );
};

export function LocalSupportTab( { events = [] } ) {
	return (
		<Panel>
			<PanelHeader
				title="Local Support"
				description="Open and manage private local-opener opportunities for upcoming events. Publishing alone never opens a request."
			/>
			{ events.length === 0 ? (
				<InlineStatus tone="info">
					No upcoming events at this venue are available to your
					organizer identity.
				</InlineStatus>
			) : (
				<Grid minColumnWidth="16rem" gap="1rem">
					{ events.map( ( event ) => {
						const state =
							STATUS[ event.status ] || STATUS.not_seeking;
						return (
							<Panel key={ event.id } compact depth={ 2 }>
								<PanelHeader
									title={ event.title }
									description={ formatDate(
										event.start_datetime
									) }
									actions={
										<Badge tone={ state.tone }>
											{ state.label }
										</Badge>
									}
								/>
								<ActionRow align="between">
									<a href={ event.permalink }>View event</a>
									<a
										className="button-2"
										href={ event.workspace_url }
									>
										{ event.status === 'not_seeking'
											? 'Find local support'
											: 'Manage request' }
									</a>
								</ActionRow>
							</Panel>
						);
					} ) }
				</Grid>
			) }
		</Panel>
	);
}
