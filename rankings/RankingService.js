const redis = require( 'redis' );
const Logger = require( './logger' );
const database = require( './database' );
const guid = require('node-uuid');

//============================//
//	Redis Listener						//
//============================//
const redisInfo = {
    server: "pocket-realm-redis.3u6ezl.ng.0001.usw1.cache.amazonaws.com",
    port:6379
}

const redisListener = redis.createClient( redisInfo.port, redisInfo.server );
redisListener.on( "error", onError );
redisListener.on( "ready", onReady );
redisListener.on( "connect", onConnect );
redisListener.on( "message", onMessage );

//============================//
//	Redis Pusher							//
//============================//
const redisClient = redis.createClient( redisInfo.port, redisInfo.server );
redisClient.on( "ready", () => { Logger.logServer( "Redis Client Ready" ); } );

//============================//
//	Event Handlers						//
//============================//
async function onError( err ) {
	Logger.logError( "Error: "+ err );
}

async function onReady() {
    Logger.logServer( "Service Ready" );
    
    // Subscribe to various channels
    redisListener.subscribe( "SET_POWER" );
    redisListener.subscribe( "GET_RANK" );
    redisListener.subscribe( "GET_RANKINGS" );
}

async function onConnect() {
	Logger.logServer( "Connected to Redis Server" );
}

async function onMessage( channel, data ) {
    Logger.logServer( "Message: " + channel + ":" + data );
    
    /*data = JSON.parse( data );
    const { userid } = data;

    let result = "";
    switch( channel ) {
        case "USER_ONLINE":
            Logger.logServer( "USER IS ONLINE" );

            // See if we've had any jobs claimed in within our throttle timer
            let lastQuery = "SELECT id FROM users_job_history WHERE userid = " + userid + " AND time > UNIX_TIMESTAMP() - " + throttle + " LIMIT 1";
            result = await database.getOne( lastQuery );
            if( result ){
                // Start up a timer
                setTimer( userid );
                break;    
            }

            // We have no recent ads claimed, let's let them know we're ready!
            dispatch( "JOB_READY", userid );
            setTimer( userid ); // To DO - REMOVE THIS
            break;
        case "USER_OFFLINE":
            Logger.logServer( "USER IS OFFLINE" );
            clearTimer( userid );
            break;
        case "CLAIM_JOB":
            Logger.logServer( "USER CLAIMED JOB" );

            const { job } = data;
            await claimJob( userid, job );


            //const { reward } = data;
            //const recordQuery = "INSERT INTO users_job_history SET userid = " + userid + ", reward = '" + reward + "', time = UNIX_TIMESTAMP()";
            //result = await database.execute( recordQuery );
            //console.log( result );

            setTimer( userid );
            break;
        case "GET_JOBS":
            Logger.logServer( "GET_JOBS" );
            getJobs( userid );
            break;
        case "CLEAR_JOBS":
            Logger.logServer( "CLEAR_JOBS" );
            clearJobs( userid );
            break;
    }*/
}

//============================//
//	Methods        						//
//============================//
function dispatchError( userid, msg ) {
  Logger.logError( "Job Error: " + msg );

  let packet = {};
  packet.userid = userid;
  packet.message = msg;
  redisClient.publish( "JOB_ERROR", JSON.stringify( packet ) );
}

function dispatch( type, userid, data ) {
  Logger.logServer( "Dispatch: " + type + " - " + userid + ( data ? " : " + JSON.stringify( data ) : "" ) );

  let packet = {};
  packet.userid = userid;
  if( data ) packet.data = data;

  redisClient.publish( type, JSON.stringify( packet ) );
}

Logger.logServer( "Ranking Service Started" );