const { execFileSync } = require( 'child_process' );
const path = require( 'path' );
const fs = require( 'fs' );
const { expect } = require( '@playwright/test' );

const env = ( name ) => {
	const value = process.env[ name ];
	if ( ! value ) {
		throw new Error( `${ name } is not set: these tests run in CI's WordPress job.` );
	}
	return value;
};

/**
 * Logs in to wp-admin on a host of the install. wp-login.php focuses and
 * selects the username field on a 200ms timer, so the form is filled after
 * that and checked before it is sent.
 */
async function login( page, host = 'wp.test' ) {
	await page.goto( `http://${ host }/wp-login.php` );
	await page.waitForLoadState( 'load' );
	await page.waitForTimeout( 400 );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await expect( page.locator( '#user_login' ) ).toHaveValue( 'admin' );
	await expect( page.locator( '#user_pass' ) ).toHaveValue( 'password' );
	await Promise.all( [ page.waitForURL( /wp-admin/ ), page.locator( '#wp-submit' ).click() ] );
}

/** Whether the page paints: a requestAnimationFrame callback within 3s. */
function paints( page ) {
	return page.evaluate( () => new Promise( ( resolve ) => {
		const timer = setTimeout( () => resolve( false ), 3000 );
		requestAnimationFrame( () => { clearTimeout( timer ); resolve( true ); } );
	} ) );
}

/**
 * Submits a form with the given button and waits for the page it redirects
 * to, which must paint (see reducedMotion in playwright.config.js).
 */
async function submit( page, button, urlPattern ) {
	await Promise.all( [ page.waitForURL( urlPattern ), button.click() ] );
	await page.waitForLoadState( 'load' );
	const reduced = await page.evaluate( () => matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	expect( await paints( page ), `${ page.url() } paints after the form was sent (reduced motion: ${ reduced })` ).toBe( true );
}

/** A full-page screenshot into screenshots/<project>/, for the job artifact. */
async function shot( page, testInfo, name ) {
	const dir = path.join( 'screenshots', testInfo.project.name );
	fs.mkdirSync( dir, { recursive: true } );
	await page.screenshot( { path: path.join( dir, `${ name }.png` ), fullPage: true } );
}

/** The OWA site ID (Profile) of a domain, from the OWA database. */
function owaSiteId( domain ) {
	return execFileSync( 'mysql', [ '-h', '127.0.0.1', '-uroot', '-proot', 'owa', '-N', '-B', '-e',
		`SELECT site_id FROM owa_site WHERE domain = '${ domain }' LIMIT 1` ] ).toString().trim();
}

/** Runs OWA's CLI in the CI install. */
function owaCli( ...args ) {
	return execFileSync( 'php', [ 'cli.php', ...args ], { cwd: env( 'OWA_DIR' ) } ).toString();
}

/** Runs WP-CLI against the CI install. */
function wp( ...args ) {
	return execFileSync( 'wp', [ `--path=${ env( 'WP_DIR' ) }`, ...args ] ).toString();
}

module.exports = { env, login, submit, paints, shot, owaSiteId, owaCli, wp };
