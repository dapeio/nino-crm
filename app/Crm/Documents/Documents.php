<?php
declare(strict_types=1);
/**
 *	Crm\Documents		The documents of the business: offers, invoices and credit notes, each with a client, dates, lines and
 *											totals in cents. A document is a draft - editable, deletable, without a number - until finalise() takes
 *											the number of its sequence, freezes the client's address into it, computes the totals once more and
 *											renders and stores its PDF, all in one transaction. From then on nothing of it changes but its state
 *											and its internal notes, and a finalised document is never deleted. All storage is Crm\Db; every
 *											statement is prepared.
 *
 *											A write answers the document as get() has it, or a string:
 *											  unknown                 no such document or client
 *											  category, client, inactive   the category is none; the client is none; the client is not active
 *											  fixed                   the document is no draft any more
 *											  state                   the state does not allow it
 *											  date:<field>, period    a date that is none; a period of one day or from after to
 *											  long:<field>, format:<field>   notes, inputs, template_key
 *											  reverse                 a reverse charge where the document has VAT mode small_business
 *											  lines, total            more than MAX_LINES lines; a net total past Money::MAX_TOTAL
 *											  line:<i>:<kind>:<field> a line (counted from 0) whose field is wrong, see Items::clean() and qty; no field for a line that is no array
 *											  pdf, template, inputs:<name>, empty    at finalise: Pdf is off; no template of the tag; a required input is
 *											                          missing; the document has no line
 *											  number                  the number format is none or the number is taken
 *											  render, exists:<path>, write   the PDF could not be made, is there already (at that path), could not be written
 *											A database failure is a \PDOException the caller answers.
 */
namespace Crm;

use Crm\Documents\Money;
use Crm\Documents\Numbers;
use Crm\Documents\Render;

class Documents {

	public const array CATEGORIES = [ 'offer', 'invoice', 'credit' ];

	public const int MAX_LINES = 200;
	public const int MAX_NOTES = 5000;
	public const int MAX_INPUT = 20000;

	// The states a document moves through by setState(), by category: from => to
	public const array TRANSITIONS = [
		'offer'   => [ 'open' => [ 'sent', 'accepted', 'declined' ], 'sent' => [ 'accepted', 'declined' ] ],
		'invoice' => [ 'open' => [ 'sent' ], 'sent' => [ 'paid' ] ],
		'credit'  => [ 'open' => [ 'sent' ] ],
	];

	private const string TIME = 'Y-m-d\TH:i:s\Z';

	// The fields of the client that are frozen into a finalised document
	private const array CLIENT_FIELDS = [ 'company', 'name', 'street', 'zip', 'city', 'country', 'number', 'vat_id', 'email' ];

