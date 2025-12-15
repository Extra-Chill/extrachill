# Roster Invitation Flow Diagram

Flow showing how roster members are invited, accept invitations, and get synchronized with community forums.

## Roster Invitation & Acceptance Flow

```
START: Artist owner invites roster member
  │
  ├─ INVITATION INITIATION
  │  ├─ Artist navigates to Link Page Editor > Roster Tab
  │  ├─ Enters recipient email address
  │  ├─ Sends invitation via REST API endpoint
  │  └─ Function: ec_send_roster_invitation()
  │
  ├─ INVITATION EMAIL SENT
  │  ├─ Email Service: WordPress wp_mail()
  │  ├─ Recipient Email Features:
  │  │  ├─ Artist name
  │  │  ├─ Invitation message
  │  │  ├─ Accept link with unique token
  │  │  └─ Expiration date (7 days default)
  │  └─ Email Template: inc/artist-profiles/roster/artist-invitation-emails.php
  │
  ├─ INVITATION DATA STORED
  │  ├─ Database Table: {prefix}_artist_invitations
  │  ├─ Stored Fields:
  │  │  ├─ artist_id: Target artist
  │  │  ├─ recipient_email: Invited email
  │  │  ├─ token: Unique acceptance token
  │  │  ├─ status: 'pending' | 'accepted' | 'declined'
  │  │  ├─ sent_date: Invitation timestamp
  │  │  └─ expires_at: Token expiration
  │  └─ Function: ec_create_roster_invitation()
  │
  ├─ RECIPIENT RECEIVES EMAIL
  │  ├─ Email arrives from artist@extrachill.com
  │  ├─ Contains:
  │  │  ├─ Personalized greeting
  │  │  ├─ Artist name and bio
  │  │  ├─ "Accept Invitation" button/link
  │  │  └─ Link format: extrachill.link/roster-accept/{token}
  │  └─ Button also includes expiration warning if close to expiry
  │
  ├─ RECIPIENT CLICKS INVITATION LINK
  │  ├─ Link redirects to: extrachill.link/roster-accept/{token}
  │  ├─ sunrise.php maps to: artist.extrachill.com/roster-accept/{token}
  │  ├─ Validate token:
  │  │  ├─ Check token exists in database
  │  │  ├─ Check status is 'pending'
  │  │  └─ Check not expired
  │  ├─ IF invalid/expired:
  │  │  └─ Display error, offer to resend
  │  └─ IF valid:
  │     ├─ Check if recipient has account
  │     └─ Proceed to acceptance
  │
  ├─ ACCOUNT CHECK
  │  ├─ Query: user_exists($recipient_email)
  │  ├─ IF user exists:
  │  │  ├─ Prompt login with pre-filled email
  │  │  └─ After login, auto-accept invitation
  │  └─ IF no account:
  │     ├─ Show registration form
  │     ├─ Create account on community.extrachill.com
  │     └─ Auto-accept invitation after registration
  │
  ├─ INVITATION ACCEPTANCE
  │  ├─ Function: ec_accept_roster_invitation($token, $user_id)
  │  ├─ Database Updates:
  │  │  ├─ Set invitation status to 'accepted'
  │  │  ├─ Record acceptance timestamp
  │  │  └─ Store accepting user_id
  │  ├─ Roster Membership Created:
  │  │  ├─ Insert into artist_roster table
  │  │  ├─ Fields:
  │  │  │  ├─ artist_id: Target artist
  │  │  │  ├─ user_id: Accepted member
  │  │  │  ├─ role: 'member' (invited members get member role)
  │  │  │  ├─ joined_date: Current timestamp
  │  │  │  └─ status: 'active'
  │  │  └─ Fire action: do_action('extrachill_roster_member_added', $artist_id, $user_id)
  │  └─ Send confirmation email to inviter
  │
  ├─ FORUM SYNCHRONIZATION (AUTOMATIC)
  │  ├─ Listener: add_action('extrachill_roster_member_added', ...)
  │  ├─ Switch to Blog ID 2 (community.extrachill.com)
  │  ├─ Get forum_id from artist meta: 'community_forum_id'
  │  ├─ Add member to forum:
  │  │  ├─ Function: bbp_add_user_to_group($user_id, $forum_id)
  │  │  └─ Grant 'bbp_participant' role
  │  ├─ Member can now:
  │  │  ├─ View forum topics
  │  │  ├─ Post replies
  │  │  └─ Access member-only areas
  │  └─ Member receives notification of forum access
  │
  ├─ LINK PAGE ACCESS GRANT
  │  ├─ Roster members (except owner) are 'editors'
  │  ├─ Permissions:
  │  │  ├─ View link page in progress
  │  │  ├─ Edit link items
  │  │  ├─ View analytics
  │  │  ├─ Cannot change appearance/settings (owner only)
  │  │  └─ Cannot manage roster (owner only)
  │  └─ Access via WordPress editor: /wp-admin/
  │
  ├─ CONFIRMATION EMAIL TO INVITER
  │  ├─ Sent to artist owner email
  │  ├─ Content:
  │  │  ├─ "{Member Name} accepted your roster invitation"
  │  │  ├─ Member now has access to shared areas
  │  │  ├─ Link to forum
  │  │  └─ Option to remove member if needed
  │  └─ Function: ec_send_acceptance_notification()
  │
  ├─ CONFIRMATION TO MEMBER
  │  ├─ Welcome email to newly accepted member
  │  ├─ Content:
  │  │  ├─ Welcome to {Artist Name} roster
  │  │  ├─ Overview of permissions and access
  │  │  ├─ Link to community forum
  │  │  ├─ Link to link page editor
  │  │  └─ Additional resources
  │  └─ Function: ec_send_roster_welcome_email()
  │
  ├─ ROSTER ACCESS UPDATE
  │  ├─ Member can immediately access:
  │  │  ├─ Shared link page in WordPress editor
  │  │  ├─ Artist forum on community.extrachill.com
  │  │  ├─ Link page analytics dashboard
  │  │  └─ Roster member directory
  │  └─ Single sign-on across sites via cookies
  │
  ├─ OPTIONAL: INVITATION DECLINED
  │  ├─ Member clicks "Decline" in email or link
  │  ├─ Database Update:
  │  │  ├─ Set invitation status to 'declined'
  │  │  └─ No roster membership created
  │  ├─ Notify inviter:
  │  │  └─ "{Member Email} declined your invitation"
  │  └─ Return to start (inviter can send new invitation)
  │
  ├─ OPTIONAL: INVITE EXPIRES
  │  ├─ Token expires after 7 days
  │  ├─ Recipient clicks link after expiration:
  │  │  ├─ Display "Invitation Expired" message
  │  │  ├─ Option to request new invitation
  │  │  └─ Email inviter to resend
  │  └─ Inviter can manually resend from Roster UI
  │
  └─ COMPLETE: Roster member now has full access

## Data Created/Modified During Roster Invitation

```
WordPress User (multisite)
├─ Created OR Retrieved (if already exists)
├─ Email: invited@example.com
└─ Access Granted: artist.extrachill.com + community.extrachill.com

