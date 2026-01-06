/**
 * ProductsTab - Product management with image uploads and size variants
 */

import { useState, useEffect, useMemo, useCallback, useRef } from '@wordpress/element';
import DraggableList from '../../../shared/components/DraggableList';
import {
	createShopProduct,
	deleteShopProduct,
	deleteShopProductImage,
	updateShopProduct,
	uploadShopProductImages,
} from '../../../shared/api/client';

const STANDARD_SIZES = [ 'XS', 'S', 'M', 'L', 'XL', 'XXL' ];

const emptyDraft = ( artistId ) => ( {
	artist_id: artistId || 0,
	status: 'draft',
	name: '',
	price: '',
	sale_price: '',
	stock_quantity: '',
	description: '',
	has_sizes: false,
	sizes: STANDARD_SIZES.map( ( name ) => ( { name, stock: 0 } ) ),
	ships_free: false,
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
	const [ initialHasSizes, setInitialHasSizes ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ localError, setLocalError ] = useState( '' );
	const [ showForm, setShowForm ] = useState( false );
	const panelRef = useRef( null );
	const [ imagesDraft, setImagesDraft ] = useState( [] );
	const [ pendingImageFiles, setPendingImageFiles ] = useState( [] );
	const [ previewUrls, setPreviewUrls ] = useState( {} );

	useEffect( () => {
		setDraft( emptyDraft( artistId ) );
		setEditingId( 0 );
		setInitialHasSizes( false );
		setLocalError( '' );
		setShowForm( false );
		setImagesDraft( [] );
		setPendingImageFiles( [] );
		setPreviewUrls( {} );
	}, [ artistId ] );

	useEffect( () => {
		if ( localError && panelRef.current ) {
			panelRef.current.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	}, [ localError ] );

	const artistProducts = useMemo( () => {
		return products.filter( ( p ) => ( p.artist_id || 0 ) === ( artistId || 0 ) );
	}, [ products, artistId ] );

	const canReceivePayments = !! stripeStatus?.can_receive_payments;

	const startEdit = ( product ) => {
		setEditingId( product.id );
		setInitialHasSizes( Array.isArray( product.sizes ) && product.sizes.length > 0 );
		setShowForm( true );
		setImagesDraft( Array.isArray( product.images ) ? product.images : [] );
		setPendingImageFiles( [] );
		setPreviewUrls( {} );

		const hasSizes = Array.isArray( product.sizes ) && product.sizes.length > 0;
		const sizes = hasSizes
			? STANDARD_SIZES.map( ( name ) => {
					const existing = product.sizes.find( ( s ) => s.name === name );
					return { name, stock: existing ? existing.stock : 0 };
			  } )
			: STANDARD_SIZES.map( ( name ) => ( { name, stock: 0 } ) );

		const stockValue = product.manage_stock ? String( product.stock_quantity || 0 ) : '';

		setDraft( {
			artist_id: product.artist_id || artistId,
			status: product.status || 'draft',
			name: product.name || '',
			price: product.price || '',
			sale_price: product.sale_price || '',
			stock_quantity: stockValue,
			description: product.description || '',
			has_sizes: hasSizes,
			sizes,
			ships_free: product.ships_free || false,
		} );
	};

	const reset = () => {
		setEditingId( 0 );
		setInitialHasSizes( false );
		setDraft( emptyDraft( artistId ) );
		setImagesDraft( [] );
		setPendingImageFiles( [] );
		setPreviewUrls( {} );
		setLocalError( '' );
		setShowForm( false );
	};

	const cleanupPreviews = useCallback( () => {
		setPreviewUrls( ( prev ) => {
			if ( window?.URL?.revokeObjectURL ) {
				Object.values( prev ).forEach( ( url ) => {
					window.URL.revokeObjectURL( url );
				} );
			}
			return {};
		} );
	}, [] );

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

		const wantsPublish = draft.status === 'publish';
		if ( wantsPublish && ! canReceivePayments ) {
			setLocalError( 'Connect Stripe in the Payments tab before products can go live.' );
			return;
		}

		if ( wantsPublish ) {
			const existingCount = imagesDraft.length;
			const pendingCount = pendingImageFiles.length;
			if ( ! editingId && pendingCount === 0 ) {
				setLocalError( 'Add at least one image before publishing.' );
				return;
			}
			if ( editingId && existingCount === 0 && pendingCount === 0 ) {
				setLocalError( 'Add at least one image before publishing.' );
				return;
			}
		}

		setSaving( true );
		setLocalError( '' );
		try {
			const hasStockValue = draft.stock_quantity !== '' && draft.stock_quantity !== null;
			const payload = {
				artist_id: artistId,
				status: draft.status,
				name: draft.name,
				price: priceNumber,
				sale_price: draft.sale_price ? parseFloat( draft.sale_price ) : 0,
				manage_stock: draft.has_sizes || hasStockValue,
				stock_quantity: hasStockValue ? parseInt( draft.stock_quantity, 10 ) || 0 : 0,
				description: draft.description,
				ships_free: draft.ships_free,
			};

			if ( draft.has_sizes ) {
				payload.sizes = draft.sizes.map( ( s ) => ( {
					name: s.name,
					stock: Math.max( 0, parseInt( s.stock, 10 ) || 0 ),
				} ) );
			} else if ( editingId && initialHasSizes ) {
				payload.sizes = [];
			}

			if ( editingId ) {
				await updateShopProduct( editingId, payload );
				if ( pendingImageFiles.length > 0 ) {
					await uploadShopProductImages( editingId, pendingImageFiles );
				}
			} else {
				const createPayload = wantsPublish ? { ...payload, status: 'draft' } : payload;
				const created = await createShopProduct( createPayload );
				const createdId = created?.id;
				if ( createdId && pendingImageFiles.length > 0 ) {
					await uploadShopProductImages( createdId, pendingImageFiles );
				}
				if ( createdId && wantsPublish ) {
					await updateShopProduct( createdId, { status: 'publish' } );
				}
			}

			cleanupPreviews();
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

	const reorderImages = useCallback(
		async ( newItems ) => {
			if ( ! editingId ) {
				setImagesDraft( newItems );
				return;
			}

			setImagesDraft( newItems );
			setSaving( true );
			setLocalError( '' );
			try {
				await updateShopProduct( editingId, {
					image_ids: newItems.map( ( img ) => img.id ),
				} );
				await onRefresh();
			} catch ( err ) {
				setLocalError( err?.message || 'Reorder failed.' );
			} finally {
				setSaving( false );
			}
		},
		[ editingId, onRefresh ]
	);

	const onPickImagesForDraft = ( e ) => {
		const files = Array.from( e.target.files || [] );
		e.target.value = '';

		if ( files.length === 0 ) {
			return;
		}

		setLocalError( '' );
		setPendingImageFiles( ( prev ) => {
			const available = 5 - imagesDraft.length - prev.length;
			if ( available <= 0 ) {
				setLocalError( 'you already have five images. please delete one before uploading another' );
				return prev;
			}

			const toAdd = files.slice( 0, available );
			setPreviewUrls( ( current ) => {
				const next = { ...current };
				if ( window?.URL?.createObjectURL ) {
					toAdd.forEach( ( file ) => {
						next[ file.name + file.size + file.lastModified ] = window.URL.createObjectURL( file );
					} );
				}
				return next;
			} );
			return [ ...prev, ...toAdd ];
		} );
	};

	const deleteImage = useCallback(
		async ( attachmentId ) => {
			if ( ! editingId ) {
				setLocalError( 'Save the product first before deleting images.' );
				return;
			}

			setSaving( true );
			setLocalError( '' );
			try {
				await deleteShopProductImage( editingId, attachmentId );
				await onRefresh();
			} catch ( err ) {
				setLocalError( err?.message || 'Delete failed.' );
			} finally {
				setSaving( false );
			}
		},
		[ editingId, onRefresh ]
	);

	const uploadPendingImages = useCallback(
		async () => {
			if ( ! editingId ) {
				setLocalError( 'Save the product first before uploading images.' );
				return;
			}

			if ( pendingImageFiles.length === 0 ) {
				return;
			}

			setSaving( true );
			setLocalError( '' );
			try {
				await uploadShopProductImages( editingId, pendingImageFiles );
				cleanupPreviews();
				setPendingImageFiles( [] );
				await onRefresh();
			} catch ( err ) {
				setLocalError( err?.message || 'Upload failed.' );
			} finally {
				setSaving( false );
			}
		},
		[ editingId, pendingImageFiles, onRefresh, cleanupPreviews ]
	);

	const startNew = () => {
		cleanupPreviews();
		setShowForm( true );
		setEditingId( 0 );
		setDraft( emptyDraft( artistId ) );
		setImagesDraft( [] );
		setPendingImageFiles( [] );
		setLocalError( '' );
	};

	const stripeNote = useMemo( () => {
		if ( ! artistId || canReceivePayments ) {
			return '';
		}

		if ( ! stripeStatus?.connected ) {
			return 'Connect Stripe in the Payments tab before products can go live.';
		}

		if ( stripeStatus?.status === 'pending' ) {
			return 'Stripe setup has started. Finish setup in the Payments tab before publishing products.';
		}

		if ( stripeStatus?.status === 'restricted' ) {
			return 'Stripe needs more information. Visit the Payments tab to finish setup before publishing.';
		}

		return 'Set up Stripe in the Payments tab before products can go live.';
	}, [ artistId, canReceivePayments, stripeStatus ] );

	return (
		<div className="ec-asm__panel" ref={ panelRef }>
			<div className="ec-asm__form-header">
				<h3>Products</h3>
				{ artistId && ! loading && ! showForm && artistProducts.length > 0 && (
					<button
						type="button"
						className="button-1 button-small"
						onClick={ startNew }
					>
						Add Product
					</button>
				) }
			</div>
			{ loading && <p>Loading</p> }
			{ error && <p className="notice notice-error">{ error }</p> }
			{ localError && <div className="notice notice-error"><p>{ localError }</p></div> }
			{ stripeNote && (
				<div className="notice notice-info">
					<p>
						<strong>Note:</strong> { stripeNote }
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
								<span>${ product.sale_price || product.price }</span>
								<span className={ `ec-asm__status ec-asm__status--${ product.status }` }>
									{ product.status }
								</span>
							</div>
							{ Array.isArray( product.sizes ) && product.sizes.length > 0 && (
								<div className="ec-asm__product-sizes">
									{ product.sizes.map( ( size ) => (
										<span
											key={ size.name }
											className={ `ec-asm__product-size${ size.stock > 0 ? '' : ' is-out' }` }
											title={ `${ size.name }: ${ size.stock } in stock` }
										>
											{ size.name }
										</span>
									) ) }
								</div>
							) }
						</div>
						<div className="ec-asm__actions">
							<button
								type="button"
								className="ec-asm__icon-btn"
								onClick={ () => startEdit( product ) }
								disabled={ saving }
								title="Edit"
							>
								<span className="dashicons dashicons-edit"></span>
							</button>
							<button
								type="button"
								className="ec-asm__icon-btn ec-asm__icon-btn--danger"
								onClick={ () => trash( product.id ) }
								disabled={ saving }
								title="Trash"
							>
								<span className="dashicons dashicons-trash"></span>
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
					<div className="ec-asm__form-header">
						<h3>{ editingId ? 'Edit Product' : 'New Product' }</h3>
						<select
							className="ec-asm__status-select"
							value={ draft.status }
							aria-label="Product visibility"
							onChange={ ( e ) => {
								const next = e.target.value;
								if ( next === 'publish' && ! canReceivePayments ) {
									setLocalError( 'Connect Stripe in the Payments tab before products can go live.' );
									return;
								}
								setLocalError( '' );
								setDraft( ( prev ) => ( { ...prev, status: next } ) );
							} }
							disabled={ saving }
						>
							<option value="draft">Draft</option>
							<option value="publish">Published</option>
						</select>
					</div>
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
							<div className="ec-asm__image-header">
								<h4>Product Images</h4>
								<p className="ec-asm__muted">Up to 5 images. First image is featured. Drag to reorder.</p>
							</div>

							<div className="ec-asm__actions">
								<label className="button-2 button-small">
									<input
										className="ec-asm__file-input"
										type="file"
										accept="image/*"
										multiple
										onChange={ onPickImagesForDraft }
										disabled={ saving }
									/>
									{ editingId ? 'Choose Images' : 'Choose Images' }
								</label>
								{ editingId && pendingImageFiles.length > 0 ? (
									<button
										type="button"
										className="button-1 button-small"
										onClick={ uploadPendingImages }
										disabled={ saving }
									>
										Upload { pendingImageFiles.length }
									</button>
								) : null }
								{ pendingImageFiles.length > 0 ? (
									<button
										type="button"
										className="button-2 button-small"
										onClick={ () => {
											cleanupPreviews();
											setPendingImageFiles( [] );
										} }
										disabled={ saving }
									>
										Clear selection
									</button>
								) : null }
							</div>

							{ ( imagesDraft.length > 0 || pendingImageFiles.length > 0 ) && (
								<div className="ec-asm__images">
									{ imagesDraft.length > 0 && (
										<DraggableList
											items={ imagesDraft }
											onReorder={ reorderImages }
											renderItem={ ( img, index ) => (
												<div className="ec-asm__image-item">
													<img className="ec-asm__image-preview" src={ img.url } alt="" />
													<div className="ec-asm__image-meta">
														{ index === 0 ? (
															<span className="ec-asm__badge">Featured</span>
														) : (
															<span className="ec-asm__badge ec-asm__badge--muted">Gallery</span>
														) }
													</div>
													<button
														type="button"
														className="ec-asm__icon-btn ec-asm__icon-btn--danger"
														onClick={ () => deleteImage( img.id ) }
														disabled={ saving }
														title="Delete"
													>
														<span className="dashicons dashicons-trash"></span>
													</button>
												</div>
											) }
										/>
									) }

									{ pendingImageFiles.length > 0 && (
										<div className="ec-asm__pending">
											<div className="ec-asm__pending-title">Selected (not uploaded yet)</div>
											<div className="ec-asm__pending-grid">
												{ pendingImageFiles.map( ( file ) => {
													const key = file.name + file.size + file.lastModified;
													const url = previewUrls[ key ];
													return (
														<div key={ key } className="ec-asm__pending-item">
															{ url ? <img className="ec-asm__image-preview" src={ url } alt="" /> : null }
															<div className="ec-asm__muted">{ file.name }</div>
														</div>
													);
												} ) }
											</div>
										</div>
									) }
								</div>
							) }
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

					<label className="ec-asm__field">
						<span>Description</span>
						<textarea
							rows={ 4 }
							value={ draft.description }
							onChange={ ( e ) =>
								setDraft( ( prev ) => ( { ...prev, description: e.target.value } ) )
							}
						/>
					</label>

					<div className="ec-asm__stock-section">
						<h4>Stock</h4>

						{ ! draft.has_sizes && (
							<label className="ec-asm__field">
								<input
									type="number"
									min="0"
									value={ draft.stock_quantity }
									onChange={ ( e ) =>
										setDraft( ( prev ) => ( { ...prev, stock_quantity: e.target.value } ) )
									}
									placeholder="Unlimited"
								/>
							</label>
						) }

						<label className="ec-asm__sizes-toggle">
							<input
								type="checkbox"
								checked={ !! draft.has_sizes }
								onChange={ ( e ) => {
									const nextHasSizes = e.target.checked;
									if ( nextHasSizes ) {
										setDraft( ( prev ) => ( { ...prev, has_sizes: true, stock_quantity: '' } ) );
										return;
									}
									const total = draft.sizes.reduce( ( sum, s ) => {
										const val = parseInt( s.stock, 10 );
										return sum + ( Number.isFinite( val ) ? val : 0 );
									}, 0 );
									setDraft( ( prev ) => ( {
										...prev,
										has_sizes: false,
										stock_quantity: String( total ),
									} ) );
								} }
							/>
							This product has sizes
						</label>

						{ draft.has_sizes && (
							<>
								<div className="ec-asm__size-grid">
									{ draft.sizes.map( ( size, index ) => (
										<div key={ size.name } className="ec-asm__size-row">
											<span className="ec-asm__size-name">{ size.name }</span>
										<input
											type="number"
											min="0"
											value={ size.stock }
											onChange={ ( e ) => {
												const newSizes = [ ...draft.sizes ];
												newSizes[ index ] = { ...size, stock: e.target.value };
												setDraft( ( prev ) => ( { ...prev, sizes: newSizes } ) );
											} }
											placeholder="0"
										/>
										</div>
									) ) }
								</div>
								<div className="ec-asm__stock-total">
									Total: { draft.sizes.reduce( ( sum, s ) => {
										const val = parseInt( s.stock, 10 );
										return sum + ( Number.isFinite( val ) ? val : 0 );
									}, 0 ) }
								</div>
							</>
						) }
					</div>

					<div className="ec-asm__shipping-section">
						<h4>Shipping</h4>
						<label className="ec-asm__ships-free-toggle">
							<input
								type="checkbox"
								checked={ !! draft.ships_free }
								onChange={ ( e ) =>
									setDraft( ( prev ) => ( { ...prev, ships_free: e.target.checked } ) )
								}
							/>
							Ships free
						</label>
						<p className="ec-asm__muted">
							For small items you'll ship yourself without a label (stickers, patches, etc.)
						</p>
					</div>

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

export default ProductsTab;
