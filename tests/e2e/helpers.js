const { execFileSync } = require( 'child_process' );
const path = require( 'path' );
const fs = require( 'fs' );

const env = ( name ) => {
	const value = process.env[ name ];
	if ( ! value ) {
		throw new Error( `${ name } is not set: these tests run in CI's WordPress job.` );
	}
	return value;
};

/** Logs in to wp-admin on a host of the install. */
async function login( page, host = 'wp.test' ) {
	await page.goto( `http://${ host }/wp-login.php` );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await Promise.all( [ page.waitForURL( /wp-admin/ ), page.click( '#wp-submit' ) ] );
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

module.exports = { env, login, shot, owaSiteId, owaCli, wp };
