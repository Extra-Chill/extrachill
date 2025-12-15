import React, { useEffect, useMemo, useState, useCallback } from 'react';
import { render } from '@wordpress/element';
import ArtistSwitcher from '../shared/components/ArtistSwitcher';
import {
	createShopProduct,
	deleteMedia,
	deleteShopProduct,
	listShopProducts,
	updateShopProduct,
	uploadMedia,
} from '../shared/api/client';

const useConfig = () => {
	const config = window.ecArtistShopManagerConfig || {};
	return useMemo(
		() => ({
			userArtists: Array.isArray( config.userArtists ) ? config.userArtists : [],
			selectedId: config.selectedId || 0,
		}),
		[ config ]
	);
};

const TabNav = ( { tabs, active, onChange } ) => (
	<div className="ec-asm__tabs">
		{ tabs.map( ( tab ) => (
			<button
				key={ tab.id }
				type="button"
				className={ `ec-asm__tab${ active === tab.id ? ' is-active' : '' }` }
				onClick={ () => onChange( tab.id ) }
			>
				{ tab.label }
			</button>
		) ) }
	</div>
);

const emptyDraft = ( artistId ) => ( {
	artist_id: artistId || 0,
	name: '',
	price: '',
	sale_price: '',
	manage_stock: false,
	stock_quantity: 0,
	short_description: '',
	description: '',
} );

const ProductsTab = ( {
	artistId,
	products,
	loading,
	error,
	onRefresh,
} ) => {
	const [ draft, setDraft ] = useState( emptyDraft( artistId ) );
	const [ editingId, setEditingId ] = useState( 0 );
	const [ saving, setSaving ] = useState( false );
	const [ localError, setLocalError ] = useState( '' );

	useEffect( () => {
		setDraft( emptyDraft( artistId ) );
		setEditingId( 0 );
		setLocalError( '' );
	}, [ artistId ] );

	const artistProducts = useMemo( () => {
		return products.filter( ( p ) => ( p.artist_id || 0 ) === ( artistId || 0 ) );
	}, [ products, artistId ] );

	const startEdit = ( product ) => {
		setEditingId( product.id );
		setDraft( {
			artist_id: product.artist_id || artistId,
			name: product.name || '',
			price: product.price || '',
			sale_price: product.sale_price || '',
			manage_stock: !! product.manage_stock,
			stock_quantity: product.stock_quantity || 0,
			short_description: product.short_description || '',
			description: product.description || '',
		} );
	};

	const reset = () => {
		setEditingId( 0 );
		setDraft( emptyDraft( artistId ) );
		setLocalError( '' );
	};

	const save = async () => {
		if ( ! artistId ) {
			setLocalError( 'Select an artist first.' );
			return;
		}

		if ( ! draft.name.trim() ) {
			setLocalError( 'Product name is required.' );
			return;
		}

		const priceNumber = parseFloat( draft.price );
		if ( ! Number.isFinite( priceNumber ) || priceNumber <= 0 ) {
			setLocalError( 'Price must be greater than zero.' );
			return;
		}

		setSaving( true );
		setLocalError( '' );
		try {
			const payload = {
				artist_id: artistId,
				name: draft.name,
				price: priceNumber,
				sale_price: draft.sale_price ? parseFloat( draft.sale_price ) : 0,
				manage_stock: !! draft.manage_stock,
				stock_quantity: draft.manage_stock ? parseInt( draft.stock_quantity, 10 ) || 0 : 0,
				short_description: draft.short_description,
				description: draft.description,
			};

			if ( editingId ) {
				await updateShopProduct( editingId, payload );
			} else {
				await createShopProduct( payload );
			}

			await onRefresh();
			reset();
		} catch ( err ) {
			setLocalError( err?.message || 'Save failed.' );
		} finally {
			setSaving( false );
		}
	};

	const trash = async ( productId ) => {
		setSaving( true );
		setLocalError( '' );
		try {
			await deleteShopProduct( productId );
			await onRefresh();
			if ( editingId === productId ) {
				reset();
			}
		} catch ( err ) {
			setLocalError( err?.message || 'Delete failed.' );
		} finally {
			setSaving( false );
		}
	};

	const uploadFeatured = async ( productId, file ) => {
		setSaving( true );
		setLocalError( '' );
		try {
			await uploadMedia( 'product_image', productId, file );
			await onRefresh();
		} catch ( err ) {
			setLocalError( err?.message || 'Upload failed.' );
		} finally {
			setSaving( false );
		}
	};

	const removeFeatured = async ( productId ) => {
		setSaving( true );
		setLocalError( '' );
		try {
			await deleteMedia( 'product_image', productId );
			await onRefresh();
		} catch ( err ) {
			setLocalError( err?.message || 'Removal failed.' );
		} finally {
			setSaving( false );
		}
	};

	return (
		<div className="ec-asm__panel">
			<h3>Products</h3>
			{ loading && <p>Loading</p> }
			{ error && <p className="notice notice-error">{ error }</p> }
			{ localError && <div className="notice notice-error"><p>{ localError }</p></div> }

			<div className="ec-asm__products">
				{ artistProducts.map( ( product ) => (
					<div key={ product.id } className="ec-asm__product">
						<img
							className="ec-asm__product-image"
							src={ product.image?.url || '' }
							alt=""
						/>
						<div className="ec-asm__meta">
							<div className="ec-asm__name">{ product.name }</div>
							<div className="ec-asm__muted">
								${ product.sale_price || product.price } { product.status }
							</div>

						</div>
						<div className="ec-asm__actions">
							<button
								type="button"
								className="button-1 button-small"
								onClick={ () => startEdit( product ) }
								disabled={ saving }
							>
								Edit
							</button>
							<label className="button-2 button-small">
								<input
									type="file"
									accept="image/*"
									onChange={ ( e ) => {
										const file = e.target.files?.[ 0 ];
										if ( file ) {
											uploadFeatured( product.id, file );
										}
									} }
									disabled={ saving }
									style={ { display: 'none' } }
								/>
								Upload Image
							</label>
							<button
								type="button"
								className="button-2 button-small"
								onClick={ () => removeFeatured( product.id ) }
								disabled={ saving }
							>
								Remove Image
							</button>
							<button
								type="button"
								className="button-danger button-small"
								onClick={ () => trash( product.id ) }
								disabled={ saving }
							>
								Trash
							</button>
						</div>
					</div>
				) ) }
				{ ! loading && artistProducts.length === 0 && <p>No products yet.</p> }
			</div>

			<div className="ec-asm__panel">
				<h3>{ editingId ? 'Edit Product' : 'New Product' }</h3>
				<div className="ec-asm__form">
					<label className="ec-asm__field">
						<span>Name *</span>
						<input
							type="text"
							value={ draft.name }
							onChange={ ( e ) => setDraft( ( prev ) => ( { ...prev, name: e.target.value } ) ) }
						/>
					</label>

					<div className="ec-asm__row">
						<label className="ec-asm__field">
							<span>Price *</span>
							<input
								type="number"
								step="0.01"
								value={ draft.price }
								onChange={ ( e ) =>
									setDraft( ( prev ) => ( { ...prev, price: e.target.value } ) )
								}
							/>
						</label>
						<label className="ec-asm__field">
							<span>Sale Price</span>
							<input
								type="number"
								step="0.01"
								value={ draft.sale_price }
								onChange={ ( e ) =>
									setDraft( ( prev ) => ( { ...prev, sale_price: e.target.value } ) )
								}
							/>
						</label>
					</div>

					<label className="ec-asm__field">
						<span>Manage Stock</span>
						<input
							type="checkbox"
							checked={ !! draft.manage_stock }
							onChange={ ( e ) =>
								setDraft( ( prev ) => ( {
									...prev,
									manage_stock: e.target.checked,
								} ) )
							}
						/>
					</label>

					{ draft.manage_stock && (
						<label className="ec-asm__field">
							<span>Stock Quantity</span>
							<input
								type="number"
								value={ draft.stock_quantity }
								onChange={ ( e ) =>
									setDraft( ( prev ) => ( { ...prev, stock_quantity: e.target.value } ) )
								}
							/>
						</label>
					) }

					<label className="ec-asm__field">
						<span>Short Description</span>
						<textarea
							rows={ 3 }
							value={ draft.short_description }
							onChange={ ( e ) =>
								setDraft( ( prev ) => ( { ...prev, short_description: e.target.value } ) )
							}
						/>
					</label>

					<label className="ec-asm__field">
						<span>Description</span>
						<textarea
							rows={ 6 }
							value={ draft.description }
							onChange={ ( e ) =>
								setDraft( ( prev ) => ( { ...prev, description: e.target.value } ) )
							}
						/>
					</label>

					<div className="ec-asm__actions">
						<button
							type="button"
							className="button-1 button-medium"
							onClick={ save }
							disabled={ saving }
						>
							{ saving ? 'Saving' : editingId ? 'Save Changes' : 'Create Product' }
						</button>
						{ editingId ? (
							<button
								type="button"
								className="button-2 button-medium"
								onClick={ reset }
								disabled={ saving }
							>
								Cancel
							</button>
						) : null }
					</div>
				</div>
			</div>
		</div>
	);
};

