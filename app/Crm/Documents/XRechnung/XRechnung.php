<?php
declare(strict_types=1);
/**
 *	Crm\Documents\XRechnung		The electronic invoice of an invoice or a credit note (Storno) of a business client: an XRechnung in the syntax Cross
 *															Industry Invoice (UN/CEFACT CII D16B), written beside the PDF at the moment the document is finalised and never later.
 *															What the file needs and the document does not have is a checklist (lacks()): the file is written only where the checklist is
 *															empty, and nothing in it is made up - what is missing is said, in words, and the document is finalised without a file.
 *
 *															The guideline and the business process the file names are the constants GUIDELINE and PROCESS: a later version of the
 *															XRechnung is the change of those two lines and of what the new version asks for.
 *
 *															Mapping (the numbers are the business terms of EN 16931): the number (BT-1), the date (BT-2), the type 380 for an invoice and
 *															381 for a credit note (BT-3), whose amounts are positive and which names the invoice it takes back (BT-25, BT-26), the currency code
 *															(BT-5), the due day where there is one (BT-9) and always the sentence of the payment terms (BT-20), the buyer reference (BT-10), the
 *															seller (BG-4, BG-5, BG-6, BT-34; the VAT id BT-31 is required, BR-CO-26, the tax number BT-32 is written where there is one) and the buyer (BG-7, BG-8, BT-49), the period (BG-14, else BT-72 is the date of the document), the
 *															payment means (BG-16) with the number of the document as the remittance, the lines (BG-25: a negative price becomes a negated quantity with
 *															a positive price), the VAT breakdown by category and rate (BG-23: E with the exemption text for the VAT mode small_business, S for a rate
 *															above 0 in regular), the totals (BG-22) from Money::totals() and the note (BT-22) from the introduction of the document. A line with the
 *															rate 0 under regular taxation and a reverse charge are not mapped: the checklist says so.
 *
 *															The file is written once below /data/crm/pdf, next to the PDF, under the same name with the ending .xml; the document keeps its path and
 *															its SHA-256, and the route /.crm/xml/<id> answers it (callbackDownload()) as the route of the PDF answers the PDF.
 *															Amounts have two decimals, quantities up to three, dates are in the format 102 (YYYYMMDD); the output is UTF-8, indented, the same
 *															for the same document.
 */
namespace Crm\Documents;

use Crm\Settings;

class XRechnung {

	// The guideline (BT-24) and the business process (BT-23) the file names: XRechnung 3.0 is the guideline of the version 3.0.2
	public const string GUIDELINE = 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0';
	public const string PROCESS = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

	// The categories that get a file, and the type code (BT-3) of each
	public const array TYPE_CODES = [ 'invoice' => '380', 'credit' => '381' ];

	// The symbol of the currency setting and its code of ISO 4217; a symbol that is not here is a line of the checklist
	public const array CURRENCIES = [ '€' => 'EUR', 'EUR' => 'EUR', 'CHF' => 'CHF', '$' => 'USD', 'USD' => 'USD', '£' => 'GBP', 'GBP' => 'GBP' ];

	// The keys of the checklist, in the order it names them
	public const array KEYS = [
		'seller_name', 'seller_street', 'seller_zip', 'seller_city', 'seller_country', 'seller_contact', 'seller_phone', 'seller_email', 'seller_vat_id', 'iban', 'currency', 'exempt_text',
		'buyer_name', 'buyer_street', 'buyer_zip', 'buyer_city', 'buyer_country', 'buyer_reference', 'buyer_email', 'lines', 'line_unit', 'line_rate', 'reverse_charge',
	];

	// The path a stored file has: the path of a PDF with the ending .xml
	private const string PATH = '~^/data/crm/pdf/[0-9]{4}/[A-Za-z0-9._-]{1,60}\.xml$~D';

	private const string ROUTE = '/.crm/xml/';

	private const string NS_RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
	private const string NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
	private const string NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';
	private const string NS_QDT = 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100';

	private static array $_texts = [];

