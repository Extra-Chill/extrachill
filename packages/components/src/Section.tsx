import type { ReactNode } from 'react';

export interface SectionProps {
	children: ReactNode;
	className?: string;
	classPrefix?: string;
	depth?: 0 | 1 | 2 | 3;
}

export function Section( {
	children,
	className = '',
	classPrefix = 'ec-section',
	depth = 2,
}: SectionProps ) {
	const classes = [ classPrefix, `${ classPrefix }--depth-${ depth }`, className ]
		.filter( Boolean )
		.join( ' ' );

	return <div className={ classes }>{ children }</div>;
}
