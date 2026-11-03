/**
 *	Crm\Documents\Money		The arithmetic of a document in the browser: the same functions as Money.php, case for case. A
 *												price and an amount are cents, a quantity is thousandths (1500 = 1,5), a rate is hundredths of a
 *												percent (1900 = 19 %). Every rounding is half away from zero in integers: the products can pass
 *												2^53, so they are computed as BigInt and handed back as numbers once they are small again.
 *												Joins the workbench's script bundle with the panel Belege; the live totals of the
 *												editor are these, the totals of a finalised document are the server's, and tests/money-cases.json
 *												is run against both.
 */

( function(wn) {

	wn.Nino.crm = wn.Nino.crm || {};

	const MAX_PRICE = 1000000000;
	const MAX_QTY = 10000000;
	const MAX_LINE = 100000000000;
	const MAX_TOTAL = 1000000000000;
	const NBSP = '\u00A0';

	/**
	 *	A dividend by a divisor, rounded half away from zero
	 *
	 *	@param		{bigint}	dividend
	 *	@param		{bigint}	divisor
	 *
	 *	@return		{number}
	 */
	const divide = function( dividend, divisor ) {

		const magnitude = dividend < 0n ? -dividend : dividend;
		const quotient = ( magnitude + divisor / 2n ) / divisor;

		return Number( dividend < 0n ? -quotient : quotient );
	};

	/**
	 *	@param		{number}	number
	 *
	 *	@return		{string}									A whole number with a dot between the groups of three digits
	 */
	const group = function( number ) {
		return String( number ).replace( /\B(?=(\d{3})+(?!\d))/g, '.' );
	};

	/**
	 *	A number with up to `decimals` decimals as a whole number of its smallest part
	 *
	 *	@param		{string}	text
	 *	@param		{number}	decimals
	 *
	 *	@return		{bigint|null}
	 */
	const parseNumber = function( text, decimals ) {

		// 1.000 is a thousand where it is German and one where it is English: a number of one to three digits, a dot and three more is neither
		if( /^-?\d{1,3}\.\d{3}$/.test( text ) === true )
			return null;

		const german = new RegExp( '^(-?)(\\d{1,3}(?:\\.\\d{3})+),(\\d{1,'+ decimals+ '})$' );
		const plain = new RegExp( '^(-?)(\\d{1,15})(?:[.,](\\d{1,'+ decimals+ '}))?$' );
		let found = german.exec( text );

		if( found !== null )
			found[2] = found[2].replace( /\./g, '' );
		else {
			found = plain.exec( text );

			if( found === null )
				return null;
		}

		const value = BigInt( found[2]+ ( found[3] ?? '' ).padEnd( decimals, '0' ) );

		return found[1] === '-' ? -value : value;
	};

	const limited = function( value, max ) {
		return value === null || value > BigInt( max ) || value < -BigInt( max ) ? null : Number( value );
	};

	wn.Nino.crm.money = {

		MAX_PRICE : MAX_PRICE,
		MAX_QTY : MAX_QTY,
		MAX_LINE : MAX_LINE,
		MAX_TOTAL : MAX_TOTAL,

		/**
		 *	@param		{number}	qty						Thousandths
		 *	@param		{number}	price					Cents
		 *
		 *	@return		{number}								qty x price / 1000, rounded half away from zero
		 */
		lineAmount : function( qty, price ) {
			return divide( BigInt( qty ) * BigInt( price ), 1000n );
		},

		/**
		 *	@param		{number}	net						Cents
		 *	@param		{number}	rate					Hundredths of a percent
		 *
		 *	@return		{number}								net x rate / 10000, rounded half away from zero
		 */
		vat : function( net, rate ) {
			return divide( BigInt( net ) * BigInt( rate ), 10000n );
		},

		/**
		 *	The totals of the lines of a document: net per rate, the VAT of each rate's net, and the sums. In small_business and
		 *	in a reverse charge every rate counts as 0, whatever a line says
		 *
		 *	@param		{Array}			lines				Each with qty, price_net and vat_rate
		 *	@param		{string}		vatMode			small_business or regular
		 *	@param		{boolean}		[reverse]
		 *
		 *	@return		{Object}									net, vat, gross, rates ([ { rate, net, vat } ], the highest rate first), amounts
		 */
		totals : function( lines, vatMode, reverse ) {

			const zero = vatMode === 'small_business' || reverse === true;
			const sums = new Map();
			const amounts = [];

			lines.forEach( function( line ) {

				const amount = wn.Nino.crm.money.lineAmount( line.qty, line.price_net );
				const rate = zero === true ? 0 : line.vat_rate;

				amounts.push( amount );
				sums.set( rate, ( sums.get( rate ) ?? 0 ) + amount );
			} );

			const rates = [ ...sums.keys() ].sort( function( a, b ) { return b - a } ).map( function( rate ) {
				return { rate : rate, net : sums.get( rate ), vat : zero === true ? 0 : wn.Nino.crm.money.vat( sums.get( rate ), rate ) };
			} );

			const net = rates.reduce( function( sum, entry ) { return sum+ entry.net }, 0 );
			const vat = rates.reduce( function( sum, entry ) { return sum+ entry.vat }, 0 );

			return { net : net, vat : vat, gross : net+ vat, rates : rates, amounts : amounts };
		},

		/**
		 *	@param		{string}	text					1.234,56 and 1234,56 and 1234.56 and -12,5
		 *
		 *	@return		{number|null}						Cents; null for any other text and for one past MAX_PRICE
		 */
		parse : function( text ) {
			return limited( parseNumber( text, 2 ), MAX_PRICE );
		},

		/**
		 *	@param		{string}	text
		 *
		 *	@return		{number|null}						Thousandths; null for any other text and for one past MAX_QTY
		 */
		parseQty : function( text ) {
			return limited( parseNumber( text, 3 ), MAX_QTY );
		},

		/**
		 *	@param		{number}	cents
		 *	@param		{string}	[currency]		'' for the number alone
		 *
		 *	@return		{string}								1.234,56, the currency behind a non-breaking space
		 */
		format : function( cents, currency ) {

			const magnitude = Math.abs( cents );
			const text = ( cents < 0 ? '-' : '' )+ group( Math.floor( magnitude / 100 ) )+ ','+ String( magnitude % 100 ).padStart( 2, '0' );

			return currency === undefined || currency === '' ? text : text+ NBSP+ currency;
		},

		/**
		 *	@param		{number}	thousandths
		 *
		 *	@return		{string}								1,5 or 2: the trailing zeros cut, at most three decimals
		 */
		formatQty : function( thousandths ) {

			const magnitude = Math.abs( thousandths );
			const text = ( thousandths < 0 ? '-' : '' )+ group( Math.floor( magnitude / 1000 ) );
			const fraction = String( magnitude % 1000 ).padStart( 3, '0' ).replace( /0+$/, '' );

			return fraction === '' ? text : text+ ','+ fraction;
		},

		/**
		 *	@param		{number}	rate
		 *
		 *	@return		{string}								19 %, 7 % or 5,5 %
		 */
		formatRate : function( rate ) {

			const fraction = String( rate % 100 ).padStart( 2, '0' ).replace( /0+$/, '' );

			return Math.floor( rate / 100 )+ ( fraction === '' ? '' : ','+ fraction )+ ' %';
		},
	};

} )(window);
