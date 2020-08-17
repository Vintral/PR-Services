var crypto = require( 'crypto' );

class Security {	
	constructor() {		
		this.algorithm = 'aes-256-ctr';
        this.salt = 'hu1@p1Atypu$';
        this.iv = Buffer.from( Array.prototype.map.call( Buffer.alloc( 16 ), () => { return Math.floor( Math.random() * 256 ) } ) );
	}	
	
	encrypt( val ) {
		let cipher = crypto.createCipheriv( this.algorithm, this.salt, this.iv );
        let ret = cipher.update( val, 'utf8', 'hex' );        
		ret += cipher.final( 'hex' );
		return ret;
	}
		
	decrypt( val ) {
		let cipher = crypto.createCipheriv( this.algorithm, this.salt, this.iv );
        let ret = cipher.update( val, 'hex', 'utf8' );        
		ret += cipher.final( 'utf8' );
		return ret;
	}
}

module.exports = new Security();