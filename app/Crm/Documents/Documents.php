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
 *											  offer                   a contract without an offer of the client in any state but draft
 *											  fixed                   the document is no draft any more
 *											  state                   the state does not allow it
 *											  date:<field>, period    a date that is none; a period of one day or from after to
 *											  long:<field>, format:<field>   notes, inputs, template_key; for the documents without lines also title, and lines
 *											  empty:title             a document without lines has no title
 *											  reverse                 a reverse charge where the document has VAT mode small_business
 *											  lines, total            more than MAX_LINES lines; a net total past Money::MAX_TOTAL
 *											  line:<i>:<kind>:<field> a line (counted from 0) whose field is wrong, see Lines::clean() and qty; no field for a line that is no array
 *											  pdf, template, inputs:<name>, empty    at finalise: Pdf is off; no template of the tag; a required input is
 *											                          missing; the document has no line
 *											  number                  the number format is none or the number is taken
 *											  render, exists:<path>, write   the PDF could not be made, is there already (at that path), could not be written
 *											  address, limit          at release: the site has no address to link to; the cap of mails an hour was reached (nothing changed)
 *											  pending, days, field, account, sign   at requestSignature: a request is waiting; the days are none; the template has no signature
 *											                          block for a member (Sign says so); the account is none of the client's; Sign, Pdf or Members is off
 *											  sign:<code>             Sign refused with template, input, render or write
 *											A database failure is a \PDOException the caller answers.
 */
namespace Crm;

use Crm\Documents\Lines;
use Crm\Documents\Mail;
use Crm\Documents\Money;
use Crm\Documents\Numbers;
use Crm\Documents\Render;

class Documents {

	public const array CATEGORIES = [ 'offer', 'invoice', 'credit', 'contract', 'privacy', 'information' ];

	// The categories without lines, totals and numbers: a contract hangs on an offer of the client, the others on the client
	public const array PLAIN = [ 'contract', 'privacy', 'information' ];

	// The categories that ask for a signature through Sign, and the states of a request that is waiting
	public const array SIGNABLE = [ 'contract', 'privacy' ];
	public const array PENDING = [ 'requested', 'viewed' ];

	public const int MAX_TITLE = 120;

	public const int MAX_LINES = 200;
	public const int MAX_NOTES = 5000;
	public const int MAX_INPUT = 20000;

	// The states a document moves through by setState(), by category: from => to. The three documents without lines also end in signed or
	// declined, which only Sign's callbacks give (callbackSigned(), callbackDeclined())
	public const array TRANSITIONS = [
		'offer'   => [ 'open' => [ 'sent', 'accepted', 'declined' ], 'sent' => [ 'accepted', 'declined' ] ],
		'invoice' => [ 'open' => [ 'sent' ], 'sent' => [ 'paid' ] ],
		'credit'  => [ 'open' => [ 'sent' ] ],
		'contract'    => [ 'open' => [ 'sent' ] ],
		'privacy'     => [ 'open' => [ 'sent' ] ],
		'information' => [ 'open' => [ 'sent' ] ],
	];

	private const string TIME = 'Y-m-d\TH:i:s\Z';

	// The fields of the client that are frozen into a finalised document
	private const array CLIENT_FIELDS = [ 'company', 'name', 'street', 'zip', 'city', 'country', 'number', 'vat_id', 'email' ];

