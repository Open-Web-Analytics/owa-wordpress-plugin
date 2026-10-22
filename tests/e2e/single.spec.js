// A single-site WordPress: the 1.x / 2.x switch, 2.x settings with an
// application password, and the bundle tag on a public page.
const { test, expect } = require( '@playwright/test' );
const { env, login, notice, shot } = require( './helpers' );

test( 'switching to 2.x shows its fields, saves, and tags pages with the bundle', async ( { page, browser }, testInfo ) => {

	const siteId = env( 'OWA_SITE_ID' );
	const save = page.getByRole( 'button', { name: 'Save Changes' } );
	const tab = ( name ) => page.getByRole( 'tab', { name } );
	const field = ( label ) => page.getByLabel( label, { exact: true } );

	await login( page );
	await page.goto( '/wp-admin/admin.php?page=owa-wordpress' );

	// 1.x is the default
	await expect( field( '1.x' ) ).toBeChecked();
	await expect( field( 'API Key' ) ).toBeVisible();
	await expect( field( 'Application Password' ) ).toHaveCount( 0 );
	await expect( field( 'Website ID' ) ).toBeVisible();
	// what is left to set up, worded for 1.x, shown by the page and not again by WordPress
	await expect( notice( page, 'Enter your OWA Endpoint, API Key and Secret Auth Key to load the list of websites from OWA.' ) ).toBeVisible();
	await expect( notice( page, 'Open Web Analytics requires that you select a Website ID before tracking can begin.' ) ).toBeVisible();
	await expect( page.locator( 'div.notice-warning' ) ).toHaveCount( 0 );
	await shot( page, testInfo, '01-settings-1x' );

	await tab( 'Tracking' ).click();
	await expect( field( 'Track Clicks' ) ).toBeVisible();
	await shot( page, testInfo, '02-tracking-tab-1x' );
	await tab( 'General' ).click();

	// flip the switch: 2.x fields in, 1.x fields and the empty Advanced tab out
	await field( '2.x (beta)' ).check();
	await expect( field( 'API Key' ) ).toHaveCount( 0 );
	await expect( field( 'Application Password' ) ).toBeVisible();
	await expect( tab( 'Advanced' ) ).toHaveCount( 0 );
	// the same field is the Observation Profile in 2.x
	await expect( field( 'Website ID' ) ).toHaveCount( 0 );
	await expect( field( 'Observation Profile' ).locator( 'option:checked' ) ).toHaveText( /^(Loading|Select Profile)…$/ );
	// the notices switch to 2.x wording with it
	await expect( notice( page, 'Enter your OWA Endpoint and an Application Password to load the list of Observation Profiles from OWA.' ) ).toBeVisible();
	await expect( notice( page, 'Open Web Analytics requires that you select an Observation Profile before tracking can begin.' ) ).toBeVisible();
	await expect( notice( page, 'API Key and Secret Auth Key' ) ).toHaveCount( 0 );
	await shot( page, testInfo, '03-switched-to-2x' );

	await tab( 'Tracking' ).click();
	await expect( field( 'Track Clicks' ) ).toHaveCount( 0 );
	await expect( field( 'Track Named Users' ) ).toBeVisible();
	await expect( field( "Don't Track Logged-in Users" ) ).toBeVisible();
	await shot( page, testInfo, '04-tracking-tab-2x' );
	await tab( 'General' ).click();

	// a 1.x key in the password field is refused, and nothing is saved
	await field( 'OWA Endpoint' ).fill( env( 'OWA_URL' ) );
	await field( 'Application Password' ).fill( '0123456789abcdef0123456789abcdef' );
	await save.click();
	await expect( page.getByText( 'This looks like a 1.x API key.' ) ).toBeVisible();
	await expect( notice( page, 'The settings were not saved.' ) ).toBeVisible();
	await shot( page, testInfo, '05-invalid-password' );

	await field( 'Application Password' ).fill( env( 'OWA_APP_PASSWORD' ) );
	await save.click();
	await expect( notice( page, 'Settings saved.' ) ).toBeVisible();
	await expect( page.getByText( 'Saved. Enter a new one to replace it.' ) ).toBeVisible();
	// saved credentials: that notice goes without a reload; choosing an Observation Profile is left
	await expect( notice( page, 'to load the list of Observation Profiles from OWA' ) ).toHaveCount( 0 );
	await expect( notice( page, 'Open Web Analytics requires that you select an Observation Profile before tracking can begin.' ) ).toBeVisible();

	// the list comes from OWA with the application password
	await expect( field( 'Observation Profile' ).locator( `option[value="${ siteId }"]` ) ).toHaveCount( 1 );
	await field( 'Observation Profile' ).selectOption( siteId );

	// chosen but not saved: no link to OWA's tracking tag screen yet
	const tagLink = page.getByRole( 'link', { name: /Configure this Observation Profile's tracking tag in OWA/ } );
	await expect( tagLink ).toHaveCount( 0 );
	await save.click();
	await expect( notice( page, 'Settings saved.' ) ).toBeVisible();
	await expect( tagLink ).toBeVisible();
	await expect( notice( page, 'before tracking can begin' ) ).toHaveCount( 0 );
	await shot( page, testInfo, '06-2x-saved' );

	// choosing another Observation Profile hides it again until that is saved
	await field( 'Observation Profile' ).selectOption( '' );
	await expect( tagLink ).toHaveCount( 0 );
	await field( 'Observation Profile' ).selectOption( siteId );
	await expect( tagLink ).toBeVisible();

	// the page loads saved, and the password is not in it
	await page.reload();
	await expect( field( 'Observation Profile' ) ).toHaveValue( siteId );
	await expect( field( 'Observation Profile' ).locator( 'option:checked' ) ).not.toHaveText( /not in the list from OWA/ );
	expect( await page.content() ).not.toContain( env( 'OWA_APP_PASSWORD' ) );

	// a visitor gets the bundle tag, and the bundle loads
	const visitor = await ( await browser.newContext() ).newPage();
	const bundle = visitor.waitForResponse( ( r ) => r.url().endsWith( `/public/tracker/${ siteId }.js` ) );
	await visitor.goto( '/' );
	expect( ( await bundle ).status() ).toBe( 200 );
	expect( await visitor.content() ).not.toContain( 'owa.tracker.js' );
} );
