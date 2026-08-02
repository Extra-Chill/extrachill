/**
 * External dependencies
 */
import {
	ActionRow,
	FieldGroup,
	Grid,
	InlineStatus,
	Panel,
	PanelHeader,
} from '@extrachill/components';

/**
 * Internal dependencies
 */
import { Status } from './status';
import { normalizeKey, sameDocument, validateConfig } from './state';

export function GuideTab( {
	config,
	baseline,
	setConfig,
	onSave,
	saving,
	status,
} ) {
	const entries = config.booking_guide.entries;
	const errors = validateConfig( config );
	const dirty = ! sameDocument( config, baseline );
	const setEntries = ( next ) =>
		setConfig( {
			...config,
			booking_guide: { ...config.booking_guide, entries: next },
		} );
	const update = ( index, patch ) =>
		setEntries(
			entries.map( ( entry, current ) =>
				current === index ? { ...entry, ...patch } : entry
			)
		);
	const move = ( index, offset ) => {
		const next = [ ...entries ];
		[ next[ index ], next[ index + offset ] ] = [
			next[ index + offset ],
			next[ index ],
		];
		setEntries( next );
	};

	return (
		<Grid minColumnWidth="100%">
			<Panel>
				<PanelHeader
					title="Booking guide"
					description="Venue-owned answers shown before inquiry and used for grounded operator assistance. Keep personal coordinator details in managed booking communication, not guide entries."
				/>
				{ entries.length === 0 && (
					<p>
						No guide entries yet. Unknown questions will remain
						unanswered.
					</p>
				) }
				{ entries.map( ( entry, index ) => (
					<fieldset
						className="ec-venue-settings__repeater"
						key={ `${ entry.key }-${ index }` }
					>
						<legend>Guide entry { index + 1 }</legend>
						<Grid minColumnWidth="16rem" maxColumns={ 2 }>
							<FieldGroup
								label="Question or title"
								htmlFor={ `guide-title-${ index }` }
								required
							>
								<input
									id={ `guide-title-${ index }` }
									value={ entry.title }
									onChange={ ( event ) =>
										update( index, {
											title: event.target.value,
											key:
												entry.key ||
												normalizeKey(
													event.target.value
												),
										} )
									}
								/>
							</FieldGroup>
							<FieldGroup
								label="Stable key"
								htmlFor={ `guide-key-${ index }` }
								help="Used by grounded consumers when citing this answer."
								required
							>
								<input
									id={ `guide-key-${ index }` }
									value={ entry.key }
									onChange={ ( event ) =>
										update( index, {
											key: normalizeKey(
												event.target.value
											),
										} )
									}
								/>
							</FieldGroup>
						</Grid>
						<FieldGroup
							label="Answer"
							htmlFor={ `guide-body-${ index }` }
							help="State only venue-confirmed facts. Direct artists to the booking inquiry or thread when details vary."
							required
						>
							<textarea
								id={ `guide-body-${ index }` }
								maxLength="5000"
								value={ entry.body }
								onChange={ ( event ) =>
									update( index, {
										body: event.target.value,
									} )
								}
							/>
						</FieldGroup>
						<FieldGroup
							label="Visibility"
							htmlFor={ `guide-visibility-${ index }` }
						>
							<select
								id={ `guide-visibility-${ index }` }
								value={ entry.visibility }
								onChange={ ( event ) =>
									update( index, {
										visibility: event.target.value,
									} )
								}
							>
								<option value="public">Public</option>
								<option value="operator">Operators only</option>
							</select>
						</FieldGroup>
						<ActionRow>
							<button
								type="button"
								className="button-2"
								disabled={ index === 0 }
								onClick={ () => move( index, -1 ) }
							>
								Move up
							</button>
							<button
								type="button"
								className="button-2"
								disabled={ index === entries.length - 1 }
								onClick={ () => move( index, 1 ) }
							>
								Move down
							</button>
							<button
								type="button"
								className="button-link-delete"
								onClick={ () =>
									setEntries(
										entries.filter(
											( _, current ) => current !== index
										)
									)
								}
							>
								Remove entry
							</button>
						</ActionRow>
					</fieldset>
				) ) }
				<button
					type="button"
					className="button-2"
					onClick={ () =>
						setEntries( [
							...entries,
							{
								key: '',
								title: '',
								body: '',
								visibility: 'public',
							},
						] )
					}
				>
					Add guide entry
				</button>
			</Panel>
			{ errors.length > 0 && (
				<InlineStatus tone="error">
					<strong>Resolve before saving:</strong>
					<ul>
						{ errors.map( ( error ) => (
							<li key={ error }>{ error }</li>
						) ) }
					</ul>
				</InlineStatus>
			) }
			<Status state={ status } />
			<ActionRow>
				<button
					type="button"
					className="button-1"
					disabled={ ! dirty || saving || errors.length > 0 }
					onClick={ onSave }
				>
					{ saving ? 'Saving...' : 'Save booking guide' }
				</button>
				{ dirty && (
					<span className="ec-venue-settings__dirty">
						Unsaved guide changes
					</span>
				) }
			</ActionRow>
		</Grid>
	);
}
