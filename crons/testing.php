<?php
	define( "TEXAS", "TEXAS" );
	define( "OHIO", "OHIO" );
	define( "MAINE", "MAINE" );
	define( "TX_RATE", 2 );
	define( "OH_RATE", 3 );
	define( "ME_RATE", 4 );	
	
	$state = "OHIOs";
	
	switch( $state ) {
		case TEXAS: $rate = TX_RATE; break;
		case OHIO: $rate = OH_RATE; $points = 2; break;
		case MAINE: $rate = ME_RATE; break;
		default: $rate = 1;
	}
	
	$base = 5;
	$amt = $base * $rate;
	print_r( $amt );
?>