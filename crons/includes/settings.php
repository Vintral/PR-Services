<?php
	//Setup the auto-loader
	spl_autoload_register( function( $class_name ) {
		include $class_name . '.php';
	} );

	//Database settings
	$servername = "pocket-realm.ctwbpohhunlz.us-west-1.rds.amazonaws.com";
	$username = "pocket_realm_user";
	$password = "Dt9Lpmr5DNfs7WKM";
	$db = "PocketRealm";

	$redis = "pocket-realm-redis.3u6ezl.ng.0001.usw1.cache.amazonaws.com";
?>
