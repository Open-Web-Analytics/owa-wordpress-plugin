// A subdomain multisite (wp.test, a.wp.test, b.wp.test; c.wp.test is added by
// the test): network mode with one application password, the mapping table,
// a refresh that brings in a new Profile and a new site, and both ways a site
// gets its Website ID.
const { test, expect } = require( '@playwright/test' );
const { env, login, shot, owaSiteId, owaCli, wp } = require( './helpers' );

const NETWORK_PAGE = 'http://wp.test/wp-admin/network/admin.php?page=owa-wordpress-network';

const site = ( page, host ) => page.getByLabel( `Website ID for ${ host }`, { exact: true } );
const status = ( page, host ) => page.locator( 'tr', { has: site( page, host ) } ).locator( '.owa-wp-status' );

test( 'the network configures each site\'s Profile', async ( { page, browser }, testInfo ) => {

	const main = owaSiteId( 'https://wp.test' );
	const siteA = owaSiteId( 'https://a.wp.test' );
	const save = page.getByRole( 'button', { name: 'Save Changes' } );

	await login( page );
	await page.goto( NETWORK_PAGE );
	await expect( page.getByLabel( 'OWA Endpoint' ) ).toBeVisible();
	await shot( page, testInfo, '01-network-empty' );

	await page.getByLabel( 'OWA Endpoint' ).fill( env( 'OWA_URL' ) );
	await page.getByLabel( 'Application Password' ).fill( env( 'OWA_APP_PASSWORD' ) );
	await page.getByLabel( /Network configures each site's Profile/ ).check();
	await save.click();

	// each site starts on its exact match; b.wp.test has none
	await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
	await expect( site( page, 'wp.test' ) ).toHaveValue( main );
	await expect( site( page, 'a.wp.test' ) ).toHaveValue( siteA );
	await expect( site( page, 'b.wp.test' ) ).toHaveValue( '' );
	await shot( page, testInfo, '02-mapping-table' );

	// a new Profile in OWA and a new site in the network
	owaCli( 'cmd=add-site', 'domain=https://b.wp.test', 'name=Site B' );
	owaCli( 'cmd=publish-trackers' );
	wp( 'site', 'create', '--slug=c', '--title=Site C' );
	const siteB = owaSiteId( 'https://b.wp.test' );

	await page.reload();
	await page.getByRole( 'button', { name: 'Refresh site list' } ).click();

	await expect( page.getByText( `New: ${ siteB }.` ) ).toBeVisible();
	await expect( page.getByText( 'New sites in the network, marked below: c.wp.test.' ) ).toBeVisible();
	await expect( status( page, 'c.wp.test' ) ).toHaveText( /^New site/ );
	await expect( site( page, 'b.wp.test' ).locator( `option[value="${ siteB }"]` ) ).toHaveText( / — new$/ );
	await shot( page, testInfo, '03-refreshed' );

	// the admin assigns the new Profile; the row says it is not saved yet
	await site( page, 'b.wp.test' ).selectOption( siteB );
	await expect( status( page, 'b.wp.test' ) ).toHaveText( 'Changed: saved when you save' );
	await shot( page, testInfo, '04-edited' );

	await save.click();
	await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
	await expect( site( page, 'b.wp.test' ) ).toHaveValue( siteB );
	await expect( status( page, 'b.wp.test' ) ).toHaveText( 'Saved' );
	await expect( page.getByText( ' — new' ) ).toHaveCount( 0 );
	await shot( page, testInfo, '05-saved' );

	// the site shows what the network assigned, read-only, and none of the network's fields
	await page.goto( 'http://a.wp.test/wp-admin/admin.php?page=owa-wordpress' );
	await expect( page.getByLabel( 'Website ID', { exact: true } ) ).toBeDisabled();
	await expect( page.getByLabel( 'Website ID', { exact: true } ) ).toHaveValue( siteA );
	await expect( page.getByLabel( 'OWA Endpoint' ) ).toHaveCount( 0 );
	await expect( page.getByLabel( 'Application Password' ) ).toHaveCount( 0 );
	await expect( page.getByLabel( '2.x (beta)' ) ).toHaveCount( 0 );
	await expect( page.getByText( 'configures this site\'s Profile' ) ).toBeVisible();
	await shot( page, testInfo, '06-site-assigned' );

	// visitors to each site get that site's bundle
	const visitor = await ( await browser.newContext() ).newPage();
	for ( const [ host, id ] of [ [ 'a.wp.test', siteA ], [ 'b.wp.test', siteB ] ] ) {
		const bundle = visitor.waitForResponse( ( r ) => r.url().endsWith( `/public/tracker/${ id }.js` ) );
		await visitor.goto( `http://${ host }/` );
		expect( ( await bundle ).status(), host ).toBe( 200 );
	}
	await visitor.goto( 'http://c.wp.test/' );
	expect( await visitor.content(), 'c.wp.test has no Profile' ).not.toContain( 'Open Web Analytics Tracker' );
} );

test( 'each site picks from the Profiles sharing its domain', async ( { page }, testInfo ) => {

	await login( page );
	await page.goto( NETWORK_PAGE );
	await page.getByLabel( /Network configures each site's Profile/ ).uncheck();
	await expect( page.getByText( 'Save to switch.' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Save Changes' } ).click();
	await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
	await expect( page.getByRole( 'cell', { name: 'not chosen yet' } ).first() ).toBeVisible();
	await shot( page, testInfo, '07-network-site-choice' );

	await page.goto( 'http://b.wp.test/wp-admin/admin.php?page=owa-wordpress' );
	const picker = page.getByLabel( 'Website ID', { exact: true } );
	await expect( picker.locator( 'option' ) ).not.toHaveCount( 1 );
	await expect( picker ).toBeEnabled();

	// the wp.test family, not www.other.example
	const labels = await picker.locator( 'option' ).allTextContents();
	expect( labels.some( ( v ) => v.includes( 'https://a.wp.test' ) ) ).toBe( true );
	expect( labels.some( ( v ) => v.includes( 'other.example' ) ) ).toBe( false );
	await expect( page.getByText( 'The list shows the Profiles that share this site\'s domain.' ) ).toBeVisible();
	await shot( page, testInfo, '08-site-choice' );
} );
