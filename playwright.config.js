// Browser tests of the plugin in a real WordPress (CI's "WordPress" job).
// One project per install type; the job runs the one it installed.
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: 'tests/e2e',
	workers: 1,
	fullyParallel: false,
	retries: 0,
	timeout: 120000,
	expect: { timeout: 15000 },
	reporter: [ [ 'list' ] ],
	use: {
		baseURL: 'http://wp.test',
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
		viewport: { width: 1280, height: 900 },
		// a hang fails in seconds, not at the test timeout
		actionTimeout: 20000,
		navigationTimeout: 30000,
		// WordPress 7 turns on cross-document view transitions in wp-admin unless
		// reduced motion is asked for; in headless Chromium the transition of a
		// form-submit navigation never ends, and the tab stops painting for good.
		reducedMotion: 'reduce',
	},
	projects: [
		{ name: 'single', testMatch: /single\.spec\.js/ },
		{ name: 'multisite', testMatch: /multisite\.spec\.js/ },
	],
} );