const App = () => {
	const config = useConfig();
	const [ activeTab, setActiveTab ] = useState( 'products' );
	const [ artistId, setArtistId ] = useState( config.selectedId || 0 );
	const [ products, setProducts ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	const tabs = useMemo( () => [ { id: 'products', label: 'Products' } ], [] );

	const load = useCallback( async () => {
		setLoading( true );
		setError( '' );
		try {
			const data = await listShopProducts();
			setProducts( Array.isArray( data ) ? data : [] );
		} catch ( err ) {
			setError( err?.message || 'Could not load products.' );
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	const onArtistChange = useCallback( ( newId ) => {
		setArtistId( newId );
	}, [] );

	return (
		<div className="ec-asm">
			<div className="ec-asm__header">
				<div className="ec-asm__header-left">
					<h2>Shop Manager</h2>
					<ArtistSwitcher
						artists={ config.userArtists }
						selectedId={ artistId }
						onChange={ onArtistChange }
					/>
				</div>
				<TabNav tabs={ tabs } active={ activeTab } onChange={ setActiveTab } />
			</div>

			{ activeTab === 'products' && (
				<ProductsTab
					artistId={ artistId }
					products={ products }
					loading={ loading }
					error={ error }
					onRefresh={ load }
				/>
			) }
		</div>
	);
};

document.addEventListener( 'DOMContentLoaded', () => {
	const el = document.getElementById( 'ec-artist-shop-manager-root' );
	if ( el ) {
		render( <App />, el );
	}
} );
