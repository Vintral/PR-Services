<?php
	include_once( "includes/settings.php" );
	
	// Create connection
	$database = new mysqli( $servername, $username, $password, $db );

	// Create and connect to redis
	$redis = new Redis();
	$redis->connect( $redisHost );

	$foodPerPopulation = 1;
	$goldPerPopulation = 1;
	$populationGrowth = 1.25;
	
	function updateUserUpkeep( $uid, $rid ) {		
		global $database;
		global $foodPerPopulation;
		global $goldPerPopulation;
		
		$result = $database->query( "SELECT land FROM users_rounds WHERE userid = $uid AND roundid = $rid LIMIT 1" );
		$result = $result->fetch_object();		
		$power = $result->land * 5;		
		
		$army = $database->query( "SELECT quantity, attack, defense, ( quantity * ( attack + defense ) ) AS total FROM users_rounds_units INNER JOIN units ON units.id = unitid WHERE userid = $uid AND roundid = $rid" );
		while( $unit = $army->fetch_object() ) {			
			$power += intval( $unit->total );			
		};
			
		$database->query( "UPDATE users_rounds LEFT JOIN ( SELECT userid, roundid, SUM( quantity * upkeep_gold ) as sumGold, SUM( quantity * upkeep_food ) AS sumFood FROM users_rounds_units INNER JOIN units ON unitid = units.id WHERE userid = $uid AND roundid = $rid GROUP BY userid, roundid ) AS s ON s.userid = users_rounds.userid SET users_rounds.power = $power, users_rounds.gold_upkeep = IF( s.sumGold, s.sumGold, 0 ), users_rounds.food_upkeep = IF( s.sumFood, s.sumFood, 0 ) + users_rounds.population * $foodPerPopulation, users_rounds.gold_income = users_rounds.population * $goldPerPopulation WHERE users_rounds.userid = $uid AND users_rounds.roundid = $rid" );				
	}
	
	function recordEvent( $uid, $rid, $msg, $type ) {		
		global $database;		
		$database->query( "INSERT INTO events SET userid = $uid, type = '$type', roundid = $rid, event = '" . addslashes( $msg ) . "', time = UNIX_TIMESTAMP()" );
	}
	
	function loseUnit( $uid, $rid, $unit, $quantity ) {
		global $database;
		
		if( $quantity === $unit->quantity ) $query = "DELETE FROM users_rounds_units WHERE userid = $uid AND roundid = $rid AND unitid = $unit->unitid";
		else $query = "UPDATE users_rounds_units SET quantity = quantity - $quantity WHERE userid = $uid AND roundid = $rid AND unitid = $unit->unitid AND quantity >= $quantity";
		
		return $database->query( $query );
	}
	
	function processGoldDeficits() {
		global $database;
		
		$users = $database->query( "SELECT userid, roundid, gold + ( gold_income - gold_upkeep ) AS goldDeficit FROM users_rounds WHERE ( gold_income - gold_upkeep ) < 0 AND gold + gold_income < gold_upkeep" );
		if( $users && $users->num_rows > 0 ) {
			while( $user = $users->fetch_object() ) {				
				//Grab their units ordered by food upkeep				
				$units = $database->query( "SELECT quantity, unitid, ( quantity * upkeep_gold ) AS upkeep, upkeep_gold, name, plural FROM users_rounds_units INNER JOIN units ON units.id = unitid WHERE userid = $user->userid AND roundid = $user->roundid ORDER BY upkeep DESC LIMIT 1" );				
				if( $units && $units->num_rows > 0 ) {					
					//They have units, so let's take some of them					
					$unit = $units->fetch_object();					
					
					
					//See how many we need to remove, if the entire stack, or only some						
					$quantity = ceil( -1 * $user->goldDeficit / $unit->upkeep_gold );
					$quantity = $quantity > $unit->quantity ? $unit->quantity : $quantity;					
					
					print_r( "Not Enough Gold: Take $quantity $unit->unitid from $user->userid\n" );
					
					//Process loss
					$result = loseUnit( $user->userid, $user->roundid, $unit, $quantity );					

					//Update our new expenses for unit upkeep gold/food
					if( $result ) {						
						updateUserUpkeep( $user->userid, $user->roundid );
							
						//Add an event saying what we lost
						$message = $quantity . ' ' . ( $quantity == 1 ? $unit->name : $unit->plural ) . ' abandoned you because you couldn\'t pay them!';
						recordEvent( $user->userid, $user->roundid, $message, 'abandoned' );
					}
				}
			}
			
			//Grab any users still in trouble
			processGoldDeficits();
		}	
	}
	
	function processFoodDeficits() {		
		global $database;
		global $foodPerPopulation;
		
		$users = $database->query( "SELECT userid, roundid, population, ( food_income - food_upkeep ) AS foodtick FROM users_rounds WHERE ( food_income - food_upkeep ) < 0 AND food + food_income < food_upkeep" );
		if( $users && $users->num_rows > 0 ) {
			while( $user = $users->fetch_object() ) {				
				//Grab their most expensive unit
				$units = $database->query( "SELECT quantity, unitid, ( quantity * upkeep_food ) AS upkeep, upkeep_food, name, plural FROM users_rounds_units INNER JOIN units ON units.id = unitid WHERE userid = $user->userid AND roundid = $user->roundid ORDER BY upkeep DESC LIMIT 1" );
				if( $units && $units->num_rows > 0 ) {					
					while( $unit = $units->fetch_object() ) {
						//See how many we need to remove, if the entire stack, or only some						
						$quantity = ceil( -1 * $user->foodtick / $unit->upkeep_food );
						$quantity = $quantity > $unit->quantity ? $unit->quantity : $quantity;											
						
						print_r( "Not Enough Food: Take $quantity $unit->unitid from $user->userid\n" );
						
						//Process loss
						$result = loseUnit( $user->userid, $user->roundid, $unit, $quantity );							
						
						//Update our new expenses for unit upkeep gold/food
						if( $result ) {						
							updateUserUpkeep( $user->userid, $user->roundid );
								
							//Add an event saying what we lost
							$message = $quantity . ' ' . ( $quantity == 1 ? $unit->name : $unit->plural ) . ' abandoned you because you couldn\'t feed them!';							
							recordEvent( $user->userid, $user->roundid, $message, 'abandoned' );
						}											
					}
				} else {					
					//We don't have any units to take, take some people					
					$left = ceil( -1 * $user->foodtick / $foodPerPopulation );					
					
					$result = $database->query( "UPDATE users_rounds SET population = population - $left WHERE userid = $user->userid AND roundid = $user->roundid AND population >= $left" );
					if( $result ) {
						updateUserUpkeep( $user->userid, $user->roundid );
						
						$message = ceil( $left ) . " population left to find food elsewhere";
						recordEvent( $user->userid, $user->roundid, $message, 'abandoned' );
					}
				}				
			}
			
			//Grab any users still in trouble
			processFoodDeficits();
		}			
	}
	
	function processPopulationDeficits() {
		global $database;

		$users = $database->query( "SELECT userid, roundid, population, population_max FROM users_rounds WHERE population > population_max" );
		if( $users && $users->num_rows > 0 ) {
			while( $user = $users->fetch_object() ) {				
				$leave = $user->population - $user->population_max;				
				
				$result = $database->query( "UPDATE users_rounds SET population = population - $leave WHERE userid = $user->userid AND roundid = $user->roundid AND population >= $leave" );
				if( $result ) {
					updateUserUpkeep($user->userid, $user->roundid );
					
					$message = $leave . " population left because you had no place for them to live";				
					recordEvent( $user->userid, $user->roundid, $message, 'abandoned' );
				}
			}
		}
	}

	function growPopulations() {
		global $database;
		global $foodPerPopulation;
		global $goldPerPopulation;
		global $populationGrowth;	
				
		$database->query( "UPDATE users_rounds LEFT JOIN ( SELECT userid, roundid, SUM( quantity * upkeep_food ) AS sumFood FROM users_rounds_units INNER JOIN units ON unitid = units.id GROUP BY userid, roundid ) AS s ON s.userid = users_rounds.userid AND s.roundid = users_rounds.roundid SET users_rounds.population = if( users_rounds.population * $populationGrowth < users_rounds.population_max, users_rounds.population * $populationGrowth, population_max ), users_rounds.gold_income = FLOOR( population ) * $goldPerPopulation, users_rounds.food_upkeep = IF( s.sumFood, s.sumFood + ( FLOOR( population )* $foodPerPopulation ), FLOOR( population ) * $foodPerPopulation ) WHERE population < population_max AND ( food > 100 OR ( food > 10 AND ( food_income - ( food_upkeep * .9 ) ) > 0 ) )" );
	}
	
	// Check connection
	if( !$database->connect_error ){
		//See who doesn't have enough gold for their upkeep		
		processGoldDeficits();
		print_r( "Did gold" );

		//Check for people who can't feed their troops/people		
		processFoodDeficits();
		print_r( "Did food" );

		// Check for people over the max population
		processPopulationDeficits();
		print_r( "Did population" );
		
		//Update User Values
		$database->query( "UPDATE users_rounds SET food = food + ( food_income - food_upkeep ), stone = stone + ( stone_income - stone_upkeep ), wood = wood + ( wood_income - wood_upkeep ), faith = faith + ( faith_income - faith_upkeep ), mana = mana + ( mana_income - mana_upkeep ), gold = gold + ( gold_income - gold_upkeep ), metal = metal + ( metal_income - metal_upkeep) WHERE active = 1" );
		print_r( "Did users" );
		
		//Various error correction queries
		$database->query( "UPDATE users_rounds SET gold = 0 WHERE gold < 0" );
		$database->query( "UPDATE users_rounds SET food = 0 WHERE food < 1" );
		//print_r( "Corrected errors" );
		
		//Grow populations		
		$database->query( "UPDATE users_rounds SET population = 1 WHERE population <= 1" );
		growPopulations();		
		
		$rounds = $database->query( "SELECT id, max_energy, energy FROM rounds WHERE active = 1" );		
		if( $rounds && $rounds->num_rows > 0 ) {			
			while( $round = $rounds->fetch_object() ) {
				
				//$database->query ( "DELETE FROM rankings WHERE roundid = 0" );
				
				//Update Users energy
				$round->energy = 100;
				
				$message = "Energy has maxxed out!";
				$fullTurnUsers = $database->query( "SELECT users_rounds.userid FROM users_rounds INNER JOIN users_notifications_settings ON users_rounds.userid = users_notifications_settings.userid WHERE type = 'energy' AND roundid = $round->id AND energy < $round->max_energy AND energy + $round->energy >= $round->max_energy" );
				if( $fullTurnUsers ) while( $u = $fullTurnUsers->fetch_object() ) {
					$packet = new stdClass();
					$packet->userid = $u->userid;
					$packet->type = "energy";
					$packet->message = $message;
					$redis->publish( 'SEND_NOTIFICATION', json_encode( $packet ) );
				}

				$database->query( "UPDATE users_rounds SET energy = $round->max_energy WHERE energy < $round->max_energy AND energy > $round->max_energy - $round->energy" );
				$database->query( "UPDATE users_rounds SET energy = energy + $round->energy WHERE energy < $round->max_energy AND roundid = $round->id" );
				
				//Redo the rankings for each round						
				$database->query( "DELETE FROM rankings WHERE roundid = $round->id" );
				$results = $database->query( "INSERT INTO rankings SELECT @rownum := @rownum + 1 AS rank, users.userid, roundid, power, land FROM users_rounds users, (SELECT @rownum :=0)r WHERE roundid = $round->id ORDER BY power DESC, id ASC" );
			}
		}		
		
		$database->query( "DELETE FROM users_rounds_units WHERE quantity = 0" );
		$database->query( "DELETE FROM users_rounds_buildings WHERE quantity = 0" );		
		
		// Clear out old notifications
		//$database->query( 'DELETE FROM users_notifications WHERE time < UNIX_TIMESTAMP() - 300' );

		$database->close();
	} else die( "Connection failed: " . $database->connect_error );	
?>