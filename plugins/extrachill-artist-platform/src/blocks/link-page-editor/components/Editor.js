/**
 * Editor Component
 *
 * Main editor layout with tab navigation, artist switcher, and save button.
 */

import { useState, useCallback, useMemo, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useEditor } from '../context/EditorContext';
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
		hasUnsavedChanges,
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
		( e ) => {
			const newId = parseInt( e.target.value, 10 );
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
		<div className="ec-editor">
			<div className="ec-editor__header">
				<div className="ec-editor__header-left">
					<LinkPageUrl
						publicUrl={ publicUrl }
						onQRCodeClick={ () => setIsQRModalOpen( true ) }
					/>
					{ userArtists.length > 1 && (
						<select
							className="ec-editor__artist-switcher"
							value={ artistId }
							onChange={ handleArtistChange }
						>
							{ userArtists.map( ( a ) => (
								<option key={ a.id } value={ a.id }>
									{ a.name }
								</option>
							) ) }
						</select>
					) }
				</div>

				<div className="ec-editor__header-right">
					{ saveError && (
						<span className="ec-editor__save-error">{ saveError }</span>
					) }
					{ saveSuccess && (
						<span className="ec-editor__save-success">
							{ __( 'Saved!', 'extrachill-artist-platform' ) }
						</span>
					) }
					<button
						type="button"
						className="button-1 button-medium"
						onClick={ handleSave }
						disabled={ isSaving }
					>
						{ isSaving
							? __( 'Saving...', 'extrachill-artist-platform' )
							: __( 'Save All', 'extrachill-artist-platform' ) }
					</button>
				</div>
			</div>

			<QRCodeModal
				isOpen={ isQRModalOpen }
				onClose={ () => setIsQRModalOpen( false ) }
				publicUrl={ publicUrl }
				artistSlug={ artist?.slug || currentArtist?.slug }
			/>

			<div className="ec-editor__body">
				<div className="ec-editor__sidebar">
					<nav className="ec-editor__tabs">
						{ TABS.map( ( tab ) => (
							<button
								key={ tab.id }
								type="button"
								className={ `ec-editor__tab ${
									activeTab === tab.id ? 'is-active' : ''
								}` }
								onClick={ () => setActiveTab( tab.id ) }
							>
								{ tab.label }
							</button>
						) ) }
					</nav>

					<div className="ec-editor__tab-content">
						{ renderTabContent() }
					</div>
				</div>

			<div className="ec-editor__preview-container">
				<Preview />
			</div>
		</div>
		<JumpToPreview />
	</div>
	);
}
