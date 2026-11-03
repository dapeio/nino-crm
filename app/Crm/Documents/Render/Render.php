<?php
declare(strict_types=1);
/**
 *	Crm\Documents\Render		The PDF of a finalised document: what the app hands a template of the Pdf feature (tokens, slots
 *													and the inputs of the document), the file written once below /data/crm/pdf with its SHA-256, and
 *													the route that answers it. The slots are filled from the .tpl files in Documents/templates/
 *													(read by name from that directory, which is the module's own); every value that
 *													enters a slot is escaped. The words of the file are in text/de_DE.php - a document is the
 *													owner's letter to his client and is German.
 *
 *													Tokens a template of the tags offer and invoice may use:
 *													  document.title, .number, .date, .due, .valid, .period, .parent, .vatnote, .net, .vat, .gross
 *													  client.number, .company, .name, .street, .zip, .city, .country, .vat_id, .email
 *													Slots: address, lines, totals. Inputs: the ones the template declares.
 */
namespace Crm\Documents;

use Crm\Settings;

class Render {

	public const string DIR = '/data/crm/pdf';

	// The path a stored PDF has: below DIR, a year, the number as file name
	private const string PATH = '~^/data/crm/pdf/[0-9]{4}/[A-Za-z0-9._-]{1,60}\.pdf$~D';

	private const string ROUTE = '/.crm/pdf/';

	private static array $_texts = [];

	/**
	 *	The tag of the Pdf feature a category's templates carry: offer for an offer, invoice for an invoice and a credit note
	 *
	 *	@param		string		$category
	 *
	 *	@return 	string
	 */
	public static function tag( string $category ): string {

		return $category === 'offer' ? 'offer' : 'invoice';
	}

	/**
	 *	A word of the documents
	 *
	 *	@param		string		$key
	 *	@param		string		...$params		Values for the %s of the word
	 *
	 *	@return 	string
	 */
	public static function text( string $key, string ...$params ): string {

		if( self::$_texts === [] )
			self::$_texts = (array) require __DIR__. '/text/de_DE.php';

		if( isset( self::$_texts[$key] ) === false )
			throw new \InvalidArgumentException( 'There is no document word "'. $key. '"' );

		return $params === [] ? self::$_texts[$key] : vsprintf( self::$_texts[$key], $params );
	}

	/**
	 *	A template of the slots, the markup of one of the slots
	 *
	 *	@param		string		$name					A file of templates/ without .tpl: lower case letters and hyphens
	 *
	 *	@return 	string
	 */
	public static function template( string $name ): string {

		if( preg_match( '/^[a-z]+(-[a-z]+)*$/D', $name ) !== 1 )
			throw new \InvalidArgumentException( 'There is no document template "'. $name. '"' );

		$markup = file_get_contents( dirname( __DIR__ ). '/templates/'. $name. '.tpl' );

		if( $markup === false )
			throw new \RuntimeException( 'The document template "'. $name. '" cannot be read' );

		return trim( $markup );
	}

	/**
	 *	A value for a slot: escaped, and the non-breaking space as the entity the Pdf lists keep it as
	 *
	 *	@param		string		$value
	 *
	 *	@return 	string
	 */
	public static function escape( string $value ): string {

		return str_replace( Money::NBSP, '&nbsp;', \Nino\Modules\Components::escape( $value ) );
	}

