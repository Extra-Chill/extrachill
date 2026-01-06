/**
 * DataTable Component
 *
 * Reusable table component for admin interfaces.
 */

import { CheckboxControl, Spinner } from '@wordpress/components';

export default function DataTable( {
	columns,
	data,
	isLoading = false,
	selectable = false,
	selectedIds = [],
	onSelectChange = () => {},
	emptyMessage = 'No data found.',
	rowKey = 'id',
} ) {
	const allSelected =
		data.length > 0 && selectedIds.length === data.length;
	const someSelected =
		selectedIds.length > 0 && selectedIds.length < data.length;

	const handleSelectAll = ( checked ) => {
		if ( checked ) {
			onSelectChange( data.map( ( row ) => row[ rowKey ] ) );
		} else {
			onSelectChange( [] );
		}
	};

	const handleSelectRow = ( id, checked ) => {
		if ( checked ) {
			onSelectChange( [ ...selectedIds, id ] );
		} else {
			onSelectChange( selectedIds.filter( ( sid ) => sid !== id ) );
		}
	};

	if ( isLoading ) {
		return (
			<div className="ec-data-table__loading">
				<Spinner />
				<span>Loading...</span>
			</div>
		);
	}

	if ( data.length === 0 ) {
		return (
			<div className="ec-data-table__empty">
				<p>{ emptyMessage }</p>
			</div>
		);
	}

	return (
		<table className="ec-data-table wp-list-table widefat fixed striped">
			<thead>
				<tr>
					{ selectable && (
						<th className="ec-data-table__check-column">
							<CheckboxControl
								checked={ allSelected }
								indeterminate={ someSelected }
								onChange={ handleSelectAll }
								__nextHasNoMarginBottom
							/>
						</th>
					) }
					{ columns.map( ( col ) => (
						<th key={ col.key } style={ col.width ? { width: col.width } : {} }>
							{ col.label }
						</th>
					) ) }
				</tr>
			</thead>
			<tbody>
				{ data.map( ( row ) => (
					<tr key={ row[ rowKey ] }>
						{ selectable && (
							<td className="ec-data-table__check-column">
								<CheckboxControl
									checked={ selectedIds.includes( row[ rowKey ] ) }
									onChange={ ( checked ) =>
										handleSelectRow( row[ rowKey ], checked )
									}
									__nextHasNoMarginBottom
								/>
							</td>
						) }
						{ columns.map( ( col ) => (
							<td key={ col.key }>
								{ col.render
									? col.render( row[ col.key ], row )
									: row[ col.key ] }
							</td>
						) ) }
					</tr>
				) ) }
			</tbody>
		</table>
	);
}
