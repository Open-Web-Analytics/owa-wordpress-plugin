<?php

/*
 * Router for `php -S`, standing in for the one OWA .htaccess rule the plugin
 * depends on:
 *
 *     RewriteRule api/(.*)$ api/index.php?owa_rest_params=$1 [QSA,NC,L]
 *
 * Everything else is served from the document root as a file.
 */

$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

if ( preg_match( '#^/api/(.+)$#', $path, $m ) ) {

	$_GET['owa_rest_params'] = $m[1];
	$_REQUEST['owa_rest_params'] = $m[1];

	chdir( $_SERVER['DOCUMENT_ROOT'] . '/api' );
	require $_SERVER['DOCUMENT_ROOT'] . '/api/index.php';

	return true;
}

return false;
