const path = require( 'path' );

/**
 * WordPress Dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config.js' );
const FixStyleOnlyEntriesPlugin = require( 'webpack-fix-style-only-entries' );
const MiniCSSExtractPlugin = require( 'mini-css-extract-plugin' );

module.exports = {
	...defaultConfig,
	entry: {
		"css/edd-sl-sdk": path.resolve( process.cwd(), 'assets/src/css', 'style.scss' ),
		"js/edd-sl-sdk": path.resolve( process.cwd(), 'assets/src/js', 'index.js' ),
	},
	output: {
		path: path.resolve( __dirname, 'assets/build' ),
	},
	plugins: [
		new MiniCSSExtractPlugin(),
		new FixStyleOnlyEntriesPlugin(),
	],
}
