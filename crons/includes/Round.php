<?php
class Round {
	private $id;
	private $database;
	
	private $turns;
	private $maxTurns;
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
	private $_debug = false;
	
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
	
	public function finalize() {
		$this->debug( "finalize" );
		
		if( $this->loaded ) {
			$this->database->executeQuery( "UPDATE rounds SET active = 0 WHERE id = $this->id" );
			$this->database->executeQuery( "UPDATE users SET current_round = 0 WHERE current_round = $this->id" );
			
			$totalUsers = $this->database->getValue( "SELECT COUNT(id) FROM users_rounds WHERE roundid = $this->id" );
			$this->debug( "Total Users: " . $totalUsers );
			
			$tiers = array();
			array_push( $tiers, ceil( $totalUsers * .01 ) );
			array_push( $tiers, ceil( $totalUsers * .05 ) );
			array_push( $tiers, ceil( $totalUsers * .10 ) );
			array_push( $tiers, ceil( $totalUsers * .25 ) );
			
			$winners = $this->database->getObjects( "SELECT userid FROM users_rounds WHERE roundid = $this->id LIMIT " . $tiers[ 3 ] );
			$count = 0;
			foreach( $winners as $winner ) {
				$reward = "";
				
				if( $count < $tiers[ 0 ] ) $reward = 25;
				else if( $count < $tiers[ 1 ] ) $reward = 15;
				else if( $count < $tiers[ 2 ] ) $reward = 10;
				else if( $count < $tiers[ 3 ] ) $reward = 5;
			
				$user = new User( $winner->userid );
				$user->creditGems( $reward );
				
				$count++;
			}

			$this->database->executeQuery( "UPDATE users_rounds SET active = 0 WHERE roundid = $this->id" );
			$this->database->executeQuery( "UPDATE rounds SET processed = 1 WHERE id = $this->id" );
			
			return $this->recurring;			
		} else $this->debug( "Not Loaded" );
	}
	
	public function create() {
		$this->debug( "create" );
		$this->debug( "INSERT INTO rounds SET turns = $this->turns, max_turns = $this->maxTurns, land = $this->land, gold = $this->gold, food = $this->food, wood = $this->wood, metal = $this->metal, active = $this->active, expires = UNIX_TIMESTAMP() + ( $this->duration * 86400 ), recurring = $this->recurring, days = $this->duration" );
		
		$rid = $this->database->executeQuery( "INSERT INTO rounds SET turns = $this->turns, max_turns = $this->maxTurns, land = $this->land, gold = $this->gold, food = $this->food, wood = $this->wood, metal = $this->metal, active = $this->active, expires = UNIX_TIMESTAMP() + ( $this->duration * 86400 ), recurring = $this->recurring, days = $this->duration" );
		
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
				$this->turns = $data->turns;
				$this->maxTurns = $data->max_turns;
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
		$this->turns = 10;
		$this->maxTurns = 250;
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