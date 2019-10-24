const redis = require( 'redis' );
const Logger = require( '../../logger' );
const database = require( '../../database' );

const firebase = require( 'firebase-admin' );
const firebaseServiceAccount = require( '../../serviceAccountKey.json' );

//==========================================//
//	Constants								//
//==========================================//
const duration = 300; // Set our check to look back for the last 5 mintues
const max = 5; // Set the max notifications received since last sign on to 5

//==========================================//
//	Firebase								//
//==========================================//
firebase.initializeApp( {
	credential: firebase.credential.cert( firebaseServiceAccount ),
	databaseURL: 'https://pocket-realm.firebaseio.com'
} );

//==========================================//
//	Redis									//
//==========================================//
const redisListener = redis.createClient();
redisListener.on( "error", onError );
redisListener.on( "ready", onReady );
redisListener.on( "connect", onConnect );
redisListener.on( "message", onMessage );

//==========================================//
//	Event Handlers							//
//==========================================//
async function onError( err ) {
	Logger.logError( "Error: "+ err );
}

async function onReady() {
	Logger.logServer( "Service Ready" );
	redisListener.subscribe( "SEND_NOTIFICATION" );
}

async function onConnect() {
	Logger.logServer( "Connected to Redis Server" );
}

async function onMessage( channel, data ) {
	Logger.logServer( "Message: " + channel + ":" + data );
	
	data = JSON.parse( data );
	const { userid, type, message } = data;

	// Grab the tokens and
	let ids = await database.get( "SELECT token FROM users_push_tokens WHERE userid = " + userid + " AND token <> 'undefined'" );
	ids = ids.map( id => { return id.token } );

	// Various check queries
	let enabledQuery = "SELECT id FROM users_notifications_settings WHERE userid = " + userid + " AND type = 'enabled' LIMIT 1";
	let checkQuery = "SELECT id FROM users_notifications_settings WHERE userid = " + userid + " AND type = '" + type + "' LIMIT 1";
	let lastQuery = "SELECT id FROM users_notifications WHERE userid = " + userid + " AND time > UNIX_TIMESTAMP() - " + duration + " LIMIT 1";
	let throttleQuery = "SELECT COUNT(users_notifications.id) AS total FROM users_notifications INNER JOIN users ON users.id = userid WHERE userid = " + userid + " AND time > last_seen";

	// Make sure they have notifications enabled
	let enabled = await database.get( enabledQuery );
	if( !enabled || enabled.length === 0 ) return;

	// Do they have THIS type of notification enabled?
	let checked = await database.get( checkQuery );
	if( !checked || checked.length === 0 ) return;

	// Have they received a notification in the last few minutes?
	let last = await database.get( lastQuery );
	if( last && last.length !== 0 ) return;

	// Have they hit our max since last sign on?
	let throttle = await database.get( throttleQuery );
	if( throttle && throttle[ 0 ].total >= max ) return;

	// Everything is good: They are set to receive notifications of this time and haven't
	// received any recently or a lot since last visit.  SEND THE NOTIFICATION
	// Create the Notification
	let payload = {
		notification: {
			title: "TITLE",
			body: message,
		}
	};
	let options = {
		priority: "high",
		collapseKey: "PocketRealm",
		timeToLive: 60 * 60
	};
	
	// Send the notification to each token
	ids.forEach( id => {
		firebase.messaging().sendToDevice( id, payload, options )
			.then( res => { 
				Logger.logNotification( "Notification Sent: " + id + " - " + type );			
			} )
			.catch( err => {
				Logger.logError( "Error Sending Notification: " + err );
			} );
	} )
	
	// Record this notification
	const recordQuery = "INSERT INTO users_notifications SET userid = " + userid + ", type = '" + type + "', message = '" + message + "', time = UNIX_TIMESTAMP()";
	const result = await database.execute( recordQuery );
	if( result && result.affectedRows === 1 ) Logger.logServer( "Notification Recorded" );
	else Logger.logError( "Notification Not Recorded: " + recordQuery );
}

Logger.logServer( "Notification Service Started" );
