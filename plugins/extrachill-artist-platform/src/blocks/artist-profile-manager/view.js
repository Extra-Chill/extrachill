import React, { useEffect, useMemo, useState } from 'react';
import { render } from '@wordpress/element';
import {
	getArtist,
	createArtist,
	updateArtist,
	getRoster,
	inviteRosterMember,
	removeRosterMember,
	cancelRosterInvite,
	getSubscribers,
	exportSubscribers,
	uploadMedia,
	deleteMedia,
	getArtistPermissions,
} from '../shared/api/client';
import ArtistSwitcher from '../shared/components/ArtistSwitcher';

const useConfig = () => {
	const config = window.ecArtistPlatformConfig || {};
	return useMemo(
		() => ({
			restUrl: config.restUrl,
			nonce: config.nonce,
			userArtists: Array.isArray(config.userArtists) ? config.userArtists : [],
			selectedId: config.selectedId || 0,
			canCreate: !!config.canCreate,
			fromJoin: !!config.fromJoin,
			prefill: config.prefill || {},
			assets: config.assets || {},
		}),
		[ config ]
	);
};

const TabNav = ({ tabs, active, onChange }) => (
	<div className="ec-apm__tabs">
		{tabs.map((tab) => (
			<button
				key={tab.id}
				type="button"
				className={`ec-apm__tab${active === tab.id ? ' is-active' : ''}`}
				onClick={() => onChange(tab.id)}
			>
				{tab.label}
			</button>
		))}
	</div>
);



const InfoTab = ({ artist, onSave, saving, prefill, canCreate, selectedId, linkPageId }) => {
	const [name, setName] = useState(artist?.name || prefill.artist_name || '');
	const [bio, setBio] = useState(artist?.bio || prefill.artist_bio || '');
	const [localCity, setLocalCity] = useState(artist?.local_city || '');
	const [genre, setGenre] = useState(artist?.genre || '');
	const [profileImage, setProfileImage] = useState(artist?.profile_image_url || prefill.avatar_thumb || '');
	const [headerImage, setHeaderImage] = useState(artist?.header_image_url || '');
	const [profileImageId, setProfileImageId] = useState(artist?.profile_image_id || prefill.avatar_id || null);
	const [headerImageId, setHeaderImageId] = useState(artist?.header_image_id || null);

	useEffect(() => {
		setName(artist?.name || prefill.artist_name || '');
		setBio(artist?.bio || prefill.artist_bio || '');
		setLocalCity(artist?.local_city || '');
		setGenre(artist?.genre || '');
		setProfileImage(artist?.profile_image_url || prefill.avatar_thumb || '');
		setHeaderImage(artist?.header_image_url || '');
		setProfileImageId(artist?.profile_image_id || prefill.avatar_id || null);
		setHeaderImageId(artist?.header_image_id || null);
	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ artist?.id ]);

	const handleFileUpload = async (file, contextKey) => {
		const response = await uploadMedia('artist', selectedId || 0, file);
		if (contextKey === 'profile') {
			setProfileImage(response?.url || '');
			setProfileImageId(response?.id || null);
		}
		if (contextKey === 'header') {
			setHeaderImage(response?.url || '');
			setHeaderImageId(response?.id || null);
		}
	};

	const handleRemoveImage = async (contextKey) => {
		if (contextKey === 'profile' && profileImageId) {
			await deleteMedia('artist', profileImageId);
			setProfileImage('');
			setProfileImageId(null);
		}
		if (contextKey === 'header' && headerImageId) {
			await deleteMedia('artist', headerImageId);
			setHeaderImage('');
			setHeaderImageId(null);
		}
	};

	const handleSubmit = (e) => {
		e.preventDefault();
		onSave({
			name,
			bio,
			local_city: localCity,
			genre,
			profile_image_id: profileImageId,
			header_image_id: headerImageId,
		});
	};

	const linkButtonLabel = linkPageId ? 'Manage Link Page' : 'Create Link Page';

	return (
		<form className="ec-apm__form" onSubmit={handleSubmit}>
			<div className="ec-apm__header">
				<h2>Artist Info</h2>
				<button
					type="button"
					className={`button-2 button-medium${linkPageId ? '' : ' disabled'}`}
					disabled={!linkPageId}
					onClick={() => {
						if (linkPageId) {
							window.location.href = `${window.location.origin}/manage-link-page/`;
						}
					}}
				>
					{linkButtonLabel}
				</button>
			</div>

			<label className="ec-apm__field">
				<span>Profile Picture</span>
				{profileImage && <img src={profileImage} alt="Profile" className="ec-apm__image-preview" />}
				<input
					type="file"
					accept="image/*"
					onChange={(e) => {
						const file = e.target.files?.[0];
						if (file) handleFileUpload(file, 'profile');
					}}
				/>
				{profileImage && (
					<button type="button" className="button-danger button-small" onClick={() => handleRemoveImage('profile')}>
						Remove
					</button>
				)}
			</label>

			<label className="ec-apm__field">
				<span>Header Image</span>
				{headerImage && <img src={headerImage} alt="Header" className="ec-apm__image-preview" />}
				<input
					type="file"
					accept="image/*"
					onChange={(e) => {
						const file = e.target.files?.[0];
						if (file) handleFileUpload(file, 'header');
					}}
				/>
				{headerImage && (
					<button type="button" className="button-danger button-small" onClick={() => handleRemoveImage('header')}>
						Remove
					</button>
				)}
			</label>

			<label className="ec-apm__field">
				<span>Artist Name *</span>
				<input value={name} onChange={(e) => setName(e.target.value)} required />
			</label>

			<label className="ec-apm__field">
				<span>City / Region</span>
				<input value={localCity} onChange={(e) => setLocalCity(e.target.value)} />
			</label>

			<label className="ec-apm__field">
				<span>Genre</span>
				<input value={genre} onChange={(e) => setGenre(e.target.value)} />
			</label>

			<label className="ec-apm__field">
				<span>Artist Bio</span>
				<textarea value={bio} onChange={(e) => setBio(e.target.value)} rows={6} />
			</label>

			<div className="ec-apm__actions">
				<button type="submit" className="button-1 button-medium" disabled={saving}>
					{saving ? (selectedId ? 'Saving…' : 'Creating…') : selectedId ? 'Save' : 'Create Artist'}
				</button>
			</div>
		</form>
	);
};

