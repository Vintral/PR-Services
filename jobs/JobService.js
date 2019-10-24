const redis = require( 'redis' );
const Logger = require( '../../logger' );
const database = require( '../../database' );
const ItemManager = require( '../../item-manager' );
const guid = require('node-uuid');

// Make sure the ItemManager has a database
ItemManager.database = database;

//==========================================//
//	Variables								//
//==========================================//
const throttle = 300; // Set our throttle to check in the last 5 minutes
const jobAmount = 5;	
            
let users = {};

//==========================================//
//	Redis Listener							//
//==========================================//
const redisListener = redis.createClient();
redisListener.on( "error", onError );
redisListener.on( "ready", onReady );
redisListener.on( "connect", onConnect );
redisListener.on( "message", onMessage );

//==========================================//
//	Redis Pusher							//
//==========================================//
const redisClient = redis.createClient();
redisClient.on( "ready", () => { Logger.logServer( "Redis Client Ready" ); } );

//==========================================//
//	Event Handlers							//
//==========================================//
async function onError( err ) {
	Logger.logError( "Error: "+ err );
}

async function onReady() {
    Logger.logServer( "Service Ready" );
    
    // Subscribe to various channels
    redisListener.subscribe( "USER_ONLINE" );
    redisListener.subscribe( "USER_OFFLINE" );
    redisListener.subscribe( "CLAIM_JOB" );
    redisListener.subscribe( "GET_JOBS" );
    redisListener.subscribe( "CLEAR_JOBS" );
}

async function onConnect() {
	Logger.logServer( "Connected to Redis Server" );
}

async function onMessage( channel, data ) {
    Logger.logServer( "Message: " + channel + ":" + data );
    
    data = JSON.parse( data );
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
    }
}

//==========================================//
//	Methods        							//
//==========================================//
function clearTimer( userid ) {
    if( users[ userid ] )
        clearTimeout( users[ userid ] );
}

function setTimer( userid ) {
    clearTimer( userid );
    users[ userid ] = setTimeout( () => {
        dispatch( "JOB_READY", userid );
    }, 1000 * throttle );
}

async function claimJob( userid, job ) {
    Logger.logServer( "claimJob: " + userid + " : " + job );

    const rewardQuery = "SELECT reward FROM users_jobs WHERE userid = " + userid + " AND guid = '" + job + "' AND claimed = 0 LIMIT 1";
    const claimQuery = "UPDATE users_jobs SET claimed = UNIX_TIMESTAMP() WHERE userid = " + userid + " AND guid = '" + job + "' LIMIT 1";

    // Find out what our reward is and save it
    result = await database.getOne( rewardQuery );
    if( !result || !result.reward ) return dispatchError( userid, "No Reward Found: "+ rewardQuery );
    const { reward } = result;

    const storeQuery = "INSERT INTO users_vault SET userid = " + userid + ", itemid = " + reward;

    // Begin our transaction.  We'll save the item, and mark it claimed
    let transaction = await database.beginTransaction();
    result = await transaction.query( storeQuery );
    if( !result || result[ 0 ].affectedRows !== 1 ) {
        await database.rollback( transaction );
        return dispatchError( userid, "Error Storing Item: " + storeQuery );
    }

    result = await transaction.query( claimQuery );
    if( !result || result[ 0 ].affectedRows !== 1 ) {
        await database.rollback( transaction );
        return dispatchError( userid, "Error Claiming Item: " + claimQuery );
    }

    // Commit the transaction
    await database.commit( transaction );
    dispatch( "JOB_CLAIMED", userid, reward );
}

async function checkJobs( userid ) {
    Logger.logServer( "checkJobs" );
    
    await database.execute( "UPDATE users_jobs SET claimed = -1 WHERE claimed = 0 AND expires < UNIX_TIMESTAMP()" );

    let data = await database.getOne( "SELECT COUNT(id) AS total FROM users_jobs WHERE userid = " + userid + " AND claimed = 0 AND expires > UNIX_TIMESTAMP()" );
    let count = data.total;		
    
    Logger.logServer( "We have: " + count + " jobs, we want: " + jobAmount );

    while( count++ < jobAmount )			
        await createJob( userid );
}

async function createJob( userid ) {
    Logger.logServer( "createJob" );

    let jobs = await database.get( "SELECT id FROM jobs" );
    let job = jobs[ Math.floor( Math.random() * jobs.length ) ];
    let reward = await ItemManager.getRandomItem( 2 );		
    let gid = guid.v4();
    let result = await database.execute( "INSERT INTO users_jobs SET guid = '" + gid + "', userid = " + userid + ", reward = " + reward.id + ", job = " + job.id + ", claimed = 0, expires = UNIX_TIMESTAMP() + ( 86400 * 2 )" );
}

async function clearJobs( userid ) {
    Logger.logServer( "clearJobs" );

    await database.execute( "DELETE FROM users_jobs WHERE userid = " + userid );
    dispatch( "JOBS_CLEARED", userid );
}

async function getJobs( userid ) {
    Logger.logServer( "getJobs" );
        
    await checkJobs( userid );
    
    let vault = await database.get( "SELECT vault_size, COUNT(users_vault.id) AS current_items FROM users INNER JOIN users_vault ON userid = users.id WHERE users.id = " + userid );
    console.log( vault );

    let jobs = await database.get( "SELECT guid, reward, header, body FROM users_jobs INNER JOIN jobs ON jobs.id = job WHERE userid = " + userid + " AND claimed = 0" );
    for( var job in jobs ) {			
        jobs[ job ].type = ItemManager.getItemByID( jobs[ job ].reward ).type;
        delete jobs[ job ].reward;			
    }
    
    let packet = {};
    packet.jobs = jobs;
    packet.vault = { current: vault[ 0 ].current_items, max: vault[ 0 ].vault_size };

    dispatch( "JOBS_RETRIEVED", userid, packet );
}

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

Logger.logServer( "Job Service Started" );