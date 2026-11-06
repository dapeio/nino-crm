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
 *											  long:<field>, format:<field>   notes, inputs, template_key, buyer_reference; for the documents without lines also title, and lines
 *											  empty:title             a document without lines has no title
 *											  reverse                 a reverse charge where the document has VAT mode small_business
 *											  lines, total            more than MAX_LINES lines; a net total past Money::MAX_TOTAL
 *											  line:<i>:<kind>:<field> a line (counted from 0) whose field is wrong, see Lines::clean() and qty; no field for a line that is no array
 *											  unready                 at finalise of an offer, an invoice or a credit note: the client has no company or name, or no street, postal code, city or country yet (Clients::invoiceReady())
 *											  pdf, template, inputs:<name>, empty    at finalise: Pdf is off; no template of the tag; a required input is
 *											                          missing; the document has no line
 *											  number                  the number format is none or the number is taken
 *											  render, exists:<path>, write   the PDF could not be made, is there already (at that path), could not be written; exists:<path> and write are also what the electronic invoice (XRechnung) is refused with, its path ending in .xml
 *											  address, limit          at release: the site has no address to link to; the cap of mails an hour was reached (nothing changed)
 *											  pending, days, field, account, sign   at requestSignature: a request is waiting; the days are none; the template has no signature
 *											                          block for a member (Sign says so); the account is none of the client's; Sign, Pdf or Members is off
 *											  sign:<code>             Sign refused with template, input, render or write
 *											  released, mail          at remind(): the invoice is not released to the portal; at remindSignature(): the member was not mailed
 *											Finalising an invoice or a credit note of a business client also writes its electronic invoice beside the PDF, where XRechnung::lacks() says nothing is
 *											missing; otherwise the document keeps the list of what was missing (xml_lacks) and is finalised without a file. A finalised document never gets one later.
 *											A database failure is a \PDOException the caller answers. Beside the steps there are readings for the surface: overdue(), summary(), needs()
 *											(what needs the owner), recent(), order() (the customer orders an offer in the portal: the record of Records and the step to accepted in one transaction), and two things made of a document: copy() (a draft of it) and preview() (the PDF of a draft, stored nowhere).
 */
namespace Crm;

