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
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { errorDetails, runAbility } from './api';
import { Status } from './status';

const RANGES = [
	{ id: 'this-week', label: 'This week' },
	{ id: 'this-month', label: 'This month' },
	{ id: 'next-month', label: 'Next month' },
	{ id: 'custom', label: 'Custom' },
];

const LAYOUTS = [
	{ id: 'daily', label: 'List by day' },
	{ id: 'calendar', label: 'Compact calendar' },
];

const ymd = ( date ) =>
	[
		date.getFullYear(),
		String( date.getMonth() + 1 ).padStart( 2, '0' ),
		String( date.getDate() ).padStart( 2, '0' ),
	].join( '-' );

/**
 * Resolve a quick range to concrete dates.
 *
 * "This week" is the next seven days rather than the calendar week: a venue
 * generating graphics on a Thursday wants tonight through next Wednesday,
 * not three leftover days.
 *
 * @param {string} id  Quick range id.
 * @param {Date}   now Reference date (today).
 * @return {{date_start: string, date_end: string}|null} Dates, or null for custom.
 */
export const quickRange = ( id, now = new Date() ) => {
	const today = new Date( now.getFullYear(), now.getMonth(), now.getDate() );
	if ( id === 'this-week' ) {
		const end = new Date( today );
		end.setDate( end.getDate() + 6 );
		return { date_start: ymd( today ), date_end: ymd( end ) };
	}
	if ( id === 'this-month' ) {
		return {
			date_start: ymd( today ),
			date_end: ymd(
				new Date( today.getFullYear(), today.getMonth() + 1, 0 )
			),
		};
	}
	if ( id === 'next-month' ) {
		return {
			date_start: ymd(
				new Date( today.getFullYear(), today.getMonth() + 1, 1 )
			),
			date_end: ymd(
				new Date( today.getFullYear(), today.getMonth() + 2, 0 )
			),
		};
	}
	return null;
};

/**
 * The layout the ability will use: an explicit pick wins, otherwise daily
 * lists for a week and the compact calendar for month-length ranges —
 * matching the server default so the toggle never lies.
 *
 * @param {string|null} layout Explicit layout pick, or null.
 * @param {string}      range  Quick range id.
 * @return {string} 'daily' or 'calendar'.
 */
export const effectiveLayout = ( layout, range ) => {
	if ( layout ) {
		return layout;
	}
	return range === 'this-month' || range === 'next-month'
		? 'calendar'
		: 'daily';
};

const triggerDownload = ( slide ) => {
	const link = document.createElement( 'a' );
	link.href = slide.data_uri;
	link.download = slide.filename;
	document.body.appendChild( link );
	link.click();
	document.body.removeChild( link );
};

