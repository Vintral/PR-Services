<?php
class User {
    public $id;
    public $power;
    public $land;

	private $database;
	
	private $roundid;
	
	private $loaded;
	private $_debug = false;
	
	public function __construct( $data ) {
        if( $data->id !== 0 ) $this->debug( "Created: " . $data->id );
		else $this->debug( "Created" );			
		
		$this->id = $data->id;
		$this->roundid = $data->roundid;
		$this->load();
	}

	public function getID() {
		return $this->id;
	}
	
	public function creditGems( $reward ) {
		if( $this->id ) {				
			$this->debug( "creditGems: $reward" );
			$this->database->executeQuery( "UPDATE users SET gems = gems + $reward WHERE id = $this->id" );
		}
    }        
	
	public function populationLeave( $amount ) {
		if( $this->id ) {
			$this->debug( "populationLeave:$amount:$rid" );
			$this->database->executeQuery( "UPDATE users_rounds SET population = population - $amount WHERE userid = $this->id AND roundid = $rid" );
			
			//Grow populations
			$this->database->executeQuery( "UPDATE users_rounds SET population = 1 WHERE population <= 0 AND userid = $this->id AND roundid = $rid" );
			
			$foodUpkeep = $this->database->getValue( "SELECT SUM( quantity * upkeep_food ) FROM users_rounds_units LEFT JOIN units ON unitid = units.id WHERE userid = $this->id AND roundid = $rid" );
			$this->database->executeQuery( "UPDATE users_rounds SET users_rounds.gold_income = population * .5, users_rounds.food_upkeep = $foodUpkeep + population * .5 WHERE userid = $this->id AND roundid = $rid" );
		}
	}
	
	private function load() {
		$this->debug( "load" );
		
		$this->database = new Database();
		
		if( $this->id ) {			
			$data = $this->database->getObject( "SELECT * FROM users WHERE id = " . $this->id );
			if( $data ) {
				//print_r( "Get Data\n" );
				//print_r( "SELECT * FROM users_rounds WHERE userid = " . $this->id . " AND roundid = " . $this->roundid . "\n" );
				$data = $this->database->getObject( "SELECT * FROM users_rounds WHERE userid = " . $this->id . " AND roundid = " . $this->roundid );
                //print_r( $data );
                
                $this->land = $data->land;
                $this->power = $data->power;
				
				$this->loaded = true;
			}
			
			$this->loaded = true;
		}
    }
    
    public function log( $action, $round = true ) {
        $this->debug( 'log: ' . $action );
        print_r( "INSERT INTO users_log ( userid, roundid, action, time ) VALUES ( $this->id, " . ( $round ? $this->roundid : 0 ) . ", '$action', UNIX_TIMESTAMP() )" );
        $this->database->executeQuery( "INSERT INTO users_log ( userid, roundid, action, time ) VALUES ( $this->id, " . ( $round ? $this->roundid : 0 ) . ", '$action', UNIX_TIMESTAMP() )" );
    }
	
	private function debug( $msg ) {
		if( $this->_debug ) 
			print_r( "User: " . $msg . "\n" );
	}
}
?>