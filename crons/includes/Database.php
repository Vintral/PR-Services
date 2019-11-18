<?php
class Database {
	private $connection;
	private $ready = false;
	private $_debug = false;
	
	public function __construct() {
		$this->debug( "Created" );
		
		$this->servername = "localhost";
		$this->username = "temp";
		$this->password = "t3mp";
		$this->db = "temp";
		
		$this->load();
	}
	
	private function load() {
		$this->debug( "load" );
		
		$this->connection = new mysqli( $this->servername, $this->username, $this->password, $this->db );		
		if( !$this->connection->connect_error ){
			$this->ready = true;
		} else die( "Connection failed: " . $this->connection->connect_error );	
	}
	
	public function executeQuery( $query ) {
		if( $this->ready ) {
			$this->debug( "executeQuery: " . $query );
			$this->connection->query( $query );
			return $this->connection->insert_id;
		}
	}
	
	public function getValue( $query ) {
		if( $this->ready ) {
			$this->debug( "getValue: " . $query );
			
			$result = $this->connection->query( $query );
			if( $result && $result->num_rows > 0 ) {
				$result = $result->fetch_object();
				foreach( $result as $data ) {
					return $data;
					break;
				}
			} else return -1;			
		}
	}
	
	public function getObject( $query ) {
		if( $this->ready ) {
			$this->debug( "getObject: " . $query );
			
			$result = $this->connection->query( $query );		
			if( $result && $result->num_rows > 0 ) {
				return $result->fetch_object();
			}
			
		}
	}
	
	public function getObjects( $query ) {
		if( $this->ready ) {
			$this->debug( "getObject: " . $query );
			
			$ret = array();
			$results = $this->connection->query( $query );
			while( $data = $results->fetch_object() ) {
				array_push( $ret, $data );
			}
			
			return $ret;
		}
	}
	
	private function debug( $msg ) {
		if( $this->_debug ) 
			print_r( "Database: " . $msg . "\n" );
	}
}
?>