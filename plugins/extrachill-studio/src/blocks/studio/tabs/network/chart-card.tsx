/**
 * ChartCard — a titled panel that renders one of the standard analytics states.
 *
 * Every Network-tab chart shares the same state machine:
 *   loading          → fetch in flight
 *   error            → the fetch failed (real, surfaceable failure)
 *   notInstrumented  → the analytics layer returned a coverage gap (measured:
 *                      false / not_instrumented). NOT a zero — render the gap
 *                      reason rather than a misleading empty/zero chart.
 *   empty            → fetch succeeded but there is genuinely nothing to show
 *   default          → render children (the chart)
 *
 * Keeping the convention in one component means each chart only decides WHICH
 * state it is in; the presentation stays consistent across all four charts.
 */
import { __ } from '@wordpress/i18n';
import type { ReactElement, ReactNode } from 'react';
import { InlineStatus, Panel, PanelHeader } from '@extrachill/components';

export type ChartCardState =
	| 'loading'
	| 'error'
	| 'notInstrumented'
	| 'empty'
	| 'ready';

interface ChartCardProps {
	title: string;
	description?: string;
	state: ChartCardState;
	/** Shown in the error state. */
	errorMessage?: string;
	/** Shown in the notInstrumented state — the analytics coverage-gap reason. */
	notInstrumentedReason?: string;
	/** Shown in the empty state. */
	emptyMessage?: string;
	/** Optional headline figure rendered above the body in the ready state. */
	headline?: ReactNode;
	/** Extra class names for the card panel (e.g. a full-width span modifier). */
	className?: string;
	/** The chart / table — rendered only in the ready state. */
	children?: ReactNode;
}

export const ChartCard = ( {
	title,
	description,
	state,
	errorMessage,
	notInstrumentedReason,
	emptyMessage,
	headline,
	className,
	children,
}: ChartCardProps ): ReactElement => {
	const renderBody = (): ReactNode => {
		switch ( state ) {
			case 'loading':
				return (
					<p
						className="ec-studio-network__placeholder"
						aria-busy="true"
					>
						{ __( 'Loading…', 'extrachill-studio' ) }
					</p>
				);
			case 'error':
				return (
					<InlineStatus
						tone="error"
						className="ec-studio-network__status"
					>
						{ errorMessage ||
							__(
								'Could not load this data. Try again shortly.',
								'extrachill-studio'
							) }
					</InlineStatus>
				);
			case 'notInstrumented':
				return (
					<InlineStatus
						tone="warning"
						className="ec-studio-network__status"
					>
						{ notInstrumentedReason ||
							__(
								'Not instrumented yet — this dimension can’t be measured here.',
								'extrachill-studio'
							) }
					</InlineStatus>
				);
			case 'empty':
				return (
					<p className="ec-studio-network__placeholder">
						{ emptyMessage ||
							__(
								'No data for this window yet.',
								'extrachill-studio'
							) }
					</p>
				);
			case 'ready':
			default:
				return (
					<>
						{ headline ? (
							<div className="ec-studio-network__headline">
								{ headline }
							</div>
						) : null }
						{ children }
					</>
				);
		}
	};

	const panelClassName = [
		'ec-studio-panel',
		'ec-studio-network__card',
		className,
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<Panel className={ panelClassName } compact>
			<PanelHeader title={ title } description={ description } />
			<div className="ec-studio-network__card-body">{ renderBody() }</div>
		</Panel>
	);
};

export default ChartCard;
