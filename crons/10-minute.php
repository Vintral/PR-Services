<?php
	include_once( "includes/settings.php" );
	
	// Create connection
	$database = new mysqli( $servername, $username, $password, $db );

	// Check connection
	if( !$database->connect_error ){		
		/*$database->query( "UPDATE users_rounds SET gold = 5000, metal = 5000, wood = 5000, stone = 5000, land = 300, land_free = 300, population = 50, population_max = 50, food = 5000, turns = 250, recruit = 10, build = 10, defense = 10 WHERE userid > 1" );
		$database->query( "DELETE FROM users_rounds_buildings WHERE userid > 30" );
		$database->query( "DELETE FROM users_rounds_units WHERE userid > 30" );*/
		
		//Various error correction queries
		$database->query( "UPDATE users_rounds SET gold = 0 WHERE gold < 0" );
		$database->query( "UPDATE users_rounds SET food = 0 WHERE food < 0" );
		$database->query( "UPDATE users_rounds SET wood = 0 WHERE wood < 0" );
		$database->query( "UPDATE users_rounds SET stone = 0 WHERE stone < 0" );
		$database->query( "UPDATE users_rounds SET metal = 0 WHERE metal < 0" );
				
		$database->close();		
	} else die( "Connection failed: " . $database->connect_error );	
?>