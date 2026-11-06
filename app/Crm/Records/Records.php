<?php
declare(strict_types=1);
/**
 *	Crm\Records		What a customer agreed to in the portal, kept as evidence. A record is one of three kinds - privacy (the customer agreed to the
 *								Datenschutz as it stood), order (the customer ordered an offer) or approval (the customer accepted a project as its files stood) - and holds
 *								the account that agreed (id, address and name as they were), the client, for an order the document and its project, for an approval the
 *								project, the text the customer was shown (the Datenschutz itself; for an order the JSON the page of the offer was drawn from, with the
 *								sentence the box stood beside; for an approval the JSON of the project and its visible files - name, size, SHA-256 and day of each - with
 *								the sentence), the SHA-256 of that text, the time and the address and browser of the request. The module writes a record once and never changes or deletes it: there is no statement for it in this class or anywhere
 *								else, and the table refuses the deletion of a client or a document a record names.
 *
 *								Two kinds stand beside these for what goes beyond the contract: consent (the customer agrees to a purpose: social, the use of the work for
 *								the references and channels of the studio, or archive, the keeping of it beyond the periods) and revocation (the customer takes a consent back;
 *								it names the consent in parent). A consent holds the purpose, the project of the offer it was given with ('' for none) and the sentence of its
 *								box as it stood; an order holds the sentences of the consents that were offered with it. A consent is voluntary and stands apart from the
 *								Datenschutz and from the order, which does not need it; it holds for as long as no revocation names it, a revocation acts for the future and
 *								deletes nothing, and a sentence that changes later changes no consent that was given. The table refuses the deletion of a consent a revocation
 *								names.
 *
 *								A client has agreed to the Datenschutz when a record of kind privacy exists whose hash is the hash of the text the setting privacy_text
 *								holds now. A text that is changed asks again; the old records stay. Where the text is empty the portal asks for nothing.
 *
 *								add() answers the record as of() has it, or a string: 'kind' (not one of KINDS), 'client' (no such client), 'member' (no account),
 *								'document' (an order needs the document it is for; the other kinds have none), 'project' (an approval needs the project it is for),
 *								'purpose' (a consent needs one of PURPOSES), 'parent' (a revocation needs a consent of the same client that no revocation names),
 *								'shown' (nothing was shown). A database failure is a
 *								\PDOException the caller answers.
 */
namespace Crm;

class Records {

	public const array KINDS = [ 'privacy', 'order', 'approval', 'consent', 'revocation' ];

	// The purposes a consent can be for
	public const array PURPOSES = [ 'social', 'archive' ];

	// The most of the browser's name that is kept
	public const int AGENT = 300;

	private const string TIME = 'Y-m-d\TH:i:s\Z';

	/**
	 *	The hash of a shown text: what a record keeps beside the text, and what an agreement is compared by
	 *
	 *	@param		string		$text
	 *
	 *	@return 	string										64 hexadecimal characters
	 */
	public static function hash( string $text ): string {

		return hash( 'sha256', $text );
	}

	/**
	 *	The sentence beside the box of a consent, as the portal shows it now
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$purpose			One of PURPOSES
	 *
	 *	@return 	string										'' where the portal offers no box for the purpose (or the purpose is none)
	 */
	public static function sentence( array &$appData, string $purpose ): string {

		return in_array( $purpose, self::PURPOSES, true ) === true ? Settings::get( $appData, 'consent_'. $purpose ) : '';
	}

	/**
	 *	The text of the Datenschutz as the portal shows it now
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	string										'' where the portal asks for nothing
	 */
	public static function privacyText( array &$appData ): string {

		return Settings::get( $appData, 'privacy_text' );
	}

