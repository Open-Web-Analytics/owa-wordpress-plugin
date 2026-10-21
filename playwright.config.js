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
		// as PhotoPress's suite: without it, pages stopped painting after a form POST on the runner
		launchOptions: { args: [ '--disable-dev-shm-usage' ] },
	},
	projects: [
		{ name: 'single', testMatch: /(diag|single)\.spec\.js/ },
		{ name: 'multisite', testMatch: /multisite\.spec\.js/ },
	],
} );