	/**
	 *	A word of the checklist
	 *
	 *	@param		string		$key					One of KEYS
	 *
	 *	@return 	string										The sentence that says what is missing
	 */
	public static function text( string $key ): string {

		if( self::$_texts === [] )
			self::$_texts = (array) require __DIR__. '/text/de_DE.php';

		if( isset( self::$_texts['lack.'. $key] ) === false )
			throw new \InvalidArgumentException( 'There is no word for "'. $key. '"' );

		return self::$_texts['lack.'. $key];
	}

	/**
	 *	The checklist as the screens draw it
	 *
	 *	@param		array 		$keys					Keys of the checklist; a key that is none of KEYS (stored by a later version) is left out
	 *
	 *	@return 	array											Each with its key and its text
	 */
	public static function sentences( array $keys ): array {

		return array_values( array_map( static fn( string $key ): array => [ 'key' => $key, 'text' => self::text( $key ) ], array_filter( $keys, static fn( mixed $key ): bool => is_string( $key ) === true && in_array( $key, self::KEYS, true ) === true ) ) );
	}

	/**
	 *	Whether a document gets an electronic invoice at all: an invoice or a credit note of a business client. Everything else - an offer, a document without
	 *	lines, a document of a private client, one finalised before the client had the flag - gets none and has no checklist
	 *
	 *	@param		array 		$document			category and client (as Documents::get() has it: the live client of a draft, the frozen one otherwise)
	 *
	 *	@return 	bool
	 */
	public static function applies( array $document ): bool {

		return isset( self::TYPE_CODES[(string) ( $document['category'] ?? '' )] ) === true && self::client( $document )['business'] === true;
	}

	/**
	 *	What keeps the file from being written
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			As Documents::get() has it, with client (the frozen one while the document is finalised)
	 *
	 *	@return 	array											The keys of KEYS that are missing, in the order of KEYS; none for a document that gets no file and for one that is complete
	 */
	public static function lacks( array &$appData, array $document ): array {

		if( self::applies( $document ) === false )
			return [];

		$set = Settings::all( $appData );
		$client = self::client( $document );
		$lines = (array) ( $document['lines'] ?? [] );
		$regular = ( $document['vat_mode'] ?? '' ) === 'regular';
		$reverse = ( $document['reverse_charge'] ?? false ) === true;
		$lacks = [];

		$missing = [
			'seller_name'    => $set['seller_name'] === '',
			'seller_street'  => $set['seller_street'] === '',
			'seller_zip'     => $set['seller_zip'] === '',
			'seller_city'    => $set['seller_city'] === '',
			'seller_country' => $set['seller_country'] === '',
			'seller_contact' => $set['seller_contact'] === '',
			'seller_phone'   => $set['seller_phone'] === '',
			'seller_email'   => $set['seller_email'] === '',
			'seller_vat_id'  => $set['vat_id'] === '',
			'iban'           => $set['iban'] === '',
			'currency'       => isset( self::CURRENCIES[$set['currency']] ) === false,
			'exempt_text'    => $regular === false && $set['vat_exempt_text'] === '',
			'buyer_name'     => $client['company'] === '' && $client['name'] === '',
			'buyer_street'   => $client['street'] === '',
			'buyer_zip'      => $client['zip'] === '',
			'buyer_city'     => $client['city'] === '',
			'buyer_country'  => $client['country'] === '',
			'buyer_reference' => trim( (string) ( $document['buyer_reference'] ?? '' ) ) === '' && $client['buyer_reference'] === '',
			'buyer_email'    => $client['email'] === '',
			'lines'          => $lines === [],
			'line_unit'      => array_filter( $lines, static fn( array $line ): bool => (string) ( $line['unit_code'] ?? '' ) === '' ) !== [],
			'line_rate'      => $regular === true && $reverse === false && array_filter( $lines, static fn( array $line ): bool => (int) $line['vat_rate'] === 0 ) !== [],
			'reverse_charge' => $reverse,
		];

		foreach( self::KEYS as $key )
			if( $missing[$key] === true )
				$lacks[] = $key;

		return $lacks;
	}

	/**
	 *	The path of the file of a PDF
	 *
	 *	@param		string		$pdfPath			As the document stores it
	 *
	 *	@return 	string
	 */
	public static function pathOf( string $pdfPath ): string {

		return substr( $pdfPath, 0, -4 ). '.xml';
	}

