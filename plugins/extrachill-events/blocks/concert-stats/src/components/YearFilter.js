/**
 * YearFilter — Year selection dropdown wrapped in a canonical FieldGroup.
 *
 * @package ExtraChillEvents
 */

import { FieldGroup } from '@extrachill/components';

const YearFilter = ( { showsByYear, activeYear, onChange } ) => {
	if ( ! showsByYear || Object.keys( showsByYear ).length === 0 ) {
		return null;
	}

	const years = Object.entries( showsByYear ).sort( ( a, b ) => b[ 0 ] - a[ 0 ] );

	return (
		<FieldGroup label="Year" className="ec-concert-stats__year-filter-group">
			<select
				className="ec-concert-stats__year-filter"
				value={ activeYear || '' }
				onChange={ ( e ) => onChange( e.target.value ? parseInt( e.target.value, 10 ) : 0 ) }
			>
				<option value="">All Time</option>
				{ years.map( ( [ yr, count ] ) => (
					<option key={ yr } value={ yr }>
						{ yr } ({ count })
					</option>
				) ) }
			</select>
		</FieldGroup>
	);
};

export default YearFilter;