use Crm\Documents\Lines;
use Crm\Documents\Mail;
use Crm\Documents\Money;
use Crm\Documents\Numbers;
use Crm\Documents\Render;
use Crm\Documents\XRechnung;

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

	// How many days an order of the portal that no invoice has followed stays among the things that need the owner
	public const int ORDER_DAYS = 14;

	// The fields of the client that are frozen into a finalised document: business is frozen as 1 or 0, the others as text. The electronic invoice
	// reads the frozen client, so what it says is what the client was when the document was finalised
	private const array CLIENT_FIELDS = [ 'company', 'name', 'street', 'zip', 'city', 'country', 'number', 'vat_id', 'email', 'business', 'buyer_reference' ];

	public const int MAX_REFERENCE = 100;

	// The categories that have a buyer reference of their own: those that can be an electronic invoice
	public const array REFERENCING = [ 'invoice', 'credit' ];

	/**
	 *	The documents, the newest first
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$filter				category, state (or expired), year, client_id, project (the id of a project)
	 *	@param		bool			$sync					Whether the state of the requests waiting in Sign is read first; a caller that needs no state leaves it out
	 *
	 *	@return 	array											Each with its client's title as the document shows it, and whether it is expired (an offer) or overdue (an invoice)
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

		if( is_string( $filter['project'] ?? null ) === true && $filter['project'] !== '' ) {
			$where[] = 'd.project = :project';
			$params['project'] = $filter['project'];
		}

		$titles = Projekte::titles( $appData );

		$rows = Db::rows( $appData, 'SELECT d.id, d.category, d.number, d.title, d.state, d.date, d.due_date, d.valid_until, d.gross, d.client_id, d.parent_id, d.project, d.client_json, d.released, d.sign_id, d.sign_state, c.company AS live_company, c.name AS live_name, c.email AS live_email
			FROM documents d JOIN clients c ON c.id = d.client_id'. ( $where === [] ? '' : ' WHERE '. implode( ' AND ', $where ) ). ' ORDER BY d.date DESC, d.id DESC', $params );

		return array_map( static function( array $row ) use ( $today, $titles ): array {

			$frozen = json_decode( (string) $row['client_json'], true );
			$frozen = is_array( $frozen ) === true ? $frozen : [];
			$shown = $row['state'] === 'draft' ? [ 'company' => $row['live_company'], 'name' => $row['live_name'], 'email' => $row['live_email'] ] : $frozen;

			return [
				'id'           => (int) $row['id'],
				'category'     => $row['category'],
				'number'       => (string) ( $row['number'] ?? '' ),
				'title'        => (string) $row['title'],
				'state'        => $row['state'],
				'released'     => (int) $row['released'] === 1,
				'sign_state'   => (string) $row['sign_state'],
				'expired'      => self::expired( $row, $today ),
				'overdue'      => self::overdue( $row, $today ),
				'date'         => $row['date'],
				'due_date'     => $row['due_date'],
				'valid_until'  => $row['valid_until'],
				'gross'        => (int) $row['gross'],
				'client_id'    => (int) $row['client_id'],
				'client_title' => Clients::title( $shown ),
				'parent_id'    => $row['parent_id'] === null ? null : (int) $row['parent_id'],
				'project'      => (string) $row['project'],
				'project_title' => $titles[(string) $row['project']] ?? '',
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
		$document['project_title'] = $document['project'] === '' ? '' : (string) ( Projekte::get( $appData, $document['project'] )['title'] ?? '' );
		$document['lines'] = array_map( [ self::class, 'shapeLine' ], Db::rows( $appData, 'SELECT * FROM document_lines WHERE document_id = :id ORDER BY sort, id', [ 'id' => $id ] ) );
		$document['client'] = $document['state'] === 'draft' ? Clients::get( $appData, $document['client_id'] ) : $document['client_frozen'];
		$parent = $document['parent_id'] === null ? null : Db::row( $appData, 'SELECT id, number, title, category, state, date FROM documents WHERE id = :id', [ 'id' => $document['parent_id'] ] );
		$document['parent'] = $parent === null ? null : [ 'id' => (int) $parent['id'], 'number' => (string) ( $parent['number'] ?? '' ), 'date' => (string) $parent['date'], 'title' => (string) $parent['title'], 'category' => $parent['category'], 'state' => $parent['state'] ];
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
			'template_key' => '', 'inputs' => [], 'period_from' => null, 'period_to' => null, 'due_date' => null, 'valid_until' => null, 'lines' => [], 'project' => '',
			'buyer_reference' => in_array( $category, self::REFERENCING, true ) === true ? $client['buyer_reference'] : '',
		];

		if( is_string( $date ) === true && self::validDate( $date ) === true && in_array( $category, self::PLAIN, true ) === false ) {
			$key = $category === 'offer' ? 'valid_until' : 'due_date';
			$base[$key] = self::dayAfter( $appData, $date, $category === 'offer' ? 'offer_days' : 'payment_days' );
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

		// The reference of the new client takes the place of the old client's - not of one the person typed
		if( isset( $input['client_id'] ) === true && $input['client_id'] !== $current['client_id'] && array_key_exists( 'buyer_reference', $input ) === false && is_array( $current['client'] ) === true
			&& $current['buyer_reference'] === $current['client']['buyer_reference'] && ( $next = Clients::get( $appData, $input['client_id'] ) ) !== null )
			$input['buyer_reference'] = $next['buyer_reference'];

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
				reverse_charge = :reverse_charge, net = :net, vat = :vat, gross = :gross, template_key = :template_key, inputs_json = :inputs_json, notes = :notes, project = :project, buyer_reference = :buyer_reference, updated = :now WHERE id = :id' )
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
	 *	The project of a document of any state, a finalised one included: the relation is no part of the finalised content - the PDF and its hash do not
	 *	read it - and so it may be moved
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		string		$project			The id of a project of the document's client; '' for none
	 *
	 *	@return 	array|string							The document as get() has it; or unknown, format:project, project (there is no such project), project_client (it is another client's)
	 */
	public static function setProject( array &$appData, int $id, string $project ): array|string {

		$document = Db::row( $appData, 'SELECT client_id FROM documents WHERE id = :id', [ 'id' => $id ] );

		if( $document === null )
			return 'unknown';

		if( $project !== '' && preg_match( Projekte::ID, $project ) !== 1 )
			return 'format:project';

		$refused = self::projectError( $appData, $project, (int) $document['client_id'] );

		if( $refused !== null )
			return $refused;

		Db::exec( $appData, 'UPDATE documents SET project = :project, updated = :now WHERE id = :id', [ 'project' => $project, 'now' => gmdate( self::TIME ), 'id' => $id ] );

		return (array) self::get( $appData, $id );
	}

	/**
	 *	Why a project is not one a document of the client may name
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$project
	 *	@param		int				$clientId
	 *
	 *	@return 	string|null								null where it is none or the project exists and is the client's; project (it does not exist); project_client (it is another client's)
	 */
	private static function projectError( array &$appData, string $project, int $clientId ): ?string {

		if( $project === '' )
			return null;

		$found = Projekte::get( $appData, $project );

		if( $found === null )
			return 'project';

		$client = Clients::get( $appData, $clientId );

		return $client !== null && $client['number'] === $found['client'] ? null : 'project_client';
	}

	/**
	 *	The project a document made from another keeps: its source's, where that is still one of the client's - a project that is gone or
	 *	moved on takes nothing with it, and the new document has none
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$project
	 *	@param		int				$clientId
	 *
	 *	@return 	string
	 */
	private static function projectKept( array &$appData, string $project, int $clientId ): string {

		return self::projectError( $appData, $project, $clientId ) === null ? $project : '';
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
		$written = [];
		$pdo->exec( 'BEGIN IMMEDIATE' );

		try {
			$outcome = self::finaliseIn( $appData, $pdo, $id, $written );
		}
		catch( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			self::discard( $appData, $written );
			throw $e;
		}

		// Most of what finaliseIn() refuses with came before the PDF was written; the files of the one that comes after it (the electronic invoice could not be written) are taken back
		if( is_string( $outcome ) === true ) {
			$pdo->exec( 'ROLLBACK' );
			self::discard( $appData, $written );
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

		Projekte::forget( $appData );

		$pdo = Db::pdo( $appData );
		$pdo->exec( 'BEGIN IMMEDIATE' );

		try {
			// Read and written in one transaction: a step another request took first is not taken twice
			$refused = self::moveIn( $pdo, $id, $state, $date );

			if( $refused !== '' ) {
				$pdo->exec( 'ROLLBACK' );
				return $refused;
			}

			$pdo->exec( 'COMMIT' );
		}
		catch( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			throw $e;
		}

		return (array) self::get( $appData, $id );
	}

	/**
	 *	The customer orders an offer in the portal: the record of the order and the step of the offer to accepted are one transaction, so that an offer is
	 *	never accepted without the record of who accepted it, nor a record kept of an offer that stayed as it was. The offer must be the client's, finalised and
	 *	released to the portal, open or sent and not expired; what the customer was shown - the JSON the page of the offer is drawn from, with the sentence beside
	 *	the box - is kept with its hash, together with the account, the time and the circumstances of the request
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id						The offer
	 *	@param		int				$clientId			The client of the account that orders
	 *	@param		array 		$member				id, email and name of that account
	 *	@param		string		$sentence			The words beside the box, as the customer read them
	 *	@param		array 		$source				ip and agent of the request, see Records::source()
	 *
	 *	@return 	array|string							document (as get() has it, accepted) and record (as Records::of() has it); or why not: unknown (no offer of the client), state (the offer cannot be ordered), long, kind, member, shown (see Records)
	 */
	public static function order( array &$appData, int $id, int $clientId, array $member, string $sentence, array $source ): array|string {

		Projekte::forget( $appData );

		$document = self::get( $appData, $id );

		if( $document === null || $document['client_id'] !== $clientId )
			return 'unknown';

		if( self::orderable( $document ) === false || $document['released'] !== true || $document['pdf_path'] === '' )
			return 'state';

		$shown = Records::orderShown( $appData, $document, $sentence );
		$pdo = Db::pdo( $appData );
		$pdo->exec( 'BEGIN IMMEDIATE' );

		try {
			// Looked at again inside the transaction: an offer that another request ordered, took back or let run out meanwhile is not ordered twice
			$statement = $pdo->prepare( 'SELECT category, state, released, valid_until FROM documents WHERE id = :id AND client_id = :client' );
			$statement->execute( [ 'id' => $id, 'client' => $clientId ] );
			$row = $statement->fetch();

			if( $row === false || (int) $row['released'] !== 1 || self::orderable( $row ) === false ) {
				$pdo->exec( 'ROLLBACK' );
				return $row === false ? 'unknown' : 'state';
			}

			$record = Records::insert( $appData, $pdo, [
				'kind' => 'order', 'client_id' => $clientId, 'member' => $member, 'document_id' => $id, 'project' => $document['project'], 'shown' => $shown,
				'ip' => (string) ( $source['ip'] ?? '' ), 'agent' => (string) ( $source['agent'] ?? '' ),
			] );
			$refused = is_string( $record ) === true ? $record : self::moveIn( $pdo, $id, 'accepted', date( 'Y-m-d' ) );

			if( $refused !== '' ) {
				$pdo->exec( 'ROLLBACK' );
				return $refused;
			}

			$pdo->exec( 'COMMIT' );
		}
		catch( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			throw $e;
		}

		return [ 'document' => (array) self::get( $appData, $id ), 'record' => (array) $record ];
	}

	/**
	 *	Whether an offer may be ordered: it is an offer, open or sent, and not past its validity day (an offer without one does not run out)
	 *
	 *	@param		array 		$row					category, state and valid_until - or expired, which a document as get() and released() have it carries already
	 *
	 *	@return 	bool
	 */
	public static function orderable( array $row ): bool {

		$expired = is_bool( $row['expired'] ?? null ) === true ? $row['expired'] : self::expired( $row, date( 'Y-m-d' ) );

		return ( $row['category'] ?? '' ) === 'offer' && in_array( $row['state'] ?? '', [ 'open', 'sent' ], true ) === true && $expired === false;
	}

	/**
	 *	One step of setState() on a connection that is in a transaction already
	 *
	 *	@param		\PDO			$pdo
	 *	@param		int				$id
	 *	@param		string		$state
	 *	@param		string|null	$date
	 *
	 *	@return 	string										'' once the document is moved; or why not: unknown, state, date:date
	 */
	private static function moveIn( \PDO $pdo, int $id, string $state, ?string $date ): string {

		$statement = $pdo->prepare( 'SELECT category, state FROM documents WHERE id = :id' );
		$statement->execute( [ 'id' => $id ] );
		$row = $statement->fetch();

		$refused = match( true ) {
			$row === false                                                                           => 'unknown',
			in_array( $state, self::TRANSITIONS[$row['category']][$row['state']] ?? [], true ) === false => 'state',
			$date !== null && self::validDate( $date ) === false                                     => 'date:date',
			default                                                                                  => '',
		};

		if( $refused !== '' )
			return $refused;

		$column = $state === 'sent' ? ', sent_at = :time' : ( $state === 'paid' ? ', paid_at = :time' : '' );
		$params = [ 'id' => $id, 'new' => $state, 'now' => gmdate( self::TIME ) ] + ( $column === '' ? [] : [ 'time' => $date === null ? gmdate( self::TIME ) : $date. 'T00:00:00Z' ] );

		$pdo->prepare( 'UPDATE documents SET state = :new, updated = :now'. $column. ' WHERE id = :id' )->execute( $params );

		return '';
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

		Projekte::forget( $appData );

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
	 *	The books at a glance: the open invoices - open or sent, not paid yet - and what they come to, what of them is overdue and since
	 *	when, the offers still waiting, what was paid this year and the drafts
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											count and gross (the open invoices); overdue_count, overdue_gross and overdue_oldest (the oldest due date, '' for none); offers_count and offers_gross
	 *																		(open or sent offers); paid_year, paid_count and paid_gross (invoices paid in the year now); drafts_count and drafts_last (the day of the newest draft, '' for none)
	 */
	public static function summary( array &$appData ): array {

		$today = date( 'Y-m-d' );
		$open = Db::row( $appData, 'SELECT COUNT(*) AS n, COALESCE( SUM( gross ), 0 ) AS gross FROM documents WHERE category = \'invoice\' AND state IN ( \'open\', \'sent\' )' );
		$late = Db::row( $appData, 'SELECT COUNT(*) AS n, COALESCE( SUM( gross ), 0 ) AS gross, MIN( due_date ) AS oldest FROM documents WHERE category = \'invoice\' AND state IN ( \'open\', \'sent\' ) AND due_date IS NOT NULL AND due_date < :today', [ 'today' => $today ] );
		$offers = Db::row( $appData, 'SELECT COUNT(*) AS n, COALESCE( SUM( gross ), 0 ) AS gross FROM documents WHERE category = \'offer\' AND state IN ( \'open\', \'sent\' )' );
		$year = date( 'Y' );
		$paid = Db::row( $appData, 'SELECT COUNT(*) AS n, COALESCE( SUM( gross ), 0 ) AS gross FROM documents WHERE category = \'invoice\' AND state = \'paid\' AND SUBSTR( paid_at, 1, 4 ) = :year', [ 'year' => $year ] );
		$drafts = Db::row( $appData, 'SELECT COUNT(*) AS n, MAX( date ) AS last FROM documents WHERE state = \'draft\'' );

		return [
			'count'          => (int) ( $open['n'] ?? 0 ),
			'gross'          => (int) ( $open['gross'] ?? 0 ),
			'overdue_count'  => (int) ( $late['n'] ?? 0 ),
			'overdue_gross'  => (int) ( $late['gross'] ?? 0 ),
			'overdue_oldest' => (string) ( $late['oldest'] ?? '' ),
			'offers_count'   => (int) ( $offers['n'] ?? 0 ),
			'offers_gross'   => (int) ( $offers['gross'] ?? 0 ),
			'paid_year'      => (int) $year,
			'paid_count'     => (int) ( $paid['n'] ?? 0 ),
			'paid_gross'     => (int) ( $paid['gross'] ?? 0 ),
			'drafts_count'   => (int) ( $drafts['n'] ?? 0 ),
			'drafts_last'    => (string) ( $drafts['last'] ?? '' ),
		];
	}

	/**
	 *	What needs the owner: the invoices that are overdue (the oldest due first), the orders of the portal of the last ORDER_DAYS days that no invoice has followed (one
	 *	entry, kind orders, with how many and the sum), the offers that run out within the next seven days, the contracts and privacy documents whose signature was
	 *	looked at and not given or ran out of time, and the active clients that have no account
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											A list in that order; each has kind (overdue, orders, expiring, sign, account), id (the document, 0 for a client), client_id, client_title, number, title,
	 *																		date (the due date, the day the offer runs out, the day the state last changed, ''), gross and sign_state ('' unless it is a signature)
	 */
	public static function needs( array &$appData ): array {

		self::syncSign( $appData );

		$today = date( 'Y-m-d' );
		$until = self::addDays( $today, 7 );
		$rows = Db::rows( $appData, 'SELECT d.id, d.category, d.number, d.title, d.state, d.date, d.due_date, d.valid_until, d.gross, d.client_id, d.client_json, d.sign_state, d.updated
			FROM documents d WHERE d.state IN ( \'open\', \'sent\' ) ORDER BY d.date, d.id' );
		$groups = [ 'overdue' => [], 'expiring' => [], 'sign' => [] ];

		foreach( $rows as $row ) {

			$frozen = json_decode( (string) $row['client_json'], true );
			$frozen = is_array( $frozen ) === true ? $frozen : [];
			$entry = [
				'kind' => '', 'id' => (int) $row['id'], 'client_id' => (int) $row['client_id'], 'client_title' => Clients::title( $frozen ), 'number' => (string) ( $row['number'] ?? '' ),
				'title' => (string) $row['title'], 'date' => '', 'gross' => (int) $row['gross'], 'sign_state' => '',
			];

			if( self::overdue( $row, $today ) === true )
				$groups['overdue'][] = [ 'kind' => 'overdue', 'date' => (string) $row['due_date'] ] + $entry;
			elseif( $row['category'] === 'offer' && is_string( $row['valid_until'] ) === true && $row['valid_until'] >= $today && $row['valid_until'] <= $until )
				$groups['expiring'][] = [ 'kind' => 'expiring', 'date' => $row['valid_until'] ] + $entry;
			elseif( in_array( $row['category'], self::SIGNABLE, true ) === true && in_array( $row['sign_state'], [ 'viewed', 'expired' ], true ) === true )
				$groups['sign'][] = [ 'kind' => 'sign', 'date' => substr( (string) $row['updated'], 0, 10 ), 'sign_state' => (string) $row['sign_state'] ] + $entry;
		}

		// The oldest due date first: it has waited longest
		usort( $groups['overdue'], static fn( array $a, array $b ): int => [ $a['date'], $a['id'] ] <=> [ $b['date'], $b['id'] ] );
		usort( $groups['expiring'], static fn( array $a, array $b ): int => [ $a['date'], $a['id'] ] <=> [ $b['date'], $b['id'] ] );

		$accounts = [];

		foreach( Clients::list( $appData ) as $client )
			if( $client['active'] === true && $client['accounts'] === 0 )
				$accounts[] = [ 'kind' => 'account', 'id' => 0, 'client_id' => $client['id'], 'client_title' => $client['title'], 'number' => (string) $client['number'], 'title' => '', 'date' => '', 'gross' => 0, 'sign_state' => '' ];

		// The orders of the portal of the last ORDER_DAYS days that no invoice has followed: one line with how many and what they come to
		$orders = Db::row( $appData, 'SELECT COUNT( DISTINCT d.id ) AS n, COALESCE( SUM( d.gross ), 0 ) AS gross FROM records r JOIN documents d ON d.id = r.document_id
			WHERE r.kind = \'order\' AND d.state = \'accepted\' AND r.at >= :since AND NOT EXISTS ( SELECT 1 FROM documents c WHERE c.parent_id = d.id AND c.category = \'invoice\' )', [ 'since' => gmdate( self::TIME, time() - self::ORDER_DAYS * 86400 ) ] );
		$ordered = (int) ( $orders['n'] ?? 0 ) === 0 ? [] : [ [ 'kind' => 'orders', 'id' => 0, 'client_id' => 0, 'client_title' => '', 'number' => '', 'title' => '', 'date' => '', 'gross' => (int) $orders['gross'], 'sign_state' => '', 'count' => (int) $orders['n'] ] ];

		return array_merge( $groups['overdue'], $ordered, $groups['sign'], $groups['expiring'], $accounts );
	}

	/**
	 *	The documents of the latest dates, drafts among them
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$limit
	 *
	 *	@return 	array											As list() has them, the newest first
	 */
	public static function recent( array &$appData, int $limit = 8 ): array {

		return array_slice( self::list( $appData, [], false ), 0, max( 0, $limit ) );
	}

	/**
	 *	A draft from a document of any state: the same kind and client, the lines (the rates held to the VAT mode of the setting now, as an invoice from an offer is),
	 *	the period and the notes left out, today as the date and the due or valid day the setting gives; the template and its inputs stay where the template is still there.
	 *	A contract hangs on the offer of the original. Nothing links the copy to the original
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *
	 *	@return 	array|string							The draft as get() has it; or why not, see add() - and line:<i>:rate:vat_rate for a rate the setting does not allow
	 */
	public static function copy( array &$appData, int $id ): array|string {

		$source = self::get( $appData, $id );

		if( $source === null )
			return 'unknown';

		$tag = Render::tag( $source['category'] );
		$keeps = $source['template_key'] !== '' && Crm::featureOn( $appData, 'pdf' ) === true && isset( \Nino\Modules\Pdf::templates( $appData, $tag )[$source['template_key']] ) === true;
		$input = [ 'template_key' => $keeps === true ? $source['template_key'] : '', 'inputs' => $keeps === true ? $source['inputs'] : [], 'project' => self::projectKept( $appData, (string) $source['project'], $source['client_id'] ) ];

		if( in_array( $source['category'], self::PLAIN, true ) === true )
			return self::add( $appData, $source['category'], $source['category'] === 'contract' ? 0 : $source['client_id'], $input + [ 'title' => $source['title'] ] + ( $source['category'] === 'contract' ? [ 'parent_id' => $source['parent_id'] ] : [] ) );

		$vatMode = Settings::get( $appData, 'vat_mode' );
		$reverse = $source['reverse_charge'] === true && $vatMode === 'regular';
		$lines = [];

		foreach( $source['lines'] as $line ) {

			// A rate the setting does not list is refused by add(), as line:<i>:rate:vat_rate
			$rate = $vatMode === 'small_business' || $reverse === true ? 0 : $line['vat_rate'];
			$lines[] = [ 'title' => $line['title'], 'description' => $line['description'], 'unit' => $line['unit'], 'unit_code' => $line['unit_code'], 'qty' => $line['qty'], 'price' => $line['price_net'], 'vat_rate' => $rate ];
		}

		return self::add( $appData, $source['category'], $source['client_id'], $input + [ 'lines' => $lines, 'reverse_charge' => $reverse, 'buyer_reference' => $source['buyer_reference'] ] );
	}

	/**
	 *	Whether an invoice may be reminded: it is an invoice that is open or sent - with a due date or without one, late or not yet: the owner decides when
	 *
	 *	@param		array 		$row					category and state
	 *
	 *	@return 	bool
	 */
	public static function remindable( array $row ): bool {

		return ( $row['category'] ?? '' ) === 'invoice' && in_array( $row['state'] ?? '', [ 'open', 'sent' ], true );
	}

	/**
	 *	A reminder to pay: the release mail again, in the words of a reminder, to each active account of the client of an invoice that is
	 *	open or sent, whether it is overdue or not, and released to the portal. The reminder is noted on the invoice (reminded_at, and whether it went by mail) - with the mail or
	 *	without it, so that the owner who phoned notes it as well; without the mail nothing is sent. Nothing else changes. The activity log names the number
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		bool			$mail					Whether the accounts of the client are mailed
	 *
	 *	@return 	array|string							document (as get() has it) and mailed (how many mails were delivered); or unknown; state (it is no invoice that is open or sent);
	 *																		released (the client could not find it in the portal); address, limit as release() has them
	 */
	public static function remind( array &$appData, int $id, bool $mail = true ): array|string {

		$document = self::get( $appData, $id );

		if( $document === null )
			return 'unknown';

		if( self::remindable( $document ) === false )
			return 'state';

		if( $document['released'] === false )
			return 'released';

		$sent = $mail === true ? Mail::remind( $appData, $document ) : 0;

		if( is_string( $sent ) === true )
			return $sent;

		Db::exec( $appData, 'UPDATE documents SET reminded_at = :now, reminded_mail = :mail WHERE id = :id', [ 'now' => gmdate( self::TIME ), 'mail' => $mail === true ? 1 : 0, 'id' => $id ] );

		return [ 'document' => (array) self::get( $appData, $id ), 'mailed' => $sent ];
	}

	/**
	 *	The PDF of a draft as its template would make it now: nothing is stored, no number is taken
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *
	 *	@return 	array|string							bytes (the PDF) and name (the file name without the extension); or unknown; state (it is no draft: its PDF is the stored one); pdf, template, inputs:<name>, render as finalise() has them
	 */
	public static function preview( array &$appData, int $id ): array|string {

		$document = self::get( $appData, $id );

		if( $document === null )
			return 'unknown';

		if( $document['state'] !== 'draft' )
			return 'state';

		if( Crm::featureOn( $appData, 'pdf' ) === false )
			return 'pdf';

		if( $document['template_key'] === '' || isset( \Nino\Modules\Pdf::templates( $appData, Render::tag( $document['category'] ) )[$document['template_key']] ) === false )
			return 'template';

		foreach( \Nino\Modules\Pdf::inputs( $appData, $document['template_key'] ) as $input )
			if( $input['required'] === true && trim( (string) ( $document['inputs'][$input['name']] ?? '' ) ) === '' )
				return 'inputs:'. $input['name'];

		$client = Clients::get( $appData, $document['client_id'] );

		if( $client === null )
			return 'unknown';

		$frozen = self::freeze( $client );
		$plain = in_array( $document['category'], self::PLAIN, true );

		if( $plain === true )
			$document['offer'] = self::offerOf( $appData, $document );

		$totals = Money::totals( $document['lines'], $document['vat_mode'], $document['reverse_charge'] );
		$lines = $plain === true ? [] : array_map( static fn( array $line, int $amount ): array => [ 'amount_net' => $amount ] + $line, $document['lines'], $totals['amounts'] );
		$data = Render::data( $appData, $document, $lines, $frozen );
		$bytes = \Nino\Modules\Pdf::render( $appData, $document['template_key'], $data, [
			'title' => $plain === true ? $document['title'] : Render::text( 'title.'. $document['category'] ),
			'date'  => $document['date'],
		] );

		return $bytes === null ? 'render' : [ 'bytes' => $bytes, 'name' => Render::name( $document ) ];
	}

	/**
	 *	Release a finalised document to the portal: its client sees it on the page Dokumente. With $mail one mail goes to each active
	 *	account of the client (Documents\Mail), in one call of the kernel's cap on mails; an offer, an invoice or a credit note that is
	 *	still open is sent by it, when at least one mail was delivered. A release again keeps the day of the first. Nothing changes
	 *	where the mail is refused (address, limit).
	 *
	 *	With $download the release of an invoice of a project also switches the download of the project's files on, in the same step: the state of the project is written
	 *	in the transaction of the release, so that a release that fails leaves the project as it was, and the mail names the files. Any other document, and an invoice
	 *	without a project, is refused with 'download'
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		bool			$mail
	 *	@param		bool			$download			Switch the download of the files of the project on
	 *	@param		string		$by						The account of the workbench that asks, kept with the switch
	 *
	 *	@return 	array|string							document (as get() has it), mailed (how many mails were delivered) and download (whether it was switched on); or why not
	 */
	public static function release( array &$appData, int $id, bool $mail, bool $download = false, string $by = '' ): array|string {

		Projekte::forget( $appData );

		$document = self::get( $appData, $id );

		if( $document === null )
			return 'unknown';

		if( $document['state'] === 'draft' || $document['pdf_path'] === '' )
			return 'state';

		if( $download === true && ( $document['category'] !== 'invoice' || Projekte::get( $appData, $document['project'] ) === null ) )
			return 'download';

		$mailed = 0;

		if( $mail === true ) {

			$sent = Mail::release( $appData, $document, $download );

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

			// The files of the project are opened with the invoice, in the same transaction
			if( $download === true && is_string( Projekte::setDownload( $appData, $document['project'], true, $by ) ) === true ) {
				$pdo->exec( 'ROLLBACK' );
				return 'download';
			}

			$pdo->exec( 'COMMIT' );
		}
		catch( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			throw $e;
		}

		return [ 'document' => (array) self::get( $appData, $id ), 'mailed' => $mailed, 'download' => $download ];
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

		Projekte::forget( $appData );

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
	 *	@param		bool			$notify				Whether Sign mails the account; with false the request is made all the same and the answer carries its link, once
	 *
	 *	@return 	array|string							document (as get() has it) and mailed (whether Sign delivered its mail) and, with $notify false, link; or why not
	 */
	public static function requestSignature( array &$appData, int $id, string $accountId, ?int $days = null, bool $notify = true ): array|string {

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

		$options = [ 'title' => $document['title'], 'page' => '/portal/belege' ] + ( $days === null ? [] : [ 'days' => $days ] ) + ( $notify === false ? [ 'notify' => false ] : [] );
		$request = \Nino\Modules\Sign::request( $appData, $document['template_key'], self::dataOf( $appData, $document ), $accountId, $options );

		if( is_string( $request ) === true )
			return match( $request ) { 'member' => 'field', 'account' => 'account', 'ratelimit' => 'limit', default => 'sign:'. $request };

		// Two requests made at once: the one that wrote its id first is the request of the document, the other is taken back
		$claimed = Db::exec( $appData, 'UPDATE documents SET sign_id = :sign, sign_state = \'requested\', updated = :now WHERE id = :id AND state IN ( \'open\', \'sent\' ) AND sign_state NOT IN ( \'requested\', \'viewed\' )',
			[ 'sign' => $request['id'], 'now' => gmdate( self::TIME ), 'id' => $id ] );

		if( $claimed === 0 ) {
			\Nino\Modules\Sign::cancel( $appData, $request['id'] );
			return 'pending';
		}

		return [ 'document' => (array) self::get( $appData, $id ), 'mailed' => $request['mailed'] === true ] + ( $notify === false ? [ 'link' => (string) ( $request['link'] ?? '' ) ] : [] );
	}

	/**
	 *	Send the link of the signature request of a contract or a privacy document again, with a new token (Sign's remind): the request is waiting or ran out of time
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		bool			$notify				Whether Sign mails the account; with false the request gets its new token all the same - the old link is dead - and the document carries the new link as link, once
	 *
	 *	@return 	array|string							The document as get() has it, and with $notify false link; or unknown; category; state (no request is waiting); sign (Sign is off); mail (the member was not mailed, the link is as it was)
	 */
	public static function remindSignature( array &$appData, int $id, bool $notify = true ): array|string {

		$document = self::get( $appData, $id );

		if( $document === null )
			return 'unknown';

		if( in_array( $document['category'], self::SIGNABLE, true ) === false )
			return 'category';

		if( in_array( $document['state'], [ 'open', 'sent' ], true ) === false || $document['sign_id'] === '' || in_array( $document['sign_state'], [ 'requested', 'viewed', 'expired' ], true ) === false )
			return 'state';

		if( Crm::featureOn( $appData, 'sign' ) === false )
			return 'sign';

		$reminded = \Nino\Modules\Sign::remind( $appData, $document['sign_id'], $notify );

		if( $reminded === false )
			return \Nino\Mail::rateLimited( $appData ) === true ? 'limit' : 'mail';

		return (array) self::get( $appData, $id ) + ( $notify === false ? [ 'link' => (string) $reminded ] : [] );
	}

	/**
	 *	Take the signature request of a contract or a privacy document back (Sign's cancel): the link is dead, the document can ask again
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *
	 *	@return 	array|string							The document as get() has it; or unknown; category; state (no request is waiting); sign (Sign is off)
	 */
	public static function cancelSignature( array &$appData, int $id ): array|string {

		$document = self::get( $appData, $id );

		if( $document === null )
			return 'unknown';

		if( in_array( $document['category'], self::SIGNABLE, true ) === false )
			return 'category';

		if( in_array( $document['state'], [ 'open', 'sent' ], true ) === false || $document['sign_id'] === '' || in_array( $document['sign_state'], self::PENDING, true ) === false )
			return 'state';

		if( Crm::featureOn( $appData, 'sign' ) === false )
			return 'sign';

		if( \Nino\Modules\Sign::cancel( $appData, $document['sign_id'] ) === false )
			return 'state';

		return (array) self::get( $appData, $id );
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
	 *	@param		string		$project			Only the documents of this project ('' for all of them)
	 *
	 *	@return 	array											id, category, number, title, state, expired, overdue, date, due_date, valid_until, gross, sign_id, sign_state, project, project_title, released_at, xml (whether there is an electronic invoice to download)
	 */
	public static function released( array &$appData, int $clientId, string $project = '' ): array {

		self::syncSign( $appData, 'client_id = :client', [ 'client' => $clientId ] );

		$today = date( 'Y-m-d' );
		$params = [ 'client' => $clientId ];

		if( $project !== '' )
			$params['project'] = $project;

		$rows = Db::rows( $appData, 'SELECT id, category, number, title, state, date, due_date, valid_until, gross, sign_id, sign_state, project, released_at, xml_path FROM documents
			WHERE client_id = :client AND released = 1 AND state != \'draft\' AND pdf_path != \'\''. ( $project !== '' ? ' AND project = :project' : '' ). ' ORDER BY date DESC, id DESC', $params );
		$titles = array_filter( $rows, static fn( array $row ): bool => (string) $row['project'] !== '' ) === [] ? [] : Projekte::titles( $appData );

		return array_map( static fn( array $row ): array => [
			'id' => (int) $row['id'], 'category' => (string) $row['category'], 'number' => (string) ( $row['number'] ?? '' ), 'title' => (string) $row['title'], 'state' => (string) $row['state'],
			'expired' => self::expired( $row, $today ), 'overdue' => self::overdue( $row, $today ), 'date' => (string) $row['date'], 'due_date' => (string) ( $row['due_date'] ?? '' ), 'valid_until' => (string) ( $row['valid_until'] ?? '' ),
			'gross' => (int) $row['gross'], 'sign_id' => (string) $row['sign_id'], 'sign_state' => (string) $row['sign_state'],
			'project' => (string) $row['project'], 'project_title' => (string) ( $titles[(string) $row['project']] ?? '' ), 'released_at' => (string) ( $row['released_at'] ?? '' ),
			'xml' => (string) $row['xml_path'] !== '',
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
	 *	Whether an invoice is open or sent and past its due date: a way to look at it and no state
	 *
	 *	@param		array 		$row					category, state, due_date
	 *	@param		string|null	$today				Y-m-d; today where none is given
	 *
	 *	@return 	bool
	 */
	public static function overdue( array $row, ?string $today = null ): bool {

		return $row['category'] === 'invoice' && in_array( $row['state'], [ 'open', 'sent' ], true ) === true && is_string( $row['due_date'] ?? null ) === true && $row['due_date'] < ( $today ?? date( 'Y-m-d' ) );
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
	 *	The day a setting of days after a date comes to: the prefill of a due date and of a validity day. A setting of 0 days is none, and the document has no such day
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$date					Y-m-d
	 *	@param		string		$setting			payment_days or offer_days
	 *
	 *	@return 	string|null								Y-m-d; null where the setting is 0
	 */
	private static function dayAfter( array &$appData, string $date, string $setting ): ?string {

		$days = (int) Settings::get( $appData, $setting );

		return $days === 0 ? null : self::addDays( $date, $days );
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

		Projekte::forget( $appData );

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
	 *	@param		array 		&$written			The virtual paths of the files this call wrote (the PDF, the electronic invoice), for the caller to take back
	 *
	 *	@return 	true|string
	 */
	private static function finaliseIn( array &$appData, \PDO $pdo, int $id, array &$written ): true|string {

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

		// An offer, an invoice and a credit note name the client with its address: a client that has not given it yet cannot be written one
		if( $plain === false && $client['ready'] === false )
			return 'unready';

		if( Crm::featureOn( $appData, 'pdf' ) === false )
			return 'pdf';

		$tag = Render::tag( $document['category'] );

		if( $document['template_key'] === '' || isset( \Nino\Modules\Pdf::templates( $appData, $tag )[$document['template_key']] ) === false )
			return 'template';

		foreach( \Nino\Modules\Pdf::inputs( $appData, $document['template_key'] ) as $input )
			if( $input['required'] === true && trim( (string) ( $document['inputs'][$input['name']] ?? '' ) ) === '' )
				return 'inputs:'. $input['name'];

		$frozen = self::freeze( $client );
		$now = gmdate( self::TIME );

		// A document without lines has no number and no totals: the PDF is named by its category and id, and a contract quotes its offer
		if( $plain === true ) {

			$document['offer'] = self::offerOf( $appData, $document );

			if( $document['category'] === 'contract' && $document['offer'] === null )
				return 'offer';

			$made = Render::make( $appData, [ 'state' => 'open' ] + $document, [], $frozen );

			if( is_string( $made ) === true )
				return $made;

			$written[] = $made['path'];

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

		$written[] = $made['path'];
		$electronic = self::electronic( $appData, $document, $lines, $frozen, $made['path'], $written );

		if( is_string( $electronic ) === true )
			return $electronic;

		$pdo->prepare( 'UPDATE documents SET number = :number, state = \'open\', net = :net, vat = :vat, gross = :gross, client_json = :client, finalised = :now, updated = :now, pdf_path = :path, pdf_sha256 = :sha,
			xml_path = :xml_path, xml_sha256 = :xml_sha, xml_lacks = :xml_lacks WHERE id = :id AND state = \'draft\'' )
			->execute( [ 'number' => $number, 'net' => $totals['net'], 'vat' => $totals['vat'], 'gross' => $totals['gross'], 'client' => json_encode( $frozen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ),
				'now' => $now, 'path' => $made['path'], 'sha' => $made['sha256'], 'id' => $id ] + $electronic );

		// A credit note that takes the whole of an invoice back cancels it; one that takes a part leaves its state
		if( $parent !== null && (int) $parent['gross'] === -$totals['gross'] )
			$pdo->prepare( 'UPDATE documents SET state = \'cancelled\', updated = :now WHERE id = :id' )->execute( [ 'now' => $now, 'id' => $document['parent']['id'] ] );

		return true;
	}

	/**
	 *	The electronic invoice of a document that is being finalised: written beside its PDF where the document is an invoice or a credit of a business and nothing is
	 *	missing, otherwise only the list of what is missing is kept. Nothing is made up and no document is held back: it is finalised either way
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			With its number and totals; its lines as get() has them
	 *	@param		array 		$lines				The lines with their amounts
	 *	@param		array 		$frozen				The client as freeze() has it
	 *	@param		string		$pdfPath			The virtual path of the PDF just written, which names the file
	 *	@param		array 		&$written			Gets the path of the file this call wrote
	 *
	 *	@return 	array|string							The values of xml_path, xml_sha and xml_lacks to store; or exists:<path>, write as XRechnung::make() has them
	 */
	private static function electronic( array &$appData, array $document, array $lines, array $frozen, string $pdfPath, array &$written ): array|string {

		$document['client'] = $frozen;
		$document['lines'] = $lines;

		if( XRechnung::applies( $document ) === false )
			return [ 'xml_path' => '', 'xml_sha' => '', 'xml_lacks' => '' ];

		$lacks = XRechnung::lacks( $appData, $document );

		if( $lacks !== [] )
			return [ 'xml_path' => '', 'xml_sha' => '', 'xml_lacks' => json_encode( $lacks, JSON_THROW_ON_ERROR ) ];

		$made = XRechnung::make( $appData, $document, $pdfPath );

		if( is_string( $made ) === true )
			return $made;

		$written[] = $made['path'];

		return [ 'xml_path' => $made['path'], 'xml_sha' => $made['sha256'], 'xml_lacks' => '[]' ];
	}

	/**
	 *	The client as a finalised document keeps it
	 *
	 *	@param		array 		$client				As Clients::get() has it
	 *
	 *	@return 	array											The fields of CLIENT_FIELDS as text; business is '1' or '0'
	 */
	private static function freeze( array $client ): array {

		$frozen = [];

		foreach( self::CLIENT_FIELDS as $field )
			$frozen[$field] = $field === 'business' ? ( $client[$field] === true ? '1' : '0' ) : (string) $client[$field];

		return $frozen;
	}

	/**
	 *	Take back the files a failed finalise() wrote (the PDF and the electronic invoice)
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$paths				The virtual paths; none for nothing written
	 *
	 *	@return 	void
	 */
	private static function discard( array &$appData, array $paths ): void {

		foreach( $paths as $path ) {

			$file = str_ends_with( $path, '.xml' ) === true ? XRechnung::file( $appData, $path ) : Render::file( $appData, $path );

			if( $file !== null )
				unlink( $file );
		}
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
			'date' => $date, 'due_date' => $category === 'invoice' ? self::dayAfter( $appData, $date, 'payment_days' ) : null, 'valid_until' => null, 'period_from' => $source['period_from'], 'period_to' => $source['period_to'],
			'template_key' => '', 'inputs' => [], 'notes' => '', 'lines' => $lines, 'amounts' => $totals['amounts'], 'project' => self::projectKept( $appData, (string) $source['project'], $source['client_id'] ),
			'buyer_reference' => $category === 'credit' ? $source['buyer_reference'] : $client['buyer_reference'],
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

		// A buyer reference belongs to the documents that can be an electronic invoice; the others keep none
		$reference = $input['buyer_reference'] ?? $current['buyer_reference'] ?? '';

		if( is_string( $reference ) === false || mb_check_encoding( $reference, 'UTF-8' ) === false || preg_match( '/[\p{C}]/u', $reference ) === 1 )
			return 'format:buyer_reference';

		$reference = trim( $reference );

		if( mb_strlen( $reference, 'UTF-8' ) > self::MAX_REFERENCE )
			return 'long:buyer_reference';

		$values['buyer_reference'] = in_array( $values['category'], self::REFERENCING, true ) === true ? $reference : '';

		$project = $input['project'] ?? $current['project'] ?? '';

		if( is_string( $project ) === false || ( $project !== '' && preg_match( Projekte::ID, $project ) !== 1 ) )
			return 'format:project';

		$refused = self::projectError( $appData, $project, (int) $values['client_id'] );

		if( $refused !== null )
			return $refused;

		$values['project'] = $project;

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
			$pdo->prepare( 'INSERT INTO documents ( category, client_id, parent_id, title, state, date, due_date, valid_until, period_from, period_to, vat_mode, reverse_charge, net, vat, gross, template_key, inputs_json, notes, project, buyer_reference, created, updated )
				VALUES ( :category, :client_id, :parent_id, :title, :state, :date, :due_date, :valid_until, :period_from, :period_to, :vat_mode, :reverse_charge, :net, :vat, :gross, :template_key, :inputs_json, :notes, :project, :buyer_reference, :now, :now )' )
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
			'inputs_json' => json_encode( $values['inputs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR ), 'notes' => $values['notes'], 'project' => $values['project'],
			'buyer_reference' => $values['buyer_reference'],
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
		$statement = $pdo->prepare( 'INSERT INTO document_lines ( document_id, sort, title, description, qty, unit, unit_code, price_net, vat_rate, amount_net ) VALUES ( :id, :sort, :title, :description, :qty, :unit, :unit_code, :price_net, :vat_rate, :amount )' );

		foreach( $lines as $position => $line )
			$statement->execute( [ 'id' => $id, 'sort' => $position + 1, 'title' => $line['title'], 'description' => $line['description'], 'qty' => $line['qty'], 'unit' => $line['unit'], 'unit_code' => $line['unit_code'],
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
		$row['reminded_at'] = (string) ( $row['reminded_at'] ?? '' );
		$row['reminded_mail'] = (int) ( $row['reminded_mail'] ?? 0 ) === 1;
		$row['number'] = (string) ( $row['number'] ?? '' );
		$lacks = json_decode( (string) $row['xml_lacks'], true );
		$row['xml_lacks'] = is_array( $lacks ) === true ? array_values( array_filter( $lacks, 'is_string' ) ) : [];
		$row['inputs'] = is_array( $inputs ) === true ? $inputs : [];
		$row['client_frozen'] = is_array( $frozen ) === true ? $frozen : [];
		$row['expired'] = self::expired( $row, date( 'Y-m-d' ) );
		$row['overdue'] = self::overdue( $row, date( 'Y-m-d' ) );
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
