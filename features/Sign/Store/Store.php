<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Nino\Modules\Sign\Store		The data of the Sign feature: the index of the requests and the files of each
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules\Sign {

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Sign\Store				What the feature keeps and how it keeps it. The index is one file,
	 *										/data/sign/requests.php, that maps the id of a request to its entry:
	 *
	 *											<id> => [ template, data, title, account, status, token, expires, created, viewed,
	 *											          signed, declined, changed, hashShown, hashSigned, method, record, page ]
	 *
	 *										and every request has a directory of its own below it, /data/sign/<id>/,
	 *										with shown.pdf (the document as the member is shown it), signature.png
	 *										and signed.pdf (the document with the signature on it and the record of
	 *										the signing as its last page). A file of a request is written once and
	 *										never again: signed.pdf and signature.png are written only while the
	 *										request is live, in the lock of the index, and the status turns to signed
	 *										in the same write.
	 *
	 *										Every write of the index is a Filesystem::mutate(), and the file is plain
	 *										php anybody can edit, so parse() reads what is there for what it has to
	 *										be and leaves out an entry that is not. The token of a request is kept as
	 *										its sha256 and is the one thing that is never given out: view() leaves it
	 *										off. 'changed' is a status that is written: the request whose document was not the one shown when it
	 *										came to be signed (see Sign). 'expired' is no status that is written - status() computes it from
	 *										the time the request is good until.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Store {

		public const string DIR = '/data/sign';
		public const string INDEX = '/data/sign/requests.php';

		// The shape of an id and of a token as it is mailed, of a sha256, of the key of a template of the Pdf feature
		public const string ID = '/^[a-f0-9]{12}$/D';
		public const string TOKEN = '/^[A-Za-z0-9_-]{43}$/D';
		public const string HASH = '/^[a-f0-9]{64}$/D';
		public const string TEMPLATE = '/^[a-z][a-z0-9-]{1,39}$/D';

		public const string TIME = 'Y-m-d\TH:i:s\Z';

		// The statuses that are written, and the PDFs a request has by the file they are kept in
		public const array STATUSES = [ 'requested', 'viewed', 'signed', 'declined', 'cancelled', 'changed' ];
		public const array FILES = [ 'shown' => 'shown.pdf', 'signed' => 'signed.pdf', 'signature' => 'signature.png' ];
		public const array METHODS = [ '', 'drawn', 'typed' ];

		public const int AGENT_LENGTH = 255;
		public const int CONSENT_LENGTH = 1000;

		/**
		 *	The index, read for what it has to be: an id of 12 hex characters, the key of a template of the
		 *	Pdf feature, the id of an account, a status that is written, the sha256 of a token and of the
		 *	document that was shown, and a time the request is good until that is a whole number. What is not
		 *	that is left out
		 *
		 *	@param		mixed			$state				As the file holds it
		 *
		 *	@return 	array										id => entry
		 */
		public static function parse( mixed $state ): array {

			$text = static fn( mixed $value ): string => is_string( $value ) === true ? $value : '';
			$when = static fn( mixed $value ): ?string => is_string( $value ) === true && $value !== '' ? $value : null;
			$requests = [];

			foreach( is_array( $state ) === true ? $state : [] as $id => $record ) {

				$id = (string) $id;

				if( preg_match( self::ID, $id ) !== 1 || is_array( $record ) === false || preg_match( self::TEMPLATE, $text( $record['template'] ?? null ) ) !== 1
					|| preg_match( self::ID, $text( $record['account'] ?? null ) ) !== 1 || in_array( $record['status'] ?? null, self::STATUSES, true ) === false
					|| preg_match( self::HASH, $text( $record['token'] ?? null ) ) !== 1 || is_int( $record['expires'] ?? null ) === false
					|| preg_match( self::HASH, $text( $record['hashShown'] ?? null ) ) !== 1 )
					continue;

				$signed = $text( $record['hashSigned'] ?? null );
				$proof = is_array( $record['record'] ?? null ) === true ? $record['record'] : [];

				$requests[$id] = [
					'template'		=> $text( $record['template'] ),
					'data'			=> self::data( $record['data'] ?? null ),
					'title'			=> $text( $record['title'] ?? null ),
					'account'		=> $text( $record['account'] ),
					'status'		=> $record['status'],
					'token'			=> $text( $record['token'] ),
					'expires'		=> $record['expires'],
					'created'		=> $text( $record['created'] ?? null ),
					'viewed'		=> $when( $record['viewed'] ?? null ),
					'signed'		=> $when( $record['signed'] ?? null ),
					'declined'	=> $when( $record['declined'] ?? null ),
					'changed'	=> $when( $record['changed'] ?? null ),
					'hashShown'	=> $text( $record['hashShown'] ),
					'hashSigned'	=> preg_match( self::HASH, $signed ) === 1 ? $signed : '',
					'method'		=> in_array( $record['method'] ?? null, self::METHODS, true ) === true ? $record['method'] : '',
					'record'		=> [ 'ip' => $text( $proof['ip'] ?? null ), 'agent' => $text( $proof['agent'] ?? null ), 'consent' => $text( $proof['consent'] ?? null ) ],
					'page'		=> $text( $record['page'] ?? null ),
				];
			}

			return $requests;
		}

		/**
		 *	What a caller hands to the Pdf feature, cleaned: the tokens (a string, a number or a list of them, down to
		 *	four keys - [[a.b.c.d]]), the slots and the inputs (strings) - nothing else, no object, no resource
		 *
		 *	@param		mixed			$given
		 *
		 *	@return 	array										Any of tokens, slots and inputs, each a list
		 */
		public static function data( mixed $given ): array {

			$clean = [];

			foreach( [ 'tokens', 'slots', 'inputs' ] as $part )
				if( is_array( $given ) === true && is_array( $given[$part] ?? null ) === true )
					$clean[$part] = self::_values( $given[$part], $part === 'tokens' ? 3 : 0, $part === 'tokens' );

			return $clean;
		}

		/**
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array										The index as parse() has it
		 */
		public static function read( array &$appData ): array {

			return self::parse( \Nino\Filesystem::getFileContent( $appData, self::INDEX, [] ) );
		}

		/**
		 *	One request as the API gives it: its id first, the status as it is now (see status()) and not
		 *	the hash of its token
		 *
		 *	@param		string		$id
		 *	@param		array 		$entry				As parse() has it
		 *
		 *	@return 	array
		 */
		public static function view( string $id, array $entry ): array {

			$entry['status'] = self::status( $entry );
			unset( $entry['token'] );

			return [ 'id' => $id ] + $entry;
		}

		/**
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *
		 *	@return 	array|null								The request as view() has it; null for an id nobody has
		 */
		public static function get( array &$appData, string $id ): ?array {

			$entry = preg_match( self::ID, $id ) === 1 ? ( self::read( $appData )[$id] ?? null ) : null;

			return $entry === null ? null : self::view( $id, $entry );
		}

		/**
		 *	The requests, the newest first: by the time they were made, and the one that was put in the index later
		 *	first where two were made in the same second
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string|null	$account			Only the requests of this account
		 *
		 *	@return 	array										A list of requests as view() has them
		 */
		public static function all( array &$appData, ?string $account = null ): array {

			$list = [];

			foreach( array_reverse( self::read( $appData ), true ) as $id => $entry )
				if( $account === null || $entry['account'] === $account )
					$list[] = self::view( (string) $id, $entry );

			// A sort is stable: what was put in later stays in front of what was put in before it, in the same second
			usort( $list, static fn( array $a, array $b ): int => strcmp( $b['created'], $a['created'] ) );

			return $list;
		}

		/**
		 *	The request a token belongs to
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$token				As it was mailed
		 *
		 *	@return 	array|null								[ id, entry ] with the entry as parse() has it - the hash of the token among it, for the caller that works on the request; null for a token nobody has
		 */
		public static function byToken( array &$appData, string $token ): ?array {

			if( preg_match( self::TOKEN, $token ) !== 1 )
				return null;

			$hash = hash( 'sha256', $token );

			foreach( self::read( $appData ) as $id => $entry )
				if( hash_equals( $entry['token'], $hash ) === true )
					return [ (string) $id, $entry ];

			return null;
		}

		/**
		 *	The status of a request now: the one that is written, but for a request that is still waiting and
		 *	whose time is up - that is expired, and nothing writes it
		 *
		 *	@param		array 		$entry
		 *
		 *	@return 	string
		 */
		public static function status( array $entry ): string {

			return in_array( $entry['status'], [ 'requested', 'viewed' ], true ) === true && $entry['expires'] <= time() ? 'expired' : $entry['status'];
		}

		/**
		 *	Whether a request can still be signed or declined: it is waiting and its time is not up
		 *
		 *	@param		array 		$entry
		 *
		 *	@return 	bool
		 */
		public static function live( array $entry ): bool {

			return in_array( self::status( $entry ), [ 'requested', 'viewed' ], true );
		}

		/**
		 *	A new id: 12 hex characters that neither the index nor the disk has
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	string
		 */
		public static function newId( array &$appData ): string {

			$requests = self::read( $appData );

			do
				$id = bin2hex( random_bytes( 6 ) );
			while( isset( $requests[$id] ) === true || is_dir( \Nino\Filesystem::path( $appData, self::DIR. '/'. $id ) ) === true );

			return $id;
		}

		/**
		 *	A new token: 32 random bytes as base64url, which the mail carries and nothing stores
		 *
		 *	@return 	string
		 */
		public static function newToken(): string {

			return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		}

		/**
		 *	Put a request in the index - not over one that is there
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *	@param		array 		$entry				As parse() has it
		 *
		 *	@return 	bool
		 */
		public static function add( array &$appData, string $id, array $entry ): bool {

			if( self::parse( [ $id => $entry ] ) === [] )
				return false;

			$added = false;

			$written = \Nino\Filesystem::mutate( $appData, self::INDEX, static function( mixed $state ) use ( $id, $entry, &$added ): ?array {

				$requests = self::parse( $state );

				if( isset( $requests[$id] ) === true )
					return null;

				$requests[$id] = $entry;
				$added = true;

				return $requests;
			}, [] );

			return $written === true && $added === true;
		}

		/**
		 *	Change one request in the lock of the index. The function is handed the entry and the app data and
		 *	answers the entry as it is to be, or null to leave it as it is; it runs with the index locked, so
		 *	what it decides on is what is written
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *	@param		callable	$change				function( array $entry, array &$appData ): ?array
		 *
		 *	@return 	array|string							The request as view() has it after the change; or why not: 'unknown' (no such id), 'refused' (the function answered null) or 'write'
		 */
		public static function change( array &$appData, string $id, callable $change ): array|string {

			$outcome = 'unknown';
			$changed = null;

			$written = \Nino\Filesystem::mutate( $appData, self::INDEX, static function( mixed $state, array &$appData ) use ( $id, $change, &$outcome, &$changed ): ?array {

				$requests = self::parse( $state );

				if( isset( $requests[$id] ) === false )
					return null;

				$new = $change( $requests[$id], $appData );
				$checked = $new === null ? [] : self::parse( [ $id => $new ] );

				// An entry that parse() would not take is none: it is not written
				if( $checked === [] ) {
					$outcome = 'refused';
					return null;
				}

				$requests[$id] = $checked[$id];
				$changed = $checked[$id];
				$outcome = 'ok';

				return $requests;
			}, [] );

			if( $outcome !== 'ok' )
				return $outcome;

			return $written === true && $changed !== null ? self::view( $id, $changed ) : 'write';
		}

		/**
		 *	Take a request out of the index and its directory off the disk, the files with it - in that order
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *
		 *	@return 	string										'ok', 'unknown' (no such id) or 'write'
		 */
		public static function remove( array &$appData, string $id ): string {

			$found = false;

			$written = \Nino\Filesystem::mutate( $appData, self::INDEX, static function( mixed $state ) use ( $id, &$found ): ?array {

				$requests = self::parse( $state );

				if( isset( $requests[$id] ) === false )
					return null;

				unset( $requests[$id] );
				$found = true;

				return $requests;
			}, [] );

			if( $found === false )
				return 'unknown';

			if( $written === false )
				return 'write';

			self::drop( $appData, $id );

			return 'ok';
		}

		/**
		 *	Delete the directory of a request: the id is matched before a path is built from it, and the path is
		 *	the feature's own
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *
		 *	@return 	void
		 */
		public static function drop( array &$appData, string $id ): void {

			if( preg_match( self::ID, $id ) === 1 )
				\Nino\Filesystem::removeDir( \Nino\Filesystem::path( $appData, self::DIR. '/'. $id ) );
		}

		/**
		 *	The virtual path of a file of a request
		 *
		 *	@param		string		$id
		 *	@param		string		$kind					shown, signed or signature
		 *
		 *	@return 	string										'' for an id or a kind that is none
		 */
		public static function file( string $id, string $kind ): string {

			return preg_match( self::ID, $id ) === 1 && isset( self::FILES[$kind] ) === true ? self::DIR. '/'. $id. '/'. self::FILES[$kind] : '';
		}

		/**
		 *	The absolute path of a file of a request that is there
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *	@param		string		$kind					shown, signed or signature
		 *
		 *	@return 	string|null								Null where the file is not there
		 */
		public static function path( array &$appData, string $id, string $kind ): ?string {

			$file = self::file( $id, $kind );
			$path = $file === '' ? '' : \Nino\Filesystem::path( $appData, $file );

			return $path !== '' && is_file( $path ) === true && is_link( $path ) === false ? $path : null;
		}

		/**
		 *	A list of values cleaned, down to a depth: a string stays, a number stays where numbers are allowed, a list
		 *	stays where there is depth left for it - and everything else goes
		 *
		 *	@param		array 		$values
		 *	@param		int				$depth				How many lists may be nested in one another below this one
		 *	@param		bool			$numbers			Whether an int and a float are values (the tokens), else only a string is
		 *
		 *	@return 	array
		 */
		private static function _values( array $values, int $depth, bool $numbers ): array {

			$clean = [];

			foreach( $values as $key => $value ) {

				if( is_string( $value ) === true || ( $numbers === true && ( is_int( $value ) === true || is_float( $value ) === true ) ) )
					$clean[$key] = $value;
				else if( is_array( $value ) === true && $depth > 0 )
					$clean[$key] = self::_values( $value, $depth - 1, $numbers );
			}

			return $clean;
		}
	}
}