	/**
	 *	The circumstances of the request that is being answered, as a record keeps them
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$request			The request of the callback
	 *
	 *	@return 	array											ip and agent (the browser's name on one line, cut to AGENT characters)
	 */
	public static function source( array &$appData, array $request ): array {

		$header = $request['/nino/http/request']['header'] ?? [];
		$agent = is_array( $header ) === true && is_string( $header['User-Agent'] ?? null ) === true ? $header['User-Agent'] : '';

		return [
			'ip'    => \Nino\Http::getClientIp( $appData ),
			'agent' => mb_substr( trim( (string) preg_replace( '/[\s\p{C}]+/u', ' ', $agent ) ), 0, self::AGENT, 'UTF-8' ),
		];
	}

	/**
	 *	Keep a record
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$input				kind, client_id, member (id, email, name of the account), shown (the text), document_id and project (for an order, project for an approval; a consent may name the offer it was given with and its project), purpose (a consent), parent (a revocation: the consent; its purpose, project and text are the consent's), ip, agent
	 *
	 *	@return 	array|string							The record; or why not, see the class
	 */
	public static function add( array &$appData, array $input ): array|string {

		return self::insert( $appData, Db::pdo( $appData ), $input );
	}

	/**
	 *	Keep a record on a connection that is in a transaction already (an order: the record and the state of the offer are one step)
	 *
	 *	@param		array 		&$appData
	 *	@param		\PDO			$pdo
	 *	@param		array 		$input				See add()
	 *
	 *	@return 	array|string
	 */
	public static function insert( array &$appData, \PDO $pdo, array $input ): array|string {

		$kind = is_string( $input['kind'] ?? null ) ? $input['kind'] : '';
		$member = is_array( $input['member'] ?? null ) ? $input['member'] : [];
		$shown = is_string( $input['shown'] ?? null ) ? $input['shown'] : '';
		$document = $input['document_id'] ?? null;
		$purpose = $kind === 'consent' && is_string( $input['purpose'] ?? null ) ? $input['purpose'] : '';
		$parent = $input['parent'] ?? null;
		$project = (string) ( $input['project'] ?? '' );

		// A revocation holds what the consent held: its purpose, its project and the sentence of its box
		if( $kind === 'revocation' ) {

			$consent = is_int( $parent ) === true ? self::revocable( $pdo, $parent, (int) ( $input['client_id'] ?? 0 ) ) : null;

			if( $consent === null )
				return 'parent';

			[ $purpose, $project, $shown ] = [ (string) $consent['purpose'], (string) $consent['project'], (string) $consent['shown'] ];
		}

		$refused = match( true ) {
			in_array( $kind, self::KINDS, true ) === false                                   => 'kind',
			is_string( $member['id'] ?? null ) === false || $member['id'] === ''             => 'member',
			$kind === 'consent' && in_array( $purpose, self::PURPOSES, true ) === false     => 'purpose',
			$shown === ''                                                                     => 'shown',
			$kind === 'order' ? is_int( $document ) === false : ( $document !== null && ( $kind !== 'consent' || is_int( $document ) === false ) ) => 'document',
			$kind === 'approval' && preg_match( Projekte::ID, (string) ( $input['project'] ?? '' ) ) !== 1 => 'project',
			default                                                                           => '',
		};

		if( $refused !== '' )
			return $refused;

		$statement = $pdo->prepare( 'SELECT id FROM clients WHERE id = :id' );
		$statement->execute( [ 'id' => (int) ( $input['client_id'] ?? 0 ) ] );

		if( $statement->fetchColumn() === false )
			return 'client';

		if( is_int( $document ) === true ) {

			$statement = $pdo->prepare( 'SELECT client_id FROM documents WHERE id = :id' );
			$statement->execute( [ 'id' => $document ] );
			$owner = $statement->fetchColumn();

			if( $owner === false || (int) $owner !== (int) $input['client_id'] )
				return 'document';
		}

		$values = [
			'kind'         => $kind,
			'client_id'    => (int) $input['client_id'],
			'member_id'    => $member['id'],
			'member_email' => (string) ( $member['email'] ?? '' ),
			'member_name'  => (string) ( $member['name'] ?? '' ),
			'document_id'  => is_int( $document ) === true ? $document : null,
			'project'      => $project,
			'sha256'       => self::hash( $shown ),
			'shown'        => $shown,
			'at'           => gmdate( self::TIME ),
			'ip'           => (string) ( $input['ip'] ?? '' ),
			'agent'        => mb_substr( (string) ( $input['agent'] ?? '' ), 0, self::AGENT, 'UTF-8' ),
			'purpose'      => $purpose,
			'parent'       => $kind === 'revocation' ? $parent : null,
		];

		$pdo->prepare( 'INSERT INTO records ( '. implode( ', ', array_keys( $values ) ). ' ) VALUES ( :'. implode( ', :', array_keys( $values ) ). ' )' )->execute( $values );

		return self::shape( [ 'id' => (int) $pdo->lastInsertId() ] + $values );
	}

