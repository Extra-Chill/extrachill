# Roster Invitation Flow Diagram

Flow showing how roster members are invited and accept invitations.

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
  │  ├─ Stored on artist profile post meta
  │  ├─ Meta key: `_pending_invitations`
  │  └─ Token + recipient email tracked per invitation
  │
  ├─ RECIPIENT RECEIVES EMAIL
  │  ├─ Email arrives from artist@extrachill.com
  │  ├─ Contains:
  │  │  ├─ Personalized greeting
  │  │  ├─ Artist name and bio
  │  │  ├─ "Accept Invitation" button/link
  │  │  └─ Link format: artist profile URL with query args (`action=bp_accept_invite&token=...&artist_id=...`)
  │  └─ Button also includes expiration warning if close to expiry
  │
  ├─ RECIPIENT CLICKS INVITATION LINK
  │  ├─ Link resolves to the artist profile URL
  │  ├─ Query args include token + artist ID
  │  ├─ Token validated against `_pending_invitations`
  │  └─ Acceptance requires the invited user to be authenticated
  │
  ├─ ACCOUNT CHECK
  │  ├─ Query: user_exists($recipient_email)
  │  ├─ IF user exists:
  │  │  ├─ Prompt login with pre-filled email
  │  │  └─ After login, auto-accept invitation
  │  └─ IF no account:
  │     ├─ User registers (site-managed)
  │     └─ After login, invitation can be accepted
  │
  ├─ INVITATION ACCEPTANCE
  │  ├─ Invitation acceptance is handled via query args on the artist profile page
  │  ├─ Token lookup happens in `_pending_invitations`
  │  ├─ On success:
  │  │  ├─ User is linked to the artist roster
  │  │  └─ Pending invite is removed from `_pending_invitations`
  │  └─ Confirmation email is sent
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
  │  │  ├─ Link to artist profile
  │  │  └─ Option to remove member if needed
  │  └─ Function: ec_send_acceptance_notification()
  │
  ├─ CONFIRMATION TO MEMBER
  │  ├─ Welcome email to newly accepted member
  │  ├─ Content:
  │  │  ├─ Welcome to {Artist Name} roster
  │  │  ├─ Overview of permissions and access
  │  │  ├─ Link to artist profile
  │  │  ├─ Link to link page editor
  │  │  └─ Additional resources
  │  └─ Function: ec_send_roster_welcome_email()
  │
  ├─ ROSTER ACCESS UPDATE
  │  ├─ Member can immediately access:
  │  │  ├─ Shared link page in WordPress editor
  │  │  └─ Link page analytics dashboard
  │  └─ Permissions are enforced by the centralized permission system
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
└─ Email: invited@example.com

Artist Profile Post Meta
├─ `_pending_invitations`: stores pending invite tokens + recipient emails
└─ `_artist_roster_members`: stores accepted roster members
```

## Permission Levels After Acceptance

```
OWNER (original artist creator)
├─ Manage link page
├─ Manage artist profile
└─ Invite/remove roster members

MEMBER (invited roster member)
├─ Manage link page (as granted)
└─ View roster members
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

- [Artist Platform CLAUDE.md](../CLAUDE.md) - Complete architecture
- [Roster Management System](../CLAUDE.md#roster-management-system) - Technical details
- [Newsletter Integration](./artist-platform-with-newsletter.md) - Notify on roster changes
- [Community Integration](./artist-platform-with-community.md) - Forum integration
