<?php
	include_once( "includes/settings.php" );
	
	// Create connection
	$database = new mysqli( $servername, $username, $password, $db );

	// Check connection
	if( !$database->connect_error ){
		//Record users active in the last 24 hours
		$result = $database->query( "SELECT COUNT(id) AS count FROM users WHERE last_seen >= UNIX_TIMESTAMP() - 86400" );		
		if( $result && $result->num_rows > 0 ) {
			$result = $result->fetch_object();			
			$database->query( "INSERT INTO users_daily SET count = " . $result->count . ", timestamp = UNIX_TIMESTAMP()" );
		};
		
		//Record new users created in the lsat 24 hours
		$result = $database->query( "SELECT COUNT(id) AS count FROM users WHERE created >= UNIX_TIMESTAMP() - 86400" ) ;
		if( $result && $result->num_rows > 0 ) {
			$result = $result->fetch_object();
			$database->query( "INSERT INTO users_daily_new SET users = " . $result->count . ", timestamp = UNIX_TIMESTAMP()" );
		}

		//Clear out login bonuses that weren't claimed, and set the ones that were to be unclaimed for tomorrow
		$database->query( "DELETE FROM login_bonus WHERE claimed = 0" );
		$database->query( "UPDATE login_bonus SET claimed = 0" );
		
		$database->close();		
	} else die( "Connection failed: " . $database->connect_error );	
?>