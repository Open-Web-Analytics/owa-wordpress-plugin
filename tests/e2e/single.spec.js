// A single-site WordPress: the 1.x / 2.x switch, 2.x settings with an
// application password, and the bundle tag on a public page.
const { test, expect } = require( '@playwright/test' );
const { env, login, shot } = require( './helpers' );

test( 'switching to 2.x shows its fields, saves, and tags pages with the bundle', async ( { page, browser }, testInfo ) => {

	const siteId = env( 'OWA_SITE_ID' );

	await login( page );
	await page.goto( '/wp-admin/admin.php?page=owa-wordpress' );

	// 1.x is the default
	await expect( page.locator( '#owa_wp_owaVersion_1' ) ).toBeChecked();
	await expect( page.locator( '#owa_wp_apiKey' ) ).toBeVisible();
	await expect( page.locator( '#owa_wp_appPassword' ) ).toBeHidden();
	await shot( page, testInfo, '01-settings-1x' );

	await page.click( 'a[href="#tracking"]' );
	await expect( page.locator( '#owa_wp_trackClicks_on' ) ).toBeVisible();
	await shot( page, testInfo, '02-tracking-tab-1x' );
	await page.click( 'a[href="#general"]' );

	// flip the switch: 2.x fields in, 1.x fields and the empty Advanced tab out
	await page.check( '#owa_wp_owaVersion_2' );
	await expect( page.locator( '#owa_wp_apiKey' ) ).toBeHidden();
	await expect( page.locator( '#owa_wp_appPassword' ) ).toBeVisible();
	await expect( page.locator( 'a[href="#advanced"]' ) ).toBeHidden();
	await shot( page, testInfo, '03-switched-to-2x' );

	await page.click( 'a[href="#tracking"]' );
	await expect( page.locator( '#owa_wp_trackClicks_on' ) ).toBeHidden();
	await expect( page.locator( '#owa_wp_trackNamedUsers_on' ) ).toBeVisible();
	await expect( page.locator( '#owa_wp_excludeLoggedInUsers_on' ) ).toBeVisible();
	await shot( page, testInfo, '04-tracking-tab-2x' );
	await page.click( 'a[href="#general"]' );

	await page.fill( '#owa_wp_owaEndpoint', env( 'OWA_URL' ) );
	await page.fill( '#owa_wp_appPassword', env( 'OWA_APP_PASSWORD' ) );
	await Promise.all( [ page.waitForLoadState( 'load' ), page.click( 'input[name="Submit"]' ) ] );

	// the list now comes from OWA with the application password
	await expect( page.locator( `#owa_wp_siteId option[value="${ siteId }"]` ) ).toHaveCount( 1 );
	await page.selectOption( '#owa_wp_siteId', siteId );
	await Promise.all( [ page.waitForLoadState( 'load' ), page.click( 'input[name="Submit"]' ) ] );

	await expect( page.locator( '#owa_wp_siteId' ) ).toHaveValue( siteId );
	await expect( page.getByText( 'Configure this Website ID\'s tracking tag in OWA' ) ).toBeVisible();
	await shot( page, testInfo, '05-2x-saved' );

	// a visitor gets the bundle tag, and the bundle loads
	const visitor = await ( await browser.newContext() ).newPage();
	const bundle = visitor.waitForResponse( ( r ) => r.url().endsWith( `/public/tracker/${ siteId }.js` ) );
	await visitor.goto( '/' );
	expect( ( await bundle ).status() ).toBe( 200 );
	expect( await visitor.content() ).not.toContain( 'owa.tracker.js' );
} );
