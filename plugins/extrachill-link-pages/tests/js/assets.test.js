const fs = require( 'node:fs' );
const assert = require( 'node:assert/strict' );

const tracking = fs.readFileSync( 'assets/js/link-page-public-tracking.js', 'utf8' );
const sharing = fs.readFileSync( 'assets/js/extrch-share-modal.js', 'utf8' );
const youtube = fs.readFileSync( 'assets/js/link-page-youtube-embed.js', 'utf8' );
const linksCss = fs.readFileSync( 'assets/css/extrch-links.css', 'utf8' );
const shareCss = fs.readFileSync( 'assets/css/extrch-share-modal.css', 'utf8' );

assert.match( tracking, /dataset\.extrchTrackingClickUrl/ );
assert.match( tracking, /link_page_id: linkPageId/ );
assert.doesNotMatch( tracking, /\/wp-json\// );
assert.match( sharing, /dataset\.extrchTrackingClickUrl/ );
assert.match( sharing, /link_page_id: document\.body\.dataset\.extrchLinkPageId/ );
assert.doesNotMatch( sharing, /const endpoint = ['"]\/wp-json/ );
assert.match( sharing, /extrch-bell-page-trigger/ );
assert.match( sharing, /navigator\.share/ );
assert.match( sharing, /extrch-link-page-profile-img img/ );
assert.match( youtube, /ExtrchLinkPageYoutubeEmbeds/ );
assert.match( youtube, /extrch-youtube-video-placeholder/ );
assert.match( linksCss, /\.extrch-link-page-profile-img/ );
assert.match( linksCss, /@media/ );
assert.match( shareCss, /\.extrch-share-option-button/ );

console.log( 'Standalone public asset contracts pass.' );
