import type { ReactNode } from 'react';

export interface InlineStatusProps {
	children: ReactNode;
	tone: 'success' | 'error' | 'warning' | 'info';
	className?: string;
	classPrefix?: string;
}

export function InlineStatus( {
	children,
	tone,
	className = '',
	classPrefix = 'ec-inline-status',
}: InlineStatusProps ) {
	return (
		<div className={ [ classPrefix, `${ classPrefix }--${ tone }`, className ].filter( Boolean ).join( ' ' ) }>
			{ children }
		</div>
	);
}