	/**
	 *	The documents, the newest first
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$filter				category, state (or expired), year, client_id
	 *	@param		bool			$sync					Whether the state of the requests waiting in Sign is read first; a caller that needs no state leaves it out
	 *
	 *	@return 	array											Each with its client's title as the document shows it
	 */
	public static function list( array &$appData, array $filter = [], bool $sync = true ): array {

		if( $sync === true )
			self::syncSign( $appData );

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

		$rows = Db::rows( $appData, 'SELECT d.id, d.category, d.number, d.title, d.state, d.date, d.due_date, d.valid_until, d.gross, d.client_id, d.parent_id, d.client_json, d.released, d.sign_id, d.sign_state, c.company AS live_company, c.name AS live_name
			FROM documents d JOIN clients c ON c.id = d.client_id'. ( $where === [] ? '' : ' WHERE '. implode( ' AND ', $where ) ). ' ORDER BY d.date DESC, d.id DESC', $params );

		return array_map( static function( array $row ) use ( $today ): array {

			$frozen = json_decode( (string) $row['client_json'], true );
			$frozen = is_array( $frozen ) === true ? $frozen : [];
			$company = $row['state'] === 'draft' ? (string) $row['live_company'] : (string) ( $frozen['company'] ?? '' );
			$name = $row['state'] === 'draft' ? (string) $row['live_name'] : (string) ( $frozen['name'] ?? '' );

			return [
				'id'           => (int) $row['id'],
				'category'     => $row['category'],
				'number'       => (string) ( $row['number'] ?? '' ),
				'title'        => (string) $row['title'],
				'state'        => $row['state'],
				'released'     => (int) $row['released'] === 1,
				'sign_state'   => (string) $row['sign_state'],
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

		self::syncSign( $appData, 'id = :id', [ 'id' => $id ] );

		$row = Db::row( $appData, 'SELECT * FROM documents WHERE id = :id', [ 'id' => $id ] );

		if( $row === null )
			return null;

		$document = self::shape( $row );
		$document['lines'] = array_map( [ self::class, 'shapeLine' ], Db::rows( $appData, 'SELECT * FROM document_lines WHERE document_id = :id ORDER BY sort, id', [ 'id' => $id ] ) );
		$document['client'] = $document['state'] === 'draft' ? Clients::get( $appData, $document['client_id'] ) : $document['client_frozen'];
		$parent = $document['parent_id'] === null ? null : Db::row( $appData, 'SELECT id, number, title, category, state FROM documents WHERE id = :id', [ 'id' => $document['parent_id'] ] );
		$document['parent'] = $parent === null ? null : [ 'id' => (int) $parent['id'], 'number' => (string) ( $parent['number'] ?? '' ), 'title' => (string) $parent['title'], 'category' => $parent['category'], 'state' => $parent['state'] ];
		$document['parent_number'] = $document['parent']['number'] ?? '';
		$document['children'] = array_map( static fn( array $child ): array => [ 'id' => (int) $child['id'], 'number' => (string) ( $child['number'] ?? '' ), 'title' => (string) $child['title'], 'category' => $child['category'], 'state' => $child['state'], 'gross' => (int) $child['gross'] ],
			Db::rows( $appData, 'SELECT id, number, title, category, state, gross FROM documents WHERE parent_id = :id ORDER BY id', [ 'id' => $id ] ) );

		return $document;
	}

	/**
	 *	Add a draft
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$category
	 *	@param		int				$clientId			An active client. A contract takes the client of its offer: 0, or the same client
	 *	@param		array 		$input				See change(); parent_id (the offer) for a contract; the date is today and the due or validity date follows from the settings where they are not given
	 *
	 *	@return 	array|string
	 */
	public static function add( array &$appData, string $category, int $clientId, array $input = [] ): array|string {

		if( in_array( $category, self::CATEGORIES, true ) === false )
			return 'category';

		$parentId = null;

		if( $category === 'contract' ) {

			$offer = self::offerFor( $appData, $input['parent_id'] ?? null );

			if( is_string( $offer ) === true )
				return $offer;

			if( $clientId !== 0 && $clientId !== $offer['client_id'] )
				return 'client';

			$clientId = $offer['client_id'];
			$parentId = $offer['id'];
		}

		$client = Clients::get( $appData, $clientId );

		if( $client === null )
			return 'client';

		if( $client['active'] === false )
			return 'inactive';

		unset( $input['client_id'], $input['parent_id'] );
		$date = $input['date'] ?? date( 'Y-m-d' );
		$base = [
			'category' => $category, 'client_id' => $clientId, 'parent_id' => $parentId, 'vat_mode' => Settings::get( $appData, 'vat_mode' ), 'reverse_charge' => false, 'date' => $date, 'notes' => '', 'title' => '',
			'template_key' => '', 'inputs' => [], 'period_from' => null, 'period_to' => null, 'due_date' => null, 'valid_until' => null, 'lines' => [],
		];

		if( is_string( $date ) === true && self::validDate( $date ) === true && in_array( $category, self::PLAIN, true ) === false ) {
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
	 *	@param		array 		$input				client_id, date, due_date, valid_until, period_from, period_to, template_key, inputs, reverse_charge, notes, lines; title for the documents without lines; parent_id (the offer) for a contract, whose client follows it
	 *
	 *	@return 	array|string
	 */
	public static function change( array &$appData, int $id, array $input ): array|string {

		$current = self::get( $appData, $id );

		if( $current === null )
			return 'unknown';

		if( $current['state'] !== 'draft' )
			return 'fixed';

		// The client of a contract is its offer's: the offer is looked at again, and the client follows it
		if( $current['category'] === 'contract' ) {

			$offer = self::offerFor( $appData, $input['parent_id'] ?? $current['parent_id'] );

			if( is_string( $offer ) === true )
				return $offer;

			if( isset( $input['client_id'] ) === true && $input['client_id'] !== $offer['client_id'] )
				return 'client';

			$input['client_id'] = $offer['client_id'];
			$input['parent_id'] = $offer['id'];
		}

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

			$pdo->prepare( 'UPDATE documents SET client_id = :client_id, parent_id = :parent_id, title = :title, date = :date, due_date = :due_date, valid_until = :valid_until, period_from = :period_from, period_to = :period_to,
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
	 *	Release a finalised document to the portal: its client sees it on the page Dokumente. With $mail one mail goes to each active
	 *	account of the client (Documents\Mail), in one call of the kernel's cap on mails; an offer, an invoice or a credit note that is
	 *	still open is sent by it, when at least one mail was delivered. A release again keeps the day of the first. Nothing changes
	 *	where the mail is refused (address, limit)
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		bool			$mail
	 *
	 *	@return 	array|string							document (as get() has it) and mailed (how many mails were delivered); or why not
	 */
	public static function release( array &$appData, int $id, bool $mail ): array|string {

		$document = self::get( $appData, $id );

		if( $document === null )
			return 'unknown';

		if( $document['state'] === 'draft' || $document['pdf_path'] === '' )
			return 'state';

		$mailed = 0;

		if( $mail === true ) {

			$sent = Mail::release( $appData, $document );

			if( is_string( $sent ) === true )
				return $sent;

			$mailed = $sent;
		}

		$now = gmdate( self::TIME );
		$sends = $mailed > 0 && in_array( $document['category'], self::PLAIN, true ) === false;
		$pdo = Db::pdo( $appData );
		$pdo->exec( 'BEGIN IMMEDIATE' );

		try {
			$statement = $pdo->prepare( 'SELECT state FROM documents WHERE id = :id' );
			$statement->execute( [ 'id' => $id ] );
			$state = $statement->fetchColumn();

			if( $state === false || $state === 'draft' ) {
				$pdo->exec( 'ROLLBACK' );
				return $state === false ? 'unknown' : 'state';
			}

			$pdo->prepare( 'UPDATE documents SET released_at = CASE WHEN released = 1 THEN released_at ELSE :now END, released = 1, updated = :now WHERE id = :id' )->execute( [ 'now' => $now, 'id' => $id ] );

			// The mail is the sending: an offer or an invoice that was open is sent now
			if( $sends === true && $state === 'open' )
				$pdo->prepare( 'UPDATE documents SET state = \'sent\', sent_at = :now WHERE id = :id AND state = \'open\'' )->execute( [ 'now' => $now, 'id' => $id ] );

			$pdo->exec( 'COMMIT' );
		}
		catch( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			throw $e;
		}

		return [ 'document' => (array) self::get( $appData, $id ), 'mailed' => $mailed ];
	}

	/**
	 *	Take a document back from the portal. The file stays, as the books want it
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *
	 *	@return 	array|string							The document as get() has it; or why not
	 */
	public static function unrelease( array &$appData, int $id ): array|string {

		if( Db::exec( $appData, 'UPDATE documents SET released = 0, released_at = NULL, updated = :now WHERE id = :id AND state != \'draft\'', [ 'now' => gmdate( self::TIME ), 'id' => $id ] ) === 0 )
			return Db::row( $appData, 'SELECT id FROM documents WHERE id = :id', [ 'id' => $id ] ) === null ? 'unknown' : 'state';

		return (array) self::get( $appData, $id );
	}

	/**
	 *	Ask an account of the document's client for a signature through Sign: a contract or a privacy document that is open or sent, with no
	 *	request waiting. The request is made with the data the PDF was rendered with and brings the member back to the page Dokumente
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		string		$accountId		An account of Members that belongs to the client
	 *	@param		int|null	$days					How long the link is good for, 1 to 90; the setting of Sign where none is given
	 *
	 *	@return 	array|string							document (as get() has it) and mailed (whether Sign delivered its mail); or why not
	 */
	public static function requestSignature( array &$appData, int $id, string $accountId, ?int $days = null ): array|string {

		$document = self::get( $appData, $id );

		if( $document === null )
			return 'unknown';

		if( in_array( $document['category'], self::SIGNABLE, true ) === false )
			return 'category';

		if( in_array( $document['state'], [ 'open', 'sent' ], true ) === false )
			return 'state';

		if( in_array( $document['sign_state'], self::PENDING, true ) === true )
			return 'pending';

		if( $days !== null && ( $days < 1 || $days > 90 ) )
			return 'days';

		if( Crm::featureOn( $appData, 'sign' ) === false || Crm::featureOn( $appData, 'pdf' ) === false || Crm::featureOn( $appData, 'members' ) === false )
			return 'sign';

		if( in_array( $accountId, Clients::accounts( $appData, $document['client_id'] ), true ) === false )
			return 'account';

		$options = [ 'title' => $document['title'], 'page' => '/portal/dokumente' ] + ( $days === null ? [] : [ 'days' => $days ] );
		$request = \Nino\Modules\Sign::request( $appData, $document['template_key'], self::dataOf( $appData, $document ), $accountId, $options );

		if( is_string( $request ) === true )
			return match( $request ) { 'member' => 'field', 'account' => 'account', default => 'sign:'. $request };

		// Two requests made at once: the one that wrote its id first is the request of the document, the other is taken back
		$claimed = Db::exec( $appData, 'UPDATE documents SET sign_id = :sign, sign_state = \'requested\', updated = :now WHERE id = :id AND state IN ( \'open\', \'sent\' ) AND sign_state NOT IN ( \'requested\', \'viewed\' )',
			[ 'sign' => $request['id'], 'now' => gmdate( self::TIME ), 'id' => $id ] );

		if( $claimed === 0 ) {
			\Nino\Modules\Sign::cancel( $appData, $request['id'] );
			return 'pending';
		}

		return [ 'document' => (array) self::get( $appData, $id ), 'mailed' => $request['mailed'] === true ];
	}

	/**
	 *	'/sign/signed': a member signed - the document of the request is signed. A document the request does not belong to is left alone
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request			The request as Sign::get() has it
	 *
	 *	@return 	void
	 */
	public static function callbackSigned( array &$appData, array &$request ): void {

		self::follow( $appData, $request, 'signed' );
	}

	/**
	 *	'/sign/declined': a member declined - the document of the request is declined
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request			The request as Sign::get() has it
	 *
	 *	@return 	void
	 */
	public static function callbackDeclined( array &$appData, array &$request ): void {

		self::follow( $appData, $request, 'declined' );
	}

	/**
	 *	The released documents of a client, the newest first, for the portal
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$clientId
	 *
	 *	@return 	array											id, category, number, title, state, expired, date, sign_id, sign_state
	 */
	public static function released( array &$appData, int $clientId ): array {

		self::syncSign( $appData, 'client_id = :client', [ 'client' => $clientId ] );

		$today = date( 'Y-m-d' );
		$rows = Db::rows( $appData, 'SELECT id, category, number, title, state, date, valid_until, sign_id, sign_state FROM documents
			WHERE client_id = :client AND released = 1 AND state != \'draft\' AND pdf_path != \'\' ORDER BY date DESC, id DESC', [ 'client' => $clientId ] );

		return array_map( static fn( array $row ): array => [
			'id' => (int) $row['id'], 'category' => (string) $row['category'], 'number' => (string) ( $row['number'] ?? '' ), 'title' => (string) $row['title'], 'state' => (string) $row['state'],
			'expired' => self::expired( $row, $today ), 'date' => (string) $row['date'], 'sign_id' => (string) $row['sign_id'], 'sign_state' => (string) $row['sign_state'],
		], $rows );
	}

	/**
	 *	How many documents a client has and how many of them are released
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$clientId
	 *
	 *	@return 	array											total, released
	 */
	public static function counts( array &$appData, int $clientId ): array {

		$row = Db::row( $appData, 'SELECT COUNT(*) AS total, COALESCE( SUM( released ), 0 ) AS released FROM documents WHERE client_id = :client', [ 'client' => $clientId ] );

		return [ 'total' => (int) ( $row['total'] ?? 0 ), 'released' => (int) ( $row['released'] ?? 0 ) ];
	}

	/**
	 *	What a template of Pdf is handed for a document that is finalised: the data its PDF was rendered with, made again from what the
	 *	document keeps (the frozen client, its lines, the offer of a contract)
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			As get() has it, finalised
	 *
	 *	@return 	array											See Render::data()
	 */
	public static function dataOf( array &$appData, array $document ): array {

		$plain = in_array( $document['category'], self::PLAIN, true );

		if( $plain === true )
			$document['offer'] = self::offerOf( $appData, $document );

		return Render::data( $appData, $document, $plain === true ? [] : $document['lines'], $document['client_frozen'] );
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
	 *	The offer a contract hangs on: of any state but draft
	 *
	 *	@param		array 		&$appData
	 *	@param		mixed			$id
	 *
	 *	@return 	array|string							id and client_id of the offer; 'offer' for an id that is none, an offer nobody has and a draft
	 */
	private static function offerFor( array &$appData, mixed $id ): array|string {

		$row = is_int( $id ) === true ? Db::row( $appData, 'SELECT id, client_id FROM documents WHERE id = :id AND category = \'offer\' AND state != \'draft\'', [ 'id' => $id ] ) : null;

		return $row === null ? 'offer' : [ 'id' => (int) $row['id'], 'client_id' => (int) $row['client_id'] ];
	}

	/**
	 *	The offer a contract quotes, with its lines
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document
	 *
	 *	@return 	array|null								As get() has it; null for a document that is no contract and for an offer that is a draft or gone
	 */
	private static function offerOf( array &$appData, array $document ): ?array {

		if( $document['category'] !== 'contract' || $document['parent_id'] === null )
			return null;

		$offer = self::get( $appData, $document['parent_id'] );

		return $offer === null || $offer['category'] !== 'offer' || $offer['state'] === 'draft' ? null : $offer;
	}

	/**
	 *	A request of Sign told this module its state: the document of the request moves with it
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$request			As Sign::get() has it
	 *	@param		string		$status
	 *
	 *	@return 	void
	 */
	private static function follow( array &$appData, array $request, string $status ): void {

		$id = is_string( $request['id'] ?? null ) === true ? $request['id'] : '';
		$at = is_string( $request['signed'] ?? null ) === true && preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $request['signed'] ) === 1 ? $request['signed'] : gmdate( self::TIME );

		if( preg_match( '/^[a-f0-9]{12}$/D', $id ) !== 1 )
			return;

		// The signing is done and Sign's answer to the member does not wait for this module: a write that fails here is made good by the next read of the document
		try {
			self::apply( $appData, $id, $status, $at );
		}
		catch( \PDOException $e ) {
			trigger_error( 'Crm: the document of the request '. $id. ' could not follow it ('. $status. ')', E_USER_WARNING );
		}
	}

	/**
	 *	Write what Sign says of a request on the document that asked for it: signed and declined end the document, the other states only
	 *	say how the request stands. A document that is signed, declined or no longer open is not touched
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$signId
	 *	@param		string		$status				requested, viewed, signed, declined, cancelled, changed or expired
	 *	@param		string		$at						When it was signed
	 *
	 *	@return 	void
	 */
	private static function apply( array &$appData, string $signId, string $status, string $at ): void {

		$now = gmdate( self::TIME );

		if( $status === 'signed' || $status === 'declined' ) {
			Db::exec( $appData, 'UPDATE documents SET state = :status, sign_state = :status, signed_at = :signed, updated = :now WHERE sign_id = :sign AND state IN ( \'open\', \'sent\' )',
				[ 'status' => $status, 'signed' => $status === 'signed' ? $at : null, 'now' => $now, 'sign' => $signId ] );
			return;
		}

		Db::exec( $appData, 'UPDATE documents SET sign_state = :status, updated = :now WHERE sign_id = :sign AND state IN ( \'open\', \'sent\' ) AND sign_state != :status', [ 'status' => $status, 'now' => $now, 'sign' => $signId ] );
	}

	/**
	 *	Read the state of the requests of the documents that wait for a signature (or ran out of time) from Sign and store it: a request the
	 *	owner cancelled in Sign's panel, one that ran out, one a member signed while this module did not hear of it. A request Sign does not
	 *	have any more is cancelled - but only where Sign's index can be read and holds requests, so an index that is unreadable or was
	 *	restored empty does not cancel every document that waits. The index is read once, however many documents wait. Where Sign is not
	 *	active nothing is read
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$where				A condition over documents, with its parameters; all documents where left out
	 *	@param		array 		$params
	 *
	 *	@return 	void
	 */
	private static function syncSign( array &$appData, string $where = '', array $params = [] ): void {

		if( Crm::featureOn( $appData, 'sign' ) === false )
			return;

		$waiting = Db::rows( $appData, 'SELECT sign_id, sign_state FROM documents WHERE sign_id != \'\' AND sign_state IN ( \'requested\', \'viewed\', \'expired\' ) AND state IN ( \'open\', \'sent\' )'. ( $where === '' ? '' : ' AND '. $where ), $params );

		if( $waiting === [] )
			return;

		$known = array_column( \Nino\Modules\Sign::requests( $appData ), null, 'id' );

		if( $known === [] )
			return;

		foreach( $waiting as $row ) {

			$request = $known[(string) $row['sign_id']] ?? null;
			$status = $request === null ? 'cancelled' : (string) $request['status'];

			if( $status !== $row['sign_state'] )
				self::apply( $appData, (string) $row['sign_id'], $status, is_string( $request['signed'] ?? null ) === true ? $request['signed'] : gmdate( self::TIME ) );
		}
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

		$plain = in_array( $document['category'], self::PLAIN, true );

		if( $plain === false && $document['lines'] === [] )
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

		$frozen = [];

		foreach( self::CLIENT_FIELDS as $field )
			$frozen[$field] = (string) $client[$field];

		$now = gmdate( self::TIME );

		// A document without lines has no number and no totals: the PDF is named by its category and id, and a contract quotes its offer
		if( $plain === true ) {

			$document['offer'] = self::offerOf( $appData, $document );

			if( $document['category'] === 'contract' && $document['offer'] === null )
				return 'offer';

			$made = Render::make( $appData, [ 'state' => 'open' ] + $document, [], $frozen );

			if( is_string( $made ) === true )
				return $made;

			$written = $made['path'];

			$pdo->prepare( 'UPDATE documents SET state = \'open\', client_json = :client, finalised = :now, updated = :now, pdf_path = :path, pdf_sha256 = :sha WHERE id = :id AND state = \'draft\'' )
				->execute( [ 'client' => json_encode( $frozen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), 'now' => $now, 'path' => $made['path'], 'sha' => $made['sha256'], 'id' => $id ] );

			return true;
		}

		// An invoice that is cancelled already is not taken back again
		$parent = $document['category'] === 'credit' && $document['parent'] !== null ? Db::row( $appData, 'SELECT gross, state FROM documents WHERE id = :id AND category = \'invoice\'', [ 'id' => $document['parent']['id'] ] ) : null;

		if( $parent !== null && $parent['state'] === 'cancelled' )
			return 'state';

		$number = Numbers::next( $pdo, $document['category'], (int) substr( $document['date'], 0, 4 ), Settings::get( $appData, Settings::FORMATS[$document['category']] ) );

		if( $number === null )
			return 'number';

		$totals = Money::totals( $document['lines'], $document['vat_mode'], $document['reverse_charge'] );
		$lines = array_map( static fn( array $line, int $amount ): array => [ 'amount_net' => $amount ] + $line, $document['lines'], $totals['amounts'] );
		$statement = $pdo->prepare( 'UPDATE document_lines SET amount_net = :amount WHERE id = :id' );

		foreach( $lines as $line )
			$statement->execute( [ 'amount' => $line['amount_net'], 'id' => $line['id'] ] );

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
			'category' => $category, 'client_id' => $source['client_id'], 'parent_id' => $source['id'], 'title' => '', 'vat_mode' => $vatMode, 'reverse_charge' => $reverse,
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

		$plain = in_array( $current['category'], self::PLAIN, true );

		// Only a contract changes what it hangs on, and only add() and change() say to what
		$values = [ 'category' => $current['category'], 'client_id' => $input['client_id'] ?? $current['client_id'], 'parent_id' => $current['category'] === 'contract' ? ( $input['parent_id'] ?? $current['parent_id'] ?? null ) : ( $current['parent_id'] ?? null ), 'vat_mode' => $current['vat_mode'] ];

		if( $plain === true ) {

			$title = $input['title'] ?? $current['title'] ?? '';

			if( is_string( $title ) === false || mb_check_encoding( $title, 'UTF-8' ) === false )
				return 'format:title';

			$title = trim( $title );

			if( $title === '' )
				return 'empty:title';

			if( preg_match( '/[\p{C}]/u', $title ) === 1 )
				return 'format:title';

			if( mb_strlen( $title, 'UTF-8' ) > self::MAX_TITLE )
				return 'long:title';

			$values['title'] = $title;
		}
		else
			$values['title'] = '';

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
		// An offer is valid for a time, an invoice is due after one; a credit note returns money and has neither; a document without lines has the date alone
		if( $values['category'] !== 'offer' )
			$values['valid_until'] = null;

		if( $values['category'] !== 'invoice' )
			$values['due_date'] = null;

		if( $plain === true )
			$values['period_from'] = $values['period_to'] = null;

		if( ( $values['period_from'] === null ) !== ( $values['period_to'] === null ) || ( $values['period_from'] !== null && $values['period_to'] !== null && $values['period_from'] > $values['period_to'] ) )
			return 'period';

		$reverse = $input['reverse_charge'] ?? $current['reverse_charge'];

		if( is_bool( $reverse ) === false )
			return 'format:reverse_charge';

		if( $plain === true && $reverse === true )
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

		if( is_array( $lines ) === false || array_is_list( $lines ) === false || ( $plain === true && $lines !== [] ) )
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
	 *	One line: the words and numbers of a line and a quantity. A reverse charge sets the rate to 0
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

		$values = Lines::clean( $appData, $line, $reverse === true ? 'small_business' : $vatMode );

		if( is_string( $values ) === true )
			return $values;

		$qty = Lines::amount( $line['qty'] ?? null, 'qty' );

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
			$pdo->prepare( 'INSERT INTO documents ( category, client_id, parent_id, title, state, date, due_date, valid_until, period_from, period_to, vat_mode, reverse_charge, net, vat, gross, template_key, inputs_json, notes, created, updated )
				VALUES ( :category, :client_id, :parent_id, :title, :state, :date, :due_date, :valid_until, :period_from, :period_to, :vat_mode, :reverse_charge, :net, :vat, :gross, :template_key, :inputs_json, :notes, :now, :now )' )
				->execute( [ 'category' => $values['category'], 'vat_mode' => $values['vat_mode'], 'state' => $state, 'now' => $now ] + self::columns( $values ) );

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
			'client_id' => $values['client_id'], 'parent_id' => $values['parent_id'], 'title' => $values['title'], 'date' => $values['date'], 'due_date' => $values['due_date'], 'valid_until' => $values['valid_until'],
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
		$row['released'] = (int) $row['released'] === 1;
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
