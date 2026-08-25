/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';

/**
 * External dependencies
 */
import {
	Badge,
	InlineStatus,
	Panel,
	PanelHeader,
} from '@extrachill/components';

/**
 * Internal dependencies
 */
import { errorDetails, runAbility } from './api';

const MAX_LINK_SECTIONS = 10;
const MAX_LINKS_PER_SECTION = 25;

const initialLinkPagePhase = ( status ) => {
	if ( status === 'not_provisioned' ) {
		return 'absent';
	}
	return status === 'unavailable' ? 'unavailable' : 'loading';
};

const normalizeLinkSections = ( document ) => {
	const sections = document?.link_page?.link_sections;
	if ( Array.isArray( sections ) && sections.length > 0 ) {
		return sections.map( ( section ) => ( {
			id: section.id || '',
			section_title: section.section_title || '',
			links: ( section.links || [] ).map( ( link ) => ( { ...link } ) ),
		} ) );
	}
	const links = ( document?.link_page?.links || [] ).filter(
		( link ) => ! Array.isArray( link.links )
	);
	return [ { id: '', section_title: '', links } ];
};

const saveableSections = ( sections ) =>
	sections.map( ( section ) => ( {
		...( section.id ? { id: section.id } : {} ),
		...( section.section_title
			? { section_title: section.section_title.trim() }
			: {} ),
		links: section.links.map( ( link ) => ( {
			...( link.id ? { id: link.id } : {} ),
			link_text: link.link_text.trim(),
			link_url: link.link_url.trim(),
			...( link.expires_at ? { expires_at: link.expires_at } : {} ),
		} ) ),
	} ) );

