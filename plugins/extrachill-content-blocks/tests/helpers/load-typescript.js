const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );
const ts = require( 'typescript' );

// Each harness gets its own module cache and shared browser globals. Wrap each
// CommonJS module so exports and relative requires are scoped as in production.
module.exports = function createLoader( globals = {}, mocks = {} ) {
	const context = vm.createContext( globals );
	const cache = new Map();
	function load( filename ) {
		filename = path.resolve( filename );
		if ( ! path.extname( filename ) ) {
			filename += fs.existsSync( filename + '.ts' ) ? '.ts' : '.tsx';
		}
		if ( cache.has( filename ) ) return cache.get( filename ).exports;
		const module = { exports: {} };
		cache.set( filename, module );
		const requireModule = ( name ) => {
			if ( Object.hasOwn( mocks, name ) ) return mocks[ name ];
			if ( name.startsWith( '.' ) ) {
				return load( path.resolve( path.dirname( filename ), name ) );
			}
			throw new Error( `Unmocked module: ${ name }` );
		};
		const code = ts.transpileModule( fs.readFileSync( filename, 'utf8' ), {
			compilerOptions: {
				module: ts.ModuleKind.CommonJS,
				jsx: ts.JsxEmit.React,
				jsxFactory: 'createElement',
			},
		} ).outputText.replace(
			/createElement\(/g,
			'require("@wordpress/element").createElement('
		);
		vm.runInContext(
			`(function (require, module, exports) {\n${ code }\n})`,
			context,
			{ filename }
		)( requireModule, module, module.exports );
		return module.exports;
	}
	return load;
};