	/**
	 *	What Pdf::render() is handed for a document
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			As Documents::get() has it: category, number, dates, vat_mode, reverse_charge, totals, parent_number, inputs
	 *	@param		array 		$lines				The lines of the document, in their order
	 *	@param		array 		$client				The client as it was frozen: company, name, street, zip, city, country, number, vat_id, email
	 *
	 *	@return 	array											tokens, slots, inputs
	 */
	public static function data( array &$appData, array $document, array $lines, array $client ): array {

		$currency = Settings::get( $appData, 'currency' );
		$category = (string) $document['category'];
		$regular = $document['vat_mode'] === 'regular';
		$totals = Money::totals( $lines, (string) $document['vat_mode'], (bool) $document['reverse_charge'] );

		$vatnote = '';

		if( $regular === false )
			$vatnote = self::text( 'vatnote.small_business' );
		elseif( (bool) $document['reverse_charge'] === true )
			$vatnote = self::text( 'vatnote.reverse' );

		$period = self::period( (string) ( $document['period_from'] ?? '' ), (string) ( $document['period_to'] ?? '' ) );

		if( $period === '' && $category !== 'offer' )
			$period = self::text( 'period.none' );

		$tokens = [
			'document' => [
				'title'   => self::text( 'title.'. $category ),
				'number'  => (string) $document['number'],
				'date'    => self::day( (string) $document['date'] ),
				'due'     => self::day( (string) ( $document['due_date'] ?? '' ) ),
				'valid'   => self::day( (string) ( $document['valid_until'] ?? '' ) ),
				'period'  => $period,
				'parent'  => (string) ( $document['parent_number'] ?? '' ),
				'vatnote' => $vatnote,
				'net'     => Money::format( $totals['net'], $currency ),
				'vat'     => Money::format( $totals['vat'], $currency ),
				'gross'   => Money::format( $totals['gross'], $currency ),
			],
			'client' => [],
		];

		foreach( [ 'number', 'company', 'name', 'street', 'zip', 'city', 'country', 'vat_id', 'email' ] as $field )
			$tokens['client'][$field] = (string) ( $client[$field] ?? '' );

		return [
			'tokens' => $tokens,
			'slots'  => [
				'address' => self::address( $client ),
				'lines'   => self::lines( $lines, $totals, $regular, $currency ),
				'totals'  => self::totals( $totals, $regular, $currency ),
			],
			'inputs' => array_map( 'strval', array_filter( (array) ( $document['inputs'] ?? [] ), 'is_string' ) ),
		];
	}

	/**
	 *	The name of a country in German where the intl extension is loaded, its code where it is not
	 *
	 *	@param		string		$code					Two capital letters
	 *
	 *	@return 	string
	 */
	public static function country( string $code ): string {

		if( extension_loaded( 'intl' ) === false || preg_match( '/^[A-Z]{2}$/D', $code ) !== 1 )
			return $code;

		$name = \Locale::getDisplayRegion( '-'. $code, 'de' );

		return $name === '' ? $code : $name;
	}

	/**
	 *	The block of the client: company, name, street, zip and city, the country when it is not DE - a line each
	 *
	 *	@param		array 		$client
	 *
	 *	@return 	string
	 */
	public static function address( array $client ): string {

		$country = (string) ( $client['country'] ?? '' );
		$lines = array_values( array_filter( [
			(string) ( $client['company'] ?? '' ),
			(string) ( $client['name'] ?? '' ),
			(string) ( $client['street'] ?? '' ),
			trim( ( $client['zip'] ?? '' ). ' '. ( $client['city'] ?? '' ) ),
			$country === 'DE' ? '' : self::country( $country ),
		], static fn( string $line ): bool => $line !== '' ) );

		$more = '';
		$line = self::template( 'address-line' );

		foreach( array_slice( $lines, 1 ) as $text )
			$more .= strtr( $line, [ '[[text]]' => self::escape( $text ) ] );

		return strtr( self::template( 'address' ), [ '[[first]]' => self::escape( $lines[0] ?? '' ), '[[more]]' => $more ] );
	}

