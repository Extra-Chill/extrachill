import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Tabs, type TabItem } from './Tabs.tsx';

export interface ResponsiveTabsProps {
	tabs: TabItem[];
	active: string;
	onChange: ( id: string ) => void;
	renderPanel: ( id: string ) => ReactNode;
	className?: string;
	classPrefix?: string;
	innerClassName?: string;
	innerMaxWidth?: 'none' | 'narrow' | 'wide';
	tabsClassName?: string;
	tabsClassPrefix?: string;
	mobileBreakpoint?: number;
	accordionClassName?: string;
	showDesktopTabs?: boolean;
	syncWithHash?: boolean;
	hashPrefix?: string;
}

export function ResponsiveTabs( {
	tabs,
	active,
	onChange,
	renderPanel,
	className = '',
	classPrefix = 'ec-responsive-tabs',
	innerClassName = '',
	innerMaxWidth = 'none',
	tabsClassName = '',
	tabsClassPrefix = 'ec-tabs',
	mobileBreakpoint = 480,
	accordionClassName = '',
	showDesktopTabs = true,
	syncWithHash = false,
	hashPrefix = 'tab-',
}: ResponsiveTabsProps ) {
	const [ isMobile, setIsMobile ] = useState( () => {
		if ( typeof window === 'undefined' ) {
			return false;
		}

		return window.innerWidth < mobileBreakpoint;
	} );
	const [ mobileActive, setMobileActive ] = useState<string | null>( active );

	const resolveTabIdFromHash = ( hash: string ) => {
		const normalized = hash.replace( /^#/, '' );
		if ( ! normalized.startsWith( hashPrefix ) ) {
			return null;
		}

		const id = normalized.slice( hashPrefix.length );
		return tabs.some( ( tab ) => tab.id === id ) ? id : null;
	};

	const updateHash = ( id: string ) => {
		if ( ! syncWithHash || typeof window === 'undefined' ) {
			return;
		}

		window.location.hash = `${ hashPrefix }${ id }`;
	};

	const handleChange = ( id: string ) => {
		onChange( id );
		updateHash( id );
	};

	useEffect( () => {
		if ( typeof window === 'undefined' ) {
			return undefined;
		}

		const handleResize = () => {
			setIsMobile( window.innerWidth < mobileBreakpoint );
		};

		handleResize();
		window.addEventListener( 'resize', handleResize );

		return () => window.removeEventListener( 'resize', handleResize );
	}, [ mobileBreakpoint ] );

	useEffect( () => {
		if ( ! isMobile ) {
			setMobileActive( active );
		}
	}, [ active, isMobile ] );

	useEffect( () => {
		if ( ! syncWithHash || typeof window === 'undefined' || tabs.length === 0 ) {
			return undefined;
		}

		const syncFromHash = () => {
			const id = resolveTabIdFromHash( window.location.hash );
			if ( ! id ) {
				return;
			}

			onChange( id );
			setMobileActive( id );
		};

		syncFromHash();
		window.addEventListener( 'hashchange', syncFromHash );

		return () => window.removeEventListener( 'hashchange', syncFromHash );
	}, [ hashPrefix, onChange, syncWithHash, tabs ] );

	const rootClass = useMemo(
		() => [
			classPrefix,
			isMobile ? `${ classPrefix }--mobile` : `${ classPrefix }--desktop`,
			innerMaxWidth !== 'none' ? `${ classPrefix }--inner-${ innerMaxWidth }` : '',
			className,
		]
			.filter( Boolean )
			.join( ' ' ),
		[ className, classPrefix, isMobile ]
	);

	if ( tabs.length === 0 ) {
		return null;
	}

	if ( ! isMobile ) {
		return (
			<div className={ rootClass }>
				<div className={ [ `${ classPrefix }__inner`, innerClassName ].filter( Boolean ).join( ' ' ) }>
					{ showDesktopTabs && (
						<Tabs
							tabs={ tabs }
							active={ active }
							onChange={ handleChange }
							className={ tabsClassName }
							classPrefix={ tabsClassPrefix }
						/>
					) }
					<div className={ `${ classPrefix }__desktop-panel` }>{ renderPanel( active ) }</div>
				</div>
			</div>
		);
	}

	return (
		<div className={ rootClass }>
			<div className={ [ `${ classPrefix }__inner`, innerClassName ].filter( Boolean ).join( ' ' ) }>
				<div className={ [ `${ classPrefix }__accordion`, accordionClassName ].filter( Boolean ).join( ' ' ) }>
				{ tabs.map( ( tab ) => {
					const isActive = tab.id === mobileActive;

					return (
						<div key={ tab.id } className={ `${ classPrefix }__item${ isActive ? ' is-active' : '' }` }>
							<button
								type="button"
								className={ `${ classPrefix }__trigger${ isActive ? ' is-active' : '' }` }
								aria-expanded={ isActive }
								onClick={ () => {
								if ( isActive ) {
									setMobileActive( null );
									return;
								}

								setMobileActive( tab.id );
								handleChange( tab.id );
							} }
						>
								<span>{ tab.label }</span>
								<span className={ `${ classPrefix }__arrow` } aria-hidden="true">
									{ isActive ? '▲' : '▼' }
								</span>
							</button>
							{ isActive && <div className={ `${ classPrefix }__panel` }>{ renderPanel( tab.id ) }</div>}
						</div>
					);
				} ) }
				</div>
			</div>
		</div>
	);
}
