import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const PRESETS = [ 7, 30, 90 ];

export default function DateRangeControl( {
	selection,
	onSelectPreset,
	onSelectCustom,
	onSelectExact,
	onReset,
} ) {
	const inputRef = useRef( null );
	const controllerRef = useRef( null );
	const [ dateError, setDateError ] = useState( '' );
	const presetLabels = {
		7: __( 'Last 7 days', 'extrachill-artist-platform' ),
		30: __( 'Last 30 days', 'extrachill-artist-platform' ),
		90: __( 'Last 90 days', 'extrachill-artist-platform' ),
	};

	useEffect( () => {
		if ( selection.mode !== 'exact' || ! inputRef.current ) {
			return undefined;
		}

		const runtime = window.ExtraChillAnalyticsDateRange;
		if ( ! runtime?.create ) {
			setDateError(
				__(
					'The custom date picker is temporarily unavailable.',
					'extrachill-artist-platform'
				)
			);
			return undefined;
		}

		try {
			controllerRef.current = runtime.create( inputRef.current, {
				maxDays: 90,
				onChange: ( range ) => {
					setDateError( '' );
					onSelectExact( range );
				},
				onError: ( error ) => {
					setDateError(
						error?.message ||
							__(
								'Choose a date range of 90 days or fewer.',
								'extrachill-artist-platform'
							)
					);
				},
			} );
		} catch ( error ) {
			setDateError(
				error?.message ||
					__(
						'The custom date picker could not be initialized.',
						'extrachill-artist-platform'
					)
			);
		}

		return () => {
			controllerRef.current?.destroy();
			controllerRef.current = null;
		};
	}, [ selection.mode, onSelectExact ] );

	const handleSelectionChange = useCallback(
		( event ) => {
			if ( event.target.value === 'exact' ) {
				onSelectCustom();
				return;
			}

			onSelectPreset( parseInt( event.target.value, 10 ) );
		},
		[ onSelectCustom, onSelectPreset ]
	);

	const handleReset = useCallback( () => {
		controllerRef.current?.reset();
		setDateError( '' );
		onReset();
	}, [ onReset ] );

	return (
		<div className="ec-aa__date-controls">
			<select
				value={ selection.mode === 'preset' ? selection.days : 'exact' }
				onChange={ handleSelectionChange }
				className="ec-aa__date-range"
				aria-label={ __(
					'Analytics date range',
					'extrachill-artist-platform'
				) }
			>
				{ PRESETS.map( ( days ) => (
					<option key={ days } value={ days }>
						{ presetLabels[ days ] }
					</option>
				) ) }
				<option value="exact">
					{ __( 'Custom dates', 'extrachill-artist-platform' ) }
				</option>
			</select>
			{ selection.mode === 'exact' && (
				<input
					ref={ inputRef }
					type="text"
					readOnly
					className="ec-aa__exact-dates"
					placeholder={ __(
						'Choose dates',
						'extrachill-artist-platform'
					) }
					aria-label={ __(
						'Custom analytics dates',
						'extrachill-artist-platform'
					) }
					aria-describedby={
						dateError ? 'ec-aa-date-error' : undefined
					}
				/>
			) }
			<button
				type="button"
				className="button-3 button-small"
				onClick={ handleReset }
			>
				{ __( 'Reset', 'extrachill-artist-platform' ) }
			</button>
			{ dateError && (
				<span
					id="ec-aa-date-error"
					className="ec-aa__date-error"
					role="alert"
				>
					{ dateError }
				</span>
			) }
		</div>
	);
}