	/**
	 *	The table of the lines; the column of the rate only where the document has VAT
	 *
	 *	@param		array 		$lines
	 *	@param		array 		$totals				Money::totals()
	 *	@param		bool			$regular
	 *	@param		string		$currency
	 *
	 *	@return 	string
	 */
	public static function lines( array $lines, array $totals, bool $regular, string $currency ): string {

		$width = $regular === true ? '40%' : '48%';
		$rows = '';

		foreach( $lines as $index => $line ) {

			$description = (string) $line['description'] === '' ? '' : strtr( self::template( 'line-description' ), [ '[[text]]' => str_replace( "\n", '<br>', self::escape( str_replace( "\r\n", "\n", (string) $line['description'] ) ) ) ] );
			$unit = (string) $line['unit'] === '' ? '' : ' '. $line['unit'];

			$rows .= strtr( self::template( 'line' ), [
				'[[title-width]]' => $width,
				'[[pos]]'         => (string) ( $index + 1 ),
				'[[title]]'       => self::escape( (string) $line['title'] ),
				'[[description]]' => $description,
				'[[qty]]'         => self::escape( Money::formatQty( (int) $line['qty'] ). $unit ),
				'[[price]]'       => self::escape( Money::format( (int) $line['price_net'], $currency ) ),
				'[[rate-cell]]'   => $regular === true ? strtr( self::template( 'line-rate' ), [ '[[rate]]' => self::escape( Money::formatRate( (int) $line['vat_rate'] ) ) ] ) : '',
				'[[amount]]'      => self::escape( Money::format( $totals['amounts'][$index], $currency ) ),
			] );
		}

		return strtr( self::template( 'lines' ), [
			'[[title-width]]' => $width,
			'[[head-pos]]'    => self::escape( self::text( 'head.pos' ) ),
			'[[head-title]]'  => self::escape( self::text( 'head.title' ) ),
			'[[head-qty]]'    => self::escape( self::text( 'head.qty' ) ),
			'[[head-price]]'  => self::escape( self::text( 'head.price' ) ),
			'[[rate-head]]'   => $regular === true ? strtr( self::template( 'lines-rate-head' ), [ '[[head-rate]]' => self::escape( self::text( 'head.rate' ) ) ] ) : '',
			'[[head-amount]]' => self::escape( self::text( 'head.amount' ) ),
			'[[rows]]'        => $rows,
		] );
	}

	/**
	 *	The totals: in regular the net, the VAT of each rate and the gross, in small_business the gross alone (it is the net)
	 *
	 *	@param		array 		$totals				Money::totals()
	 *	@param		bool			$regular
	 *	@param		string		$currency
	 *
	 *	@return 	string
	 */
	public static function totals( array $totals, bool $regular, string $currency ): string {

		$rows = '';

		if( $regular === true ) {

			$row = self::template( 'totals-rate' );
			$rows .= strtr( $row, [ '[[label]]' => self::escape( self::text( 'totals.net' ) ), '[[amount]]' => self::escape( Money::format( $totals['net'], $currency ) ) ] );

			foreach( $totals['rates'] as $rate => $sums )
				$rows .= strtr( $row, [
					'[[label]]'  => self::escape( self::text( 'totals.vat', Money::formatRate( $rate ), Money::format( $sums['net'], $currency ) ) ),
					'[[amount]]' => self::escape( Money::format( $sums['vat'], $currency ) ),
				] );
		}

		return strtr( self::template( 'totals' ), [
			'[[rows]]'        => $rows,
			'[[gross-label]]' => self::escape( self::text( 'totals.gross' ) ),
			'[[gross]]'       => self::escape( Money::format( $totals['gross'], $currency ) ),
		] );
	}

	/**
	 *	Render the PDF of a document with the template it names and keep it, once, below /data/crm/pdf
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			With its number; see data()
	 *	@param		array 		$lines
	 *	@param		array 		$client
	 *
	 *	@return 	array|string							path (virtual) and sha256 of the file; or 'render' (Pdf made nothing, its warning says why), 'exists:<path>' (the file is there already; the path is the virtual one) or 'write'
	 */
	public static function make( array &$appData, array $document, array $lines, array $client ): array|string {

		$year = (int) substr( (string) $document['date'], 0, 4 );
		$path = self::DIR. '/'. sprintf( '%04d', $year ). '/'. $document['number']. '.pdf';

		if( preg_match( self::PATH, $path ) !== 1 )
			return 'write';

		$file = \Nino\Filesystem::path( $appData, $path );

		if( file_exists( $file ) === true || is_link( $file ) === true )
			return 'exists:'. $path;

		$data = self::data( $appData, $document, $lines, $client );
		$bytes = \Nino\Modules\Pdf::render( $appData, (string) $document['template_key'], $data, [
			'title' => self::text( 'title.'. $document['category'] ). ' '. $document['number'],
			'date'  => (string) $document['date'],
		] );

		if( $bytes === null )
			return 'render';

		\Nino\Filesystem::forceDir( $appData, dirname( $path ) );

		$temporary = $file. '.'. bin2hex( random_bytes( 6 ) ). '.tmp';

		if( @file_put_contents( $temporary, $bytes ) !== strlen( $bytes ) || @rename( $temporary, $file ) === false ) {
			@unlink( $temporary );
			trigger_error( 'Crm: the PDF of document '. $document['number']. ' could not be written', E_USER_WARNING );

			return 'write';
		}

		return [ 'path' => $path, 'sha256' => hash( 'sha256', $bytes ) ];
	}

