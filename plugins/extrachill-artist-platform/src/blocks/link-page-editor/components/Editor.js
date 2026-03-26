/**
 * Editor Component
 *
 * Main editor layout with tab navigation, artist switcher, and save button.
 */

import { useState, useCallback, useMemo, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ActionRow, BlockShell, BlockShellHeader, BlockShellInner, InlineStatus, Panel, Tabs } from '@extrachill/components';
import { useEditor } from '../context/EditorContext';
import ArtistSwitcher from '../../shared/components/ArtistSwitcher';
import Preview from './Preview';
import TabInfo from './tabs/TabInfo';
import TabLinks from './tabs/TabLinks';
import TabSocials from './tabs/TabSocials';
import TabCustomize from './tabs/TabCustomize';
import TabAdvanced from './tabs/TabAdvanced';
import JumpToPreview from './JumpToPreview';
import LinkPageUrl from './shared/LinkPageUrl';
import QRCodeModal from './shared/QRCodeModal';

const TABS = [
	{ id: 'info', label: __( 'Info', 'extrachill-artist-platform' ) },
	{ id: 'links', label: __( 'Links', 'extrachill-artist-platform' ) },
	{ id: 'socials', label: __( 'Socials', 'extrachill-artist-platform' ) },
	{ id: 'customize', label: __( 'Customize', 'extrachill-artist-platform' ) },
	{ id: 'advanced', label: __( 'Advanced', 'extrachill-artist-platform' ) },
];

export default function Editor() {
	const {
		artistId,
		activeTab,
		setActiveTab,
		isLoading,
		isSaving,
		error,
		saveError,
		userArtists,
		artist,
		saveAll,
		switchArtist,
		socialIconsCssUrl,
		fontAwesomeUrl,
	} = useEditor();

	const [ saveSuccess, setSaveSuccess ] = useState( false );
	const [ isQRModalOpen, setIsQRModalOpen ] = useState( false );

	const currentArtist = useMemo( () => {
		return userArtists.find( ( a ) => a.id === artistId );
	}, [ userArtists, artistId ] );

	const publicUrl = useMemo( () => {
		const slug = artist?.slug || currentArtist?.slug;
		return slug ? `https://extrachill.link/${ slug }` : null;
	}, [ artist?.slug, currentArtist?.slug ] );

	useEffect( () => {
		const ensureLink = ( id, href ) => {
			if ( ! href ) {
				return;
			}

			const existing = document.getElementById( id );
			if ( existing && existing.href === href ) {
				return;
			}

			if ( existing && existing.parentNode ) {
				existing.parentNode.removeChild( existing );
			}

			const link = document.createElement( 'link' );
			link.id = id;
			link.rel = 'stylesheet';
			link.href = href;
			document.head.appendChild( link );
		};

		ensureLink( 'ec-social-icons-editor-css', socialIconsCssUrl );
		ensureLink( 'ec-font-awesome-editor-css', fontAwesomeUrl );
	}, [ socialIconsCssUrl, fontAwesomeUrl ] );

	const handleSave = useCallback( async () => {
		setSaveSuccess( false );
		const success = await saveAll();
		if ( success ) {
			setSaveSuccess( true );
			setTimeout( () => setSaveSuccess( false ), 3000 );
		}
	}, [ saveAll ] );

	const handleArtistChange = useCallback(
		( newId ) => {
			if ( newId && newId !== artistId ) {
				switchArtist( newId );
			}
		},
		[ artistId, switchArtist ]
	);

	if ( isLoading ) {
		return (
			<div className="ec-editor-loading">
				<span className="spinner is-active"></span>
				<p>{ __( 'Loading editor...', 'extrachill-artist-platform' ) }</p>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="notice notice-error">
				<p>{ error }</p>
			</div>
		);
	}

	const renderTabContent = () => {
		switch ( activeTab ) {
			case 'info':
				return <TabInfo />;
			case 'links':
				return <TabLinks />;
			case 'socials':
				return <TabSocials />;
			case 'customize':
				return <TabCustomize />;
		case 'advanced':
			return <TabAdvanced />;
		default:
				return <TabInfo />;
		}
	};

	return (
		<BlockShell className="ec-editor">
			<BlockShellInner maxWidth="wide">
				<BlockShellHeader
					title={
						<div className="ec-editor__header-left">
							<LinkPageUrl
								publicUrl={ publicUrl }
								onQRCodeClick={ () => setIsQRModalOpen( true ) }
							/>
						</div>
					}
					actions={
						<ActionRow align="end" className="ec-editor__header-right">
							<ArtistSwitcher
								artists={ userArtists }
								selectedId={ artistId }
								onChange={ handleArtistChange }
							/>
							{ saveError && <InlineStatus tone="error">{ saveError }</InlineStatus> }
							{ saveSuccess && (
								<InlineStatus tone="success">
									{ __( 'Saved!', 'extrachill-artist-platform' ) }
								</InlineStatus>
							) }
							<button
								type="button"
								className="button-1 button-small"
								onClick={ handleSave }
								disabled={ isSaving }
							>
								{ isSaving
									? __( 'Saving...', 'extrachill-artist-platform' )
									: __( 'Save All', 'extrachill-artist-platform' ) }
							</button>
						</ActionRow>
					}
					showDivider={ false }
				/>

				<QRCodeModal
					isOpen={ isQRModalOpen }
					onClose={ () => setIsQRModalOpen( false ) }
					publicUrl={ publicUrl }
					artistSlug={ artist?.slug || currentArtist?.slug }
				/>

				<div className="ec-editor__body">
					<div className="ec-editor__sidebar">
						<Tabs
							tabs={ TABS }
							active={ activeTab }
							onChange={ setActiveTab }
							className="ec-editor__tabs"
						/>

						<Panel className="ec-editor__tab-content" compact depth={ 2 }>
							{ renderTabContent() }
						</Panel>
					</div>

					<div className="ec-editor__preview-container">
						<Preview />
					</div>
				</div>
				<JumpToPreview />
			</BlockShellInner>
		</BlockShell>
	);
}
