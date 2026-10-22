// A subdomain multisite (wp.test, a.wp.test, b.wp.test; c.wp.test is added by
// the test): network mode with one application password, the mapping table,
// a reload that brings in a new Profile and a new site, and both ways a site
// gets its Website ID.
const { test, expect } = require( '@playwright/test' );
const { env, login, notice, shot, owaSiteId, owaCli, wp } = require( './helpers' );

const NETWORK_PAGE = 'http://wp.test/wp-admin/network/admin.php?page=owa-wordpress-network';

const row = ( page, host ) => page.locator( `tr[data-host="${ host }"]` );
const site = ( page, host ) => page.getByLabel( `Observation Profile for ${ host }`, { exact: true } );
const edit = ( page, host ) => page.getByRole( 'button', { name: `Edit the Observation Profile for ${ host }`, exact: true } );
const tracking = ( page, host ) => page.getByLabel( `Track ${ host }`, { exact: true } );

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
	await page.getByLabel( /Network configures each site's Observation Profile/ ).check();
	await save.click();

	// each site is saved on its exact match and shown as text with Edit and a switch;
	// b.wp.test has none, so it keeps its dropdown and has no switch
	await expect( notice( page, 'Settings saved.' ) ).toBeVisible();
	await expect( row( page, 'wp.test' ) ).toContainText( main );
	await expect( row( page, 'a.wp.test' ) ).toContainText( `Site A – https://a.wp.test (${ siteA })` );
	await expect( site( page, 'a.wp.test' ) ).toHaveCount( 0 );
	await expect( edit( page, 'a.wp.test' ) ).toBeVisible();
	await expect( tracking( page, 'a.wp.test' ) ).toBeVisible();
	await expect( site( page, 'b.wp.test' ) ).toHaveValue( '' );
	await expect( site( page, 'b.wp.test' ).locator( 'option:checked' ) ).toHaveText( 'Select Profile…' );
	await expect( edit( page, 'b.wp.test' ) ).toHaveCount( 0 );
	await expect( tracking( page, 'b.wp.test' ) ).toHaveCount( 0 );
	await shot( page, testInfo, '02-mapping-table' );

	// a new Profile in OWA and a new site in the network, while the page is open
	owaCli( 'cmd=add-site', 'domain=https://b.wp.test', 'name=Site B' );
	owaCli( 'cmd=publish-trackers' );
	wp( 'site', 'create', '--slug=c', '--title=Site C' );
	const siteB = owaSiteId( 'https://b.wp.test' );

	// reloading the page fetches the Profiles and reads the sites together
	await page.reload();

	await expect( notice( page, `New Observation Profiles in OWA since the last visit: ${ siteB }.` ) ).toBeVisible();
	await expect( notice( page, 'New sites in the network, marked below: c.wp.test.' ) ).toBeVisible();
	// the new site is not saved: marked New and shown with its dropdown, without a switch
	await expect( row( page, 'c.wp.test' ).locator( '.owa-wp-badge' ) ).toHaveText( 'New' );
	await expect( site( page, 'c.wp.test' ) ).toBeVisible();
	await expect( tracking( page, 'c.wp.test' ) ).toHaveCount( 0 );
	await shot( page, testInfo, '03-reloaded' );

	// b.wp.test's dropdown lists the new Profile, marked
	await expect( site( page, 'b.wp.test' ).locator( `option[value="${ siteB }"]` ) ).toHaveText( / — new$/ );
	await site( page, 'b.wp.test' ).selectOption( siteB );
	await shot( page, testInfo, '04-edited' );

	// Cancel puts a row back as saved
	await edit( page, 'wp.test' ).click();
	await row( page, 'wp.test' ).getByRole( 'button', { name: 'Cancel' } ).click();
	await expect( site( page, 'wp.test' ) ).toHaveCount( 0 );

	await save.click();
	await expect( notice( page, 'Settings saved.' ) ).toBeVisible();
	// b.wp.test now has a saved Profile: text, Edit and its switch, on
	await expect( row( page, 'b.wp.test' ) ).toContainText( `Site B – https://b.wp.test (${ siteB })` );
	await expect( site( page, 'b.wp.test' ) ).toHaveCount( 0 );
	await expect( tracking( page, 'b.wp.test' ) ).toBeChecked();
	// c.wp.test was saved without a Profile: no longer New, still a dropdown and no switch
	await expect( row( page, 'c.wp.test' ).locator( '.owa-wp-badge' ) ).toHaveCount( 0 );
	await expect( site( page, 'c.wp.test' ) ).toBeVisible();
	await expect( tracking( page, 'c.wp.test' ) ).toHaveCount( 0 );
	await expect( page.getByText( ' — new' ) ).toHaveCount( 0 );
	await shot( page, testInfo, '05-saved' );

	// the network switches a.wp.test off: the row greys out and cannot be edited; it keeps its Profile
	await expect( tracking( page, 'a.wp.test' ) ).toBeChecked();
	await tracking( page, 'a.wp.test' ).uncheck();
	await expect( row( page, 'a.wp.test' ) ).toHaveClass( /is-disabled/ );
	await expect( edit( page, 'a.wp.test' ) ).toBeDisabled();
	await save.click();
	await expect( notice( page, 'Settings saved.' ) ).toBeVisible();
	await expect( tracking( page, 'a.wp.test' ) ).not.toBeChecked();
	await expect( row( page, 'a.wp.test' ) ).toHaveClass( /is-disabled/ );
	await expect( row( page, 'a.wp.test' ) ).toContainText( `Site A – https://a.wp.test (${ siteA })` );
	await shot( page, testInfo, '05b-site-switched-off' );

	// the site shows what the network assigned, read-only, and none of the network's fields
	await page.goto( 'http://a.wp.test/wp-admin/admin.php?page=owa-wordpress' );
	await expect( page.getByLabel( 'Observation Profile', { exact: true } ) ).toBeDisabled();
	await expect( page.getByLabel( 'Observation Profile', { exact: true } ) ).toHaveValue( siteA );
	// labelled from OWA's list, though the network has tracking off here
	await expect( page.getByLabel( 'Observation Profile', { exact: true } ).locator( 'option:checked' ) ).toHaveText( /^Site A – https:\/\/a\.wp\.test/ );
	await expect( page.getByLabel( 'OWA Endpoint' ) ).toHaveCount( 0 );
	await expect( page.getByLabel( 'Application Password' ) ).toHaveCount( 0 );
	await expect( page.getByLabel( '2.x (beta)' ) ).toHaveCount( 0 );
	await expect( page.getByText( 'configures this site\'s Observation Profile and has tracking on this site turned off' ) ).toBeVisible();
	await expect( page.getByLabel( 'Enable OWA' ) ).toHaveCount( 0 );
	await shot( page, testInfo, '06-site-assigned' );

	// visitors to b.wp.test get its bundle; a.wp.test is switched off, c.wp.test has no Profile
	const visitor = await ( await browser.newContext() ).newPage();
	const bundle = visitor.waitForResponse( ( r ) => r.url().endsWith( `/public/tracker/${ siteB }.js` ) );
	await visitor.goto( 'http://b.wp.test/' );
	expect( ( await bundle ).status(), 'b.wp.test' ).toBe( 200 );
	for ( const host of [ 'a.wp.test', 'c.wp.test' ] ) {
		await visitor.goto( `http://${ host }/` );
		expect( await visitor.content(), host ).not.toContain( 'Open Web Analytics Tracker' );
	}
} );

