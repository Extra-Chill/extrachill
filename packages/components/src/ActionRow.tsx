import type { ReactNode } from 'react';

export interface ActionRowProps {
	children: ReactNode;
	align?: 'start' | 'between' | 'end';
	className?: string;
	classPrefix?: string;
}

export function ActionRow( {
	children,
	align = 'start',
	className = '',
	classPrefix = 'ec-action-row',
}: ActionRowProps ) {
	return (
		<div
			className={ [ classPrefix, `${ classPrefix }--${ align }`, className ].filter( Boolean ).join( ' ' ) }
		>
			{ children }
		</div>
	);
}
