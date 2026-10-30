/**
 * Internal dependencies
 */
import type { AnalyticsDateRange } from '../../types/date-range';

export const MAX_DATE_RANGE_DAYS = 364;
export const DEFAULT_DATE_RANGE_DAYS = 28;
export const DATE_RANGE_PRESETS = [ 28, 84, 364 ] as const;

const DAY_IN_MILLISECONDS = 86400000;

const formatUtcDate = ( date: Date ): string =>
	date.toISOString().slice( 0, 10 );

const parseUtcDate = ( value: string ): Date =>
	new Date( `${ value }T00:00:00.000Z` );

export const getYesterday = ( today = new Date() ): string => {
	const yesterday = new Date(
		Date.UTC(
			today.getUTCFullYear(),
			today.getUTCMonth(),
			today.getUTCDate() - 1
		)
	);
	return formatUtcDate( yesterday );
};

export const createPresetRange = (
	days: number,
	today = new Date()
): AnalyticsDateRange => {
	const endDate = getYesterday( today );
	const start = parseUtcDate( endDate );
	start.setUTCDate( start.getUTCDate() - days + 1 );

	return { startDate: formatUtcDate( start ), endDate };
};

export const getRangeDays = ( range: AnalyticsDateRange ): number =>
	Math.round(
		( parseUtcDate( range.endDate ).getTime() -
			parseUtcDate( range.startDate ).getTime() ) /
			DAY_IN_MILLISECONDS
	) + 1;

export const getPresetDays = (
	range: AnalyticsDateRange,
	today = new Date()
): number | null => {
	for ( const days of DATE_RANGE_PRESETS ) {
		const preset = createPresetRange( days, today );
		if (
			preset.startDate === range.startDate &&
			preset.endDate === range.endDate
		) {
			return days;
		}
	}

	return null;
};

export const endsAfterYesterday = (
	range: AnalyticsDateRange,
	today = new Date()
): boolean => range.endDate > getYesterday( today );
