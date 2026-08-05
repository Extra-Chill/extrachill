/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * External dependencies
 */
import type { ReactElement } from 'react';
import analyticsDateRangeRuntime from 'extrachill-analytics-date-range';

/**
 * Internal dependencies
 */
import type {
	AnalyticsDateRange,
	AnalyticsDateRangeController,
} from '../../types/date-range';
import { endsAfterYesterday, MAX_DATE_RANGE_DAYS } from './date-range-state';

interface DateRangeInputProps {
	id: string;
	value: AnalyticsDateRange;
	onChange: ( range: AnalyticsDateRange ) => void;
}

export const DateRangeInput = ( {
	id,
	value,
	onChange,
}: DateRangeInputProps ): ReactElement => {
	const inputRef = useRef< HTMLInputElement | null >( null );
	const controllerRef = useRef< AnalyticsDateRangeController | null >( null );
	const valueRef = useRef( value );
	const onChangeRef = useRef( onChange );
	const [ errorMessage, setErrorMessage ] = useState( '' );
	const errorId = `${ id }-error`;
	valueRef.current = value;
	onChangeRef.current = onChange;

	const restoreControlledValue = (): void => {
		const current = valueRef.current;
		controllerRef.current?.setRange(
			current.startDate,
			current.endDate,
			false
		);
	};

	useEffect( () => {
		if ( ! inputRef.current ) {
			return undefined;
		}

		let controller: AnalyticsDateRangeController;
		try {
			controller = analyticsDateRangeRuntime.create( inputRef.current, {
				startDate: valueRef.current.startDate,
				endDate: valueRef.current.endDate,
				maxDays: MAX_DATE_RANGE_DAYS,
				onChange: ( range ) => {
					if ( ! range ) {
						restoreControlledValue();
						return;
					}
					if ( endsAfterYesterday( range ) ) {
						setErrorMessage(
							__(
								'Choose a range ending no later than yesterday.',
								'extrachill-studio'
							)
						);
						restoreControlledValue();
						return;
					}
					setErrorMessage( '' );
					onChangeRef.current( range );
				},
				onError: () => {
					setErrorMessage(
						__(
							'Choose a complete range from 1 to 364 days.',
							'extrachill-studio'
						)
					);
					restoreControlledValue();
				},
			} );
			controllerRef.current = controller;
		} catch {
			setErrorMessage(
				__(
					'The date range control could not be loaded.',
					'extrachill-studio'
				)
			);
			return undefined;
		}

		return () => {
			controller.destroy();
			if ( controllerRef.current === controller ) {
				controllerRef.current = null;
			}
		};
	}, [] );

	useEffect( () => {
		const controller = controllerRef.current;
		if ( controller ) {
			setErrorMessage( '' );
		}
		const current = controller?.getRange();
		if (
			controller &&
			( current?.startDate !== value.startDate ||
				current?.endDate !== value.endDate )
		) {
			controller.setRange( value.startDate, value.endDate, false );
		}
	}, [ value.endDate, value.startDate ] );

	return (
		<>
			<input
				ref={ inputRef }
				id={ id }
				type="text"
				className="ec-toolbar__input ec-studio-network__date-input"
				aria-label={ __( 'Custom date range', 'extrachill-studio' ) }
				aria-describedby={ errorMessage ? errorId : undefined }
				aria-invalid={ errorMessage ? 'true' : undefined }
			/>
			{ errorMessage ? (
				<span
					id={ errorId }
					className="ec-studio-network__date-error"
					role="status"
				>
					{ errorMessage }
				</span>
			) : null }
		</>
	);
};
