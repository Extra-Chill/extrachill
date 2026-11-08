/**
 * SellerSwitcher - choose which artist, venue or promoter shop to manage.
 */

/**
 * WordPress dependencies
 */
import { useId } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { sellerKey, sellerLabel } from '../sellers';

export default function SellerSwitcher( { sellers, selected, onChange } ) {
	const selectId = useId();

	if ( ! Array.isArray( sellers ) || sellers.length <= 1 ) {
		return null;
	}

	const handleChange = ( e ) => {
		const next = sellers.find(
			( seller ) => sellerKey( seller ) === e.target.value
		);
		if ( next && sellerKey( next ) !== sellerKey( selected ) ) {
			onChange( next );
		}
	};

	return (
		<select
			id={ selectId }
			className="ec-toolbar__select"
			value={ sellerKey( selected ) }
			onChange={ handleChange }
			aria-label="Select shop"
		>
			{ sellers.map( ( seller ) => (
				<option
					key={ sellerKey( seller ) }
					value={ sellerKey( seller ) }
				>
					{ sellerLabel( seller ) }
				</option>
			) ) }
		</select>
	);
}