test( 'each site picks from the Profiles sharing its domain', async ( { page }, testInfo ) => {

	await login( page );
	await page.goto( NETWORK_PAGE );
	await page.getByLabel( /Network configures each site's Observation Profile/ ).uncheck();
	await expect( page.getByText( 'Save to switch.' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Save Changes' } ).click();
	await expect( notice( page, 'Settings saved.' ) ).toBeVisible();
	await expect( page.getByRole( 'cell', { name: 'not chosen yet' } ).first() ).toBeVisible();
	await shot( page, testInfo, '07-network-site-choice' );

	await page.goto( 'http://b.wp.test/wp-admin/admin.php?page=owa-wordpress' );
	const picker = page.getByLabel( 'Observation Profile', { exact: true } );
	await expect( picker.locator( 'option' ) ).not.toHaveCount( 1 );
	await expect( picker ).toBeEnabled();

	// the wp.test family, not www.other.example
	const labels = await picker.locator( 'option' ).allTextContents();
	expect( labels.some( ( v ) => v.includes( 'https://a.wp.test' ) ) ).toBe( true );
	expect( labels.some( ( v ) => v.includes( 'other.example' ) ) ).toBe( false );
	await expect( page.getByText( 'The list shows the Observation Profiles that share this site\'s domain.' ) ).toBeVisible();
	await shot( page, testInfo, '08-site-choice' );
} );