	/**
	 *	Write the file of a document beside its PDF, once
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			As Documents::get() has it, with its number, and the frozen client under client; the checklist is empty
	 *	@param		string		$pdfPath			The virtual path of the PDF, which names the file
	 *
	 *	@return 	array|string							path (virtual) and sha256 of the file; or exists:<path> (it is there already) or write
	 */
	public static function make( array &$appData, array $document, string $pdfPath ): array|string {

		$path = self::pathOf( $pdfPath );

		if( preg_match( self::PATH, $path ) !== 1 )
			return 'write';

		$file = \Nino\Filesystem::path( $appData, $path );

		if( file_exists( $file ) === true || is_link( $file ) === true )
			return 'exists:'. $path;

		$bytes = self::build( $appData, $document );

		\Nino\Filesystem::forceDir( $appData, dirname( $path ) );

		$temporary = $file. '.'. bin2hex( random_bytes( 6 ) ). '.tmp';

		if( @file_put_contents( $temporary, $bytes ) !== strlen( $bytes ) || @rename( $temporary, $file ) === false ) {
			@unlink( $temporary );
			trigger_error( 'Crm: the electronic invoice of document '. (string) $document['number']. ' could not be written', E_USER_WARNING );

			return 'write';
		}

		return [ 'path' => $path, 'sha256' => hash( 'sha256', $bytes ) ];
	}

	/**
	 *	The file of a stored electronic invoice
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$path					As the document stores it
	 *
	 *	@return 	string|null								The absolute path; null for a path the module did not write and for a file that is not there
	 */
	public static function file( array &$appData, string $path ): ?string {

		if( preg_match( self::PATH, $path ) !== 1 )
			return null;

		$file = \Nino\Filesystem::path( $appData, $path );

		return $file !== '' && is_file( $file ) === true && is_link( $file ) === false ? $file : null;
	}