	/**
	 *	Take a consent back: the revocation and the look at the consent are one step, so that two clicks on one page keep one revocation
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$clientId
	 *	@param		int				$consent			The id of the record of kind consent
	 *	@param		array 		$member				id, email and name of the account
	 *	@param		array 		$source				ip and agent, see source()
	 *
	 *	@return 	array|string							The record of kind revocation; or why not, see the class ('parent' for a consent that is none of the client's or is revoked already)
	 */
	public static function revoke( array &$appData, int $clientId, int $consent, array $member, array $source ): array|string {

		// BEGIN IMMEDIATE takes the write lock before the consent is read: two posts of one button see the consent one after the other, and the second finds it revoked
		$pdo = Db::pdo( $appData );
		$pdo->exec( 'BEGIN IMMEDIATE' );

		try {
			$record = self::insert( $appData, $pdo, [
				'kind' => 'revocation', 'client_id' => $clientId, 'member' => $member, 'parent' => $consent,
				'ip' => (string) ( $source['ip'] ?? '' ), 'agent' => (string) ( $source['agent'] ?? '' ),
			] );
			$pdo->exec( is_array( $record ) === true ? 'COMMIT' : 'ROLLBACK' );
		}
		catch( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			throw $e;
		}

		return $record;
	}

	/**
	 *	The records of a client, the newest first
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$clientId
	 *	@param		string|null	$kind					Only this kind; null for both
	 *
	 *	@return 	array											id, kind, client_id, member_id, member_email, member_name, document_id (null for privacy), project, purpose ('' but for a consent and a revocation), parent (null but for a revocation), sha256, shown, at, ip, agent
	 */
	public static function of( array &$appData, int $clientId, ?string $kind = null ): array {

		return array_map( [ self::class, 'shape' ], Db::rows( $appData, 'SELECT * FROM records WHERE client_id = :client'. ( $kind === null ? '' : ' AND kind = :kind' ). ' ORDER BY id DESC', [ 'client' => $clientId ] + ( $kind === null ? [] : [ 'kind' => $kind ] ) ) );
	}

	/**
	 *	The order that was placed for a document
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$documentId
	 *
	 *	@return 	array|null								The newest record of kind order; null for a document nobody ordered in the portal
	 */
	public static function forDocument( array &$appData, int $documentId ): ?array {

		$row = Db::row( $appData, 'SELECT * FROM records WHERE document_id = :id AND kind = \'order\' ORDER BY id DESC LIMIT 1', [ 'id' => $documentId ] );

		return $row === null ? null : self::shape( $row );
	}

	/**
	 *	The newest approval of a project
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$project			The id of the project
	 *
	 *	@return 	array|null								As of() has it; null for a project nobody approved
	 */
	public static function approval( array &$appData, string $project ): ?array {

		$row = Db::row( $appData, 'SELECT * FROM records WHERE kind = \'approval\' AND project = :project ORDER BY id DESC LIMIT 1', [ 'project' => $project ] );

		return $row === null ? null : self::shape( $row );
	}

