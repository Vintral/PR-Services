const redis = require( 'redis' );
const Logger = require( './logger' );
const mailer = require( 'nodemailer' );

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
	redisListener.subscribe( "SEND_EMAIL" );
}

async function onConnect() {
	Logger.logServer( "Connected to Redis Server" );
}

async function onMessage( channel, data ) {
	Logger.logServer( "Message: " + channel + ":" + data );
	
    data = JSON.parse( data );
    const { to, subject, body } = data;

    if( !to ) return Logger.logError( "Missing Email Address" );
    if( !subject ) return Logger.logError( "Missing Subject" );
    if( !body ) return Logger.logError( "Missing Body" );

    console.log( to );
    console.log( subject );
    console.log( body );

	let transporter = mailer.createTransport( {
		host: "smtp.zoho.com",
		port: 587,
		secure: false, // true for 465, false for other ports
		auth: {
		  user: "jeff@hulaplatypus.com",
		  pass: "Trallara1!" // generated ethereal password
		}
    } );
    
    // setup email data with unicode symbols
	let mailOptions = {
		from: '"Jeffrey Heater 👻" <jeffrey.heater@hulaplatypus.com>', // sender address
		to: to, // list of receivers
		subject: subject, // Subject line
		text: body, // plain text body
		html: "<b>" + body + "</b>" // html body
    };
    
    // send mail with defined transport object
    let info = await transporter.sendMail(mailOptions)
    Logger.logServer( "Message sent: " + info.messageId );
}

Logger.logServer( "Email Service Started" );
