import React, { useEffect, useMemo, useState, useCallback } from 'react';
import { render } from '@wordpress/element';
import ArtistSwitcher from '../shared/components/ArtistSwitcher';
import {
	createShopProduct,
	createStripeConnectDashboardLink,
	createStripeConnectOnboardingLink,
	deleteMedia,
	deleteShopProduct,
	getStripeConnectStatus,
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
	description: '',
} );

const ProductsTab = ( {
	artistId,
	products,
	loading,
	error,
	stripeStatus,
	onOpenPayments,
	onRefresh,
} ) => {
	const [ draft, setDraft ] = useState( emptyDraft( artistId ) );
	const [ editingId, setEditingId ] = useState( 0 );
	const [ saving, setSaving ] = useState( false );
	const [ localError, setLocalError ] = useState( '' );
	const [ showForm, setShowForm ] = useState( false );
	const [ pendingImageFile, setPendingImageFile ] = useState( null );

	useEffect( () => {
		setDraft( emptyDraft( artistId ) );
		setEditingId( 0 );
		setLocalError( '' );
		setShowForm( false );
		setPendingImageFile( null );
	}, [ artistId ] );

	const artistProducts = useMemo( () => {
		return products.filter( ( p ) => ( p.artist_id || 0 ) === ( artistId || 0 ) );
	}, [ products, artistId ] );

	const canReceivePayments = !! stripeStatus?.can_receive_payments;

	const startEdit = ( product ) => {
		setEditingId( product.id );
		setShowForm( true );
		setPendingImageFile( null );
		setDraft( {
			artist_id: product.artist_id || artistId,
			name: product.name || '',
			price: product.price || '',
			sale_price: product.sale_price || '',
			manage_stock: !! product.manage_stock,
			stock_quantity: product.stock_quantity || 0,
			description: product.description || '',
		} );
	};

	const reset = () => {
		setEditingId( 0 );
		setDraft( emptyDraft( artistId ) );
		setLocalError( '' );
		setShowForm( false );
		setPendingImageFile( null );
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
				description: draft.description,
			};

			if ( editingId ) {
				await updateShopProduct( editingId, payload );
				if ( pendingImageFile ) {
					await uploadFeatured( editingId, pendingImageFile );
				}
			} else {
				const created = await createShopProduct( payload );
				const createdId = created?.id;
				if ( createdId && pendingImageFile ) {
					await uploadFeatured( createdId, pendingImageFile );
				}
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

	const activeProduct = useMemo( () => {
		if ( ! editingId ) {
			return null;
		}
		return artistProducts.find( ( p ) => p.id === editingId ) || null;
	}, [ artistProducts, editingId ] );

	const onPickImageForDraft = ( e ) => {
		const file = e.target.files?.[ 0 ];
		setPendingImageFile( file || null );
	};

	const startNew = () => {
		setShowForm( true );
		setEditingId( 0 );
		setDraft( emptyDraft( artistId ) );
		setPendingImageFile( null );
		setLocalError( '' );
	};

	return (
		<div className="ec-asm__panel">
			<h3>Products</h3>
			{ loading && <p>Loading</p> }
			{ error && <p className="notice notice-error">{ error }</p> }
			{ localError && <div className="notice notice-error"><p>{ localError }</p></div> }
			{ artistId && ! canReceivePayments && (
				<div className="notice notice-info">
					<p>
						<strong>Note:</strong> Connect Stripe in the Payments tab before products can go live.
					</p>
					{ typeof onOpenPayments === 'function' && (
						<button type="button" className="button-2 button-small" onClick={ onOpenPayments }>
							Go to Payments
						</button>
					) }
				</div>
			) }

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
			</div>

			{ ! loading && artistProducts.length === 0 && ! showForm && (
				<div className="ec-asm__panel">
					<p>No products yet.</p>
					<button type="button" className="button-1 button-medium" onClick={ startNew }>
						Create your first product
					</button>
				</div>
			) }

			{ showForm && (
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

						<div className="ec-asm__image">
							<h4>Product Image</h4>
							<div className="ec-asm__actions">
								<label className="button-2 button-small">
									<input
										className="ec-asm__file-input"
										type="file"
										accept="image/*"
										onChange={ onPickImageForDraft }
										disabled={ saving }
									/>
									{ editingId ? 'Upload / Replace Image' : 'Choose Image' }
								</label>
								{ editingId ? (
									<button
										type="button"
										className="button-2 button-small"
										onClick={ () => removeFeatured( editingId ) }
										disabled={ saving }
									>
										Remove Image
									</button>
								) : null }
							</div>
							{ editingId && activeProduct?.image?.url ? (
								<img className="ec-asm__image-preview" src={ activeProduct.image.url } alt="" />
							) : null }
							{ ! editingId && pendingImageFile ? (
								<p className="ec-asm__muted">Selected: { pendingImageFile.name }</p>
							) : null }
						</div>

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

					<label>
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
						Manage Stock
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
			) }
		</div>
	);
};

const PaymentsTab = ( {
	artistId,
	status,
	loading,
	error,
	onConnect,
	onOpenDashboard,
	onRefresh,
} ) => {
	const connected = !! status?.connected;
	const canReceivePayments = !! status?.can_receive_payments;

	return (
		<div className="ec-asm__panel ec-asm__payments">
			<h3>Payments</h3>
			<p className="ec-asm__muted">
				Products stay as drafts until your Stripe account can receive payments.
			</p>

			{ loading && <p>Loading</p> }
			{ error && <div className="notice notice-error"><p>{ error }</p></div> }

			{ artistId ? (
				<div className="ec-asm__stripe">
					<div className="ec-asm__stripe-status">
						<div>
							<strong>Status:</strong> { connected ? ( status?.status || 'connected' ) : 'not connected' }
						</div>
						{ connected ? (
							<ul>
								<li>Charges enabled: { status?.charges_enabled ? 'yes' : 'no' }</li>
								<li>Payouts enabled: { status?.payouts_enabled ? 'yes' : 'no' }</li>
								<li>Details submitted: { status?.details_submitted ? 'yes' : 'no' }</li>
								<li>Can receive payments: { canReceivePayments ? 'yes' : 'no' }</li>
							</ul>
						) : null }
					</div>

					<div className="ec-asm__actions">
						{ ! connected ? (
							<button
								type="button"
								className="button-1 button-medium"
								onClick={ onConnect }
								disabled={ loading }
							>
								Connect Stripe
							</button>
						) : (
							<button
								type="button"
								className="button-2 button-medium"
								onClick={ onOpenDashboard }
								disabled={ loading }
							>
								Open Stripe Dashboard
							</button>
						) }
						<button
							type="button"
							className="button-2 button-medium"
							onClick={ onRefresh }
							disabled={ loading }
						>
							Refresh Status
						</button>
					</div>
				</div>
			) : (
				<p>Select an artist to manage payments.</p>
			) }
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
	const [ stripeStatus, setStripeStatus ] = useState( null );
	const [ stripeLoading, setStripeLoading ] = useState( false );
	const [ stripeError, setStripeError ] = useState( '' );

	const tabs = useMemo(
		() => [
			{ id: 'products', label: 'Products' },
			{ id: 'payments', label: 'Payments' },
		],
		[]
	);

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

	const loadStripe = useCallback( async () => {
		if ( ! artistId ) {
			setStripeStatus( null );
			setStripeError( '' );
			return;
		}

		setStripeLoading( true );
		setStripeError( '' );
		try {
			const data = await getStripeConnectStatus( artistId );
			setStripeStatus( data || null );
		} catch ( err ) {
			setStripeError( err?.message || 'Could not load Stripe status.' );
			setStripeStatus( null );
		} finally {
			setStripeLoading( false );
		}
	}, [ artistId ] );

	useEffect( () => {
		load();
	}, [ load ] );

	useEffect( () => {
		loadStripe();
	}, [ loadStripe ] );

	const onArtistChange = useCallback( ( newId ) => {
		setArtistId( newId );
	}, [] );

	const openPaymentsTab = useCallback( () => {
		setActiveTab( 'payments' );
	}, [] );

	const connectStripe = useCallback( async () => {
		if ( ! artistId ) {
			setStripeError( 'Select an artist first.' );
			return;
		}
		setStripeLoading( true );
		setStripeError( '' );
		try {
			const data = await createStripeConnectOnboardingLink( artistId );
			const url = data?.url;
			if ( ! url ) {
				throw new Error( 'Onboarding link missing.' );
			}
			window.location.assign( url );
		} catch ( err ) {
			setStripeError( err?.message || 'Could not start Stripe onboarding.' );
			setStripeLoading( false );
		}
	}, [ artistId ] );

	const openStripeDashboard = useCallback( async () => {
		if ( ! artistId ) {
			setStripeError( 'Select an artist first.' );
			return;
		}
		setStripeLoading( true );
		setStripeError( '' );
		try {
			const data = await createStripeConnectDashboardLink( artistId );
			const url = data?.url;
			if ( ! url ) {
				throw new Error( 'Dashboard link missing.' );
			}
			window.open( url, '_blank', 'noopener,noreferrer' );
		} catch ( err ) {
			setStripeError( err?.message || 'Could not open Stripe dashboard.' );
		} finally {
			setStripeLoading( false );
		}
	}, [ artistId ] );

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
					stripeStatus={ stripeStatus }
					onOpenPayments={ openPaymentsTab }
					onRefresh={ load }
				/>
			) }

			{ activeTab === 'payments' && (
				<PaymentsTab
					artistId={ artistId }
					status={ stripeStatus }
					loading={ stripeLoading }
					error={ stripeError }
					onConnect={ connectStripe }
					onOpenDashboard={ openStripeDashboard }
					onRefresh={ loadStripe }
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
