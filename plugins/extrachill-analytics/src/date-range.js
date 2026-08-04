/**
 * Framework-neutral analytics date-range runtime.
 *
 * Public API:
 * window.ExtraChillAnalyticsDateRange.create(input, options)
 *
 * `options.startDate` / `endDate` and all delivered values are canonical UTC
 * calendar dates (Y-m-d). The returned controller supports getRange, setRange,
 * setPreset({ days, endDate? }), clear, reset, and destroy.
 */

/**
 * External dependencies
 */
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.css';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './date-range.css';

const DEFAULT_MAX_DAYS = 364;
const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

function parseDate( value ) {
	if ( ! DATE_PATTERN.test( value || '' ) ) {
		return null;
	}

	const [ year, month, day ] = value.split( '-' ).map( Number );
	const date = new Date( Date.UTC( year, month - 1, day ) );
	return date.getUTCFullYear() === year &&
		date.getUTCMonth() === month - 1 &&
		date.getUTCDate() === day
		? date
		: null;
}

function formatDate( date ) {
	return [
		date.getFullYear(),
		String( date.getMonth() + 1 ).padStart( 2, '0' ),
		String( date.getDate() ).padStart( 2, '0' ),
	].join( '-' );
}

function rangeDays( startDate, endDate ) {
	return (
		Math.round(
			( parseDate( endDate ) - parseDate( startDate ) ) / 86400000
		) + 1
	);
}

function validateRange( startDate, endDate, maxDays ) {
	if ( ! parseDate( startDate ) || ! parseDate( endDate ) ) {
		throw new TypeError(
			'Date ranges require valid Y-m-d start and end values.'
		);
	}

	const days = rangeDays( startDate, endDate );
	if ( days < 1 || days > maxDays ) {
		throw new RangeError( `Date ranges must span 1 to ${ maxDays } days.` );
	}
}

function makeNavigationAccessible( instance, controls ) {
	controls.forEach( ( { element, handler } ) =>
		element.removeEventListener( 'keydown', handler )
	);
	controls.length = 0;

	[
		[
			instance.prevMonthNav,
			__( 'Previous month', 'extrachill-analytics' ),
		],
		[ instance.nextMonthNav, __( 'Next month', 'extrachill-analytics' ) ],
	].forEach( ( [ element, label ] ) => {
		element.setAttribute( 'role', 'button' );
		element.setAttribute( 'tabindex', '0' );
		element.setAttribute( 'aria-label', label );
		const handler = ( event ) => {
			if ( event.key === 'Enter' || event.key === ' ' ) {
				event.preventDefault();
				element.click();
			}
		};
		element.addEventListener( 'keydown', handler );
		controls.push( { element, handler } );
	} );
}

function create( input, options = {} ) {
	if ( ! ( input instanceof window.HTMLInputElement ) ) {
		throw new TypeError( 'Date range input must be an HTMLInputElement.' );
	}

	const maxDays = Math.min(
		DEFAULT_MAX_DAYS,
		Math.max( 1, Number( options.maxDays ) || DEFAULT_MAX_DAYS )
	);
	const initial =
		options.startDate || options.endDate
			? { startDate: options.startDate, endDate: options.endDate }
			: null;
	if ( initial ) {
		validateRange( initial.startDate, initial.endDate, maxDays );
	}

	let current = initial ? { ...initial } : null;
	let destroyed = false;
	const controls = [];
	const reportError = options.onError || ( () => {} );
	const picker = flatpickr( input, {
		mode: 'range',
		dateFormat: 'Y-m-d',
		allowInput: false,
		defaultDate: initial
			? [ initial.startDate, initial.endDate ]
			: undefined,
		onReady( _dates, _value, instance ) {
			makeNavigationAccessible( instance, controls );
		},
		onMonthChange( _dates, _value, instance ) {
			makeNavigationAccessible( instance, controls );
		},
		onYearChange( _dates, _value, instance ) {
			makeNavigationAccessible( instance, controls );
		},
		onChange( dates, _value, instance ) {
			if ( dates.length === 0 ) {
				current = null;
				options.onChange?.( null );
				return;
			}
			if ( dates.length !== 2 ) {
				return;
			}

			const range = {
				startDate: formatDate( dates[ 0 ] ),
				endDate: formatDate( dates[ 1 ] ),
			};
			try {
				validateRange( range.startDate, range.endDate, maxDays );
				current = range;
				options.onChange?.( { ...range } );
			} catch ( error ) {
				instance.clear( false );
				current = null;
				reportError( error );
			}
		},
	} );

	function assertActive() {
		if ( destroyed ) {
			throw new Error( 'Date range controller has been destroyed.' );
		}
	}

	function setRange( startDate, endDate, trigger = true ) {
		assertActive();
		validateRange( startDate, endDate, maxDays );
		current = { startDate, endDate };
		picker.setDate( [ startDate, endDate ], false );
		if ( trigger ) {
			options.onChange?.( { ...current } );
		}
	}

	return {
		picker,
		getRange: () => ( current ? { ...current } : null ),
		setRange,
		setPreset( preset ) {
			assertActive();
			const days = Math.max( 1, Number( preset?.days ) || 0 );
			if ( days > maxDays ) {
				throw new RangeError(
					`Date ranges must span 1 to ${ maxDays } days.`
				);
			}
			const end = parseDate(
				preset?.endDate || new Date().toISOString().slice( 0, 10 )
			);
			if ( ! end ) {
				throw new TypeError(
					'Preset endDate must be a valid Y-m-d value.'
				);
			}
			const start = new Date( end );
			start.setUTCDate( start.getUTCDate() - days + 1 );
			setRange(
				start.toISOString().slice( 0, 10 ),
				end.toISOString().slice( 0, 10 )
			);
		},
		clear() {
			assertActive();
			picker.clear();
		},
		reset() {
			assertActive();
			if ( initial ) {
				setRange( initial.startDate, initial.endDate );
			} else {
				picker.clear();
			}
		},
		destroy() {
			if ( destroyed ) {
				return;
			}
			controls.forEach( ( { element, handler } ) =>
				element.removeEventListener( 'keydown', handler )
			);
			controls.length = 0;
			destroyed = true;
			picker.destroy();
		},
	};
}

const ExtraChillAnalyticsDateRange = { create, maxDays: DEFAULT_MAX_DAYS };
window.ExtraChillAnalyticsDateRange = ExtraChillAnalyticsDateRange;

export default ExtraChillAnalyticsDateRange;
