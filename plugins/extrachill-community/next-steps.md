## Priority Issues

### Critical Bugs
- **Blank Link Pages**: Debug excessive band link page creation and add admin viewing paths
- **bbPress Styling**: Fix reply edit form and author display after stylesheet removal
- **Editor Preview**: Align visual editor with frontend display (blockquote styling mismatch)

### User Experience  
- **Auto oEmbed**: Convert bare YouTube/Spotify URLs to embeds via content filters
- **Social Icons**: Standardize icon display across link page, band profile, and editing interface

### Developer Experience
- **Code Organization**: Continue cleanup of disorganized files and dead code
- **WordPress Hooks**: Implement centralized logic via custom filters and actions

## Planned Features

### Authentication Enhancement
1. **OAuth Integration**: Add Google and Apple login via existing login/register interface

### Forum Evolution  
2. **Hybrid Social-Forum Model**: Custom "forum_post" post type for quick status updates alongside traditional topics/replies. Enables Twitter-like posting while maintaining forum structure.

3. **Introduction Requirements**: Force new users to post in introduction forum before accessing other areas (spam prevention)

### Community Management
4. **Spam Filter Review**: Audit current filters to balance security with user accessibility
