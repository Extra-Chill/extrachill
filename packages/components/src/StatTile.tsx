import type { ReactNode } from 'react';

export interface StatTileProps {
	value: string | number;
	label: ReactNode;
	href?: string;
	tone?: 'default' | 'muted' | 'success' | 'error' | 'warning' | 'info';
	className?: string;
	classPrefix?: string;
}

export function StatTile( {
	value,
	label,
	href,
	tone = 'default',
	className = '',
	classPrefix = 'ec-stat-tile',
}: StatTileProps ) {
	const classes = [
		classPrefix,
		`${ classPrefix }--${ tone }`,
		href ? `${ classPrefix }--link` : '',
		className,
	].filter( Boolean ).join( ' ' );

	const inner = (
		<>
			<span className={ `${ classPrefix }__value` }>{ value }</span>
			<span className={ `${ classPrefix }__label` }>{ label }</span>
		</>
	);

	if ( href ) {
		return (
			<a className={ classes } href={ href }>
				{ inner }
			</a>
		);
	}

	return <div className={ classes }>{ inner }</div>;
}