	/**
	 *	The file of a stored PDF
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
	 *	GET /.crm/pdf/<id>: the PDF of a finalised document, to a workbench session that holds the permission of the panel
	 *	Belege. The id is a number or the answer is the 404; so are a draft, a document nobody has, a file that is gone and a
	 *	visitor. A file whose hash is not the one stored is a 409 with a line in the log that names the number. ?download=1 is
	 *	saved under <number>.pdf, else it is shown
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

		if( preg_match( '/^[1-9][0-9]{0,9}$/D', $id ) === 1 && \Nino\Auth::checkPermission( $appData, Admin::MANAGE_PERM ) === true ) {

			try {
				$row = \Crm\Db::row( $appData, 'SELECT number, pdf_path, pdf_sha256 FROM documents WHERE id = :id AND pdf_path != \'\'', [ 'id' => (int) $id ] );
			}
			catch( \PDOException $e ) {
				$row = null;
			}
		}

		$file = $row === null ? null : self::file( $appData, (string) $row['pdf_path'] );

		if( $row === null || $file === null ) {
			self::answer( $request, 404, 'Not Found' );

			if( $row !== null )
				trigger_error( 'Crm: the PDF of document '. $row['number']. ' is gone', E_USER_WARNING );

			return;
		}

		if( hash_equals( (string) $row['pdf_sha256'], (string) hash_file( 'sha256', $file ) ) === false ) {
			trigger_error( 'Crm: the PDF of document '. $row['number']. ' does not match its hash', E_USER_WARNING );
			self::answer( $request, 409, 'Conflict' );

			return;
		}

		$query = $request['/nino/http/request']['query'] ?? [];
		$saved = is_array( $query ) === true && ( $query['download'] ?? null ) === '1';

		$request['/nino/http/response']['file'] = $file;
		$request['/nino/http/response']['header']['Content-Type'] = 'application/pdf';
		$request['/nino/http/response']['header']['Content-Disposition'] = ( $saved === true ? 'attachment' : 'inline' ). '; filename="'. $row['number']. '.pdf"';
		$request['/nino/http/response']['header']['Cache-Control'] = 'private, no-store';
	}

	/**
	 *	A plain-text answer that is no file
	 *
	 *	@param		array 		&$request
	 *	@param		int				$status
	 *	@param		string		$body
	 *
	 *	@return 	void
	 */
	private static function answer( array &$request, int $status, string $body ): void {

		$request['/nino/http/response']['statusCode'] = $status;
		$request['/nino/http/response']['body'] = $body;
		$request['/nino/http/response']['header']['Content-Type'] = 'text/plain; charset=utf-8';
		$request['/nino/http/response']['header']['Cache-Control'] = 'no-store';
	}

	/**
	 *	d.m.Y of a Y-m-d
	 *
	 *	@param		string		$date
	 *
	 *	@return 	string										'' for none
	 */
	public static function day( string $date ): string {

		return preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $found ) === 1 ? $found[3]. '.'. $found[2]. '.'. $found[1] : '';
	}

	/**
	 *	The period of a service: both days or none
	 *
	 *	@param		string		$from
	 *	@param		string		$to
	 *
	 *	@return 	string
	 */
	public static function period( string $from, string $to ): string {

		return $from === '' || $to === '' ? '' : self::day( $from ). ' - '. self::day( $to );
	}
}