	/**
	 *	The documents, the newest first
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$filter				category, state (or expired), year, client_id, search (number, company and name of the client)
	 *
	 *	@return 	array											Each with its client's title as the document shows it
	 */
	public static function list( array &$appData, array $filter = [] ): array {

		$where = [];
		$params = [];
		$today = date( 'Y-m-d' );

		if( in_array( $filter['category'] ?? null, self::CATEGORIES, true ) === true ) {
			$where[] = 'd.category = :category';
			$params['category'] = $filter['category'];
		}

		if( is_string( $filter['state'] ?? null ) === true && $filter['state'] !== '' ) {

			if( $filter['state'] === 'expired' ) {
				$where[] = 'd.category = \'offer\' AND d.state IN ( \'open\', \'sent\' ) AND d.valid_until < :today';
				$params['today'] = $today;
			}
			else {
				$where[] = 'd.state = :state';
				$params['state'] = $filter['state'];
			}
		}

		if( is_int( $filter['year'] ?? null ) === true ) {
			$where[] = 'SUBSTR( d.date, 1, 4 ) = :year';
			$params['year'] = sprintf( '%04d', $filter['year'] );
		}

		if( is_int( $filter['client_id'] ?? null ) === true ) {
			$where[] = 'd.client_id = :client';
			$params['client'] = $filter['client_id'];
		}

		$search = trim( (string) ( $filter['search'] ?? '' ) );

		if( $search !== '' ) {
			$where[] = '( d.number LIKE :s ESCAPE \'\\\' OR c.company LIKE :s ESCAPE \'\\\' OR c.name LIKE :s ESCAPE \'\\\' OR json_extract( d.client_json, \'$.company\' ) LIKE :s ESCAPE \'\\\' OR json_extract( d.client_json, \'$.name\' ) LIKE :s ESCAPE \'\\\' )';
			$params['s'] = '%'. addcslashes( $search, '%_\\' ). '%';
		}

		$rows = Db::rows( $appData, 'SELECT d.id, d.category, d.number, d.state, d.date, d.due_date, d.valid_until, d.gross, d.client_id, d.parent_id, d.client_json, c.company AS live_company, c.name AS live_name
			FROM documents d JOIN clients c ON c.id = d.client_id'. ( $where === [] ? '' : ' WHERE '. implode( ' AND ', $where ) ). ' ORDER BY d.date DESC, d.id DESC', $params );

		return array_map( static function( array $row ) use ( $today ): array {

			$frozen = json_decode( (string) $row['client_json'], true );
			$frozen = is_array( $frozen ) === true ? $frozen : [];
			$company = $row['number'] === null ? (string) $row['live_company'] : (string) ( $frozen['company'] ?? '' );
			$name = $row['number'] === null ? (string) $row['live_name'] : (string) ( $frozen['name'] ?? '' );

			return [
				'id'           => (int) $row['id'],
				'category'     => $row['category'],
				'number'       => (string) ( $row['number'] ?? '' ),
				'state'        => $row['state'],
				'expired'      => self::expired( $row, $today ),
				'date'         => $row['date'],
				'gross'        => (int) $row['gross'],
				'client_id'    => (int) $row['client_id'],
				'client_title' => $company !== '' ? $company : $name,
				'parent_id'    => $row['parent_id'] === null ? null : (int) $row['parent_id'],
			];
		}, $rows );
	}

	/**
	 *	The years the documents are dated in, the latest first
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											Years as integers
	 */
	public static function years( array &$appData ): array {

		return array_map( 'intval', array_column( Db::rows( $appData, 'SELECT DISTINCT SUBSTR( date, 1, 4 ) AS year FROM documents ORDER BY year DESC' ), 'year' ) );
	}

	/**
	 *	One document with its lines, its client, the document it comes from and the documents that come from it
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *
	 *	@return 	array|null
	 */
	public static function get( array &$appData, int $id ): ?array {

		$row = Db::row( $appData, 'SELECT * FROM documents WHERE id = :id', [ 'id' => $id ] );

		if( $row === null )
			return null;

		$document = self::shape( $row );
		$document['lines'] = array_map( [ self::class, 'shapeLine' ], Db::rows( $appData, 'SELECT * FROM document_lines WHERE document_id = :id ORDER BY sort, id', [ 'id' => $id ] ) );
		$document['client'] = $document['number'] === '' ? Clients::get( $appData, $document['client_id'] ) : $document['client_frozen'];
		$parent = $document['parent_id'] === null ? null : Db::row( $appData, 'SELECT id, number, category, state FROM documents WHERE id = :id', [ 'id' => $document['parent_id'] ] );
		$document['parent'] = $parent === null ? null : [ 'id' => (int) $parent['id'], 'number' => (string) ( $parent['number'] ?? '' ), 'category' => $parent['category'], 'state' => $parent['state'] ];
		$document['parent_number'] = $document['parent']['number'] ?? '';
		$document['children'] = array_map( static fn( array $child ): array => [ 'id' => (int) $child['id'], 'number' => (string) ( $child['number'] ?? '' ), 'category' => $child['category'], 'state' => $child['state'], 'gross' => (int) $child['gross'] ],
			Db::rows( $appData, 'SELECT id, number, category, state, gross FROM documents WHERE parent_id = :id ORDER BY id', [ 'id' => $id ] ) );

		return $document;
	}

	/**
	 *	Add a draft
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$category
	 *	@param		int				$clientId			An active client
	 *	@param		array 		$input				See change(); the date is today and the due or validity date follows from the settings where they are not given
	 *
	 *	@return 	array|string
	 */
	public static function add( array &$appData, string $category, int $clientId, array $input = [] ): array|string {

		if( in_array( $category, self::CATEGORIES, true ) === false )
			return 'category';

		$client = Clients::get( $appData, $clientId );

		if( $client === null )
			return 'client';

		if( $client['active'] === false )
			return 'inactive';

		unset( $input['client_id'] );
		$date = $input['date'] ?? date( 'Y-m-d' );
		$base = [
			'category' => $category, 'client_id' => $clientId, 'vat_mode' => Settings::get( $appData, 'vat_mode' ), 'reverse_charge' => false, 'date' => $date, 'notes' => '',
			'template_key' => '', 'inputs' => [], 'period_from' => null, 'period_to' => null, 'due_date' => null, 'valid_until' => null, 'lines' => [],
		];

		if( is_string( $date ) === true && self::validDate( $date ) === true ) {
			$key = $category === 'offer' ? 'valid_until' : 'due_date';
			$base[$key] = self::addDays( $date, (int) Settings::get( $appData, $category === 'offer' ? 'offer_days' : 'payment_days' ) );
		}

		$values = self::clean( $appData, $input, $base );

		if( is_string( $values ) === true )
			return $values;

		return (array) self::get( $appData, self::insert( $appData, $values, 'draft' ) );
	}

	/**
	 *	Change a draft: the fields given; the others stay. Lines given replace the lines
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		array 		$input				client_id, date, due_date, valid_until, period_from, period_to, template_key, inputs, reverse_charge, notes, lines
	 *
	 *	@return 	array|string
	 */
	public static function change( array &$appData, int $id, array $input ): array|string {

		$current = self::get( $appData, $id );

		if( $current === null )
			return 'unknown';

		if( $current['state'] !== 'draft' )
			return 'fixed';

		if( isset( $input['client_id'] ) === true && $input['client_id'] !== $current['client_id'] ) {

			$client = is_int( $input['client_id'] ) === true ? Clients::get( $appData, $input['client_id'] ) : null;

			if( $client === null )
				return 'client';

			if( $client['active'] === false )
				return 'inactive';
		}

		$values = self::clean( $appData, $input, $current );

		if( is_string( $values ) === true )
			return $values;

		$pdo = Db::pdo( $appData );
		$pdo->exec( 'BEGIN IMMEDIATE' );

		try {
			$state = $pdo->prepare( 'SELECT state FROM documents WHERE id = :id' );
			$state->execute( [ 'id' => $id ] );

			if( $state->fetchColumn() !== 'draft' ) {
				$pdo->exec( 'ROLLBACK' );
				return 'fixed';
			}

			$pdo->prepare( 'UPDATE documents SET client_id = :client_id, date = :date, due_date = :due_date, valid_until = :valid_until, period_from = :period_from, period_to = :period_to,
				reverse_charge = :reverse_charge, net = :net, vat = :vat, gross = :gross, template_key = :template_key, inputs_json = :inputs_json, notes = :notes, updated = :now WHERE id = :id' )
				->execute( [ 'id' => $id, 'now' => gmdate( self::TIME ) ] + self::columns( $values ) );

			self::writeLines( $pdo, $id, $values['lines'], $values['amounts'] );
			$pdo->exec( 'COMMIT' );
		}
		catch( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			throw $e;
		}

		return (array) self::get( $appData, $id );
	}

	/**
	 *	The internal notes of a document, which stay changeable after it is finalised
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		string		$notes
	 *
	 *	@return 	array|string
	 */
	public static function setNotes( array &$appData, int $id, string $notes ): array|string {

		if( mb_strlen( $notes, 'UTF-8' ) > self::MAX_NOTES )
			return 'long:notes';

		if( Db::exec( $appData, 'UPDATE documents SET notes = :notes, updated = :now WHERE id = :id', [ 'notes' => $notes, 'now' => gmdate( self::TIME ), 'id' => $id ] ) === 0 )
			return 'unknown';

		return (array) self::get( $appData, $id );
	}

	/**
	 *	Delete a draft with its lines
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *
	 *	@return 	true|string
	 */
	public static function remove( array &$appData, int $id ): true|string {

		// The state is part of the statement, so a document finalised a moment ago is not deleted; what it did not delete it tells apart afterwards
		if( Db::exec( $appData, 'DELETE FROM documents WHERE id = :id AND state = \'draft\' AND number IS NULL', [ 'id' => $id ] ) === 1 )
			return true;

		return Db::row( $appData, 'SELECT id FROM documents WHERE id = :id', [ 'id' => $id ] ) === null ? 'unknown' : 'fixed';
	}

	/**
	 *	Finalise a draft: number, frozen client, totals, PDF - in one transaction, or nothing of it
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *
	 *	@return 	array|string
	 */
	public static function finalise( array &$appData, int $id ): array|string {

		$pdo = Db::pdo( $appData );
		$written = '';
		$pdo->exec( 'BEGIN IMMEDIATE' );

		try {
			$outcome = self::finaliseIn( $appData, $pdo, $id, $written );
		}
		catch( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			self::discard( $appData, $written );
			throw $e;
		}

		// Whatever finaliseIn() refuses with came before the PDF was written: there is no file to take back
		if( is_string( $outcome ) === true ) {
			$pdo->exec( 'ROLLBACK' );
			return $outcome;
		}

		try {
			$pdo->exec( 'COMMIT' );
		}
		catch( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			self::discard( $appData, $written );
			throw $e;
		}

		return (array) self::get( $appData, $id );
	}

	/**
	 *	Move a finalised document one step forward
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		string		$state				sent, paid, accepted or declined - see TRANSITIONS
	 *	@param		string|null	$date					Y-m-d for sent_at and paid_at; now where none is given
	 *
	 *	@return 	array|string
	 */
	public static function setState( array &$appData, int $id, string $state, ?string $date = null ): array|string {

		$pdo = Db::pdo( $appData );
		$pdo->exec( 'BEGIN IMMEDIATE' );

		try {
			// Read and written in one transaction: a step another request took first is not taken twice
			$statement = $pdo->prepare( 'SELECT category, state FROM documents WHERE id = :id' );
			$statement->execute( [ 'id' => $id ] );
			$row = $statement->fetch();

			$refused = match( true ) {
				$row === false                                                                           => 'unknown',
				in_array( $state, self::TRANSITIONS[$row['category']][$row['state']] ?? [], true ) === false => 'state',
				$date !== null && self::validDate( $date ) === false                                     => 'date:date',
				default                                                                                  => '',
			};

			if( $refused !== '' ) {
				$pdo->exec( 'ROLLBACK' );
				return $refused;
			}

			$column = $state === 'sent' ? ', sent_at = :time' : ( $state === 'paid' ? ', paid_at = :time' : '' );
			$params = [ 'id' => $id, 'new' => $state, 'now' => gmdate( self::TIME ) ] + ( $column === '' ? [] : [ 'time' => $date === null ? gmdate( self::TIME ) : $date. 'T00:00:00Z' ] );

			$pdo->prepare( 'UPDATE documents SET state = :new, updated = :now'. $column. ' WHERE id = :id' )->execute( $params );
			$pdo->exec( 'COMMIT' );
		}
		catch( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			throw $e;
		}

		return (array) self::get( $appData, $id );
	}

	/**
	 *	An invoice draft from an offer that is open, sent or accepted: the same client, the lines of the offer, the VAT mode of the
	 *	setting now (every rate is held to it: 0 in small_business, one of the list in regular, else line:<i>:rate:vat_rate). An offer
	 *	that is open or sent is accepted by it
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *
	 *	@return 	array|string
	 */
	public static function fromOffer( array &$appData, int $id ): array|string {

		// The invoice takes the VAT mode of the setting now, and the offer is accepted by making it: in one transaction, which
		// reads the offer as well, so an offer that was declined a moment ago is not made an invoice of
		$pdo = Db::pdo( $appData );
		$pdo->exec( 'BEGIN IMMEDIATE' );

		try {
			$offer = self::get( $appData, $id );

			if( $offer === null || $offer['category'] !== 'offer' || in_array( $offer['state'], [ 'open', 'sent', 'accepted' ], true ) === false ) {
				$pdo->exec( 'ROLLBACK' );
				return $offer === null ? 'unknown' : 'state';
			}

			$draft = self::derive( $appData, $offer, 'invoice', 1, Settings::get( $appData, 'vat_mode' ), true );

			if( is_string( $draft ) === true ) {
				$pdo->exec( 'ROLLBACK' );
				return $draft;
			}

			$accepted = $pdo->prepare( 'UPDATE documents SET state = \'accepted\', updated = :now WHERE id = :id AND state IN ( \'open\', \'sent\', \'accepted\' )' );
			$accepted->execute( [ 'now' => gmdate( self::TIME ), 'id' => $offer['id'] ] );

			// An offer that changed its state since it was read is not the offer the invoice was made from
			if( $accepted->rowCount() === 0 ) {
				$pdo->exec( 'ROLLBACK' );
				return 'state';
			}

			$pdo->exec( 'COMMIT' );
		}
		catch( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			throw $e;
		}

		return (array) self::get( $appData, $draft['id'] );
	}

	/**
	 *	A credit note draft from an invoice that is open, sent or paid: the lines negated
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *
	 *	@return 	array|string
	 */
	public static function creditOf( array &$appData, int $id ): array|string {

		$invoice = self::get( $appData, $id );

		if( $invoice === null )
			return 'unknown';

		if( $invoice['category'] !== 'invoice' || in_array( $invoice['state'], [ 'open', 'sent', 'paid' ], true ) === false )
			return 'state';

		return self::derive( $appData, $invoice, 'credit', -1, $invoice['vat_mode'], false );
	}

	/**
	 *	The open invoices - sent or not paid yet - and what they come to
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											count, gross
	 */
	public static function summary( array &$appData ): array {

		$row = Db::row( $appData, 'SELECT COUNT(*) AS n, COALESCE( SUM( gross ), 0 ) AS gross FROM documents WHERE category = \'invoice\' AND state IN ( \'open\', \'sent\' )' );

		return [ 'count' => (int) ( $row['n'] ?? 0 ), 'gross' => (int) ( $row['gross'] ?? 0 ) ];
	}

	/**
	 *	Whether an offer is open or sent and past its validity date: a way to look at it and no state
	 *
	 *	@param		array 		$row					category, state, valid_until
	 *	@param		string		$today				Y-m-d
	 *
	 *	@return 	bool
	 */
	public static function expired( array $row, string $today ): bool {

		return $row['category'] === 'offer' && in_array( $row['state'], [ 'open', 'sent' ], true ) === true && is_string( $row['valid_until'] ?? null ) === true && $row['valid_until'] < $today;
	}

	/**
	 *	Whether a text is a day Y-m-d of the years 2000 to 2999
	 *
	 *	@param		string		$date
	 *
	 *	@return 	bool
	 */
	public static function validDate( string $date ): bool {

		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );

		return $parsed !== false && $parsed->format( 'Y-m-d' ) === $date && $parsed->format( 'Y' ) >= '2000' && $parsed->format( 'Y' ) <= '2999';
	}

	/**
	 *	@param		string		$date					Y-m-d
	 *	@param		int				$days
	 *
	 *	@return 	string
	 */
	public static function addDays( string $date, int $days ): string {

		return ( new \DateTimeImmutable( $date ) )->modify( '+'. $days. ' days' )->format( 'Y-m-d' );
	}

	/**
	 *	The body of the transaction of finalise(): everything up to the commit
	 *
	 *	@param		array 		&$appData
	 *	@param		\PDO			$pdo
	 *	@param		int				$id
	 *	@param		string		&$written			The virtual path of the PDF this call wrote, for the caller to take back
	 *
	 *	@return 	true|string
	 */
	private static function finaliseIn( array &$appData, \PDO $pdo, int $id, string &$written ): true|string {

		$document = self::get( $appData, $id );

		if( $document === null )
			return 'unknown';

		if( $document['state'] !== 'draft' )
			return 'fixed';

		if( $document['lines'] === [] )
			return 'empty';

		$client = Clients::get( $appData, $document['client_id'] );

		if( $client === null || $client['active'] === false )
			return 'inactive';

		if( Crm::featureOn( $appData, 'pdf' ) === false )
			return 'pdf';

		$tag = Render::tag( $document['category'] );

		if( $document['template_key'] === '' || isset( \Nino\Modules\Pdf::templates( $appData, $tag )[$document['template_key']] ) === false )
			return 'template';

		foreach( \Nino\Modules\Pdf::inputs( $appData, $document['template_key'] ) as $input )
			if( $input['required'] === true && trim( (string) ( $document['inputs'][$input['name']] ?? '' ) ) === '' )
				return 'inputs:'. $input['name'];

		// An invoice that is cancelled already is not taken back again
		$parent = $document['category'] === 'credit' && $document['parent'] !== null ? Db::row( $appData, 'SELECT gross, state FROM documents WHERE id = :id AND category = \'invoice\'', [ 'id' => $document['parent']['id'] ] ) : null;

		if( $parent !== null && $parent['state'] === 'cancelled' )
			return 'state';

		$number = Numbers::next( $pdo, $document['category'], (int) substr( $document['date'], 0, 4 ), Settings::get( $appData, Settings::FORMATS[$document['category']] ) );

		if( $number === null )
			return 'number';

		$frozen = [];

		foreach( self::CLIENT_FIELDS as $field )
			$frozen[$field] = (string) $client[$field];

		$totals = Money::totals( $document['lines'], $document['vat_mode'], $document['reverse_charge'] );
		$lines = array_map( static fn( array $line, int $amount ): array => [ 'amount_net' => $amount ] + $line, $document['lines'], $totals['amounts'] );
		$statement = $pdo->prepare( 'UPDATE document_lines SET amount_net = :amount WHERE id = :id' );

		foreach( $lines as $line )
			$statement->execute( [ 'amount' => $line['amount_net'], 'id' => $line['id'] ] );

		$now = gmdate( self::TIME );
		$document = [ 'number' => $number, 'net' => $totals['net'], 'vat' => $totals['vat'], 'gross' => $totals['gross'], 'state' => 'open' ] + $document;

		$made = Render::make( $appData, $document, $lines, $frozen );

		if( is_string( $made ) === true )
			return $made;

		$written = $made['path'];

		$pdo->prepare( 'UPDATE documents SET number = :number, state = \'open\', net = :net, vat = :vat, gross = :gross, client_json = :client, finalised = :now, updated = :now, pdf_path = :path, pdf_sha256 = :sha WHERE id = :id AND state = \'draft\'' )
			->execute( [ 'number' => $number, 'net' => $totals['net'], 'vat' => $totals['vat'], 'gross' => $totals['gross'], 'client' => json_encode( $frozen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ),
				'now' => $now, 'path' => $made['path'], 'sha' => $made['sha256'], 'id' => $id ] );

		// A credit note that takes the whole of an invoice back cancels it; one that takes a part leaves its state
		if( $parent !== null && (int) $parent['gross'] === -$totals['gross'] )
			$pdo->prepare( 'UPDATE documents SET state = \'cancelled\', updated = :now WHERE id = :id' )->execute( [ 'now' => $now, 'id' => $document['parent']['id'] ] );

		return true;
	}

	/**
	 *	Take back a PDF a failed finalise() wrote
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$path					'' for none
	 *
	 *	@return 	void
	 */
	private static function discard( array &$appData, string $path ): void {

		$file = $path === '' ? null : Render::file( $appData, $path );

		if( $file !== null )
			unlink( $file );
	}

	/**
	 *	A draft from another document: the same client, its lines multiplied by $factor
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$source				As get() has it
	 *	@param		string		$category
	 *	@param		int				$factor				1 or -1
	 *	@param		string		$vatMode			The mode of the draft: the source's own for a credit note (it returns what was charged), the setting's for an invoice
	 *
	 *	@param		bool			$joined				The caller has a transaction open, which the insert joins
	 *
	 *	@return 	array|string
	 */
	private static function derive( array &$appData, array $source, string $category, int $factor, string $vatMode, bool $joined ): array|string {

		$client = Clients::get( $appData, $source['client_id'] );

		if( $client === null )
			return 'client';

		if( $client['active'] === false )
			return 'inactive';

		$reverse = $source['reverse_charge'] === true && $vatMode === 'regular';
		$lines = [];

		foreach( $source['lines'] as $index => $line ) {

			$rate = $vatMode === 'small_business' || $reverse === true ? 0 : $line['vat_rate'];

			if( $category === 'invoice' && $vatMode === 'regular' && $reverse === false && Settings::rateAllowed( $appData, $rate, $vatMode ) === false )
				return 'line:'. $index. ':rate:vat_rate';

			$lines[] = [ 'qty' => $line['qty'] * $factor, 'vat_rate' => $rate ] + $line;
		}

		$totals = Money::totals( $lines, $vatMode, $reverse );
		$date = date( 'Y-m-d' );

		$values = [
			'category' => $category, 'client_id' => $source['client_id'], 'parent_id' => $source['id'], 'vat_mode' => $vatMode, 'reverse_charge' => $reverse,
			'date' => $date, 'due_date' => $category === 'invoice' ? self::addDays( $date, (int) Settings::get( $appData, 'payment_days' ) ) : null, 'valid_until' => null, 'period_from' => $source['period_from'], 'period_to' => $source['period_to'],
			'template_key' => '', 'inputs' => [], 'notes' => '', 'lines' => $lines, 'amounts' => $totals['amounts'],
		] + $totals;

		return (array) self::get( $appData, self::insert( $appData, $values, 'draft', $joined ) );
	}

	/**
	 *	The checked fields of a draft, from what was given over what the draft has
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$input
	 *	@param		array 		$current			The draft, or the defaults of a new one: category, client_id, vat_mode and the fields below
	 *
	 *	@return 	array|string
	 */
	private static function clean( array &$appData, array $input, array $current ): array|string {

		$values = [ 'category' => $current['category'], 'client_id' => $input['client_id'] ?? $current['client_id'], 'parent_id' => $current['parent_id'] ?? null, 'vat_mode' => $current['vat_mode'] ];

		foreach( [ 'date', 'due_date', 'valid_until', 'period_from', 'period_to' ] as $field ) {

			$date = array_key_exists( $field, $input ) === true ? $input[$field] : $current[$field];
			$date = $date === '' ? null : $date;

			if( $date !== null && ( is_string( $date ) === false || self::validDate( $date ) === false ) )
				return 'date:'. $field;

			$values[$field] = $date;
		}

		if( $values['date'] === null )
			return 'date:date';

		// The date a category does not use is not kept
		// An offer is valid for a time, an invoice is due after one; a credit note returns money and has neither
		if( $values['category'] !== 'offer' )
			$values['valid_until'] = null;

		if( $values['category'] !== 'invoice' )
			$values['due_date'] = null;

		if( ( $values['period_from'] === null ) !== ( $values['period_to'] === null ) || ( $values['period_from'] !== null && $values['period_to'] !== null && $values['period_from'] > $values['period_to'] ) )
			return 'period';

		$reverse = $input['reverse_charge'] ?? $current['reverse_charge'];

		if( is_bool( $reverse ) === false )
			return 'format:reverse_charge';

		if( $reverse === true && $values['vat_mode'] !== 'regular' )
			return 'reverse';

		$values['reverse_charge'] = $reverse;

		$notes = $input['notes'] ?? $current['notes'];

		if( is_string( $notes ) === false )
			return 'format:notes';

		if( mb_strlen( $notes, 'UTF-8' ) > self::MAX_NOTES )
			return 'long:notes';

		$values['notes'] = $notes;

		$key = $input['template_key'] ?? $current['template_key'];

		if( is_string( $key ) === false )
			return 'format:template_key';

		if( $key !== '' && ( Crm::featureOn( $appData, 'pdf' ) === false || isset( \Nino\Modules\Pdf::templates( $appData, Render::tag( $values['category'] ) )[$key] ) === false ) )
			return 'template';

		$values['template_key'] = $key;

		$inputs = $input['inputs'] ?? $current['inputs'];

		if( is_array( $inputs ) === false || count( $inputs ) > 60 )
			return 'format:inputs';

		foreach( $inputs as $name => $text )
			if( is_string( $name ) === false || preg_match( '/^[a-z][a-z0-9-]{1,39}$/D', $name ) !== 1 || is_string( $text ) === false || mb_strlen( $text, 'UTF-8' ) > self::MAX_INPUT )
				return 'format:inputs';

		$values['inputs'] = $inputs;

		$lines = $input['lines'] ?? $current['lines'];

		if( is_array( $lines ) === false || array_is_list( $lines ) === false )
			return 'format:lines';

		if( count( $lines ) > self::MAX_LINES )
			return 'lines';

		$clean = [];

		foreach( $lines as $index => $line ) {

			$one = is_array( $line ) === true ? self::cleanLine( $appData, $line, $values['vat_mode'], $reverse ) : 'format:';

			if( is_string( $one ) === true )
				return 'line:'. $index. ':'. $one;

			$clean[] = $one;
		}

		$totals = Money::totals( $clean, $values['vat_mode'], $reverse );

		if( abs( $totals['net'] ) > Money::MAX_TOTAL )
			return 'total';

		return $values + [ 'lines' => $clean, 'amounts' => $totals['amounts'] ] + $totals;
	}

	/**
	 *	One line: the fields of an item and a quantity. A reverse charge sets the rate to 0
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$line
	 *	@param		string		$vatMode
	 *	@param		bool			$reverse
	 *
	 *	@return 	array|string
	 */
	private static function cleanLine( array &$appData, array $line, string $vatMode, bool $reverse ): array|string {

		$line['price'] = $line['price'] ?? $line['price_net'] ?? 0;

		if( $reverse === true )
			$line['vat_rate'] = 0;

		$values = Items::clean( $appData, $line, $reverse === true ? 'small_business' : $vatMode );

		if( is_string( $values ) === true )
			return $values;

		$qty = Items::amount( $line['qty'] ?? null, 'qty' );

		if( $qty === null || $qty === 0 )
			return 'amount:qty';

		if( abs( Money::lineAmount( $qty, $values['price_net'] ) ) > Money::MAX_LINE )
			return 'amount:price';

		return $values + [ 'qty' => $qty ];
	}

	/**
	 *	Insert a document and its lines, in a transaction of their own unless the caller has one open
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$values				See clean()
	 *	@param		string		$state
	 *	@param		bool			$joined				The caller has a transaction open, which this one joins
	 *
	 *	@return 	int												The id
	 */
	private static function insert( array &$appData, array $values, string $state, bool $joined = false ): int {

		$pdo = Db::pdo( $appData );
		$now = gmdate( self::TIME );
		$own = $joined === false;

		if( $own === true )
			$pdo->exec( 'BEGIN IMMEDIATE' );

		try {
			$pdo->prepare( 'INSERT INTO documents ( category, client_id, parent_id, state, date, due_date, valid_until, period_from, period_to, vat_mode, reverse_charge, net, vat, gross, template_key, inputs_json, notes, created, updated )
				VALUES ( :category, :client_id, :parent_id, :state, :date, :due_date, :valid_until, :period_from, :period_to, :vat_mode, :reverse_charge, :net, :vat, :gross, :template_key, :inputs_json, :notes, :now, :now )' )
				->execute( [ 'category' => $values['category'], 'parent_id' => $values['parent_id'] ?? null, 'vat_mode' => $values['vat_mode'], 'state' => $state, 'now' => $now ] + self::columns( $values ) );

			$id = (int) $pdo->lastInsertId();
			self::writeLines( $pdo, $id, $values['lines'], $values['amounts'] );

			if( $own === true )
				$pdo->exec( 'COMMIT' );
		}
		catch( \Throwable $e ) {
			if( $own === true )
				$pdo->exec( 'ROLLBACK' );

			throw $e;
		}

		return $id;
	}

	/**
	 *	The columns a draft's fields are written to
	 *
	 *	@param		array 		$values
	 *
	 *	@return 	array
	 */
	private static function columns( array $values ): array {

		return [
			'client_id' => $values['client_id'], 'date' => $values['date'], 'due_date' => $values['due_date'], 'valid_until' => $values['valid_until'],
			'period_from' => $values['period_from'], 'period_to' => $values['period_to'], 'reverse_charge' => $values['reverse_charge'] === true ? 1 : 0,
			'net' => $values['net'], 'vat' => $values['vat'], 'gross' => $values['gross'], 'template_key' => $values['template_key'],
			'inputs_json' => json_encode( $values['inputs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR ), 'notes' => $values['notes'],
		];
	}

	/**
	 *	Replace the lines of a draft
	 *
	 *	@param		\PDO			$pdo
	 *	@param		int				$id
	 *	@param		array 		$lines
	 *	@param		array 		$amounts
	 *
	 *	@return 	void
	 */
	private static function writeLines( \PDO $pdo, int $id, array $lines, array $amounts ): void {

		$pdo->prepare( 'DELETE FROM document_lines WHERE document_id = :id' )->execute( [ 'id' => $id ] );
		$statement = $pdo->prepare( 'INSERT INTO document_lines ( document_id, sort, title, description, qty, unit, price_net, vat_rate, amount_net ) VALUES ( :id, :sort, :title, :description, :qty, :unit, :price_net, :vat_rate, :amount )' );

		foreach( $lines as $position => $line )
			$statement->execute( [ 'id' => $id, 'sort' => $position + 1, 'title' => $line['title'], 'description' => $line['description'], 'qty' => $line['qty'], 'unit' => $line['unit'],
				'price_net' => $line['price_net'], 'vat_rate' => $line['vat_rate'], 'amount' => $amounts[$position] ] );
	}

	/**
	 *	@param		array 		$row
	 *
	 *	@return 	array
	 */
	private static function shape( array $row ): array {

		$inputs = json_decode( (string) $row['inputs_json'], true );
		$frozen = json_decode( (string) $row['client_json'], true );

		foreach( [ 'id', 'client_id', 'net', 'vat', 'gross' ] as $field )
			$row[$field] = (int) $row[$field];

		$row['parent_id'] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
		$row['reverse_charge'] = (int) $row['reverse_charge'] === 1;
		$row['number'] = (string) ( $row['number'] ?? '' );
		$row['inputs'] = is_array( $inputs ) === true ? $inputs : [];
		$row['client_frozen'] = is_array( $frozen ) === true ? $frozen : [];
		$row['expired'] = self::expired( $row, date( 'Y-m-d' ) );
		unset( $row['inputs_json'], $row['client_json'] );

		return $row;
	}

	/**
	 *	@param		array 		$row
	 *
	 *	@return 	array
	 */
	private static function shapeLine( array $row ): array {

		foreach( [ 'id', 'document_id', 'sort', 'qty', 'price_net', 'vat_rate', 'amount_net' ] as $field )
			$row[$field] = (int) $row[$field];

		return $row;
	}
}