	/**
	 *	The XML of a document: the same bytes for the same document. It reads the checklist as complete and invents nothing (a document that lacks something
	 *	is not handed here by make())
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			As make() has it
	 *
	 *	@return 	string
	 */
	public static function build( array &$appData, array $document ): string {

		$set = Settings::all( $appData );
		$client = self::client( $document );
		$credit = $document['category'] === 'credit';
		$sign = $credit === true ? -1 : 1;
		$small = $document['vat_mode'] !== 'regular';
		$lines = array_values( (array) $document['lines'] );
		$totals = Money::totals( $lines, (string) $document['vat_mode'], (bool) $document['reverse_charge'] );
		$currency = self::CURRENCIES[$set['currency']];
		$number = (string) $document['number'];
		$reference = trim( (string) ( $document['buyer_reference'] ?? '' ) ) !== '' ? trim( (string) $document['buyer_reference'] ) : $client['buyer_reference'];
		$period = (string) ( $document['period_from'] ?? '' ) !== '' && (string) ( $document['period_to'] ?? '' ) !== '';
		$note = trim( (string) ( ( (array) ( $document['inputs'] ?? [] ) )['einleitung'] ?? '' ) );

		$w = new \XMLWriter();
		$w->openMemory();
		$w->setIndent( true );
		$w->setIndentString( '  ' );
		$w->startDocument( '1.0', 'UTF-8' );

		$w->startElement( 'rsm:CrossIndustryInvoice' );
		$w->writeAttribute( 'xmlns:rsm', self::NS_RSM );
		$w->writeAttribute( 'xmlns:ram', self::NS_RAM );
		$w->writeAttribute( 'xmlns:udt', self::NS_UDT );
		$w->writeAttribute( 'xmlns:qdt', self::NS_QDT );

		$w->startElement( 'rsm:ExchangedDocumentContext' );
		self::nest( $w, 'ram:BusinessProcessSpecifiedDocumentContextParameter', null, 'ram:ID', self::PROCESS );
		self::nest( $w, 'ram:GuidelineSpecifiedDocumentContextParameter', null, 'ram:ID', self::GUIDELINE );
		$w->endElement();

		$w->startElement( 'rsm:ExchangedDocument' );
		self::put( $w, 'ram:ID', $number );
		self::put( $w, 'ram:TypeCode', self::TYPE_CODES[(string) $document['category']] );
		self::day( $w, 'ram:IssueDateTime', (string) $document['date'] );

		if( $note !== '' ) {
			$w->startElement( 'ram:IncludedNote' );
			self::put( $w, 'ram:Content', $note );
			$w->endElement();
		}

		$w->endElement();

		$w->startElement( 'rsm:SupplyChainTradeTransaction' );

		foreach( $lines as $index => $line ) {

			// A negative price is no price: the quantity is negated and the price is positive. A credit note has positive amounts: its lines are negated as a whole
			$qty = (int) $line['qty'] * $sign;
			$price = (int) $line['price_net'];

			if( $price < 0 ) {
				$qty = -$qty;
				$price = -$price;
			}

			$w->startElement( 'ram:IncludedSupplyChainTradeLineItem' );
			$w->startElement( 'ram:AssociatedDocumentLineDocument' );
			self::put( $w, 'ram:LineID', (string) ( $index + 1 ) );
			$w->endElement();
			$w->startElement( 'ram:SpecifiedTradeProduct' );
			self::put( $w, 'ram:Name', (string) $line['title'] );

			if( (string) $line['description'] !== '' )
				self::put( $w, 'ram:Description', (string) $line['description'] );

			$w->endElement();
			self::nest( $w, 'ram:SpecifiedLineTradeAgreement', null, 'ram:NetPriceProductTradePrice', null, 'ram:ChargeAmount', self::amount( $price ) );
			$w->startElement( 'ram:SpecifiedLineTradeDelivery' );
			$w->startElement( 'ram:BilledQuantity' );
			$w->writeAttribute( 'unitCode', (string) $line['unit_code'] );
			$w->text( self::quantity( $qty ) );
			$w->endElement();
			$w->endElement();
			$w->startElement( 'ram:SpecifiedLineTradeSettlement' );
			$w->startElement( 'ram:ApplicableTradeTax' );
			self::put( $w, 'ram:TypeCode', 'VAT' );
			self::put( $w, 'ram:CategoryCode', $small === true ? 'E' : 'S' );
			self::put( $w, 'ram:RateApplicablePercent', self::rate( $small === true ? 0 : (int) $line['vat_rate'] ) );
			$w->endElement();
			self::nest( $w, 'ram:SpecifiedTradeSettlementLineMonetarySummation', null, 'ram:LineTotalAmount', self::amount( $totals['amounts'][$index] * $sign ) );
			$w->endElement();
			$w->endElement();
		}

		$w->startElement( 'ram:ApplicableHeaderTradeAgreement' );
		self::put( $w, 'ram:BuyerReference', $reference );

		$w->startElement( 'ram:SellerTradeParty' );
		self::put( $w, 'ram:Name', $set['seller_name'] );
		$w->startElement( 'ram:DefinedTradeContact' );
		self::put( $w, 'ram:PersonName', $set['seller_contact'] );
		self::nest( $w, 'ram:TelephoneUniversalCommunication', null, 'ram:CompleteNumber', $set['seller_phone'] );
		self::nest( $w, 'ram:EmailURIUniversalCommunication', null, 'ram:URIID', $set['seller_email'] );
		$w->endElement();
		self::address( $w, $set['seller_zip'], $set['seller_street'], $set['seller_city'], $set['seller_country'] );
		self::electronicAddress( $w, $set['seller_email'] );

		if( $set['vat_id'] !== '' )
			self::registration( $w, 'VA', $set['vat_id'] );

		if( $set['tax_id'] !== '' )
			self::registration( $w, 'FC', $set['tax_id'] );

		$w->endElement();

		$w->startElement( 'ram:BuyerTradeParty' );
		self::put( $w, 'ram:Name', $client['company'] !== '' ? $client['company'] : $client['name'] );
		self::address( $w, $client['zip'], $client['street'], $client['city'], $client['country'] );
		self::electronicAddress( $w, $client['email'] );

		if( $client['vat_id'] !== '' )
			self::registration( $w, 'VA', $client['vat_id'] );

		$w->endElement();
		$w->endElement();

		$w->startElement( 'ram:ApplicableHeaderTradeDelivery' );

		if( $period === false ) {
			$w->startElement( 'ram:ActualDeliverySupplyChainEvent' );
			self::day( $w, 'ram:OccurrenceDateTime', (string) $document['date'] );
			$w->endElement();
		}

		$w->endElement();

		$w->startElement( 'ram:ApplicableHeaderTradeSettlement' );
		self::put( $w, 'ram:PaymentReference', $number );
		self::put( $w, 'ram:InvoiceCurrencyCode', $currency );

		$w->startElement( 'ram:SpecifiedTradeSettlementPaymentMeans' );
		self::put( $w, 'ram:TypeCode', '58' );
		$w->startElement( 'ram:PayeePartyCreditorFinancialAccount' );
		self::put( $w, 'ram:IBANID', str_replace( ' ', '', $set['iban'] ) );
		self::put( $w, 'ram:AccountName', $set['seller_name'] );
		$w->endElement();

		if( $set['bic'] !== '' )
			self::nest( $w, 'ram:PayeeSpecifiedCreditorFinancialInstitution', null, 'ram:BICID', $set['bic'] );

		$w->endElement();

		foreach( $totals['rates'] as $rate => $sums ) {
			$w->startElement( 'ram:ApplicableTradeTax' );
			self::put( $w, 'ram:CalculatedAmount', self::amount( $sums['vat'] * $sign ) );
			self::put( $w, 'ram:TypeCode', 'VAT' );

			if( $small === true )
				self::put( $w, 'ram:ExemptionReason', $set['vat_exempt_text'] );

			self::put( $w, 'ram:BasisAmount', self::amount( $sums['net'] * $sign ) );
			self::put( $w, 'ram:CategoryCode', $small === true ? 'E' : 'S' );
			self::put( $w, 'ram:RateApplicablePercent', self::rate( $small === true ? 0 : $rate ) );
			$w->endElement();
		}

		if( $period === true ) {
			$w->startElement( 'ram:BillingSpecifiedPeriod' );
			self::day( $w, 'ram:StartDateTime', (string) $document['period_from'] );
			self::day( $w, 'ram:EndDateTime', (string) $document['period_to'] );
			$w->endElement();
		}

		$w->startElement( 'ram:SpecifiedTradePaymentTerms' );
		self::put( $w, 'ram:Description', Render::dueSentence( (string) ( $document['due_date'] ?? '' ), $number ) );

		if( (string) ( $document['due_date'] ?? '' ) !== '' )
			self::day( $w, 'ram:DueDateDateTime', (string) $document['due_date'] );

		$w->endElement();

		$w->startElement( 'ram:SpecifiedTradeSettlementHeaderMonetarySummation' );
		self::put( $w, 'ram:LineTotalAmount', self::amount( $totals['net'] * $sign ) );
		self::put( $w, 'ram:TaxBasisTotalAmount', self::amount( $totals['net'] * $sign ) );
		$w->startElement( 'ram:TaxTotalAmount' );
		$w->writeAttribute( 'currencyID', $currency );
		$w->text( self::amount( $totals['vat'] * $sign ) );
		$w->endElement();
		self::put( $w, 'ram:GrandTotalAmount', self::amount( $totals['gross'] * $sign ) );
		self::put( $w, 'ram:TotalPrepaidAmount', self::amount( 0 ) );
		self::put( $w, 'ram:DuePayableAmount', self::amount( $totals['gross'] * $sign ) );
		$w->endElement();

		$invoice = $credit === true ? $document['parent'] ?? null : null;

		if( is_array( $invoice ) === true && (string) ( $invoice['number'] ?? '' ) !== '' ) {
			$w->startElement( 'ram:InvoiceReferencedDocument' );
			self::put( $w, 'ram:IssuerAssignedID', (string) $invoice['number'] );
			self::day( $w, 'ram:FormattedIssueDateTime', (string) ( $invoice['date'] ?? '' ), 'qdt' );
			$w->endElement();
		}

		$w->endElement();
		$w->endElement();
		$w->endElement();
		$w->endDocument();

		return $w->outputMemory();
	}

