# Artist Join Flow Diagram

Complete flow diagram showing the artist platform join experience from start to finish.

## User Join Flow

```
START: User visits extrachill.link/join
  │
  ├─ Domain Mapping: sunrise.php redirects to artist.extrachill.com/login/?from_join=true
  │
  ├─ AUTHENTICATION
  │  ├─ User sees join flow modal (inc/join/templates/join-flow-modal.php)
  │  ├─ Existing Account → login
  │  └─ New Account → register
  │
  ├─ POST-AUTH ROUTING
  │  ├─ Function: ec_join_flow_login_redirect()
  │  ├─ If user already has artists → redirect to /manage-link-page/
  │  └─ Otherwise → redirect to /create-artist/
  │
  ├─ ARTIST CREATION
  │  ├─ User completes artist creation on /create-artist/
  │  └─ Artist/link page creation is handled by the create-artist flow (outside the join modal)
  │
  ├─ LINK PAGE EDITING
  │  ├─ Link pages are edited in Gutenberg with the Link Page Editor block
  │  └─ Public URLs resolve at extrachill.link/{artist-slug}
  │
  └─ COMPLETE: User can manage link page and artist profile

## Data Created During Join Flow

The join modal itself does not create artist/link page posts. It flags the session as a join flow entry and routes the user to the appropriate management page after authentication.

## Error Handling

```
IF user account creation fails
   ├─ Display error message
   ├─ Allow retry
   └─ Log error for admin review

IF artist profile creation fails
   ├─ Display error message
   └─ Log error for debugging

IF link page creation fails
   ├─ Notify user
   ├─ Offer to create manually
   └─ Log error
```

## Security Checks

1. **Nonce Verification**
   - All form submissions verify nonces
   - Prevents CSRF attacks

2. **Capability Checks**
   - Only logged-in users can join
   - Only profile owner can edit profile/link page
   - Only roster members can access shared settings

3. **Data Validation**
   - Email validation (valid format, not already used)
   - Username validation (unique, alphanumeric)
   - Password validation (strength requirements)
   - Slug validation (unique, URL-safe)

4. **Rate Limiting**
   - Login attempts: 5 attempts per 15 minutes
   - Registration: 1 account per 24 hours per IP (optional)
   - API calls: 60 requests per minute (authenticated)

## Performance Optimizations

1. **Modal-Only Entry Point**
   - Join flow modal only renders with `from_join` parameter
   - No HTML overhead on regular login pages

2. **Transient Storage**
   - Join flow session flags stored during auth
   - Allows state recovery if redirect fails

3. **Direct Redirect Pattern**
   - Post-auth redirect to appropriate page (create-artist or manage-link-page)
   - No intermediate processing steps

## Related Documentation

- [Artist Platform AGENTS.md](../AGENTS.md) - Complete architecture
- [Join Flow System](../AGENTS.md#join-flow-system) - Technical details
- [Artist Creator Block](../artist-profiles/artist-profile-management.md) - Profile creation workflow
