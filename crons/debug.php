<?php
	include_once( "includes/settings.php" );
	
	// Create connection
	$database = new mysqli( $servername, $username, $password, $db );	
	
	function getTotal() {
		global $database;
		
		$ret = 0;
		
		$total = $database->query( "SELECT COUNT(id) AS total FROM users_log WHERE roundid = 21" );
		$total = $total->fetch_object();
		$ret = $total->total;
		
		return $ret;
	}
	
	// Check connection
	if( !$database->connect_error ){						

		print_r( "Processing..." );
		
		$total = getTotal();		
		while( $total > 0 ) {
			$database->query( "DELETE FROM users_log WHERE roundid = 21 LIMIT 10000" );
			
			$total = $database->query( "SELECT COUNT(id) AS total FROM users_log WHERE roundid = 21" );
			$total = $total->fetch_object();
			$total = $total->total;
			
			$total -= 10000;
			print_r( "." );
		}
		
		print_r( "Done!\n\n" );
		
		
		
		
		$database->close();		
	} else die( "Connection failed: " . $database->connect_error );	
?>