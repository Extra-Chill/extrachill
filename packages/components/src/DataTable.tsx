import type { ReactNode } from 'react';

export interface DataTableColumn<T = Record<string, unknown>> {
	key: string;
	label: string;
	width?: string;
	render?: (value: unknown, row: T) => ReactNode;
}

export interface DataTableProps<T = Record<string, unknown>> {
	columns: DataTableColumn<T>[];
	data: T[];
	isLoading?: boolean;
	selectable?: boolean;
	selectedIds?: Array<string | number>;
	onSelectChange?: (ids: Array<string | number>) => void;
	emptyMessage?: string;
	rowKey?: string;
}

export function DataTable<T extends Record<string, unknown>>({
	columns,
	data,
	isLoading = false,
	selectable = false,
	selectedIds = [],
	onSelectChange = () => {},
	emptyMessage = 'No data found.',
	rowKey = 'id',
}: DataTableProps<T>) {
	const allSelected = data.length > 0 && selectedIds.length === data.length;

	const handleSelectAll = (e: React.ChangeEvent<HTMLInputElement>) => {
		if (e.target.checked) {
			onSelectChange(data.map((row) => row[rowKey] as string | number));
		} else {
			onSelectChange([]);
		}
	};

	const handleSelectRow = (id: string | number, checked: boolean) => {
		if (checked) {
			onSelectChange([...selectedIds, id]);
		} else {
			onSelectChange(selectedIds.filter((sid) => sid !== id));
		}
	};

	if (isLoading) {
		return (
			<div className="ec-data-table__loading">
				<span>Loading...</span>
			</div>
		);
	}

	if (data.length === 0) {
		return (
			<div className="ec-data-table__empty">
				<p>{emptyMessage}</p>
			</div>
		);
	}

	return (
		<table className="ec-data-table wp-list-table widefat fixed striped">
			<thead>
				<tr>
					{selectable && (
						<th className="ec-data-table__check-column">
							<input
								type="checkbox"
								checked={allSelected}
								onChange={handleSelectAll}
							/>
						</th>
					)}
					{columns.map((col) => (
						<th key={col.key} style={col.width ? { width: col.width } : undefined}>
							{col.label}
						</th>
					))}
				</tr>
			</thead>
			<tbody>
				{data.map((row) => (
					<tr key={row[rowKey] as string | number}>
						{selectable && (
							<td className="ec-data-table__check-column">
								<input
									type="checkbox"
									checked={selectedIds.includes(row[rowKey] as string | number)}
									onChange={(e) =>
										handleSelectRow(row[rowKey] as string | number, e.target.checked)
									}
								/>
							</td>
						)}
						{columns.map((col) => (
							<td key={col.key}>
								{col.render ? col.render(row[col.key], row) : (row[col.key] as ReactNode)}
							</td>
						))}
					</tr>
				))}
			</tbody>
		</table>
	);
}
