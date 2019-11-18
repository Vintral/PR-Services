const redis = require( 'redis' );
const { promisify } = require( 'util' );
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
const addAsync = promisify( redisClient.zadd ).bind( redisClient );
const getRange = promisify( redisClient.zrevrange ).bind( redisClient );
const getRank = promisify( redisClient.zrevrank ).bind( redisClient );
const getTotal = promisify( redisClient.zcount ).bind( redisClient );
const removeAsync = promisify( redisClient.zrem ).bind( redisClient );

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
    redisListener.subscribe( "GET_TOP_RANKINGS" );
}

async function onConnect() {
	Logger.logServer( "Connected to Redis Server" );
}

async function onMessage( channel, data ) {
    Logger.logServer( "Message: " + channel + ":" + data );
    
    data = JSON.parse( data );
    
    let result = "";
    switch( channel ) {
      case "SET_POWER":
        Logger.logServer( "SET POWER" );
        console.log( data );
        
        await removeAsync( "round-" + data.roundid, "2" );
        await removeAsync( "round-" + data.roundid, "3" );
        await removeAsync( "round-" + data.roundid, "4" );

        let result = await addAsync( "round-" + data.roundid, data.power, data.username );        
        console.log( result );

        result = await getRange( "round-" + data.roundid, 0, 10 );
        console.log( result );

        result = await getRange( "round-" + data.roundid, 0, 10, "withscores" );
        console.log( result );
        break;
      case "GET_RANKINGS":
        console.log( "GET_RANKINGS" );

        let rank = await getRank( "round-" + data.roundid, data.username );
        let total = await getTotal( "round-" + data.roundid, "-inf", "+inf" );
        let min = Math.max( 0, rank - 10 );
        let max = Math.min( total, rank + 10 );
        let ranks = await getRange( "round-" + data.roundid, min, max, "withscores" );        

        let packet = {};
        packet.command = "GET_RANKINGS";
        packet.start = min + 1;
        packet.ranks = [];
        packet.request = data.request;
        for( let i = 0; i < ranks.length; i += 2 ) {
          packet.ranks.push( ranks[ i ] + "|||" + ranks[ i + 1 ] );
        }        

        redisClient.publish( data.server, JSON.stringify( packet ) );
        break;
      case "GET_TOP_RANKINGS":
        console.log( "GET_TOP_RANKINGS" );
        break;
    }
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