const ManagersTab = ({ artistId }) => {
	const [loading, setLoading] = useState(false);
	const [roster, setRoster] = useState([]);
	const [invites, setInvites] = useState([]);
	const [email, setEmail] = useState('');
	const [error, setError] = useState('');

	const load = async () => {
		setLoading(true);
		try {
			const data = await getRoster(artistId);
			setRoster(Array.isArray(data.members) ? data.members : []);
			setInvites(Array.isArray(data.invites) ? data.invites : []);
			setError('');
		} catch (err) {
			setError('Could not load managers.');
		} finally {
			setLoading(false);
		}
	};

	useEffect(() => {
		if (artistId) {
			load();
		}
	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ artistId ]);

	const invite = async () => {
		if (!email) return;
		setLoading(true);
		try {
			await inviteRosterMember(artistId, email);
			setEmail('');
			await load();
		} catch (err) {
			setError(err?.message || 'Error sending invite.');
		} finally {
			setLoading(false);
		}
	};

	const remove = async (userId) => {
		setLoading(true);
		try {
			await removeRosterMember(artistId, userId);
			await load();
		} catch (err) {
			setError(err?.message || 'Error removing member.');
		} finally {
			setLoading(false);
		}
	};

	const cancelInvite = async (inviteId) => {
		setLoading(true);
		try {
			await cancelRosterInvite(artistId, inviteId);
			await load();
		} catch (err) {
			setError(err?.message || 'Error cancelling invite.');
		} finally {
			setLoading(false);
		}
	};

	return (
		<div className="ec-apm__panel">
			<h3>Profile Managers</h3>
			{loading && <p>Loading…</p>}
			{error && <p className="ec-apm__error">{error}</p>}
			<div className="ec-apm__inline">
				<input
					type="email"
					value={email}
					onChange={(e) => setEmail(e.target.value)}
					placeholder="Invite by email"
				/>
				<button type="button" className="button-1 button-medium" onClick={invite} disabled={loading || !email}>
					Send Invite
				</button>
			</div>
			<div className="ec-apm__list">
				{roster.map((member) => (
					<div key={member.user_id} className="ec-apm__list-item">
						<div>
							<strong>{member.display_name}</strong>
							{member.status && <span className="ec-apm__pill">{member.status}</span>}
						</div>
						<button type="button" className="button-danger button-small" onClick={() => remove(member.user_id)}>
							Remove
						</button>
					</div>
				))}
				{invites.map((invite) => (
					<div key={invite.invite_id} className="ec-apm__list-item">
						<div>
							<strong>{invite.email}</strong>
							<span className="ec-apm__pill">Pending</span>
						</div>
						<button type="button" className="button-danger button-small" onClick={() => cancelInvite(invite.invite_id)}>
							Cancel
						</button>
					</div>
				))}
				{!loading && roster.length === 0 && invites.length === 0 && <p>No managers yet.</p>}
			</div>
		</div>
	);
};

