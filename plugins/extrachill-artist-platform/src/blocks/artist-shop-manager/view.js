import { useEffect, useMemo, useState, useCallback, useRef } from '@wordpress/element';
import { render } from '@wordpress/element';
import ArtistSwitcher from '../shared/components/ArtistSwitcher';
import TabNav from '../shared/components/TabNav';
import OrdersTab from './components/tabs/OrdersTab';
import PaymentsTab from './components/tabs/PaymentsTab';
import ProductsTab from './components/tabs/ProductsTab';
import ShippingTab from './components/ShippingTab';
import {
	createStripeConnectDashboardLink,
	createStripeConnectOnboardingLink,
	getStripeConnectStatus,
	listShopOrders,
	listShopProducts,
	refundShopOrder,
	updateShopOrderStatus,
} from '../shared/api/client';

const useConfig = () => {
	const config = window.ecArtistShopManagerConfig || {};
	return useMemo(
		() => ({
			userArtists: Array.isArray( config.userArtists ) ? config.userArtists : [],
			selectedId: parseInt( config.selectedId, 10 ) || 0,
			shopSiteUrl: config.shopSiteUrl || '',
		}),
		[ config ]
	);
};

const getCachedStripeStatus = ( userArtists, artistId ) => {
	if ( ! artistId ) {
		return null;
	}

	const artist = ( userArtists || [] ).find( ( a ) => ( a.id || 0 ) === ( artistId || 0 ) );
	if ( ! artist ) {
		return null;
	}

	const connected = !! artist.stripe_connected;
	const status = artist.stripe_status || null;
	const canReceivePayments = !! artist.can_receive_payments;

	return {
		connected,
		status,
		can_receive_payments: canReceivePayments,
	};
};

const App = () => {
	const config = useConfig();
	const [ activeTab, setActiveTab ] = useState( 'products' );
	const [ artistId, setArtistId ] = useState( config.selectedId || 0 );
	const [ products, setProducts ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ stripeStatus, setStripeStatus ] = useState( () =>
		getCachedStripeStatus( config.userArtists, config.selectedId || 0 )
	);
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
			const data = await listShopProducts();
			setProducts( Array.isArray( data ) ? data : [] );
		} catch ( err ) {
			setError( err?.message || 'Could not load products.' );
		} finally {
			setLoading( false );
		}
	}, [] );

	const loadOrders = useCallback( async () => {
		if ( ! artistId ) {
			setOrders( [] );
			setNeedsFulfillmentCount( 0 );
			return;
		}
		setOrdersLoading( true );
		setOrdersError( '' );
		try {
			const data = await listShopOrders( artistId, ordersFilter );
			setOrders( Array.isArray( data?.orders ) ? data.orders : [] );
			setNeedsFulfillmentCount( data?.needs_fulfillment_count || 0 );
		} catch ( err ) {
			setOrdersError( err?.message || 'Could not load orders.' );
		} finally {
			setOrdersLoading( false );
		}
	}, [ artistId, ordersFilter ] );

	const stripeRequestInFlight = useRef( false );

	const loadStripe = useCallback( async () => {
		if ( stripeRequestInFlight.current ) {
			return;
		}

		if ( ! artistId ) {
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
			const data = await getStripeConnectStatus( artistId );
			setStripeStatus( data || null );
		} catch ( err ) {
			setStripeError( err?.message || 'Could not load Stripe status.' );
		} finally {
			stripeRequestInFlight.current = false;
			setStripeLoading( false );
		}
	}, [ artistId ] );

	useEffect( () => {
		load();
	}, [ load ] );

	useEffect( () => {
		if ( activeTab !== 'payments' ) {
			return;
		}
		loadStripe();
	}, [ activeTab, loadStripe ] );

	useEffect( () => {
		if ( activeTab !== 'orders' ) {
			return;
		}
		loadOrders();
	}, [ activeTab, loadOrders ] );

	// Stripe status is fetched only when Payments is active, or on manual refresh.


	const onArtistChange = useCallback(
		( newId ) => {
			setArtistId( newId );
			setStripeStatus( getCachedStripeStatus( config.userArtists, newId ) );
			setStripeError( '' );
			setOrders( [] );
			setSelectedOrder( null );
			setOrdersError( '' );
		},
		[ config.userArtists ]
	);

	const openPaymentsTab = useCallback( () => {
		setActiveTab( 'payments' );
	}, [] );

	const handleMarkShipped = useCallback(
		async ( orderId, trackingNumber ) => {
			await updateShopOrderStatus( orderId, artistId, 'completed', trackingNumber );
			await loadOrders();
		},
		[ artistId, loadOrders ]
	);

	const handleRefundOrder = useCallback(
		async ( orderId ) => {
			await refundShopOrder( orderId, artistId );
			await loadOrders();
		},
		[ artistId, loadOrders ]
	);

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

	const currentArtist = config.userArtists.find( ( a ) => a.id === artistId );
	const artistSlug = currentArtist?.slug || '';

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
				<div className="ec-asm__header-right">
					{ artistSlug && config.shopSiteUrl && (
						<a
							href={ `${ config.shopSiteUrl }/artist/${ artistSlug }/` }
							className="button-3 button-medium"
						>
							View Shop
						</a>
					) }
				</div>
			</div>

			<TabNav tabs={ tabs } active={ activeTab } onChange={ setActiveTab } classPrefix="ec-asm" />

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

			{ activeTab === 'orders' && (
				<OrdersTab
					artistId={ artistId }
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
			) }

			{ activeTab === 'shipping' && (
				<ShippingTab artistId={ artistId } />
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
