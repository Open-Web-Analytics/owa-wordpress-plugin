/**
 * wp-scripts' webpack config with the plugin's two admin pages as entries.
 * Built into build/, with an .asset.php per entry that admin.php enqueues by.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		settings: './js/settings/index.js',
		network: './js/network/index.js',
	},
};
