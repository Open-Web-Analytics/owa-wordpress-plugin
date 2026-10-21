// Temporary: why pages stop painting after the first save in CI.
const { test } = require( '@playwright/test' );
const { env, login } = require( './helpers' );

const paints = ( page ) => page.evaluate( () => new Promise( ( resolve ) => {
	const timer = setTimeout( () => resolve( false ), 3000 );
	requestAnimationFrame( () => { clearTimeout( timer ); resolve( true ); } );
} ) );

async function probe( label, page, url ) {
	if ( url ) {
		await page.goto( url );
	}
	await page.waitForLoadState( 'load' );
	const info = await page.evaluate( () => ( {
		ready: document.readyState,
		visibility: document.visibilityState,
		sheets: Array.from( document.styleSheets ).length,
		pending: performance.getEntriesByType( 'resource' ).filter( ( r ) => ! r.responseEnd ).map( ( r ) => r.name ),
	} ) );
	console.log( `DIAG ${ label }: painted=${ await paints( page ) } ${ JSON.stringify( info ) } url=${ page.url() }` );
}

test( 'diagnose painting', async ( { page, browser } ) => {
	await login( page );
	await probe( 'settings before save', page, '/wp-admin/admin.php?page=owa-wordpress' );

	await page.check( '#owa_wp_owaVersion_2' );
	await page.fill( '#owa_wp_owaEndpoint', env( 'OWA_URL' ) );
	await page.fill( '#owa_wp_appPassword', env( 'OWA_APP_PASSWORD' ) );
	await Promise.all( [ page.waitForURL( /settings-updated=true/ ), page.locator( 'input[name="Submit"]' ).click() ] );
	await probe( 'settings after save (same page)', page );

	const head = await page.evaluate( () => document.head.outerHTML );
	console.log( 'DIAG head after save:\n' + head.replace( /\n\s*\n/g, '\n' ).slice( 0, 6000 ) );

	await probe( 'dashboard (same page)', page, '/wp-admin/' );
	await probe( 'front page (same page)', page, '/' );

	const tab = await page.context().newPage();
	await probe( 'settings (new tab, same session)', tab, '/wp-admin/admin.php?page=owa-wordpress' );

	const fresh = await ( await browser.newContext() ).newPage();
	await login( fresh );
	await probe( 'settings (new session)', fresh, '/wp-admin/admin.php?page=owa-wordpress' );
	await probe( 'dashboard (new session)', fresh, '/wp-admin/' );
} );