	/**
	 *	GET /.crm/xml/<id>: the electronic invoice of a finalised document, to a workbench session that holds the permission of the panel Belege and - once the
	 *	document is released - to the signed-in member whose client it belongs to; the checks are those of the route of the PDF (Render::callbackDownload()), and
	 *	so are the answers: the 404 for a draft, a document that has no file, a document nobody has, a visitor, a document of another client, one that is not
	 *	released and a file that is gone, and the 409 for a file whose hash is not the one stored. The answer is always an attachment named <number>.xml
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackDownload( array &$appData, array &$request ): void {

		$uri = (string) ( $request['/nino/http/request']['uri'] ?? '' );
		$id = str_starts_with( $uri, self::ROUTE ) === true ? substr( $uri, strlen( self::ROUTE ) ) : '';
		$row = null;

		if( preg_match( '/^[1-9][0-9]{0,9}$/D', $id ) === 1 ) {

			try {
				if( \Nino\Auth::checkPermission( $appData, Admin::MANAGE_PERM ) === true ) {
					$row = \Crm\Db::row( $appData, 'SELECT xml_path, xml_sha256 FROM documents WHERE id = :id AND xml_path != \'\'', [ 'id' => (int) $id ] );
				}
				else {
					$client = \Crm\Portal::client( $appData );

					if( $client !== null )
						$row = \Crm\Db::row( $appData, 'SELECT xml_path, xml_sha256 FROM documents WHERE id = :id AND client_id = :client AND released = 1 AND xml_path != \'\'', [ 'id' => (int) $id, 'client' => $client['id'] ] );
				}
			}
			catch( \PDOException $e ) {
				$row = null;
			}
		}

		$file = $row === null ? null : self::file( $appData, (string) $row['xml_path'] );
		$name = $row === null ? '' : basename( (string) $row['xml_path'], '.xml' );

		if( $row === null || $file === null ) {
			Render::answer( $request, 404, 'Not Found' );

			if( $row !== null )
				trigger_error( 'Crm: the electronic invoice of document '. $name. ' is gone', E_USER_WARNING );

			return;
		}

		if( hash_equals( (string) $row['xml_sha256'], (string) hash_file( 'sha256', $file ) ) === false ) {
			trigger_error( 'Crm: the electronic invoice of document '. $name. ' does not match its hash', E_USER_WARNING );
			Render::answer( $request, 409, 'Conflict' );

			return;
		}

		$request['/nino/http/response']['file'] = $file;
		$request['/nino/http/response']['header']['Content-Type'] = 'application/xml';
		$request['/nino/http/response']['header']['Content-Disposition'] = 'attachment; filename="'. $name. '.xml"';
		$request['/nino/http/response']['header']['Cache-Control'] = 'private, no-store';
	}

	/**
	 *	The client of a document as the checklist and the file read it: texts, and business as a flag. The live client of a draft has the flag as a boolean, a
	 *	frozen one as '1' or '0'; a client frozen before the flag existed has none, and is no business
	 *
	 *	@param		array 		$document
	 *
	 *	@return 	array											company, name, street, zip, city, country, email, vat_id, buyer_reference, business
	 */
	private static function client( array $document ): array {

		$client = is_array( $document['client'] ?? null ) === true ? $document['client'] : [];
		$clean = [];

		foreach( [ 'company', 'name', 'street', 'zip', 'city', 'country', 'email', 'vat_id', 'buyer_reference' ] as $field )
			$clean[$field] = trim( (string) ( $client[$field] ?? '' ) );

		$clean['business'] = in_array( $client['business'] ?? false, [ true, 1, '1' ], true );

		return $clean;
	}

