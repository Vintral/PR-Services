<?php
	//Setup the auto-loader
	spl_autoload_register( function( $class_name ) {
		include $class_name . '.php';
	} );

	//Database settings
	$servername = "pocketrealm-db.ctwbpohhunlz.us-west-1.rds.amazonaws.com";
	$username = "admin";
	$password = "password";
	$db = "PocketRealm";

    $redisHost = "127.0.0.1";//"pocket-realm-redis.3u6ezl.ng.0001.usw1.cache.amazonaws.com";
    
    $LAND_PRECISION = 10000;
?>