export function PromoterLinkPageManager( {
	promoterId,
	initialStatus,
	onDirtyChange = () => {},
} ) {
	const [ phase, setPhase ] = useState(
		initialLinkPagePhase( initialStatus )
	);
	const [ document, setDocument ] = useState( null );
	const [ sections, setSections ] = useState( [] );
	const [ message, setMessage ] = useState( '' );

	const acceptDocument = ( result, successMessage = '' ) => {
		setDocument( result );
		setSections( normalizeLinkSections( result ) );
		setMessage( successMessage );
		setPhase( 'ready' );
		onDirtyChange( false );
	};
	const load = async () => {
		setPhase( 'loading' );
		setMessage( '' );
		try {
			acceptDocument(
				await runAbility( 'extrachill/get-promoter-link-page', {
					promoter_term_id: promoterId,
				} )
			);
		} catch ( error ) {
			const details = errorDetails( error );
			if ( details.status === 404 ) {
				setPhase( 'absent' );
				return;
			}
			setMessage( details.message );
			setPhase( 'error' );
		}
	};

	useEffect( () => {
		if ( initialStatus === 'available' ) {
			load();
		} else if ( initialStatus === 'unavailable' ) {
			setPhase( 'unavailable' );
		} else {
			setPhase( 'absent' );
		}
		// Identity changes remount this exact manager scope.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ promoterId, initialStatus ] );

	const provision = async () => {
		setPhase( 'saving' );
		setMessage( '' );
		try {
			acceptDocument(
				await runAbility( 'extrachill/provision-promoter-link-page', {
					promoter_term_id: promoterId,
				} ),
				'Link Page created.'
			);
		} catch ( error ) {
			setMessage( errorDetails( error ).message );
			setPhase( 'error' );
		}
	};
	const save = async ( event ) => {
		event.preventDefault();
		setPhase( 'saving' );
		setMessage( '' );
		try {
			acceptDocument(
				await runAbility( 'extrachill/save-promoter-link-page-links', {
					promoter_term_id: promoterId,
					links: saveableSections( sections ),
				} ),
				'Links saved.'
			);
		} catch ( error ) {
			setMessage( errorDetails( error ).message );
			setPhase( 'error' );
		}
	};
	const updateSection = ( sectionIndex, changes ) => {
		onDirtyChange( true );
		setSections( ( current ) =>
			current.map( ( section, index ) =>
				index === sectionIndex ? { ...section, ...changes } : section
			)
		);
	};
	const updateLink = ( sectionIndex, linkIndex, changes ) => {
		onDirtyChange( true );
		setSections( ( current ) =>
			current.map( ( section, index ) =>
				index === sectionIndex
					? {
							...section,
							links: section.links.map( ( link, itemIndex ) =>
								itemIndex === linkIndex
									? { ...link, ...changes }
									: link
							),
					  }
					: section
			)
		);
	};

	return (
		<section
			id="promoter-link-page"
			className="ec-promoter-link-page-manager"
			aria-labelledby="promoter-link-page-heading"
			aria-busy={ phase === 'loading' || phase === 'saving' }
		>
			<Panel depth={ 2 }>
				<PanelHeader
					title="Promoter Link Page"
					description="Manage the public links for this promoter organization."
				/>
				<h2
					id="promoter-link-page-heading"
					className="screen-reader-text"
				>
					Promoter Link Page management
				</h2>
				{ phase === 'loading' && (
					<p role="status">Loading Link Page...</p>
				) }
				{ phase === 'absent' && (
					<div className="ec-promoter-link-page-manager__empty">
						<p>This promoter does not have a Link Page yet.</p>
						<button
							type="button"
							className="button-1"
							onClick={ provision }
						>
							Create Link Page
						</button>
					</div>
				) }
				{ phase === 'error' && (
					<InlineStatus tone="error" role="alert">
						{ message || 'Link Page management could not load.' }{ ' ' }
						<button
							type="button"
							className="button-link"
							onClick={ load }
						>
							Retry
						</button>
					</InlineStatus>
				) }
				{ phase === 'unavailable' && (
					<InlineStatus tone="warning" role="status">
						Link Page management is unavailable because its runtime
						is not active.
					</InlineStatus>
				) }
				{ ( phase === 'ready' || phase === 'saving' ) && document && (
					<form
						onSubmit={ save }
						className="ec-promoter-link-page-manager__form"
					>
						{ document.link_page.public_url && (
							<p>
								<a
									href={ document.link_page.public_url }
									target="_blank"
									rel="noreferrer"
								>
									Open public Link Page
								</a>
							</p>
						) }
						<fieldset
							className="ec-promoter-link-page-manager__controls"
							disabled={ phase === 'saving' }
						>
							<legend className="screen-reader-text">
								Link Page editor controls
							</legend>
							{ sections.map( ( section, sectionIndex ) => (
								<fieldset
									key={
										section.id ||
										`section-${ sectionIndex }`
									}
								>
									<legend>
										Link section { sectionIndex + 1 }
									</legend>
									<label
										htmlFor={ `promoter-section-title-${ sectionIndex }` }
									>
										Section title
										<input
											id={ `promoter-section-title-${ sectionIndex }` }
											type="text"
											maxLength={ 200 }
											value={ section.section_title }
											onChange={ ( event ) =>
												updateSection( sectionIndex, {
													section_title:
														event.target.value,
												} )
											}
										/>
									</label>
									{ section.links.length === 0 && (
										<p>No links in this section.</p>
									) }
									{ section.links.map(
										( link, linkIndex ) => (
											<div
												className="ec-promoter-link-page-manager__link"
												key={
													link.id ||
													`link-${ linkIndex }`
												}
											>
												<label
													htmlFor={ `promoter-link-text-${ sectionIndex }-${ linkIndex }` }
												>
													Link text
													<input
														id={ `promoter-link-text-${ sectionIndex }-${ linkIndex }` }
														type="text"
														maxLength={ 200 }
														required
														value={ link.link_text }
														onChange={ ( event ) =>
															updateLink(
																sectionIndex,
																linkIndex,
																{
																	link_text:
																		event
																			.target
																			.value,
																}
															)
														}
													/>
												</label>
												<label
													htmlFor={ `promoter-link-url-${ sectionIndex }-${ linkIndex }` }
												>
													URL
													<input
														id={ `promoter-link-url-${ sectionIndex }-${ linkIndex }` }
														type="url"
														maxLength={ 2048 }
														required
														value={ link.link_url }
														onChange={ ( event ) =>
															updateLink(
																sectionIndex,
																linkIndex,
																{
																	link_url:
																		event
																			.target
																			.value,
																}
															)
														}
													/>
												</label>
												<button
													type="button"
													className="button-link-delete"
													onClick={ () =>
														updateSection(
															sectionIndex,
															{
																links: section.links.filter(
																	(
																		item,
																		index
																	) =>
																		index !==
																		linkIndex
																),
															}
														)
													}
												>
													Remove link
												</button>
											</div>
										)
									) }
									{ section.links.length <
										MAX_LINKS_PER_SECTION && (
										<button
											type="button"
											className="button-2"
											onClick={ () =>
												updateSection( sectionIndex, {
													links: [
														...section.links,
														{
															id: '',
															link_text: '',
															link_url: '',
														},
													],
												} )
											}
										>
											Add link
										</button>
									) }
								</fieldset>
							) ) }
							{ sections.length < MAX_LINK_SECTIONS && (
								<button
									type="button"
									className="button-2"
									onClick={ () => {
										onDirtyChange( true );
										setSections( ( current ) => [
											...current,
											{
												id: '',
												section_title: '',
												links: [],
											},
										] );
									} }
								>
									Add section
								</button>
							) }{ ' ' }
							<button
								type="submit"
								className="button-1"
								disabled={ phase === 'saving' }
							>
								{ phase === 'saving'
									? 'Saving...'
									: 'Save links' }
							</button>
						</fieldset>
						{ message && (
							<InlineStatus tone="success" role="status">
								{ message }
							</InlineStatus>
						) }
					</form>
				) }
			</Panel>
		</section>
	);
}

export function ManagedIdentitySelector( {
	identities,
	selectedReference,
	onChange,
} ) {
	const venueIdentities = identities.filter(
		( item ) => item.type === 'venue'
	);
	const promoterIdentities = identities.filter(
		( item ) => item.type === 'promoter'
	);
	const selectedIsCurrent = identities.some(
		( identity ) => identity.reference === selectedReference
	);
	return (
		<section
			className="ec-managed-identity"
			aria-labelledby="ec-managed-identity-heading"
		>
			<div>
				<h2 id="ec-managed-identity-heading">Active identity</h2>
				<p>
					Choose who you are managing. This selection changes
					workspace context, not account permissions.
				</p>
			</div>
			<label htmlFor="ec-managed-identity-select">
				<span className="screen-reader-text">
					Active managed identity
				</span>
				<select
					id="ec-managed-identity-select"
					value={ selectedReference }
					onChange={ ( event ) => onChange( event.target.value ) }
				>
					<option value="">All managed venues</option>
					{ selectedReference && ! selectedIsCurrent && (
						<option value={ selectedReference } disabled>
							Unavailable selected identity
						</option>
					) }
					{ venueIdentities.length > 0 && (
						<optgroup label="Venue identities">
							{ venueIdentities.map( ( identity ) => (
								<option
									key={ identity.reference }
									value={ identity.reference }
								>
									{ identity.name }
								</option>
							) ) }
						</optgroup>
					) }
					{ promoterIdentities.length > 0 && (
						<optgroup label="Promoter organizations">
							{ promoterIdentities.map( ( identity ) => (
								<option
									key={ identity.reference }
									value={ identity.reference }
								>
									{ identity.name }
								</option>
							) ) }
						</optgroup>
					) }
				</select>
			</label>
			{ promoterIdentities.length === 0 && (
				<p className="ec-managed-identity__empty">
					No active promoter identities are available for this
					account.
				</p>
			) }
		</section>
	);
}

export function PromoterWorkspacePanel( { workspace, onLinkPageDirtyChange } ) {
	const { selection, promoter, granted_venues: venues } = workspace;
	if ( selection.state === 'stale' ) {
		return (
			<InlineStatus tone="warning" role="status">
				This managed identity is no longer active. Choose another
				identity.
			</InlineStatus>
		);
	}
	if ( selection.state === 'denied' ) {
		return (
			<InlineStatus tone="error" role="alert">
				You do not have access to the selected managed identity.
			</InlineStatus>
		);
	}
	if ( selection.state !== 'active' || ! promoter ) {
		return (
			<InlineStatus tone="info" role="status">
				Choose a promoter identity to open its workspace.
			</InlineStatus>
		);
	}
	return (
		<div
			className="ec-promoter-workspace"
			aria-labelledby="promoter-workspace-heading"
		>
			<Panel depth={ 2 }>
				<PanelHeader
					title={ promoter.name }
					description="Active verified promoter organization"
				/>
				<h1
					id="promoter-workspace-heading"
					className="screen-reader-text"
				>
					{ promoter.name } promoter workspace
				</h1>
				<p>
					<Badge tone="success" variant="solid">
						Active member
					</Badge>{ ' ' }
					{ promoter.is_owner && (
						<Badge tone="info" variant="solid">
							Organization owner
						</Badge>
					) }
				</p>
			</Panel>

			<section aria-labelledby="promoter-venues-heading">
				<h2 id="promoter-venues-heading">Granted venues</h2>
				{ venues.length === 0 ? (
					<InlineStatus tone="info" role="status">
						This promoter has no active venue grants.
					</InlineStatus>
				) : (
					<div className="ec-promoter-workspace__venues">
						{ venues.map( ( venue ) => (
							<Panel compact depth={ 2 } key={ venue.id }>
								<h3>{ venue.name }</h3>
								<p>
									<Badge tone="info" variant="solid">
										{ venue.action_label }
									</Badge>
								</p>
							</Panel>
						) ) }
					</div>
				) }
			</section>

			<PromoterLinkPageManager
				promoterId={ promoter.id }
				initialStatus={ promoter.link_page.status }
				onDirtyChange={ onLinkPageDirtyChange }
			/>

			<InlineStatus tone="info">
				Promoter access is limited to organizing local support at the
				venues shown here. Venue settings, private booking data, team
				access, and finances remain venue-owned.
			</InlineStatus>
		</div>
	);
}

export function VenuePromoterRelationships( { relationships } ) {
	return (
		<section
			className="ec-venue-collaborations"
			aria-labelledby="venue-promoter-collaborations-heading"
		>
			<h2 id="venue-promoter-collaborations-heading">
				Promoter collaborations
			</h2>
			<p>Read-only delegated relationships for this venue.</p>
			{ relationships.length === 0 ? (
				<p>
					No promoter relationships have been created for this venue.
				</p>
			) : (
				<ul className="ec-venue-settings__records">
					{ relationships.map( ( relationship ) => (
						<li
							key={ `${ relationship.promoter_term_id }:${ relationship.action }` }
						>
							<span>
								<strong>{ relationship.promoter_name }</strong>{ ' ' }
								{ relationship.action_label }
							</span>
							<Badge
								tone={
									relationship.status === 'active'
										? 'success'
										: 'warning'
								}
								variant="solid"
							>
								{ relationship.status }
							</Badge>
						</li>
					) ) }
				</ul>
			) }
		</section>
	);
}
