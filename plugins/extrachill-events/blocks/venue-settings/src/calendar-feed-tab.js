/**
 * External dependencies
 */
import {
	ActionRow,
	FieldGroup,
	InlineStatus,
	Panel,
	PanelHeader,
} from '@extrachill/components';

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { errorDetails, runAbility } from './api';
import { Status } from './status';

const formatSynced = ( value ) => {
	if ( ! value ) {
		return 'Never';
	}
	const parsed = new Date( `${ value.replace( ' ', 'T' ) }Z` );
	return Number.isNaN( parsed.getTime() ) ? value : parsed.toLocaleString();
};

/**
 * Summarize what a sync actually did.
 *
 * Reporting real counts rather than a generic success message is what lets a
 * venue owner confirm the right calendar is connected — "Added 12 events" is
 * verifiable, "Sync complete" is not.
 *
 * @param {Object} result Sync counts returned by the sync ability.
 * @return {string} Human-readable summary of what changed.
 */
const summarize = ( result ) => {
	const parts = [];
	if ( result.created ) {
		parts.push( `${ result.created } added` );
	}
	if ( result.updated ) {
		parts.push( `${ result.updated } updated` );
	}
	if ( result.unchanged ) {
		parts.push( `${ result.unchanged } unchanged` );
	}
	if ( result.cancelled ) {
		parts.push( `${ result.cancelled } removed` );
	}
	if ( result.skipped ) {
		parts.push( `${ result.skipped } skipped` );
	}
	if ( result.excluded ) {
		parts.push(
			`${ result.excluded } private or unconfirmed, not imported`
		);
	}
	if ( ! parts.length ) {
		return 'No events found in the feed';
	}
	const summary = parts.join( ', ' );
	return result.created
		? `${ summary }. New events are awaiting review before they go public.`
		: summary;
};

export function CalendarFeedTab( { venue } ) {
	const [ feed, setFeed ] = useState( null );
	const [ url, setUrl ] = useState( '' );
	const [ status, setStatus ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ loadError, setLoadError ] = useState( '' );

	const load = async () => {
		setLoadError( '' );
		try {
			const result = await runAbility(
				'extrachill/get-venue-calendar-feed',
				{ venue_term_id: venue.id }
			);
			setFeed( result );
			setUrl( result.feed_url || '' );
		} catch ( error ) {
			setLoadError( errorDetails( error ).message );
		}
	};

	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ venue.id ] );

	const connect = async () => {
		setBusy( 'connect' );
		setStatus( null );
		try {
			const result = await runAbility(
				'extrachill/set-venue-calendar-feed',
				{ venue_term_id: venue.id, feed_url: url }
			);
			setFeed( result );
			setUrl( result.feed_url || '' );
			setStatus( {
				tone: 'success',
				message: `Calendar connected. Found ${
					result.event_count
				} importable event${
					result.event_count === 1 ? '' : 's'
				}. Import now to add them for review.`,
			} );
		} catch ( error ) {
			setStatus( {
				tone: 'error',
				message: errorDetails( error ).message,
			} );
		} finally {
			setBusy( '' );
		}
	};

	const sync = async () => {
		setBusy( 'sync' );
		setStatus( null );
		try {
			const result = await runAbility(
				'extrachill/sync-venue-calendar-feed',
				{ venue_term_id: venue.id }
			);
			setFeed( result );
			setStatus( { tone: 'success', message: summarize( result ) } );
		} catch ( error ) {
			setStatus( {
				tone: 'error',
				message: errorDetails( error ).message,
			} );
		} finally {
			setBusy( '' );
		}
	};

	const disconnect = async () => {
		setBusy( 'disconnect' );
		setStatus( null );
		try {
			const result = await runAbility(
				'extrachill/remove-venue-calendar-feed',
				{ venue_term_id: venue.id }
			);
			setFeed( result );
			setUrl( '' );
			setStatus( {
				tone: 'success',
				message:
					'Calendar disconnected. Events already imported were kept.',
			} );
		} catch ( error ) {
			setStatus( {
				tone: 'error',
				message: errorDetails( error ).message,
			} );
		} finally {
			setBusy( '' );
		}
	};

	if ( loadError ) {
		return (
			<Panel>
				<InlineStatus tone="error">{ loadError }</InlineStatus>
				<button type="button" className="button-2" onClick={ load }>
					Retry
				</button>
			</Panel>
		);
	}

	if ( ! feed ) {
		return (
			<Panel>
				<p>Loading calendar connection...</p>
			</Panel>
		);
	}

	let connectLabel = feed.bound ? 'Update calendar' : 'Connect calendar';
	if ( busy === 'connect' ) {
		connectLabel = 'Checking...';
	}

	return (
		<Panel>
			<PanelHeader
				title="Calendar feed"
				description="Connect this venue's calendar and its shows will be imported for review before they appear on Extra Chill."
			/>

			<p className="ec-venue-settings__hint">
				Entries marked private or confidential in your calendar, and
				anything still tentative or cancelled, are never imported.
				Everything else is added for review first, so nothing goes
				public until someone approves it.
			</p>

			{ feed.bound && feed.status === 'error' && (
				<InlineStatus tone="error">
					{ feed.last_error ||
						'This calendar could not be imported. Import it now to try again.' }
				</InlineStatus>
			) }

			<FieldGroup
				label="Public calendar address"
				htmlFor={ `venue-${ venue.id }-calendar-feed-url` }
			>
				<input
					id={ `venue-${ venue.id }-calendar-feed-url` }
					type="url"
					value={ url }
					placeholder="https://calendar.google.com/calendar/ical/.../public/basic.ics"
					onChange={ ( event ) => setUrl( event.target.value ) }
				/>
			</FieldGroup>

			<p className="ec-venue-settings__hint">
				In Google Calendar, open <strong>Settings</strong> for the
				calendar, scroll to <strong>Integrate calendar</strong>, and
				copy the <strong>Public address in iCal format</strong>. The
				calendar must be public. Apple Calendar and most ticketing
				platforms also publish an address ending in <code>.ics</code>.
			</p>

			{ feed.bound && (
				<dl className="ec-venue-settings__meta">
					<dt>Last imported</dt>
					<dd>{ formatSynced( feed.last_synced ) }</dd>
				</dl>
			) }

			<Status state={ status } />

			<ActionRow>
				<button
					type="button"
					className="button-1"
					disabled={ Boolean( busy ) || ! url.trim() }
					onClick={ connect }
				>
					{ connectLabel }
				</button>
				{ feed.bound && (
					<button
						type="button"
						className="button-2"
						disabled={ Boolean( busy ) }
						onClick={ sync }
					>
						{ busy === 'sync' ? 'Importing...' : 'Import now' }
					</button>
				) }
				{ feed.bound && (
					<button
						type="button"
						className="button-2"
						disabled={ Boolean( busy ) }
						onClick={ disconnect }
					>
						{ busy === 'disconnect'
							? 'Disconnecting...'
							: 'Disconnect' }
					</button>
				) }
			</ActionRow>
		</Panel>
	);
}
