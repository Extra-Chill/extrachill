import React, { useEffect, useState } from 'react';
import {
	getArtistShippingAddress,
	updateArtistShippingAddress,
} from '../../shared/api/client';

const US_STATES = [
	{ value: '', label: 'Select State' },
	{ value: 'AL', label: 'Alabama' },
	{ value: 'AK', label: 'Alaska' },
	{ value: 'AZ', label: 'Arizona' },
	{ value: 'AR', label: 'Arkansas' },
	{ value: 'CA', label: 'California' },
	{ value: 'CO', label: 'Colorado' },
	{ value: 'CT', label: 'Connecticut' },
	{ value: 'DE', label: 'Delaware' },
	{ value: 'FL', label: 'Florida' },
	{ value: 'GA', label: 'Georgia' },
	{ value: 'HI', label: 'Hawaii' },
	{ value: 'ID', label: 'Idaho' },
	{ value: 'IL', label: 'Illinois' },
	{ value: 'IN', label: 'Indiana' },
	{ value: 'IA', label: 'Iowa' },
	{ value: 'KS', label: 'Kansas' },
	{ value: 'KY', label: 'Kentucky' },
	{ value: 'LA', label: 'Louisiana' },
	{ value: 'ME', label: 'Maine' },
	{ value: 'MD', label: 'Maryland' },
	{ value: 'MA', label: 'Massachusetts' },
	{ value: 'MI', label: 'Michigan' },
	{ value: 'MN', label: 'Minnesota' },
	{ value: 'MS', label: 'Mississippi' },
	{ value: 'MO', label: 'Missouri' },
	{ value: 'MT', label: 'Montana' },
	{ value: 'NE', label: 'Nebraska' },
	{ value: 'NV', label: 'Nevada' },
	{ value: 'NH', label: 'New Hampshire' },
	{ value: 'NJ', label: 'New Jersey' },
	{ value: 'NM', label: 'New Mexico' },
	{ value: 'NY', label: 'New York' },
	{ value: 'NC', label: 'North Carolina' },
	{ value: 'ND', label: 'North Dakota' },
	{ value: 'OH', label: 'Ohio' },
	{ value: 'OK', label: 'Oklahoma' },
	{ value: 'OR', label: 'Oregon' },
	{ value: 'PA', label: 'Pennsylvania' },
	{ value: 'RI', label: 'Rhode Island' },
	{ value: 'SC', label: 'South Carolina' },
	{ value: 'SD', label: 'South Dakota' },
	{ value: 'TN', label: 'Tennessee' },
	{ value: 'TX', label: 'Texas' },
	{ value: 'UT', label: 'Utah' },
	{ value: 'VT', label: 'Vermont' },
	{ value: 'VA', label: 'Virginia' },
	{ value: 'WA', label: 'Washington' },
	{ value: 'WV', label: 'West Virginia' },
	{ value: 'WI', label: 'Wisconsin' },
	{ value: 'WY', label: 'Wyoming' },
	{ value: 'DC', label: 'District of Columbia' },
];

const ShippingTab = ( { artistId } ) => {
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ success, setSuccess ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ address, setAddress ] = useState( {
		name: '',
		street1: '',
		street2: '',
		city: '',
		state: '',
		zip: '',
		country: 'US',
	} );

	useEffect( () => {
		if ( ! artistId ) {
			setLoading( false );
			return;
		}

		const load = async () => {
			setLoading( true );
			try {
				const data = await getArtistShippingAddress( artistId );
				if ( data?.address ) {
					setAddress( {
						name: data.address.name || '',
						street1: data.address.street1 || '',
						street2: data.address.street2 || '',
						city: data.address.city || '',
						state: data.address.state || '',
						zip: data.address.zip || '',
						country: 'US',
					} );
				}
			} catch ( err ) {
				setError( 'Could not load shipping address.' );
			} finally {
				setLoading( false );
			}
		};

		load();
	}, [ artistId ] );

	const handleSave = async () => {
		if ( ! address.name.trim() ) {
			setError( 'Name is required.' );
			return;
		}
		if ( ! address.street1.trim() ) {
			setError( 'Street address is required.' );
			return;
		}
		if ( ! address.city.trim() ) {
			setError( 'City is required.' );
			return;
		}
		if ( ! address.state ) {
			setError( 'State is required.' );
			return;
		}
		if ( ! address.zip.trim() ) {
			setError( 'ZIP code is required.' );
			return;
		}

		setSaving( true );
		setError( '' );
		setSuccess( false );

		try {
			await updateArtistShippingAddress( artistId, address );
			setSuccess( true );
			setTimeout( () => setSuccess( false ), 3000 );
		} catch ( err ) {
			setError( err?.message || 'Failed to save address.' );
		} finally {
			setSaving( false );
		}
	};

	const updateField = ( field, value ) => {
		setAddress( ( prev ) => ( { ...prev, [ field ]: value } ) );
		setError( '' );
	};

	if ( loading ) {
		return (
			<div className="ec-asm__panel">
				<p>Loading...</p>
			</div>
		);
	}

	return (
		<div className="ec-asm__panel ec-asm__shipping">
			<h3>Shipping Address</h3>
			<p className="ec-asm__muted">
				Set up your shipping address for printing labels. This is where your orders will ship from.
			</p>

			{ error && <div className="notice notice-error"><p>{ error }</p></div> }
			{ success && <div className="notice notice-success"><p>Address saved successfully.</p></div> }

			<div className="ec-asm__form">
				<label className="ec-asm__field">
					<span>Name (for shipping label) *</span>
					<input
						type="text"
						value={ address.name }
						onChange={ ( e ) => updateField( 'name', e.target.value ) }
						placeholder="Your name or business name"
					/>
				</label>

				<label className="ec-asm__field">
					<span>Street Address *</span>
					<input
						type="text"
						value={ address.street1 }
						onChange={ ( e ) => updateField( 'street1', e.target.value ) }
						placeholder="123 Main St"
					/>
				</label>

				<label className="ec-asm__field">
					<span>Apartment, suite, etc.</span>
					<input
						type="text"
						value={ address.street2 }
						onChange={ ( e ) => updateField( 'street2', e.target.value ) }
						placeholder="Apt 4B"
					/>
				</label>

				<div className="ec-asm__row">
					<label className="ec-asm__field">
						<span>City *</span>
						<input
							type="text"
							value={ address.city }
							onChange={ ( e ) => updateField( 'city', e.target.value ) }
							placeholder="City"
						/>
					</label>

					<label className="ec-asm__field">
						<span>State *</span>
						<select
							value={ address.state }
							onChange={ ( e ) => updateField( 'state', e.target.value ) }
						>
							{ US_STATES.map( ( s ) => (
								<option key={ s.value } value={ s.value }>
									{ s.label }
								</option>
							) ) }
						</select>
					</label>

					<label className="ec-asm__field">
						<span>ZIP Code *</span>
						<input
							type="text"
							value={ address.zip }
							onChange={ ( e ) => updateField( 'zip', e.target.value ) }
							placeholder="12345"
							maxLength={ 10 }
						/>
					</label>
				</div>

				<label className="ec-asm__field">
					<span>Country</span>
					<input
						type="text"
						value="United States"
						disabled
						className="ec-asm__field--disabled"
					/>
					<span className="ec-asm__muted">US domestic shipping only</span>
				</label>

				<div className="ec-asm__actions">
					<button
						type="button"
						className="button-1 button-medium"
						onClick={ handleSave }
						disabled={ saving }
					>
						{ saving ? 'Saving...' : 'Save Address' }
					</button>
				</div>
			</div>
		</div>
	);
};

export default ShippingTab;
