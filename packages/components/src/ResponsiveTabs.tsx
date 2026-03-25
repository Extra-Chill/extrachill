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
	tabsClassName?: string;
	tabsClassPrefix?: string;
	mobileBreakpoint?: number;
	accordionClassName?: string;
	showDesktopTabs?: boolean;
}

export function ResponsiveTabs( {
	tabs,
	active,
	onChange,
	renderPanel,
	className = '',
	classPrefix = 'ec-responsive-tabs',
	tabsClassName = '',
	tabsClassPrefix = 'ec-tabs',
	mobileBreakpoint = 768,
	accordionClassName = '',
	showDesktopTabs = true,
}: ResponsiveTabsProps ) {
	const [ isMobile, setIsMobile ] = useState( () => {
		if ( typeof window === 'undefined' ) {
			return false;
		}

		return window.innerWidth < mobileBreakpoint;
	} );

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

	const rootClass = useMemo(
		() => [
			classPrefix,
			isMobile ? `${ classPrefix }--mobile` : `${ classPrefix }--desktop`,
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
				{ showDesktopTabs && (
					<Tabs
						tabs={ tabs }
						active={ active }
						onChange={ onChange }
						className={ tabsClassName }
						classPrefix={ tabsClassPrefix }
					/>
				) }
				<div className={ `${ classPrefix }__desktop-panel` }>{ renderPanel( active ) }</div>
			</div>
		);
	}

	return (
		<div className={ rootClass }>
			<div className={ [ `${ classPrefix }__accordion`, accordionClassName ].filter( Boolean ).join( ' ' ) }>
				{ tabs.map( ( tab ) => {
					const isActive = tab.id === active;

					return (
						<div key={ tab.id } className={ `${ classPrefix }__item${ isActive ? ' is-active' : '' }` }>
							<button
								type="button"
								className={ `${ classPrefix }__trigger${ isActive ? ' is-active' : '' }` }
								aria-expanded={ isActive }
								onClick={ () => onChange( tab.id ) }
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
	);
}
