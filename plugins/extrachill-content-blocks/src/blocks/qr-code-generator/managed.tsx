/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

/**
 * External dependencies
 */
import {
	ActionRow,
	DataTable,
	FieldGroup,
	InlineStatus,
	Panel,
	PanelHeader,
} from '@extrachill/components';

export interface Team {
	type: string;
	id: number;
	name: string;
}
export interface ManagedCode extends Record< string, unknown > {
	id: number;
	label: string;
	destination: string;
	short_url: string;
	team_type: string;
	team_id: number;
	scans: number;
}

export function validDestination( value: string ) {
	try {
		return [ 'http:', 'https:' ].includes( new URL( value ).protocol );
	} catch {
		return false;
	}
}

function ability< T >(
	operation: string,
	input: Record< string, unknown > = {}
) {
	return apiFetch< T >( {
		path: `/wp-abilities/v1/abilities/extrachill/${ operation }-managed-qr-code/run`,
		method: 'POST',
		data: { input },
	} );
}

export function ManagedCodes( {
	teams,
	quota = 100,
	openInitially = false,
}: {
	teams: Team[];
	quota?: number;
	openInitially?: boolean;
} ) {
	const [ open, setOpen ] = useState( openInitially );
	const [ items, setItems ] = useState< ManagedCode[] >( [] );
	const [ loaded, setLoaded ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ editing, setEditing ] = useState< ManagedCode | null >( null );
	const [ teamKey, setTeamKey ] = useState(
		`${ teams[ 0 ].type }:${ teams[ 0 ].id }`
	);
	const [ label, setLabel ] = useState( '' );
	const [ destination, setDestination ] = useState( '' );
	const [ pendingDelete, setPendingDelete ] = useState< ManagedCode | null >(
		null
	);
	const [ image, setImage ] = useState( '' );
	const lock = useRef( false );
	const id = useRef(
		`ec-managed-qr-${ Math.random().toString( 36 ).slice( 2 ) }`
	).current;

	async function run( action: () => Promise< void > ) {
		if ( lock.current ) {
			return;
		}
		lock.current = true;
		setBusy( true );
		setError( '' );
		setStatus( '' );
		try {
			await action();
		} catch ( failure ) {
			setError(
				( failure as { message?: string } )?.message ||
					__(
						'Could not manage QR codes. Please try again.',
						'extrachill-content-blocks'
					)
			);
		} finally {
			lock.current = false;
			setBusy( false );
		}
	}

	async function load() {
		await run( async () => {
			const result = await ability< { items: ManagedCode[] } >( 'list' );
			setItems( result.items );
			setLoaded( true );
		} );
	}

	useEffect( () => {
		if ( open && ! loaded ) {
			void load();
		}
		// Loading is triggered on opening; failed requests can be retried explicitly.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ open ] );

	async function save( event: React.FormEvent ) {
		event.preventDefault();
		if ( ! validDestination( destination.trim() ) ) {
			setError(
				__(
					'Please enter a valid HTTP or HTTPS URL.',
					'extrachill-content-blocks'
				)
			);
			return;
		}
		await run( async () => {
			const team = teams.find(
				( item ) => `${ item.type }:${ item.id }` === teamKey
			)!;
			const input = {
				label: label.trim(),
				destination: destination.trim(),
			};
			const result = await ability< ManagedCode >(
				editing ? 'update' : 'create',
				editing
					? { ...input, id: editing.id }
					: { ...input, team_type: team.type, team_id: team.id }
			);
			setItems(
				editing
					? items.map( ( item ) =>
							item.id === result.id ? result : item
						)
					: [ ...items, result ]
			);
			setEditing( null );
			setLabel( '' );
			setDestination( '' );
			setStatus( __( 'QR code saved.', 'extrachill-content-blocks' ) );
		} );
	}

	return (
		<Panel>
			<PanelHeader
				title={ __( 'My QR codes', 'extrachill-content-blocks' ) }
				actions={
					<button
						type="button"
						className="button-2"
						aria-expanded={ open }
						onClick={ () => setOpen( ! open ) }
					>
						{ open
							? __( 'Close', 'extrachill-content-blocks' )
							: __( 'Open', 'extrachill-content-blocks' ) }
					</button>
				}
			/>
			{ open && (
				<div aria-busy={ busy }>
					{ error && (
						<div role="alert">
							<InlineStatus tone="error">{ error }</InlineStatus>
						</div>
					) }
					<div aria-live="polite">
						{ status && (
							<InlineStatus tone="success">
								{ status }
							</InlineStatus>
						) }
					</div>
					{ ! loaded && (
						<button
							type="button"
							className="button-2"
							disabled={ busy }
							onClick={ load }
						>
							{ __(
								'Load QR codes',
								'extrachill-content-blocks'
							) }
						</button>
					) }
					{ loaded && (
						<>
							{ teams.map( ( team ) => {
								const codes = items.filter(
									( item ) =>
										item.team_type === team.type &&
										item.team_id === team.id
								);
								return (
									<Panel
										key={ `${ team.type }:${ team.id }` }
									>
										<PanelHeader
											title={ team.name }
											description={ sprintf(
												/* translators: %d: number of QR codes the team can still create. */
												__(
													'%d codes remaining',
													'extrachill-content-blocks'
												),
												Math.max(
													0,
													quota - codes.length
												)
											) }
										/>
										<DataTable< ManagedCode >
											data={ codes }
											emptyMessage={ __(
												'No QR codes yet.',
												'extrachill-content-blocks'
											) }
											columns={ [
												{
													key: 'label',
													label: __(
														'Name',
														'extrachill-content-blocks'
													),
												},
												{
													key: 'destination',
													label: __(
														'Destination',
														'extrachill-content-blocks'
													),
												},
												{
													key: 'scans',
													label: __(
														'Fan reach (last 90 days)',
														'extrachill-content-blocks'
													),
													render: ( value ) =>
														sprintf(
															/* translators: %d: number of recorded scans. */
															__(
																'Scanned %d times',
																'extrachill-content-blocks'
															),
															value
														),
												},
												{
													key: 'short_url',
													label: __(
														'Short URL',
														'extrachill-content-blocks'
													),
													render: ( value ) => (
														<a
															href={ String(
																value
															) }
														>
															{ String( value ) }
														</a>
													),
												},
												{
													key: 'actions',
													label: __(
														'Actions',
														'extrachill-content-blocks'
													),
													render: (
														_value,
														code
													) => (
														<ActionRow>
															<button
																type="button"
																className="button-2 button-small"
																disabled={
																	busy
																}
																onClick={ () => {
																	setEditing(
																		code
																	);
																	setLabel(
																		code.label
																	);
																	setDestination(
																		code.destination
																	);
																} }
															>
																{ __(
																	'Edit / rename',
																	'extrachill-content-blocks'
																) }
															</button>
															<button
																type="button"
																className="button-3 button-small"
																disabled={
																	busy
																}
																onClick={ () =>
																	run(
																		async () => {
																			await navigator.clipboard.writeText(
																				code.short_url
																			);
																			setStatus(
																				__(
																					'Short URL copied.',
																					'extrachill-content-blocks'
																				)
																			);
																		}
																	)
																}
															>
																{ __(
																	'Copy short URL',
																	'extrachill-content-blocks'
																) }
															</button>
															<button
																type="button"
																className="button-3 button-small"
																disabled={
																	busy
																}
																onClick={ () =>
																	run(
																		async () => {
																			setImage(
																				''
																			);
																			const result =
																				await apiFetch< {
																					image_url: string;
																				} >(
																					{
																						path: '/extrachill/v1/tools/qr-code',
																						method: 'POST',
																						data: {
																							url: code.short_url,
																						},
																					}
																				);
																			if (
																				! result.image_url?.startsWith(
																					'data:image/png;base64,'
																				)
																			) {
																				throw new Error(
																					__(
																						'QR generation returned an invalid image.',
																						'extrachill-content-blocks'
																					)
																				);
																			}
																			setImage(
																				result.image_url
																			);
																		}
																	)
																}
															>
																{ __(
																	'Prepare PNG',
																	'extrachill-content-blocks'
																) }
															</button>
															<button
																type="button"
																className="button-3 button-small"
																disabled={
																	busy
																}
																onClick={ () =>
																	setPendingDelete(
																		code
																	)
																}
															>
																{ __(
																	'Delete',
																	'extrachill-content-blocks'
																) }
															</button>
														</ActionRow>
													),
												},
											] }
										/>
									</Panel>
								);
							} ) }
							{ image && (
								<a
									className="button-1"
									href={ image }
									download="managed-qr-code.png"
								>
									{ __(
										'Download PNG',
										'extrachill-content-blocks'
									) }
								</a>
							) }
							{ pendingDelete && (
								<Panel>
									<InlineStatus tone="warning">
										{ __(
											'Delete this QR code? Printed copies will no longer reach your destination.',
											'extrachill-content-blocks'
										) }
									</InlineStatus>
									<ActionRow>
										<button
											type="button"
											className="button-1"
											disabled={ busy }
											onClick={ () =>
												run( async () => {
													await ability( 'delete', {
														id: pendingDelete.id,
													} );
													setItems(
														items.filter(
															( item ) =>
																item.id !==
																pendingDelete.id
														)
													);
													if (
														editing?.id ===
														pendingDelete.id
													) {
														setEditing( null );
														setLabel( '' );
														setDestination( '' );
													}
													setPendingDelete( null );
												} )
											}
										>
											{ __(
												'Confirm delete',
												'extrachill-content-blocks'
											) }
										</button>
										<button
											type="button"
											className="button-2"
											disabled={ busy }
											onClick={ () =>
												setPendingDelete( null )
											}
										>
											{ __(
												'Cancel',
												'extrachill-content-blocks'
											) }
										</button>
									</ActionRow>
								</Panel>
							) }
							<form onSubmit={ save }>
								<fieldset disabled={ busy }>
									<legend>
										{ editing
											? __(
													'Edit QR code',
													'extrachill-content-blocks'
												)
											: __(
													'Create QR code',
													'extrachill-content-blocks'
												) }
									</legend>
									{ ! editing && (
										<FieldGroup
											label={ __(
												'Team',
												'extrachill-content-blocks'
											) }
											htmlFor={ `${ id }-team` }
										>
											<select
												id={ `${ id }-team` }
												value={ teamKey }
												onChange={ ( event ) =>
													setTeamKey(
														event.target.value
													)
												}
											>
												{ teams.map( ( team ) => (
													<option
														key={ `${ team.type }:${ team.id }` }
														value={ `${ team.type }:${ team.id }` }
													>
														{ team.name }
													</option>
												) ) }
											</select>
										</FieldGroup>
									) }
									<FieldGroup
										label={ __(
											'Name',
											'extrachill-content-blocks'
										) }
										htmlFor={ `${ id }-name` }
									>
										<input
											id={ `${ id }-name` }
											value={ label }
											maxLength={ 200 }
											required
											onChange={ ( event ) =>
												setLabel( event.target.value )
											}
										/>
									</FieldGroup>
									<FieldGroup
										label={ __(
											'Destination',
											'extrachill-content-blocks'
										) }
										htmlFor={ `${ id }-destination` }
									>
										<input
											id={ `${ id }-destination` }
											type="url"
											value={ destination }
											maxLength={ 2000 }
											required
											onChange={ ( event ) =>
												setDestination(
													event.target.value
												)
											}
										/>
									</FieldGroup>
									<ActionRow>
										<button
											type="submit"
											className="button-1"
										>
											{ editing
												? __(
														'Save changes',
														'extrachill-content-blocks'
													)
												: __(
														'Create QR code',
														'extrachill-content-blocks'
													) }
										</button>
										{ editing && (
											<button
												type="button"
												className="button-2"
												onClick={ () => {
													setEditing( null );
													setLabel( '' );
													setDestination( '' );
												} }
											>
												{ __(
													'Cancel editing',
													'extrachill-content-blocks'
												) }
											</button>
										) }
									</ActionRow>
								</fieldset>
							</form>
						</>
					) }
				</div>
			) }
		</Panel>
	);
}
