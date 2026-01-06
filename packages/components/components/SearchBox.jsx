/**
 * SearchBox Component
 *
 * Reusable search input for admin interfaces.
 */

import { useState } from '@wordpress/element';
import { TextControl, Button } from '@wordpress/components';

export default function SearchBox( {
	value = '',
	onSearch,
	placeholder = 'Search...',
	onClear,
} ) {
	const [ inputValue, setInputValue ] = useState( value );

	const handleSearch = () => {
		onSearch( inputValue );
	};

	const handleClear = () => {
		setInputValue( '' );
		if ( onClear ) {
			onClear();
		} else {
			onSearch( '' );
		}
	};

	const handleKeyDown = ( e ) => {
		if ( e.key === 'Enter' ) {
			handleSearch();
		}
	};

	return (
		<div className="ec-search-box">
			<TextControl
				value={ inputValue }
				onChange={ setInputValue }
				placeholder={ placeholder }
				onKeyDown={ handleKeyDown }
				__nextHasNoMarginBottom
			/>
			<Button variant="secondary" onClick={ handleSearch }>
				Search
			</Button>
			{ inputValue && (
				<Button variant="tertiary" onClick={ handleClear }>
					Clear
				</Button>
			) }
		</div>
	);
}