Artist Invitations Table
├─ artist_id: 123
├─ recipient_email: invited@example.com
├─ token: abc123def456... (unique, random)
├─ status: 'accepted'
├─ sent_date: 2024-01-15 10:30:00
├─ accepted_date: 2024-01-15 14:22:00
└─ invited_by_user_id: (owner)

Artist Roster Table
├─ artist_id: 123
├─ user_id: (accepted member)
├─ role: 'member' (or 'owner' for artist account holder)
├─ joined_date: 2024-01-15 14:22:00
└─ status: 'active'

bbPress Forum Membership (Blog ID 2)
├─ forum_id: (artist forum)
├─ user_id: (accepted member)
├─ role: 'bbp_participant'
└─ access: Full forum read/write
```

## Permission Levels After Acceptance

```
OWNER (original artist creator)
├─ Edit link page (all aspects)
├─ Manage appearance and settings
├─ Invite/remove roster members
├─ View full analytics
├─ Manage forum (moderator)
└─ Delete link page

MEMBER (invited roster member)
├─ Edit link items (URLs, images, text)
├─ View link page in draft/preview
├─ View basic analytics
├─ Post in artist forum
├─ View roster members
└─ Cannot:
   ├─ Invite new members
   ├─ Edit appearance/settings
   ├─ Moderate forum
   ├─ Change permissions
   └─ Delete link page
```

## Error Handling

```
IF invitation creation fails
  ├─ Display error to artist
  ├─ Log error for debugging
  └─ Offer retry

IF email sending fails
  ├─ Store invitation with 'email_failed' status
  ├─ Display message: "We saved the invitation but email failed"
  ├─ Offer to resend email
  └─ Admin can retry from tools

IF member already invited
  ├─ Check if pending invitation exists
  ├─ Option to resend or update
  └─ Prevent duplicate invitations

IF member already in roster
  ├─ Display: "{email} is already a roster member"
  ├─ Offer to remove and re-invite
  └─ Prevent duplicate roster entries

IF token invalid/expired
  ├─ Display: "Invitation expired (expired 3 days ago)"
  ├─ Offer to request new invitation
  ├─ Or: Email original inviter
  └─ Function: ec_request_new_invitation()

IF forum sync fails
  ├─ Roster member added successfully
  ├─ Forum addition deferred
  ├─ Admin notified of sync failure
  └─ Manual sync available in tools
```

## Performance Optimizations

1. **Async Email Sending**
   - Emails sent via background job (if available)
   - Doesn't block user experience

2. **Token Generation**
   - Uses wp_generate_password() for security
   - Stored hashed in database
   - Single use, single token per invitation

3. **Database Indexing**
   - artist_id indexed
   - user_id indexed
   - token indexed (unique)
   - status indexed

4. **Caching**
   - Roster membership cached via object cache
   - Forum ID cached on post meta
   - User permissions cached per session

## Security Considerations

1. **Token Security**
   - Random, unguessable tokens (min 32 chars)
   - Tokens single-use (consumed on acceptance)
   - Tokens expire after 7 days
   - Tokens never appear in logs

2. **Email Verification**
   - Recipient must click unique token link
   - Account creation requires email verification
   - Cross-domain cookies ensure authentication

3. **CSRF Protection**
   - All form submissions use WordPress nonces
   - REST API endpoints verify nonces

4. **Permission Checks**
   - Only artist owner can send invitations
   - Only valid tokens can be accepted
   - Only authorized users can manage roster

## Related Documentation

- [Artist Platform AGENTS.md](../AGENTS.md) - Complete architecture
- [Roster Management System](../AGENTS.md#roster-management-system) - Technical details
- [Newsletter Integration](./artist-platform-with-newsletter.md) - Notify on roster changes
- [Community Integration](./artist-platform-with-community.md) - Forum integration
