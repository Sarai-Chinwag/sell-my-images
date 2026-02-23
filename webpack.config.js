const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		...defaultConfig.entry(),
		'checkout/index': path.resolve( __dirname, 'src/checkout/index.ts' ),
	},
};
