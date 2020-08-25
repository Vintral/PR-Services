<?php
class Round {
	private $id;
	private $database;
	
	private $energy;
	private $maxEnergy;
	private $land;
	private $gold;
	private $food;
	private $wood;
	private $metal;
	private $active;
	private $expires;
	private $recurring;
	private $duration;
	
	private $loaded;
	private $_debug = true;
	
	public function getRecurring() { return $this->recurring; }
	public function setRecurring( $value ) { $this->recurring = $value; }
	
	public function getDuration() { return $this->duration; }
	public function setDuration( $value ) { $this->duration = $value; }
	
	public function getExpires() { return $this->expires; }	
	
	public function __construct( $id = 0 ) {
		if( $id !== 0 ) $this->debug( "Created: " . $id );
		else $this->debug( "Created" );
		
		$this->id = $id;
		$this->load();
    }
    
    private function getGemReward( $tier ) {
        switch( $tier ) {
            case 'platinum': return 50;
            case 'gold': return 25;
            case 'silver': return 10;
            case 'bronze': return 5;
            default: return 0;
        }
    }
	
	public function finalize() {
		$this->debug( "finalize" );
		
		if( $this->loaded ) {
			//$this->database->executeQuery( "UPDATE rounds SET active = 0 WHERE id = $this->id" );
			//$this->database->executeQuery( "UPDATE users SET current_round = 0 WHERE current_round = $this->id" );
			
			$totalUsers = $this->database->getValue( "SELECT COUNT(id) FROM users_rounds WHERE roundid = $this->id" );
			$this->debug( "Total Users: " . $totalUsers );
			
			$tiers = array();
			array_push( $tiers, ceil( $totalUsers * .01 ) );
			array_push( $tiers, ceil( $totalUsers * .05 ) );
			array_push( $tiers, ceil( $totalUsers * .10 ) );
            array_push( $tiers, ceil( $totalUsers * .25 ) );
                        
			$players = $this->database->getObjects( "SELECT userid FROM users_rounds WHERE roundid = $this->id ORDER BY power DESC" );
			$count = 0;
			foreach( $players as $player ) {
				$tier = "";
				
				if( $count < $tiers[ 0 ] ) $tier = "platinum";
				else if( $count < $tiers[ 1 ] ) $tier = "gold";
                else if( $count < $tiers[ 2 ] ) $tier = "silver";
                else if( $count < $tiers[ 3 ] ) $tier = "bronze";
                else $tier = "none";
            
                $reward = $this->getGemReward( $tier );
                
				$user = new User( (object)[ 'id' => $player->userid, 'roundid' => $this->id ] );
                $user->creditGems( $reward );
                                
                $this->database->executeQuery( "INSERT INTO rankings ( `rank`, userid, round, power, land, tier, earned ) VALUES ( " . ( $count + 1 ) . ", $user->id, $this->id, $user->power, " . floor( $user->land / 10000 ) . ", '$tier', $reward )" );
                
                $count++;
                $user->log( "Rank: $count Round: $this->id Earned: $reward gems", false );
			}

			$this->database->executeQuery( "UPDATE users_rounds SET active = 0 WHERE roundid = $this->id" );
			$this->database->executeQuery( "UPDATE rounds SET processed = 1 WHERE id = $this->id" );
			
			return $this->recurring;
		} else $this->debug( "Not Loaded" );
	}
	
	public function create() {
		$this->debug( "create" );
		$this->debug( "INSERT INTO rounds SET energy = $this->energy, max_energy = $this->maxEnergy, land = $this->land, gold = $this->gold, food = $this->food, wood = $this->wood, metal = $this->metal, active = $this->active, expires = UNIX_TIMESTAMP() + ( $this->duration * 86400 ), recurring = $this->recurring, days = $this->duration" );
		
		$rid = $this->database->executeQuery( "INSERT INTO rounds SET energy = $this->energy, max_energy = $this->maxEnergy, land = $this->land, gold = $this->gold, food = $this->food, wood = $this->wood, metal = $this->metal, active = $this->active, expires = UNIX_TIMESTAMP() + ( $this->duration * 86400 ), recurring = $this->recurring, days = $this->duration" );
		
		$this->database->executeQuery( "INSERT INTO market SET roundid = $rid, type='wood', price = 1.5, total_bought = 100, total_sold = 100" );
		$this->database->executeQuery( "INSERT INTO market SET roundid = $rid, type='stone', price = 1.5, total_bought = 100, total_sold = 100" );
		$this->database->executeQuery( "INSERT INTO market SET roundid = $rid, type='food', price = 1.5, total_bought = 100, total_sold = 100" );
		$this->database->executeQuery( "INSERT INTO market SET roundid = $rid, type='metal', price = 1.5, total_bought = 100, total_sold = 100" );
		
	}
	
	public function updateMarket() {
		$this->debug( "updateMarket" );
			
		//Grab and loop through the items
		$items = $this->database->getObjects( "SELECT * FROM market WHERE roundid = $this->id" );
		foreach( $items as $item ) {					
			$item->oldPrice = $item->price;			
			if( $item->bought != $item->sold ) {
				if( $item->bought > $item->sold ) {
					//Calculate the ratio, and limit it to 2%
					$scale = $item->sold != 0 ? $item->bought / $item->sold : 2;
					if( $scale > 2 ) $scale = 2;
					$scale = ( $scale / 100 ) + 1;
					
					$item->price = round( $item->price * $scale, 2 );					
				} else {
					//Calculate the ratio, and limit it to 2%
					$scale = $item->bought != 0 ? $item->sold / $item->bought : 2;
					if( $scale > 2 ) $scale = 2;
					$scale = ( $scale / 100 ) + 1;
					
					$item->price = round( $item->price / $scale, 2 );					
				}
			}

			//Store in market history
			$historyQuery = "INSERT INTO market_history SET roundid = $this->id, type = '$item->type', price = $item->oldPrice, bought = $item->bought, sold = $item->sold, timestamp = UNIX_TIMESTAMP()";			
			$this->debug( $historyQuery );
			$this->database->executeQuery( $historyQuery );
			
			//Update current values
			$updateQuery = "UPDATE market SET bought = 0, sold = 0, price = $item->price WHERE id = $item->id AND roundid = $this->id";
			$this->debug( $updateQuery );
			$this->database->executeQuery( $updateQuery );
		}		
	}
	
	private function load() {
		$this->debug( "load" );
		
		$this->database = new Database();
		
		if( $this->id ) {			
			$data = $this->database->getObject( "SELECT * FROM rounds WHERE id = " . $this->id );
			if( $data ) {
				$this->energy = $data->energy;
				$this->maxEnergy = $data->max_energy;
				$this->land = $data->land;
				$this->gold = $data->gold;
				$this->food = $data->food;
				$this->wood = $data->wood;
				$this->metal = $data->metal;
				$this->active = $data->active;
				$this->expires = $data->expires;
				$this->recurring = $data->recurring;
				$this->duration = $data->days;
				
				$this->loaded = true;
			}
		}
		
		if( !$this->loaded ) $this->setDefaults();
	}
	
	private function setDefaults() {
		$this->energy = 10;
		$this->maxEnergy = 250;
		$this->land = 100;
		$this->gold = 500;
		$this->food = 200;
		$this->wood = 200;
		$this->metal = 200;
		$this->active = 1;
	}
	
	private function debug( $msg ) {
		if( $this->_debug ) 
			print_r( "Round: " . $msg . "\n" );
	}
}
?>