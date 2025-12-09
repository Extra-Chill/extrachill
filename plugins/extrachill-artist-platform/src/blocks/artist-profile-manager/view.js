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



const InfoTab = ({ formState, setFormState, selectedId }) => {
	const handleFileUpload = async (file, contextKey) => {
		const response = await uploadMedia('artist', selectedId || 0, file);
		if (contextKey === 'profile') {
			setFormState((prev) => ({
				...prev,
				profileImage: response?.url || '',
				profileImageId: response?.id || null,
			}));
		}
		if (contextKey === 'header') {
			setFormState((prev) => ({
				...prev,
				headerImage: response?.url || '',
				headerImageId: response?.id || null,
			}));
		}
	};

	const handleRemoveImage = async (contextKey) => {
		if (contextKey === 'profile' && formState.profileImageId) {
			await deleteMedia('artist', formState.profileImageId);
			setFormState((prev) => ({
				...prev,
				profileImage: '',
				profileImageId: null,
			}));
		}
		if (contextKey === 'header' && formState.headerImageId) {
			await deleteMedia('artist', formState.headerImageId);
			setFormState((prev) => ({
				...prev,
				headerImage: '',
				headerImageId: null,
			}));
		}
	};

	return (
		<div className="ec-apm__panel">
			<label className="ec-apm__field">
				<span>Profile Picture</span>
				{formState.profileImage && <img src={formState.profileImage} alt="Profile" className="ec-apm__image-preview" />}
				<input
					type="file"
					accept="image/*"
					onChange={(e) => {
						const file = e.target.files?.[0];
						if (file) handleFileUpload(file, 'profile');
					}}
				/>
				{formState.profileImage && (
					<button type="button" className="button-danger button-small" onClick={() => handleRemoveImage('profile')}>
						Remove
					</button>
				)}
			</label>

			<label className="ec-apm__field">
				<span>Header Image</span>
				{formState.headerImage && <img src={formState.headerImage} alt="Header" className="ec-apm__image-preview" />}
				<input
					type="file"
					accept="image/*"
					onChange={(e) => {
						const file = e.target.files?.[0];
						if (file) handleFileUpload(file, 'header');
					}}
				/>
				{formState.headerImage && (
					<button type="button" className="button-danger button-small" onClick={() => handleRemoveImage('header')}>
						Remove
					</button>
				)}
			</label>

			<label className="ec-apm__field">
				<span>Artist Name *</span>
				<input
					type="text"
					value={formState.name}
					onChange={(e) => setFormState((prev) => ({ ...prev, name: e.target.value }))}
					required
				/>
			</label>

			<label className="ec-apm__field">
				<span>City / Region</span>
				<input
					type="text"
					value={formState.localCity}
					onChange={(e) => setFormState((prev) => ({ ...prev, localCity: e.target.value }))}
				/>
			</label>

			<label className="ec-apm__field">
				<span>Genre</span>
				<input
					type="text"
					value={formState.genre}
					onChange={(e) => setFormState((prev) => ({ ...prev, genre: e.target.value }))}
				/>
			</label>

			<label className="ec-apm__field">
				<span>Artist Bio</span>
				<textarea
					value={formState.bio}
					onChange={(e) => setFormState((prev) => ({ ...prev, bio: e.target.value }))}
					rows={6}
				/>
			</label>
		</div>
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

const getInitialFormState = (artist, prefill) => ({
	name: artist?.name || prefill?.artist_name || '',
	bio: artist?.bio || prefill?.artist_bio || '',
	localCity: artist?.local_city || '',
	genre: artist?.genre || '',
	profileImage: artist?.profile_image_url || prefill?.avatar_thumb || '',
	headerImage: artist?.header_image_url || '',
	profileImageId: artist?.profile_image_id || prefill?.avatar_id || null,
	headerImageId: artist?.header_image_id || null,
});

const App = () => {
	const config = useConfig();
	const [activeTab, setActiveTab] = useState('info');
	const [selectedId, setSelectedId] = useState(config.selectedId || 0);
	const [artist, setArtist] = useState(null);
	const [loading, setLoading] = useState(false);
	const [saving, setSaving] = useState(false);
	const [saveSuccess, setSaveSuccess] = useState(false);
	const [error, setError] = useState('');
	const [artists, setArtists] = useState(config.userArtists);
	const [formState, setFormState] = useState(() => getInitialFormState(null, config.prefill));

	const tabs = [
		{ id: 'info', label: 'Info' },
		{ id: 'managers', label: 'Profile Managers' },
		{ id: 'subscribers', label: 'Subscribers' },
	];

	const loadArtist = async (id) => {
		if (!id) {
			setArtist(null);
			setFormState(getInitialFormState(null, config.prefill));
			return;
		}
		setLoading(true);
		try {
			const data = await getArtist(id);
			setArtist(data);
			setFormState(getInitialFormState(data, config.prefill));
			setError('');
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

	const handleSave = async () => {
		if (!formState.name.trim()) {
			setError('Artist name is required.');
			return;
		}

		setSaving(true);
		setSaveSuccess(false);
		setError('');

		const payload = {
			name: formState.name,
			bio: formState.bio,
			local_city: formState.localCity,
			genre: formState.genre,
			profile_image_id: formState.profileImageId,
			header_image_id: formState.headerImageId,
		};

		try {
			if (selectedId) {
				const updated = await updateArtist(selectedId, payload);
				setArtist(updated);
				setFormState(getInitialFormState(updated, config.prefill));
				setSaveSuccess(true);
				setTimeout(() => setSaveSuccess(false), 3000);
			} else if (config.canCreate) {
				const created = await createArtist(payload);
				setArtist(created);
				const createdId = created?.id || 0;
				setSelectedId(createdId);
				setArtists([...artists, { id: createdId, name: created.name, slug: created.slug }]);
				setFormState(getInitialFormState(created, config.prefill));
				setSaveSuccess(true);
				setTimeout(() => setSaveSuccess(false), 3000);
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

	const artistName = formState.name || (selectedId ? '' : 'New Artist');
	const saveButtonLabel = saving
		? (selectedId ? 'Saving…' : 'Creating…')
		: (selectedId ? 'Save' : 'Create Artist');

	return (
		<div className="ec-apm">
			<div className="ec-apm__header">
				<div className="ec-apm__header-left">
					<h2>{artistName}</h2>
					<ArtistSwitcher
						artists={artists}
						selectedId={selectedId}
						onChange={handleSelect}
						showCreateOption={config.canCreate}
						showLabel={false}
						hideIfSingle={false}
						emptyStateMessage="Artist profiles are for artists and music professionals."
					/>
				</div>
				<div className="ec-apm__header-right">
					{error && <span className="ec-apm__save-error">{error}</span>}
					{saveSuccess && <span className="ec-apm__save-success">Saved!</span>}
					<button
						type="button"
						className="button-1 button-medium"
						onClick={handleSave}
						disabled={saving}
					>
						{saveButtonLabel}
					</button>
				</div>
			</div>

			{loading && <p>Loading artist…</p>}

			{!selectedId && config.canCreate && (
				<div className="notice notice-info">
					<p>Start by entering artist info, then save to create the profile.</p>
				</div>
			)}

			<TabNav tabs={currentTabs} active={activeTab} onChange={setActiveTab} />

			{activeTab === 'info' && (
				<InfoTab
					formState={formState}
					setFormState={setFormState}
					selectedId={selectedId}
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
