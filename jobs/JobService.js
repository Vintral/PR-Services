const dir = process.cwd();

const redis = require( 'redis' );
const Logger = require( './logger' );
const database = require( './database' );
const ItemManager = require( './item-manager' );
const guid = require('node-uuid');

require('dotenv').config();

// Make sure the ItemManager has a database
ItemManager.database = database;

//==========================================//
//	Variables								//
//==========================================//
const throttle = 30; // Set our throttle to check in the last 5 minutes
const jobAmount = 5;	
            
let users = {};

//==========================================//
//	Redis Listener							//
//==========================================//
const redisInfo = {
    server: process.env.REDIS_HOST,
    port: process.env.REDIS_PORT
}

const redisListener = redis.createClient( redisInfo.port, redisInfo.server );
redisListener.on( "error", onError );
redisListener.on( "ready", onReady );
redisListener.on( "connect", onConnect );
redisListener.on( "message", onMessage );

//==========================================//
//	Redis Pusher							//
//==========================================//
const redisClient = redis.createClient( redisInfo.port, redisInfo.server );
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
    const { server, userid } = data;

    let result = "";
    switch( channel ) {
        case "USER_ONLINE":
            Logger.logServer( "USER IS ONLINE" );

            // See if we've had any jobs claimed in within our throttle timer
            let lastQuery = "SELECT id FROM users_job_history WHERE userid = " + userid + " AND time > UNIX_TIMESTAMP() - " + throttle + " LIMIT 1";
            result = await database.getOne( lastQuery );
            if( result ){
                // Start up a timer
                setTimer( server, userid, false );
                break;    
            } else setTimer( server, userid, true );            

            // We have no recent ads claimed, let's let them know we're ready!
            //dispatch( server, { command:'JOB_READY', user:userid } );
            //setTimer( server, userid ); // To DO - REMOVE THIS
            break;
        case "USER_OFFLINE":
            Logger.logServer( "USER IS OFFLINE: " + userid );
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

            setTimer( server, userid, false );
            break;
        case "GET_JOBS":
            Logger.logServer( "GET_JOBS" );

            console.log( data );
            getJobs( userid, data.server, data.request );
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

function setTimer( server, userid, short ) {
    clearTimer( userid );
    users[ userid ] = {
        server,
        timer: setTimeout( () => {
            dispatch( server, { command:'JOB_READY', user:userid } );
        }, short ? 5000 : 1000 * throttle )
    };
}

async function claimJob( userid, job ) {
    Logger.logServer( "claimJob: " + userid + " : " + job );
    
    const claimQuery = "UPDATE users_jobs SET claimed = UNIX_TIMESTAMP() WHERE userid = " + userid + " AND guid = '" + job + "' LIMIT 1";
    result = await database.execute( claimQuery );

    //const recordQuery = "INSERT INTO users_job_history SET userid = " + userid + ", job = '" + job + "', time = UNIX_TIMESTAMP();
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

    let jobsDone = await database.getOne( "SELECT COUNT(id) AS total FROM users_jobs WHERE userid = " + userid + " AND claimed > UNIX_TIMESTAMP() - 86400" );
    let level = 1;
    if( jobsDone > 5 ) level = 2;

    let jobs = await database.get( "SELECT id FROM jobs" );
    let job = jobs[ Math.floor( Math.random() * jobs.length ) ];
    let reward = await ItemManager.getRandomItem( level );
    let gid = guid.v4();
    let result = await database.execute( "INSERT INTO users_jobs SET guid = '" + gid + "', userid = " + userid + ", reward = " + reward.id + ", job = " + job.id + ", claimed = 0, expires = UNIX_TIMESTAMP() + ( 86400 * 2 )" );
}

async function clearJobs( userid ) {
    Logger.logServer( "clearJobs" );

    await database.execute( "DELETE FROM users_jobs WHERE userid = " + userid );
    dispatch( "JOBS_CLEARED", userid );
}

async function getJobs( userid, server, request ) {
    Logger.logServer( "getJobs" );
        
    await checkJobs( userid );
    
    let vault = await database.get( "SELECT vault_size, COUNT(users_vault.id) AS current_items FROM users INNER JOIN users_vault ON userid = users.id WHERE users.id = " + userid );
    console.log( vault );

    let jobs = await database.get( "SELECT guid, reward, job FROM users_jobs INNER JOIN jobs ON jobs.id = job WHERE userid = " + userid + " AND claimed = 0" );
    for( var job in jobs ) {			
        jobs[ job ].type = ItemManager.getItemByID( jobs[ job ].reward ).type;
        delete jobs[ job ].reward;			
    }
    
    let packet = {};
    packet.jobs = jobs;
    packet.vault = { current: vault[ 0 ].current_items, max: vault[ 0 ].vault_size };    

    dispatch( server, { command:'JOBS', request, packet } );
}

function dispatchError( userid, msg ) {
    Logger.logError( "Job Error: " + msg );

    let packet = {};
    packet.userid = userid;
    packet.message = msg;
    redisClient.publish( "JOB_ERROR", JSON.stringify( packet ) );
}

function dispatch( type, data ) {
    Logger.logServer( "Dispatch: " + type + " - " + ( data ? " : " + JSON.stringify( data ) : "" ) );

    /*let packet = data;
    packet.data = data;*/

    redisClient.publish( type, JSON.stringify( data ) );
}

Logger.logServer( "Job Service Started" );