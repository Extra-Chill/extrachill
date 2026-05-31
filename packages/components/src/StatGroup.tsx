import type { ReactNode } from 'react';

export interface StatGroupProps {
	children: ReactNode;
	className?: string;
	classPrefix?: string;
}

export function StatGroup( {
	children,
	className = '',
	classPrefix = 'ec-stat-group',
}: StatGroupProps ) {
	return (
		<div className={ [ classPrefix, className ].filter( Boolean ).join( ' ' ) }>
			{ children }
		</div>
	);
}
