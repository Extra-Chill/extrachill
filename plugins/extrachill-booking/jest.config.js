module.exports = {
	preset: '@wordpress/jest-preset-default',
	transform: {
		'\\.[jt]sx?$': [
			'babel-jest',
			{ presets: [ '@wordpress/babel-preset-default' ] },
		],
	},
	moduleNameMapper: {
		'^@wordpress/(.*)$': '<rootDir>/node_modules/@wordpress/$1',
		// Pin react (including the automatic JSX runtime used by Babel) to
		// booking's copy, also for shared sources imported from extrachill-events.
		'^react$': require.resolve( 'react' ),
		'^react/(jsx-runtime|jsx-dev-runtime)$': '<rootDir>/node_modules/react/$1',
		'^react-dom(.*)$': '<rootDir>/node_modules/react-dom$1',
		'^@extrachill/components$':
			'<rootDir>/../../packages/components/dist/index.js',
	},
	transformIgnorePatterns: [ '/node_modules/(?!@extrachill/)' ],
};