	/**
	 *	The newest approval of every project that has one, read in one query and without the text that was shown (the lists need who and when, not the JSON)
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											project id => id, client_id, project, at, member_email, member_name, sha256
	 */
	public static function approvals( array &$appData ): array {

		$found = [];

		foreach( Db::rows( $appData, 'SELECT id, client_id, project, at, member_email, member_name, sha256 FROM records WHERE kind = \'approval\' ORDER BY id' ) as $row )
			$found[(string) $row['project']] = [ 'id' => (int) $row['id'], 'client_id' => (int) $row['client_id'] ] + $row;

		return $found;
	}

	/**
	 *	The newest agreement to the Datenschutz of a client, whatever text it was to
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$clientId
	 *
	 *	@return 	array|null
	 */
	public static function latestPrivacy( array &$appData, int $clientId ): ?array {

		$row = Db::row( $appData, 'SELECT * FROM records WHERE client_id = :client AND kind = \'privacy\' ORDER BY id DESC LIMIT 1', [ 'client' => $clientId ] );

		return $row === null ? null : self::shape( $row );
	}

	/**
	 *	Where a client stands with the Datenschutz of now
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$clientId
	 *
	 *	@return 	array											asked (the portal has a text to agree to), agreed (a record holds the hash of that text), at (the time of the newest such record) and member_name (who agreed: the name of the account, its address where it has no name); '' for both where not agreed
	 */
	public static function privacy( array &$appData, int $clientId ): array {

		$text = self::privacyText( $appData );

		if( $text === '' )
			return self::standing( '', null );

		return self::standing( $text, Db::row( $appData, 'SELECT * FROM records WHERE client_id = :client AND kind = \'privacy\' AND sha256 = :hash ORDER BY id DESC LIMIT 1', [ 'client' => $clientId, 'hash' => self::hash( $text ) ] ) );
	}

	/**
	 *	The standing of several clients with the Datenschutz of now, read in one query
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$ids					Client ids
	 *
	 *	@return 	array											client id => as privacy() has it
	 */
	public static function privacies( array &$appData, array $ids ): array {

		$text = self::privacyText( $appData );
		$found = [];

		if( $text !== '' )
			foreach( Db::rows( $appData, 'SELECT * FROM records WHERE kind = \'privacy\' AND sha256 = :hash ORDER BY id', [ 'hash' => self::hash( $text ) ] ) as $row )
				$found[(int) $row['client_id']] = $row;

		$standing = [];

		foreach( $ids as $id )
			$standing[(int) $id] = self::standing( $text, $found[(int) $id] ?? null );

		return $standing;
	}

	/**
	 *	The consents of a client with what became of them, the newest first
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$clientId
	 *
	 *	@return 	array											The records of kind consent as of() has them, each with revoked: the record of kind revocation that names it, null for a consent that holds. One query
	 */
	public static function consents( array &$appData, int $clientId ): array {

		return self::paired( Db::rows( $appData, 'SELECT * FROM records WHERE client_id = :client AND kind IN ( \'consent\', \'revocation\' ) ORDER BY id DESC', [ 'client' => $clientId ] ) );
	}

	/**
	 *	The consent that holds for a purpose and a project of a client
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$clientId
	 *	@param		string		$purpose			One of PURPOSES
	 *	@param		string		$project			The id of the project, '' for a consent that is not for one
	 *
	 *	@return 	array|null								The newest consent of that purpose that no revocation names and that holds for the project: one given for it, or one given for all projects (project ''); null for none. One query
	 */
	public static function consent( array &$appData, int $clientId, string $purpose, string $project ): ?array {

		$row = Db::row( $appData, 'SELECT c.* FROM records c WHERE c.client_id = :client AND c.kind = \'consent\' AND c.purpose = :purpose AND ( c.project = :project OR c.project = \'\' )'
			. ' AND NOT EXISTS ( SELECT 1 FROM records r WHERE r.kind = \'revocation\' AND r.parent = c.id ) ORDER BY c.id DESC LIMIT 1', [ 'client' => $clientId, 'purpose' => $purpose, 'project' => $project ] );

		return $row === null ? null : self::shape( $row );
	}

