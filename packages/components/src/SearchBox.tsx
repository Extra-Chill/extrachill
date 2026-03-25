import { useState, type KeyboardEvent } from 'react';

export interface SearchBoxProps {
	value?: string;
	onSearch: (value: string) => void;
	placeholder?: string;
	onClear?: () => void;
}

export function SearchBox({
	value = '',
	onSearch,
	placeholder = 'Search...',
	onClear,
}: SearchBoxProps) {
	const [inputValue, setInputValue] = useState(value);

	const handleSearch = () => {
		onSearch(inputValue);
	};

	const handleClear = () => {
		setInputValue('');
		if (onClear) {
			onClear();
		} else {
			onSearch('');
		}
	};

	const handleKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
		if (e.key === 'Enter') {
			handleSearch();
		}
	};

	return (
		<div className="ec-search-box">
			<input
				type="text"
				value={inputValue}
				onChange={(e) => setInputValue(e.target.value)}
				placeholder={placeholder}
				onKeyDown={handleKeyDown}
			/>
			<button type="button" onClick={handleSearch}>
				Search
			</button>
			{inputValue && (
				<button type="button" onClick={handleClear}>
					Clear
				</button>
			)}
		</div>
	);
}