	/**
	 *	An amount in cents with two decimals
	 *
	 *	@param		int				$cents
	 *
	 *	@return 	string
	 */
	private static function amount( int $cents ): string {

		return ( $cents < 0 ? '-' : '' ). intdiv( abs( $cents ), 100 ). '.'. sprintf( '%02d', abs( $cents ) % 100 );
	}

	/**
	 *	A quantity in thousandths with up to three decimals
	 *
	 *	@param		int				$thousandths
	 *
	 *	@return 	string
	 */
	private static function quantity( int $thousandths ): string {

		$fraction = rtrim( sprintf( '%03d', abs( $thousandths ) % 1000 ), '0' );

		return ( $thousandths < 0 ? '-' : '' ). intdiv( abs( $thousandths ), 1000 ). ( $fraction === '' ? '' : '.'. $fraction );
	}

	/**
	 *	A rate in hundredths of a percent with two decimals
	 *
	 *	@param		int				$rate
	 *
	 *	@return 	string
	 */
	private static function rate( int $rate ): string {

		return intdiv( $rate, 100 ). '.'. sprintf( '%02d', $rate % 100 );
	}

	/**
	 *	A text element; a character the XML cannot carry is left out
	 *
	 *	@param		\XMLWriter	$w
	 *	@param		string		$name
	 *	@param		string		$value
	 *
	 *	@return 	void
	 */
	private static function put( \XMLWriter $w, string $name, string $value ): void {

		$w->startElement( $name );
		$w->text( (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', mb_scrub( $value, 'UTF-8' ) ) );
		$w->endElement();
	}

	/**
	 *	One or two elements nested in an element of their own: <outer><inner>value</inner></outer>, with a third level where a second pair is given
	 *
	 *	@param		\XMLWriter	$w
	 *	@param		string		$outer
	 *	@param		string|null	$outerValue		Text of the outer element; null for none
	 *	@param		string		$inner
	 *	@param		string|null	$innerValue		Text of the inner element; null where it has a child of its own
	 *	@param		string		$leaf					The child of the inner element, '' for none
	 *	@param		string		$leafValue
	 *
	 *	@return 	void
	 */
	private static function nest( \XMLWriter $w, string $outer, ?string $outerValue, string $inner, ?string $innerValue, string $leaf = '', string $leafValue = '' ): void {

		$w->startElement( $outer );

		if( $outerValue !== null )
			$w->text( $outerValue );

		if( $innerValue !== null )
			self::put( $w, $inner, $innerValue );
		else {
			$w->startElement( $inner );
			self::put( $w, $leaf, $leafValue );
			$w->endElement();
		}

		$w->endElement();
	}

	/**
	 *	A day in the format 102
	 *
	 *	@param		\XMLWriter	$w
	 *	@param		string		$name
	 *	@param		string		$date					Y-m-d
	 *	@param		string		$prefix				The namespace of the string: udt, or qdt for a FormattedDateTimeType such as BT-26
	 *
	 *	@return 	void
	 */
	private static function day( \XMLWriter $w, string $name, string $date, string $prefix = 'udt' ): void {

		$w->startElement( $name );
		$w->startElement( $prefix. ':DateTimeString' );
		$w->writeAttribute( 'format', '102' );
		$w->text( str_replace( '-', '', $date ) );
		$w->endElement();
		$w->endElement();
	}

	/**
	 *	A postal address
	 *
	 *	@param		\XMLWriter	$w
	 *	@param		string		$zip
	 *	@param		string		$street
	 *	@param		string		$city
	 *	@param		string		$country
	 *
	 *	@return 	void
	 */
	private static function address( \XMLWriter $w, string $zip, string $street, string $city, string $country ): void {

		$w->startElement( 'ram:PostalTradeAddress' );
		self::put( $w, 'ram:PostcodeCode', $zip );
		self::put( $w, 'ram:LineOne', $street );
		self::put( $w, 'ram:CityName', $city );
		self::put( $w, 'ram:CountryID', $country );
		$w->endElement();
	}

	/**
	 *	The electronic address of a party: its e-mail address
	 *
	 *	@param		\XMLWriter	$w
	 *	@param		string		$email
	 *
	 *	@return 	void
	 */
	private static function electronicAddress( \XMLWriter $w, string $email ): void {

		$w->startElement( 'ram:URIUniversalCommunication' );
		$w->startElement( 'ram:URIID' );
		$w->writeAttribute( 'schemeID', 'EM' );
		$w->text( $email );
		$w->endElement();
		$w->endElement();
	}

	/**
	 *	A tax registration of a party
	 *
	 *	@param		\XMLWriter	$w
	 *	@param		string		$scheme				VA (the VAT id) or FC (the tax number)
	 *	@param		string		$id
	 *
	 *	@return 	void
	 */
	private static function registration( \XMLWriter $w, string $scheme, string $id ): void {

		$w->startElement( 'ram:SpecifiedTaxRegistration' );
		$w->startElement( 'ram:ID' );
		$w->writeAttribute( 'schemeID', $scheme );
		$w->text( $id );
		$w->endElement();
		$w->endElement();
	}
}