export function GraphicsTab( { venue } ) {
	const [ range, setRange ] = useState( 'this-week' );
	const [ custom, setCustom ] = useState( { date_start: '', date_end: '' } );
	const [ layout, setLayout ] = useState( null );
	const [ title, setTitle ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ status, setStatus ] = useState( null );
	const [ result, setResult ] = useState( null );

	const layoutChoice = effectiveLayout( layout, range );
	const dates = range === 'custom' ? custom : quickRange( range );
	const ready =
		Boolean( dates && dates.date_start && dates.date_end ) && ! busy;

	const generate = async () => {
		setBusy( true );
		setStatus( null );
		try {
			const built = await runAbility( 'extrachill/venue-graphics-build', {
				venue_term_id: venue.id,
				date_start: dates.date_start,
				date_end: dates.date_end,
				layout: layoutChoice,
				...( title.trim() ? { title: title.trim() } : {} ),
			} );
			setResult( built );
			setStatus( {
				tone: 'success',
				message: `Made ${ built.slide_count } slide${
					built.slide_count === 1 ? '' : 's'
				} from ${ built.total_events } show${
					built.total_events === 1 ? '' : 's'
				}.`,
			} );
		} catch ( error ) {
			setResult( null );
			setStatus( {
				tone: 'error',
				message: errorDetails( error ).message,
			} );
		} finally {
			setBusy( false );
		}
	};

	const downloadAll = () => {
		( result?.slides || [] ).forEach( triggerDownload );
	};

	return (
		<Panel>
			<PanelHeader
				title="Promo graphics"
				description="Turn this venue's published calendar into Instagram-ready slides. Pick a range, generate, and download."
			/>

			<p className="ec-venue-settings__hint">
				Slides are built from events already live on the Extra Chill
				calendar and carry your venue logo when one is set on the Venue
				tab.
			</p>

			<div
				className="ec-venue-graphics__ranges"
				role="group"
				aria-label="Date range"
			>
				{ RANGES.map( ( option ) => (
					<button
						key={ option.id }
						type="button"
						aria-pressed={ range === option.id }
						className={
							range === option.id
								? 'button-1 button-small is-active'
								: 'button-3 button-small'
						}
						onClick={ () => setRange( option.id ) }
					>
						{ option.label }
					</button>
				) ) }
			</div>

			{ range === 'custom' && (
				<div className="ec-venue-graphics__custom">
					<FieldGroup
						label="From"
						htmlFor={ `venue-${ venue.id }-graphics-start` }
					>
						<input
							id={ `venue-${ venue.id }-graphics-start` }
							type="date"
							value={ custom.date_start }
							onChange={ ( event ) =>
								setCustom( {
									...custom,
									date_start: event.target.value,
								} )
							}
						/>
					</FieldGroup>
					<FieldGroup
						label="To"
						htmlFor={ `venue-${ venue.id }-graphics-end` }
					>
						<input
							id={ `venue-${ venue.id }-graphics-end` }
							type="date"
							value={ custom.date_end }
							onChange={ ( event ) =>
								setCustom( {
									...custom,
									date_end: event.target.value,
								} )
							}
						/>
					</FieldGroup>
				</div>
			) }

			<div
				className="ec-venue-graphics__layouts"
				role="group"
				aria-label="Layout"
			>
				{ LAYOUTS.map( ( option ) => (
					<button
						key={ option.id }
						type="button"
						aria-pressed={ layoutChoice === option.id }
						className={
							layoutChoice === option.id
								? 'button-1 button-small is-active'
								: 'button-3 button-small'
						}
						onClick={ () => setLayout( option.id ) }
					>
						{ option.label }
					</button>
				) ) }
			</div>

			<FieldGroup
				label="Title (optional)"
				htmlFor={ `venue-${ venue.id }-graphics-title` }
			>
				<input
					id={ `venue-${ venue.id }-graphics-title` }
					type="text"
					value={ title }
					placeholder={ `This Week at ${ venue.name }` }
					onChange={ ( event ) => setTitle( event.target.value ) }
				/>
			</FieldGroup>

			<Status state={ status } />

			<ActionRow>
				<button
					type="button"
					className="button-1"
					disabled={ ! ready }
					onClick={ generate }
				>
					{ busy ? 'Generating...' : 'Generate graphics' }
				</button>
				{ result && result.slides.length > 1 && (
					<button
						type="button"
						className="button-2"
						disabled={ busy }
						onClick={ downloadAll }
					>
						Download all
					</button>
				) }
			</ActionRow>

			{ result && (
				<ul className="ec-venue-graphics__slides">
					{ result.slides.map( ( slide, index ) => (
						<li
							key={ slide.filename }
							className="ec-venue-graphics__slide"
						>
							<img
								src={ slide.data_uri }
								alt={ `Slide ${ index + 1 } of ${
									result.slide_count
								}` }
							/>
							<a
								className="button-2 button-small"
								href={ slide.data_uri }
								download={ slide.filename }
							>
								Download slide { index + 1 }
							</a>
						</li>
					) ) }
				</ul>
			) }
		</Panel>
	);
}
