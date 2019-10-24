<?php
	include_once( "includes/settings.php" );
	
	// Create connection
	$database = new Database();
	
	$result = $database->getValue( "SELECT COUNT(id) AS count FROM users WHERE last_seen >= UNIX_TIMESTAMP() - 3600" );	
	$database->executeQuery( "INSERT INTO users_hourly SET count = " . $result . ", timestamp = UNIX_TIMESTAMP()" );
	
	$rounds = $database->getObjects( "SELECT id FROM rounds WHERE active = 1" );
	foreach( $rounds as $roundinfo ) {
		$round = new Round( $roundinfo->id );		
		
		if( $round->getExpires() < time() ) {
			$duration = $round->getDuration();
			$spawn = $round->finalize();		
			if( $spawn ) {
				$round = new Round();
				$round->setRecurring( 1 );
				$round->setDuration( $duration );
				$round->create();
			}
		} else {
			$round->updateMarket();
		}
	}			
?>