const SubscribersTab = ({ artistId }) => {
	const [loading, setLoading] = useState(false);
	const [subs, setSubs] = useState([]);
	const [page, setPage] = useState(1);
	const [total, setTotal] = useState(0);
	const [error, setError] = useState('');

	const load = async (nextPage = 1) => {
		setLoading(true);
		try {
			const data = await getSubscribers(artistId, nextPage, 20);
			setSubs(Array.isArray(data.subscribers) ? data.subscribers : []);
			setTotal(data.total || 0);
			setPage(nextPage);
			setError('');
		} catch (err) {
			setError('Could not load subscribers.');
		} finally {
			setLoading(false);
		}
	};

	useEffect(() => {
		if (artistId) {
			load(1);
		}
	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ artistId ]);

	const exportCsv = async () => {
		try {
			const data = await exportSubscribers(artistId, false);
			if (data?.url) {
				window.location.href = data.url;
			}
		} catch (err) {
			setError('Export failed.');
		}
	};

	const totalPages = Math.max(1, Math.ceil(total / 20));

	return (
		<div className="ec-apm__panel">
			<h3>Subscribers</h3>
			{loading && <p>Loading…</p>}
			{error && <p className="ec-apm__error">{error}</p>}
			<div className="ec-apm__list">
				{subs.map((sub) => (
					<div key={sub.subscriber_id} className="ec-apm__list-item">
						<div>
							<strong>{sub.subscriber_email}</strong>
							{sub.username && <span className="ec-apm__pill">{sub.username}</span>}
						</div>
						<span className="ec-apm__pill">{sub.subscribed_at}</span>
					</div>
				))}
				{!loading && subs.length === 0 && <p>No subscribers yet.</p>}
			</div>
			<div className="ec-apm__footer">
				<button type="button" className="button-2 button-medium" onClick={exportCsv} disabled={!subs.length}>
					Export CSV
				</button>
				<div className="ec-apm__pagination">
					<button type="button" className="button-3 button-small" onClick={() => load(Math.max(1, page - 1))} disabled={page <= 1}>
						Prev
					</button>
					<span>
						Page {page} of {totalPages}
					</span>
					<button type="button" className="button-3 button-small" onClick={() => load(Math.min(totalPages, page + 1))} disabled={page >= totalPages}>
						Next
					</button>
				</div>
			</div>
		</div>
	);
};

const App = () => {
	const config = useConfig();
	const [activeTab, setActiveTab] = useState('info');
	const [selectedId, setSelectedId] = useState(config.selectedId || 0);
	const [artist, setArtist] = useState(null);
	const [loading, setLoading] = useState(false);
	const [saving, setSaving] = useState(false);
	const [error, setError] = useState('');
	const [permissions, setPermissions] = useState(null);
	const [creatingArtistId, setCreatingArtistId] = useState(0);
	const [artists, setArtists] = useState(config.userArtists);

	const tabs = [
		{ id: 'info', label: 'Info' },
		{ id: 'managers', label: 'Profile Managers' },
		{ id: 'subscribers', label: 'Subscribers' },
	];


	const loadArtist = async (id) => {
		if (!id) {
			setArtist(null);
			return;
		}
		setLoading(true);
		try {
			const data = await getArtist(id);
			setArtist(data);
			setError('');
			const perms = await getArtistPermissions(id);
			setPermissions(perms || null);
		} catch (err) {
			setError(err?.message || 'Could not load artist.');
		} finally {
			setLoading(false);
		}
	};

	useEffect(() => {
		if (config.selectedId) {
			loadArtist(config.selectedId);
		}
	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ config.selectedId ]);

	const handleSave = async (payload) => {
		setSaving(true);
		try {
			if (selectedId) {
				const updated = await updateArtist(selectedId, payload);
				setArtist(updated);
				setError('');
			} else if (config.canCreate) {
				const created = await createArtist(payload);
				setArtist(created);
				const createdId = created?.id || 0;
				setSelectedId(createdId);
				setArtists([...artists, created]);
				setCreatingArtistId(createdId);
				setError('');
				setActiveTab('managers');
			}
		} catch (err) {
			setError(err?.message || 'Save failed.');
		} finally {
			setSaving(false);
		}
	};


	const handleSelect = (id) => {
		setSelectedId(id || 0);
		loadArtist(id || 0);
	};

	const currentTabs = useMemo(() => {
		if (!selectedId) {
			return [ tabs[0] ];
		}
		return tabs;
	}, [ selectedId ]);

	return (
		<div className="ec-apm">
			<ArtistSwitcher
				artists={artists}
				selectedId={selectedId}
				onChange={handleSelect}
				showCreateOption={config.canCreate}
				showLabel={true}
				hideIfSingle={false}
				emptyStateMessage="Artist profiles are for artists and music professionals."
			/>

			{loading && <p>Loading artist…</p>}
			{error && <p className="ec-apm__error">{error}</p>}

			{!selectedId && config.canCreate && (
				<div className="notice notice-info">
					<p>Start by entering artist info, then save to create the profile.</p>
				</div>
			)}

			<TabNav tabs={currentTabs} active={activeTab} onChange={setActiveTab} />

			{activeTab === 'info' && (
				<InfoTab
					artist={artist}
					onSave={handleSave}
					saving={saving}
					prefill={config.prefill}
					canCreate={config.canCreate}
					selectedId={selectedId}
					linkPageId={artist?.link_page_id || null}
				/>
			)}

			{selectedId && activeTab === 'managers' && <ManagersTab artistId={selectedId} />}
			{selectedId && activeTab === 'subscribers' && <SubscribersTab artistId={selectedId} />}
		</div>
	);
};

const rootEl = document.getElementById('ec-artist-profile-manager-root');

if (rootEl) {
	render(<App />, rootEl);
}
