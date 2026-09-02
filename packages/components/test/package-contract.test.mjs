import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { promisify } from 'node:util';
import test from 'node:test';

const execFileAsync = promisify( execFile );
const packageJson = JSON.parse(
	await readFile( new URL( '../package.json', import.meta.url ), 'utf8' )
);

const moduleNames = [
	'ActionRow',
	'Badge',
	'BlockIntro',
	'BlockShell',
	'BlockShellHeader',
	'BlockShellInner',
	'DataTable',
	'FieldGroup',
	'Grid',
	'ImagePreview',
	'InlineStatus',
	'MediaField',
	'Modal',
	'Pagination',
	'Panel',
	'PanelHeader',
	'ProgressBar',
	'ResponsiveTabs',
	'SearchBox',
	'Section',
	'ShellTabs',
	'StatGroup',
	'StatTile',
	'Subsection',
	'Surface',
	'Tabs',
	'Toolbar',
	'initResponsiveTabsDom',
	'useTabClientContext',
];

const tokenNames = [
	'accent',
	'background-color',
	'border-color',
	'border-radius-lg',
	'border-radius-md',
	'border-radius-pill',
	'border-radius-sm',
	'button-text-color',
	'card-background',
	'error-color',
	'focus-border-color',
	'font-family-body',
	'font-family-heading',
	'font-size-2xl',
	'font-size-base',
	'font-size-body',
	'font-size-lg',
	'font-size-sm',
	'font-size-xs',
	'header-background',
	'header-text-color',
	'info-bg',
	'info-color',
	'link-color',
	'muted-text',
	'spacing-lg',
	'spacing-md',
	'spacing-sm',
	'spacing-xs',
	'success-color',
	'text-color',
	'warning-bg',
	'warning-color',
];

test( 'package exports only compiled runtime modules', () => {
	assert.equal( packageJson.files.includes( 'src' ), false );
	assert.equal( Object.keys( packageJson.exports ).some( ( key ) => key.startsWith( './src' ) ), false );

	for ( const name of moduleNames ) {
		assert.deepEqual( packageJson.exports[ `./${ name }` ], {
			types: `./dist/${ name }.d.ts`,
			default: `./dist/${ name }.js`,
		} );
	}

	assert.equal( packageJson.exports[ './styles/components.css' ], './dist/components.css' );
	assert.equal( packageJson.exports[ './styles/components.scss' ], './styles/components.scss' );
} );

test( 'compiled component subpaths match root exports', async () => {
	const root = await import( '@extrachill/components' );

	for ( const name of moduleNames ) {
		const subpath = await import( `@extrachill/components/${ name }` );
		assert.equal( subpath[ name ], root[ name ], `${ name } must retain its named export` );
	}
} );

test( 'compiled CSS is compatible with canonical tokens and standalone fallbacks', async () => {
	const [ css, tokenCss ] = await Promise.all( [
		readFile( new URL( '../dist/components.css', import.meta.url ), 'utf8' ),
		readFile( new URL( '../node_modules/@extrachill/tokens/css/root.css', import.meta.url ), 'utf8' ),
	] );
	const consumedProperties = [
		...new Set( [ ...css.matchAll( /var\(--([a-z0-9-]+)/g ) ].map( ( match ) => match[ 1 ] ) ),
	];
	const consumedTokens = consumedProperties.filter( ( name ) => ! name.startsWith( 'ec-' ) ).sort();

	assert.deepEqual( consumedTokens, tokenNames, 'every non-component property must be a declared canonical token' );

	for ( const name of tokenNames ) {
		assert.match( tokenCss, new RegExp( `--${ name }\\s*:` ), `tokens package must define --${ name }` );
		assert.match( css, new RegExp( `var\\(--${ name },\\s*[^)]+\\)` ), `component CSS must provide a fallback for --${ name }` );
	}
} );

test( 'npm package contains compiled contracts and excludes source runtime files', async () => {
	const { stdout } = await execFileAsync( 'npm', [ 'pack', '--dry-run', '--json', '--ignore-scripts' ], {
		cwd: new URL( '..', import.meta.url ),
	} );
	const [ pack ] = JSON.parse( stdout );
	const files = new Set( pack.files.map( ( file ) => file.path ) );

	assert.equal( files.has( 'dist/index.js' ), true );
	assert.equal( files.has( 'dist/index.d.ts' ), true );
	assert.equal( files.has( 'dist/components.css' ), true );
	assert.equal( files.has( 'styles/components.scss' ), true );
	assert.equal( [ ...files ].some( ( file ) => file.startsWith( 'src/' ) ), false );
} );
