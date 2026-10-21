// A subdomain multisite (wp.test, a.wp.test, b.wp.test; c.wp.test is added by
// the test): network mode with one application password, the mapping table,
// a refresh that brings in a new Profile and a new site, and both ways a site
// gets its Website ID.
const { test, expect } = require( '@playwright/test' );
const { env, login, paints, shot, owaSiteId, owaCli, wp } = require( './helpers' );

const NETWORK_PAGE = 'http://wp.test/wp-admin/network/admin.php?page=owa-wordpress-network';

const row = ( page, host ) => page.locator( 'table.owa-wp-network-sites tr', { has: page.getByText( host, { exact: true } ) } );

async function saveNetwork( page, button = 'Save Changes' ) {
	await Promise.all( [ page.waitForURL( /page=owa-wordpress-network/ ), page.getByRole( 'button', { name: button } ).click() ] );
	await page.waitForLoadState( 'load' );
	expect( await paints( page ), 'the network page paints after the form was sent' ).toBe( true );
	return ( await page.locator( '.notice' ).allTextContents() ).join( '\n' );
}

test( 'the network configures each site\'s Profile', async ( { page, browser }, testInfo ) => {

	const main = owaSiteId( 'https://wp.test' );
	const siteA = owaSiteId( 'https://a.wp.test' );

	await login( page );
	await page.goto( NETWORK_PAGE );
	await shot( page, testInfo, '01-network-empty' );

	await page.fill( '#owa_wp_network_owaEndpoint', env( 'OWA_URL' ) );
	await page.fill( '#owa_wp_network_appPassword', env( 'OWA_APP_PASSWORD' ) );
	await page.check( '#owa_wp_network_assignProfiles' );
	expect( await saveNetwork( page ) ).toContain( 'Settings saved.' );

	// each site starts on its exact match; b.wp.test has none
	await expect( row( page, 'wp.test' ).locator( 'select' ) ).toHaveValue( main );
	await expect( row( page, 'a.wp.test' ).locator( 'select' ) ).toHaveValue( siteA );
	await expect( row( page, 'b.wp.test' ).locator( 'select' ) ).toHaveValue( '' );
	await shot( page, testInfo, '02-mapping-table' );

	// a new Profile in OWA and a new site in the network
	owaCli( 'cmd=add-site', 'domain=https://b.wp.test', 'name=Site B' );
	owaCli( 'cmd=publish-trackers' );
	wp( 'site', 'create', '--slug=c', '--title=Site C' );
	const siteB = owaSiteId( 'https://b.wp.test' );

	await page.goto( NETWORK_PAGE );
	const refreshed = await saveNetwork( page, 'Refresh site list' );
	expect( refreshed ).toContain( `New: ${ siteB }.` );
	expect( refreshed ).toContain( 'New sites in the network, marked below: c.wp.test.' );
	await expect( row( page, 'c.wp.test' ).locator( '.owa-wp-status' ) ).toHaveText( /^New site/ );
	await expect( row( page, 'b.wp.test' ).locator( `option[value="${ siteB }"]` ) ).toHaveText( / — new$/ );
	await shot( page, testInfo, '03-refreshed' );

	// the admin assigns the new Profile; the row says it is not saved yet
	await row( page, 'b.wp.test' ).locator( 'select' ).selectOption( siteB );
	await expect( row( page, 'b.wp.test' ).locator( '.owa-wp-status' ) ).toHaveText( 'Changed: saved when you save' );
	await shot( page, testInfo, '04-edited' );

	await saveNetwork( page );
	await expect( row( page, 'b.wp.test' ).locator( 'select' ) ).toHaveValue( siteB );
	await expect( row( page, 'b.wp.test' ).locator( '.owa-wp-status' ) ).toHaveText( 'Saved' );
	await expect( page.getByText( ' — new' ) ).toHaveCount( 0 );
	await shot( page, testInfo, '05-saved' );

	// the site shows what the network assigned, read-only, and none of the network's fields
	await page.goto( 'http://a.wp.test/wp-admin/admin.php?page=owa-wordpress' );
	await expect( page.locator( '#owa_wp_siteId' ) ).toBeDisabled();
	await expect( page.locator( '#owa_wp_siteId' ) ).toHaveValue( siteA );
	await expect( page.locator( '#owa_wp_owaEndpoint' ) ).toBeHidden();
	await expect( page.locator( '#owa_wp_appPassword' ) ).toBeHidden();
	await expect( page.locator( '#owa_wp_owaVersion_2' ) ).toBeHidden();
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
	await page.uncheck( '#owa_wp_network_assignProfiles' );
	await saveNetwork( page );
	await shot( page, testInfo, '07-network-site-choice' );

	await page.goto( 'http://b.wp.test/wp-admin/admin.php?page=owa-wordpress' );
	await expect( page.locator( '#owa_wp_siteId' ) ).toBeEnabled();

	// the wp.test family, not www.other.example
	const values = await page.locator( '#owa_wp_siteId option' ).evaluateAll( ( options ) => options.map( ( o ) => o.textContent ) );
	expect( values.some( ( v ) => v.includes( 'https://a.wp.test' ) ) ).toBe( true );
	expect( values.some( ( v ) => v.includes( 'other.example' ) ) ).toBe( false );
	await expect( page.getByText( 'The list shows the Profiles that share this site\'s domain.' ) ).toBeVisible();
	await shot( page, testInfo, '08-site-choice' );
} );