	/**
	 *	The consents that hold for a project, whatever client gave them, with what became of them: those given for it and those given for all projects (project '')
	 *
	 *	@param		array 		&$appData
	 *	@param		string|null	$project			One project; null for every consent there is
	 *
	 *	@return 	array											As consents() has them, the newest first. One query
	 */
	public static function consentsOfProject( array &$appData, ?string $project = null ): array {

		return self::paired( Db::rows( $appData, 'SELECT * FROM records WHERE kind IN ( \'consent\', \'revocation\' )'. ( $project === null ? '' : ' AND ( project = :project OR project = \'\' )' ). ' ORDER BY id DESC', $project === null ? [] : [ 'project' => $project ] ) );
	}

	/**
	 *	The consents that were given with the order of a document, with what became of them
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$documentId
	 *
	 *	@return 	array											As consents() has them, the newest first. One query
	 */
	public static function consentsOfDocument( array &$appData, int $documentId ): array {

		return self::paired( Db::rows( $appData, 'SELECT * FROM records WHERE ( kind = \'consent\' AND document_id = :id ) OR ( kind = \'revocation\' AND parent IN ( SELECT id FROM records WHERE kind = \'consent\' AND document_id = :same ) ) ORDER BY id DESC', [ 'id' => $documentId, 'same' => $documentId ] ) );
	}

	/**
	 *	Whether any record names a project
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$project			The id of the project
	 *
	 *	@return 	bool											True for a record of any kind that holds the project
	 */
	public static function names( array &$appData, string $project ): bool {

		return Db::row( $appData, 'SELECT id FROM records WHERE project = :project LIMIT 1', [ 'project' => $project ] ) !== null;
	}

	/**
	 *	The consent that can be revoked, read on the connection of the write
	 *
	 *	@param		\PDO			$pdo
	 *	@param		int				$id						The consent
	 *	@param		int				$clientId			Whose it must be
	 *
	 *	@return 	array|null								The record of kind consent that no revocation names; null for none
	 */
	private static function revocable( \PDO $pdo, int $id, int $clientId ): ?array {

		$statement = $pdo->prepare( 'SELECT c.* FROM records c WHERE c.id = :id AND c.client_id = :client AND c.kind = \'consent\' AND NOT EXISTS ( SELECT 1 FROM records r WHERE r.kind = \'revocation\' AND r.parent = c.id )' );
		$statement->execute( [ 'id' => $id, 'client' => $clientId ] );
		$row = $statement->fetch();

		return $row === false ? null : $row;
	}

	/**
	 *	@param		array 		$rows					Records of kind consent and revocation, the newest first
	 *
	 *	@return 	array											The consents among them, each with revoked
	 */
	private static function paired( array $rows ): array {

		$revoked = [];

		foreach( $rows as $row )
			if( $row['kind'] === 'revocation' )
				$revoked[(int) $row['parent']] = self::shape( $row );

		$consents = [];

		foreach( $rows as $row )
			if( $row['kind'] === 'consent' )
				$consents[] = self::shape( $row ) + [ 'revoked' => $revoked[(int) $row['id']] ?? null ];

		return $consents;
	}

