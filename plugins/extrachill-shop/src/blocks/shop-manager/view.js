/**
 * External dependencies
 */
import {
	ActionRow,
	BlockShell,
	BlockShellHeader,
	BlockShellInner,
	ResponsiveTabs,
} from '@extrachill/components';

/**
 * WordPress dependencies
 */
import {
	useEffect,
	useMemo,
	useState,
	useCallback,
	useRef,
	render,
} from '@wordpress/element';

/**
 * Internal dependencies
 */
import SellerSwitcher from './components/SellerSwitcher';
import OrdersTab from './components/OrdersTab';
import PaymentsTab from './components/PaymentsTab';
import ProductsTab from './components/ProductsTab';
import ShippingTab from './components/ShippingTab';
import {
	configureApi,
	createStripeConnectDashboardLink,
	createStripeConnectOnboardingLink,
	getStripeConnectStatus,
	listShopOrders,
	listShopProducts,
	refundShopOrder,
	selectSeller,
	updateShopOrderStatus,
} from './api';
import { sellerKey, sellerLabel, sellerUrl } from './sellers';

const App = ( { config } ) => {
	const sellers = useMemo(
		() => ( Array.isArray( config.sellers ) ? config.sellers : [] ),
		[ config.sellers ]
	);
	// The seller is fixed for the life of the page: switching navigates through
	// the server so every tab resets and authority is checked again.
	const seller = config.selected || null;
	const [ activeTab, setActiveTab ] = useState( 'products' );
	const [ products, setProducts ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ stripeStatus, setStripeStatus ] = useState( null );
	const [ stripeLoading, setStripeLoading ] = useState( false );
	const [ stripeError, setStripeError ] = useState( '' );

	const [ orders, setOrders ] = useState( [] );
	const [ ordersLoading, setOrdersLoading ] = useState( false );
	const [ ordersError, setOrdersError ] = useState( '' );
	const [ ordersFilter, setOrdersFilter ] = useState( 'all' );
	const [ selectedOrder, setSelectedOrder ] = useState( null );
	const [ needsFulfillmentCount, setNeedsFulfillmentCount ] = useState( 0 );

	const tabs = useMemo(
		() => [
			{ id: 'products', label: 'Products' },
			{ id: 'orders', label: 'Orders', badge: needsFulfillmentCount },
			{ id: 'shipping', label: 'Shipping' },
			{ id: 'payments', label: 'Payments' },
		],
		[ needsFulfillmentCount ]
	);

	const load = useCallback( async () => {
		setLoading( true );
		setError( '' );
		try {
			const data = await listShopProducts( seller );
			setProducts( Array.isArray( data ) ? data : [] );
		} catch ( err ) {
			setError( err?.message || 'Could not load products.' );
		} finally {
			setLoading( false );
		}
	}, [ seller ] );

	const loadOrders = useCallback( async () => {
		if ( ! seller ) {
			setOrders( [] );
			setNeedsFulfillmentCount( 0 );
			return;
		}
		setOrdersLoading( true );
		setOrdersError( '' );
		try {
			const data = await listShopOrders( seller, ordersFilter );
			setOrders( Array.isArray( data?.orders ) ? data.orders : [] );
			setNeedsFulfillmentCount( data?.needs_fulfillment_count || 0 );
		} catch ( err ) {
			setOrdersError( err?.message || 'Could not load orders.' );
		} finally {
			setOrdersLoading( false );
		}
	}, [ seller, ordersFilter ] );

	const stripeRequestInFlight = useRef( false );

	const loadStripe = useCallback( async () => {
		if ( stripeRequestInFlight.current ) {
			return;
		}

		if ( ! seller ) {
			stripeRequestInFlight.current = false;
			setStripeStatus( null );
			setStripeError( '' );
			setStripeLoading( false );
			return;
		}

		stripeRequestInFlight.current = true;
		setStripeLoading( true );
		setStripeError( '' );
		try {
			const data = await getStripeConnectStatus( seller );
			setStripeStatus( data || null );
		} catch ( err ) {
			setStripeError( err?.message || 'Could not load Stripe status.' );
		} finally {
			stripeRequestInFlight.current = false;
			setStripeLoading( false );
		}
	}, [ seller ] );

	useEffect( () => {
		load();
	}, [ load ] );

	// Products need Stripe status to gate publishing, so fetch it from Shop up
	// front instead of trusting seller metadata.
	useEffect( () => {
		loadStripe();
	}, [ loadStripe ] );

	// Remember an explicit selection (e.g. an incoming ?seller= link).
	useEffect( () => {
		if ( seller ) {
			selectSeller( seller ).catch( () => {} );
		}
	}, [ seller ] );

	useEffect( () => {
		if ( activeTab !== 'orders' ) {
			return;
		}
		loadOrders();
	}, [ activeTab, loadOrders ] );

	const onSellerChange = useCallback(
		async ( next ) => {
			try {
				await selectSeller( next );
			} catch {
				// Navigation still carries the selection; the server re-checks it.
			}
			window.location.assign( sellerUrl( config.pageUrl, next ) );
		},
		[ config.pageUrl ]
	);

	const openPaymentsTab = useCallback( () => {
		setActiveTab( 'payments' );
	}, [] );

	const handleMarkShipped = useCallback(
		async ( orderId, trackingNumber ) => {
			await updateShopOrderStatus(
				orderId,
				seller,
				'completed',
				trackingNumber
			);
			await loadOrders();
		},
		[ seller, loadOrders ]
	);

	const handleRefundOrder = useCallback(
		async ( orderId ) => {
			await refundShopOrder( orderId, seller );
			await loadOrders();
		},
		[ seller, loadOrders ]
	);

	const connectStripe = useCallback( async () => {
		if ( ! seller ) {
			setStripeError( 'Select a shop first.' );
			return;
		}
		setStripeLoading( true );
		setStripeError( '' );
		try {
			const data = await createStripeConnectOnboardingLink( seller );
			const url = data?.url;
			if ( ! url ) {
				throw new Error( 'Onboarding link missing.' );
			}
			window.location.assign( url );
		} catch ( err ) {
			setStripeError(
				err?.message || 'Could not start Stripe onboarding.'
			);
			setStripeLoading( false );
		}
	}, [ seller ] );

	const openStripeDashboard = useCallback( async () => {
		if ( ! seller ) {
			setStripeError( 'Select a shop first.' );
			return;
		}
		setStripeLoading( true );
		setStripeError( '' );
		try {
			const data = await createStripeConnectDashboardLink( seller );
			const url = data?.url;
			if ( ! url ) {
				throw new Error( 'Dashboard link missing.' );
			}
			window.open( url, '_blank', 'noopener,noreferrer' );
		} catch ( err ) {
			setStripeError(
				err?.message || 'Could not open Stripe dashboard.'
			);
		} finally {
			setStripeLoading( false );
		}
	}, [ seller ] );

	const currentSeller = sellers.find(
		( s ) => sellerKey( s ) === sellerKey( seller )
	);
	const storefrontUrl = currentSeller?.storefront_url || '';
	const renderPanel = ( id ) => {
		switch ( id ) {
			case 'products':
				return (
					<ProductsTab
						seller={ seller }
						products={ products }
						loading={ loading }
						error={ error }
						stripeStatus={ stripeStatus }
						onOpenPayments={ openPaymentsTab }
						onRefresh={ load }
					/>
				);
			case 'orders':
				return (
					<OrdersTab
						seller={ seller }
						orders={ orders }
						loading={ ordersLoading }
						error={ ordersError }
						filter={ ordersFilter }
						selectedOrder={ selectedOrder }
						onFilterChange={ setOrdersFilter }
						onSelectOrder={ setSelectedOrder }
						onMarkShipped={ handleMarkShipped }
						onRefund={ handleRefundOrder }
						onRefresh={ loadOrders }
					/>
				);
			case 'shipping':
				return <ShippingTab seller={ seller } />;
			case 'payments':
				return (
					<PaymentsTab
						seller={ seller }
						status={ stripeStatus }
						loading={ stripeLoading }
						error={ stripeError }
						onConnect={ connectStripe }
						onOpenDashboard={ openStripeDashboard }
						onRefresh={ loadStripe }
					/>
				);
			default:
				return null;
		}
	};

	return (
		<BlockShell className="ec-asm">
			<BlockShellInner maxWidth="wide">
				<BlockShellHeader
					title={
						currentSeller ? (
							<h2 className="ec-asm__title">
								{ sellerLabel( currentSeller ) }
							</h2>
						) : null
					}
					actions={
						<ActionRow align="end" className="ec-asm__toolbar">
							<SellerSwitcher
								sellers={ sellers }
								selected={ seller }
								onChange={ onSellerChange }
							/>
							{ storefrontUrl && (
								<a
									href={ storefrontUrl }
									className="button-3 button-small"
								>
									View Shop
								</a>
							) }
						</ActionRow>
					}
				/>

				<ResponsiveTabs
					tabs={ tabs }
					active={ activeTab }
					onChange={ setActiveTab }
					renderPanel={ renderPanel }
					showDesktopTabs={ true }
				/>
			</BlockShellInner>
		</BlockShell>
	);
};

document.addEventListener( 'DOMContentLoaded', () => {
	const el = document.getElementById( 'ec-shop-manager-root' );
	if ( ! el ) {
		return;
	}
	let config = {};
	try {
		config = JSON.parse( el.dataset.config || '{}' );
	} catch {
		config = {};
	}
	configureApi( { restUrl: config.restUrl, nonce: config.nonce } );
	render( <App config={ config } />, el );
} );
