/**
 * Booking-owned venue-settings extension, loaded before the shell.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { addFilter, removeFilter } from '@wordpress/hooks';

/**
 * External dependencies
 */
import { InlineStatus, Panel } from '@extrachill/components';

/**
 * Internal dependencies
 */
import { errorDetails, runAbility } from './api';
import { BookingConsole } from './booking-console';
import { BookingFormTab } from './booking-form-tab';
import { BookingTab } from './booking-tab';
import { LoadingPanel } from './status';
import { editableConfig, sameDocument } from './state';

function BookingSettingsProvider( {
	context,
	venues,
	profiles,
	onDirtyChange,
	children,
} ) {
	const [ configs, setConfigs ] = useState( {} );
	const [ baselines, setBaselines ] = useState( {} );
	const [ revisions, setRevisions ] = useState( {} );
	const [ errors, setErrors ] = useState( {} );
	const [ statuses, setStatuses ] = useState( {} );
	const [ saving, setSaving ] = useState( {} );
	const requests = useRef( {} );
	const setVenueState = ( setter, id, value ) =>
		setter( ( current ) => ( { ...current, [ id ]: value } ) );
	const initialize = ( id, result ) => {
		const editable = editableConfig( result );
		setVenueState( setRevisions, id, result.revision );
		setVenueState( setConfigs, id, editable );
		setVenueState( setBaselines, id, editable );
	};
	const load = async ( venue ) => {
		const request = ( requests.current[ venue.id ] || 0 ) + 1;
		requests.current[ venue.id ] = request;
		setVenueState( setErrors, venue.id, null );
		try {
			const result = await runAbility(
				'extrachill/get-venue-booking-config',
				{
					venue_term_id: venue.id,
				}
			);
			if ( requests.current[ venue.id ] === request ) {
				initialize( venue.id, result );
			}
		} catch ( error ) {
			if ( requests.current[ venue.id ] === request ) {
				setVenueState(
					setErrors,
					venue.id,
					errorDetails( error ).message
				);
			}
		}
	};
	const save = async ( venue ) => {
		setVenueState( setSaving, venue.id, true );
		setVenueState( setStatuses, venue.id, null );
		try {
			const result = await runAbility(
				'extrachill/update-venue-booking-config',
				{
					venue_term_id: venue.id,
					expected_revision: revisions[ venue.id ],
					config: configs[ venue.id ],
				}
			);
			initialize( venue.id, result );
			setVenueState( setStatuses, venue.id, {
				tone: 'success',
				message: 'Venue settings saved.',
			} );
		} catch ( error ) {
			const details = errorDetails( error );
			setVenueState( setStatuses, venue.id, {
				tone: details.status === 409 ? 'warning' : 'error',
				message:
					details.status === 409
						? `${ details.message } Reload before saving again.`
						: details.message,
			} );
		} finally {
			setVenueState( setSaving, venue.id, false );
		}
	};
	useEffect( () => {
		venues.forEach( load );
		// Scope is immutable for this mount; switching navigates the full route.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );
	const dirty = venues.some(
		( venue ) =>
			configs[ venue.id ] &&
			baselines[ venue.id ] &&
			! sameDocument( configs[ venue.id ], baselines[ venue.id ] )
	);
	useEffect( () => {
		onDirtyChange( 'booking', Boolean( dirty ) );
		// The shell callback changes identity but reports the same state.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ dirty ] );
	return children( {
		renderCalendar: () => (
			<BookingConsole
				context={ context }
				venues={ venues }
				defaultDeals={ Object.fromEntries(
					venues.map( ( venue ) => [
						venue.id,
						configs[ venue.id ]?.default_deal,
					] )
				) }
			/>
		),
		renderSettings: ( { venue, tab, idPrefix } ) => {
			if ( errors[ venue.id ] ) {
				return (
					<Panel>
						<InlineStatus tone="error">
							{ errors[ venue.id ] }
						</InlineStatus>
						<button
							type="button"
							className="button-2"
							onClick={ () => load( venue ) }
						>
							Retry booking settings
						</button>
					</Panel>
				);
			}
			if ( ! configs[ venue.id ] ) {
				return <LoadingPanel label="Loading venue settings..." />;
			}
			const props = {
				config: configs[ venue.id ],
				baseline: baselines[ venue.id ],
				setConfig: ( value ) =>
					setVenueState( setConfigs, venue.id, value ),
				onSave: () => save( venue ),
				saving: Boolean( saving[ venue.id ] ),
				status: statuses[ venue.id ],
				idPrefix,
			};
			return tab === 'booking-form' ? (
				<BookingFormTab
					{ ...props }
					onInitializeConfig={ ( value ) => {
						setVenueState( setConfigs, venue.id, value );
						setVenueState( setBaselines, venue.id, value );
					} }
					bookingUrl={
						venue.booking_url || context.booking_url || ''
					}
					venueName={ venue.name }
					profile={ profiles[ venue.id ] }
				/>
			) : (
				<BookingTab { ...props } />
			);
		},
	} );
}

const CalendarPanel = ( { extension } ) => extension.renderCalendar();
const SettingsPanel = ( props ) => props.extension.renderSettings( props );

export function registerBookingSettings() {
	removeFilter(
		'extrachill.venueSettings.extensions',
		'extrachill/booking-settings'
	);
	removeFilter(
		'extrachill.venueSettings.tabs',
		'extrachill/booking-settings'
	);
	addFilter(
		'extrachill.venueSettings.extensions',
		'extrachill/booking-settings',
		( extensions ) => [
			...extensions,
			{ id: 'booking', component: BookingSettingsProvider },
		]
	);
	addFilter(
		'extrachill.venueSettings.tabs',
		'extrachill/booking-settings',
		( tabs ) => [
			{
				id: 'calendar',
				label: 'Bookings',
				extension: 'booking',
				scope: 'workspace',
				isVisible: ( { accessibleVenues } ) =>
					accessibleVenues.length > 0,
				component: CalendarPanel,
			},
			...tabs.filter( ( tab ) => tab.id !== 'team' ),
			...[
				[ 'booking-form', 'Booking Form' ],
				[ 'settings', 'Booking Rules' ],
			].map( ( [ id, label ] ) => ( {
				id,
				label,
				extension: 'booking',
				isVisible: ( { accessibleVenues } ) =>
					accessibleVenues.length > 0,
				component: SettingsPanel,
			} ) ),
			...tabs.filter( ( tab ) => tab.id === 'team' ),
		]
	);
}

registerBookingSettings();