	/**
	 *	What the order of an offer shows: the JSON the page of the offer is drawn from - the number, the title, the day, the validity, the lines, the sums, the
	 *	VAT mode and the particulars of the company - and the sentence the customer agreed to. The same document gives the same text, so the hash of a record can be made again
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			As Documents::get() has it, finalised
	 *	@param		string		$sentence			The words beside the box, as the customer read them
	 *	@param		array 		$consents			purpose => the sentence beside the box of a consent that was offered with the order; none where no consent was offered
	 *
	 *	@return 	string										JSON
	 */
	public static function orderShown( array &$appData, array $document, string $sentence, array $consents = [] ): string {

		$totals = Documents\Money::totals( $document['lines'], (string) $document['vat_mode'], (bool) $document['reverse_charge'] );
		$sums = [ 'net' => $totals['net'], 'vat' => [], 'gross' => $totals['gross'] ];

		foreach( $totals['rates'] as $rate => $part )
			$sums['vat'][] = [ 'rate' => (int) $rate, 'net' => (int) $part['net'], 'vat' => (int) $part['vat'] ];

		$lines = [];

		foreach( $document['lines'] as $index => $line )
			$lines[] = [
				'title'       => (string) $line['title'],
				'description' => (string) $line['description'],
				'qty'         => (int) $line['qty'],
				'unit'        => (string) $line['unit'],
				'price_net'   => (int) $line['price_net'],
				'vat_rate'    => (int) $line['vat_rate'],
				'amount_net'  => (int) $totals['amounts'][$index],
			];

		$company = [];

		foreach( [ 'name' => 'general/name', 'address' => 'contact/address', 'country' => 'contact/country', 'email' => 'contact/email', 'phone' => 'contact/phone' ] as $field => $key )
			$company[$field] = trim( html_entity_decode( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/'. $key ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		$company['tax_id'] = Settings::get( $appData, 'tax_id' );

		return (string) json_encode( [
			'document'       => (int) $document['id'],
			'number'         => (string) $document['number'],
			'title'          => (string) $document['title'],
			'date'           => (string) $document['date'],
			'valid_until'    => (string) ( $document['valid_until'] ?? '' ),
			'project'        => (string) $document['project'],
			'vat_mode'       => (string) $document['vat_mode'],
			'reverse_charge' => (bool) $document['reverse_charge'],
			'currency'       => Settings::get( $appData, 'currency' ),
			'lines'          => $lines,
			'sums'           => $sums,
			'company'        => $company,
			'sentence'       => $sentence,
		] + ( $consents === [] ? [] : [ 'consents' => $consents ] ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR );
	}

	/**
	 *	What the approval of a project shows: the JSON the Dateien of the project are drawn from - the id and the title of the project, the files the member may see (the name,
	 *	the size, the SHA-256 and the day of each, the files that were removed from the server left out, in the order of their names) - and the sentence the customer agreed to.
	 *	The same files give the same text, so the hash of a record can be made again
	 *
	 *	@param		array 		$project			id and title
	 *	@param		array 		$files				As Files::files() has them
	 *	@param		string		$sentence			The words beside the box, as the customer read them
	 *
	 *	@return 	string										JSON
	 */
	public static function approvalShown( array $project, array $files, string $sentence ): string {

		$list = [];

		foreach( $files as $file )
			if( isset( $file['gone'] ) === false )
				$list[] = [ 'name' => (string) $file['name'], 'size' => (int) $file['size'], 'sha256' => (string) $file['sha256'], 'date' => substr( (string) $file['created'], 0, 10 ) ];

		usort( $list, static fn( array $a, array $b ): int => [ $a['name'], $a['sha256'], $a['date'] ] <=> [ $b['name'], $b['sha256'], $b['date'] ] );

		return (string) json_encode( [
			'project'  => [ 'id' => (string) $project['id'], 'title' => (string) $project['title'] ],
			'files'    => $list,
			'sentence' => $sentence,
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR );
	}

	/**
	 *	@param		string		$text					The Datenschutz of now ('' for none)
	 *	@param		array|null	$row					The record that agrees to it, null for none
	 *
	 *	@return 	array
	 */
	private static function standing( string $text, ?array $row ): array {

		$name = $row === null ? '' : ( (string) $row['member_name'] !== '' ? (string) $row['member_name'] : (string) $row['member_email'] );

		return [ 'asked' => $text !== '', 'agreed' => $row !== null, 'at' => $row === null ? '' : (string) $row['at'], 'member_name' => $name ];
	}

	/**
	 *	@param		array 		$row
	 *
	 *	@return 	array
	 */
	private static function shape( array $row ): array {

		$row['id'] = (int) $row['id'];
		$row['client_id'] = (int) $row['client_id'];
		$row['document_id'] = $row['document_id'] === null ? null : (int) $row['document_id'];
		$row['parent'] = ( $row['parent'] ?? null ) === null ? null : (int) $row['parent'];

		return $row;
	}
}
