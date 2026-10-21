<?php

// Patchwork has to load before any function it may redefine.
require_once dirname( __DIR__ ) . '/vendor/antecedent/patchwork/Patchwork.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'WPINC', 'wp-includes' );
define( 'ABSPATH', '/tmp/wordpress/' );
