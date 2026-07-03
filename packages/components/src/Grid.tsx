import type { CSSProperties, ReactNode } from 'react';

export interface GridProps {
	children: ReactNode;
	className?: string;
	classPrefix?: string;
	/**
	 * Minimum column width (the `minmax()` floor), e.g. `"320px"`. Drives the
	 * `--ec-card-grid-min` custom property. Below this width the grid collapses
	 * to a single column without overflowing.
	 */
	minColumnWidth?: string;
	/**
	 * Gap between grid items. Drives the `--ec-card-grid-gap` custom property;
	 * defaults to the shared spacing token when omitted.
	 */
	gap?: string;
	/**
	 * Optional hard cap on the number of columns.
	 *
	 * Pure `auto-fit` cannot express a maximum column count on its own, so this
	 * is applied by constraining the grid's `max-width` to
	 * `maxColumns * minColumnWidth` (plus gaps). This caps columns only when
	 * `minColumnWidth` is a plain pixel value; with other units the cap is a
	 * best-effort approximation. When you need an exact cap, prefer the CSS
	 * `.ec-card-grid` utility with a bespoke wrapper.
	 */
	maxColumns?: number;
}

export function Grid( {
	children,
	className = '',
	classPrefix = 'ec-card-grid',
	minColumnWidth,
	gap,
	maxColumns,
}: GridProps ) {
	const style: CSSProperties & Record<string, string> = {};

	if ( minColumnWidth ) {
		style[ '--ec-card-grid-min' ] = minColumnWidth;
	}

	if ( gap ) {
		style[ '--ec-card-grid-gap' ] = gap;
	}

	if ( maxColumns && maxColumns > 0 && minColumnWidth ) {
		const gapValue = gap ?? 'var(--spacing-md, 1rem)';
		style.maxWidth = `calc(${ minColumnWidth } * ${ maxColumns } + ${ gapValue } * ${ maxColumns - 1 })`;
	}

	return (
		<div
			className={ [ classPrefix, className ].filter( Boolean ).join( ' ' ) }
			style={ Object.keys( style ).length ? style : undefined }
		>
			{ children }
		</div>
	);
}
