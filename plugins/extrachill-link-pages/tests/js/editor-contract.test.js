const fs = require( 'fs' );
const path = require( 'path' );
const assert = require( 'assert' );

const root = path.resolve( __dirname, '../..' );
const source = fs.readFileSync(
	path.join( root, 'src/editor/index.js' ),
	'utf8'
);
const block = JSON.parse(
	fs.readFileSync( path.join( root, 'src/editor/block.json' ), 'utf8' )
);

assert.strictEqual( block.name, 'extrachill/link-page-editor' );
assert.strictEqual( block.supports.multiple, false );
assert.strictEqual( block.supports.reusable, false );
assert.strictEqual( block.editorScript, 'file:./block.js' );
assert.match(
	source,
	/window\.ExtraChillLinkPageEditor = \{ mount, normalizeDocument, registerAdapter \}/
);
assert.match( source, /adapter\.read/ );
assert.match( source, /adapter\.save/ );
assert.doesNotMatch( source, /artist|venue|promoter/i );
const plugin = fs.readFileSync(
	path.join( root, 'extrachill-link-pages.php' ),
	'utf8'
);
const editorEnqueue = plugin.match(
	/function ec_enqueue_link_page_editor\(\) \{([\s\S]*?)\n\}/
)[ 1 ];
assert.match(
	editorEnqueue,
	/ec_register_link_page_public_styles\(\);[\s\S]*wp_enqueue_style\( 'extrch-font-awesome' \)/
);
const editorStyles = fs.readFileSync(
	path.join( root, 'src/editor/style.scss' ),
	'utf8'
);
assert.match(
	editorStyles,
	/\.ec-lpe-field input[^,]*:not\(\[type="checkbox"\]\):not\(\[type="radio"\]\)/
);
console.log( 'editor contract tests passed' );
