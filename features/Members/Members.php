<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Modules\\Members					see _nino/Nino/Modules/Modules.php for the
 *											package-level docblock
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules {

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Members						Member accounts for the website, without passwords. An
	 *										account is an email address, a name and a group, kept in
	 *										/data/members.php. A member signs in with a code: POST
	 *										/.members/login keeps the address and a random nonce in the
	 *										session of the browser that asked, mails six digits to an
	 *										address that belongs to an active account (and answers every
	 *										address alike), and the form then asks for the code, which
	 *										POST /.members/code takes. The code is kept only as
	 *										sha256( nonce | code ) in /data/members-tokens.php, so it is
	 *										worth nothing outside the session that asked for it, and it is
	 *										good for one sign-in, for as long as the setting linkMinutes
	 *										allows and for five tries. An invitation, and the link the
	 *										owner sends from the panel, are links: GET
	 *										/.members/login?t=<token> opens a page that names the
	 *										account and asks for a button to be pressed, which POSTs to
	 *										/.members/confirm and spends the token: a GET changes
	 *										nothing, so a mail scanner or a prefetch that follows the link
	 *										uses nobody's link up. That token is 32 random bytes, mailed
	 *										once and kept only as its sha256 in the same file, good for one
	 *										confirmation and for as long as linkMinutes (inviteDays for an
	 *										invitation) allows. The sign-in is a value of the kernel's
	 *										session, stamped with the session epoch of
	 *										/data/members-session.php (see _openEpoch()): signOutAll() -
	 *										the panel's button - writes a new one, and every session
	 *										from before it reads signed out. The one cookie of the feature's
	 *										own is nino_remember, for a member who ticked the box of the code
	 *										form: its value is the id of the account and a token of 32 random bytes,
	 *										of which the account keeps only the sha256 (the list remember, ten at most,
	 *										the oldest one goes first). A visitor who has no session and brings a good one
	 *										is signed in as after a code, and the token is replaced by a new one
	 *										with every use (see _resume()); signing out takes the token of that
	 *										device, forgetAll() those of all.
	 *
	 *										A path under one of the protected prefixes of the
	 *										setting paths - or an address of a page whose uri lies under
	 *										one, in whichever language its routes give it - shows the
	 *										login page, in place and with the status 401, to everyone who
	 *										is not signed in (the gate,
	 *										see callbackGate(), priority 1 on '/nino/http/response' -
	 *										before Modules\Cache's 9, so a page for a member is never
	 *										the one that gets cached). The page asked for is kept in
	 *										the session - with its query, as it came -, and the code requested from that page takes
	 *										the member back to it.
	 *
	 *										Three components put the form, a link to sign out and
	 *										the member's name on any page the Builder composes
	 *										(componentMembersLogin(), componentMembersLogout() and
	 *										componentMembersName(); the manifest declares them), a
	 *										fourth - componentMembersConfirm() - is the button of the page
	 *										a link opens, and the panel in Admin/Admin.php manages the
	 *										accounts. The form of the first draws the field for the
	 *										address, and - once a code is out, in the session of the
	 *										visitor - the field for the code.
	 *
	 *										An account's address is changed by the owner (change() with
	 *										'email') or by the member: askChange() keeps the request in the
	 *										session of the member and mails six digits to the new address -
	 *										whoever the address belongs to is not told -, confirmChange() takes
	 *										them, once, in time and within five tries. Every link and code the
	 *										account has out dies with the change, the account gets an epoch of
	 *										its own that ends every other session of it (the one that confirms
	 *										keeps its place), and the old address is told.
	 *
	 *										Other features build on it through current(), account(),
	 *										accounts() and groups(), and by the callbacks
	 *										'/members/login', '/members/logout' and '/members/changed',
	 *										which are fired with the account as their argument (the last
	 *										with who changed the address, too), and '/members/start', which a
	 *										sign-in fires before it leads on: a feature may say where to.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Members {

		// Where this feature's own templates are, as \Nino\Filesystem resolves
		// them: /features is the installed features directory, wherever
		// NINO_FEATURES_DIR put it
		public const string TEMPLATES = '/features/Members/templates';

		// The accounts: [ 'accounts' => [ <id> => [ email, name, group, active, created, login ] ] ]; an account whose address was changed has an
		// 'epoch' besides, 32 hex characters, which the sessions of the account carry (see current())
		public const string ACCOUNTS = '/data/members.php';

		// The links and the codes that are out: [ <sha256 of the token, or of nonce|code> => [ account, kind, expires, target ] ] - and the new address, for the code of a change
		public const string TOKENS = '/data/members-tokens.php';

		// The session epoch - what 'sign everybody out' rewrites: [ 'epoch' => <32 hex> ]
		public const string EPOCH = '/data/members-session.php';

		private const string FEATURE_KEY = 'members';

		// A runtime session value - who is signed in, and under which epoch -, the
		// path a protected page was asked for until a code is requested, and the code
		// that is out: [ email, nonce, tries, until ]. The kernel's session carries
		// all three: the feature has no cookie of its own
		private const string SESSION_ACCOUNT = './members/account';
		private const string SESSION_TARGET = './members/target';

		// The longest page a sign-in may lead back to, its query included
		private const int TARGET_MAX = 2000;
		private const string SESSION_PENDING = './members/pending';

		// The change of an address the member asked for: [ account, email, nonce, tries, until ]
		private const string SESSION_CHANGE = './members/change';

		// Request-lifecycle keys: the state a callback of this request set for the
		// form to say, how many forms this request has drawn, and the link a page
		// asks to be confirmed (its token, the name of the account, a state)
		private const string STATE = './members/state';
		private const string FORMS = './members/forms';
		private const string CONFIRM = './members/confirm';

		// The counter of the login requests, \Nino\Feature::count() under this
		// name: the file /data/members-tries.php. Not 'members' - that is the file of
		// the accounts, and a counter drops whatever in its file is no entry of its
		// own, the accounts among it. It is not in the manifest's 'data' either: a
		// window of an hour is nothing a backup carries
		private const string COUNTER = 'members-tries';

		private const int WINDOW = 3600;
		private const int ADDRESS_LIMIT = 3;
		private const int DEFAULT_ATTEMPTS = 5;
		private const int NAME_LENGTH = 120;
		private const int CODE_TRIES = 5;
		private const string TIME = 'Y-m-d\TH:i:s\Z';

		// The cookie of a device that stays signed in, the tokens an account keeps of them, the longest user agent that is kept with one, the days it holds
		// by default, the wrong cookies of an address that are counted in an hour and the seconds the token that was replaced still signs in
		private const string REMEMBER = 'nino_remember';
		private const int REMEMBER_MAX = 10;
		private const int AGENT_LENGTH = 300;
		private const int DEFAULT_REMEMBER = 90;
		private const int REMEMBER_TRIES = 20;
		private const int REMEMBER_GRACE = 30;
		private const string REMEMBER_COUNTS = 'remember|';

		// What the form may say besides nothing, the key of its words where that is
		// not the state's own name, what a token may be for, and what of it a link
		// may be: the entry of a code is made by a request for one and never by link()
		private const array STATES = [ 'sent', 'limit', 'invalid', 'out', 'expired', 'wrong', 'gone' ];
		private const array STATE_KEYS = [ 'sent' => 'code' ];
		private const array KINDS = [ 'login', 'invite', 'send', 'code', 'change' ];
		private const array LINKS = [ 'login', 'invite', 'send' ];

		// How long the record of a link that has run out is kept: what the link led to is all it is kept for, so that the page that says the link no longer works can lead the
		// code of the sign-in to the same page
		private const int LINK_KEEP = 2592000;

		/**
		 *	Register the five routes this feature owns with their handlers,
		 *	the gate and the exclusion for the Seo feature, and keep the full-page
		 *	cache away from the protected paths
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	void
		 */
		public static function init( array &$appData ): void {

			$appData['/nino/http/routes']['POST://.members/login']	= [ 'uri' => '/.members/login' ];
			$appData['/nino/http/routes']['POST://.members/code']		= [ 'uri' => '/.members/code' ];
			$appData['/nino/http/routes']['GET://.members/login']		= [ 'uri' => '/.members/login' ];
			$appData['/nino/http/routes']['GET://.members/logout']	= [ 'uri' => '/.members/logout' ];
			$appData['/nino/http/routes']['POST://.members/logout']	= [ 'uri' => '/.members/logout' ];
			$appData['/nino/http/routes']['POST://.members/confirm']	= [ 'uri' => '/.members/confirm' ];

			// Priority 1: before Modules\Cache's own callback (9) decides whether
			// this response may be stored or served from the cache, and before the
			// render, so the login page is what actually renders for a visitor
			\Nino\Callbacks::registerCallback( $appData, '/nino/http/response', [ self::class, 'callbackGate' ], 1 );

			\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://.members/login', [ self::class, 'callbackLoginRequest' ] );
			\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://.members/code', [ self::class, 'callbackCode' ] );
			\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://.members/login', [ self::class, 'callbackLoginLink' ] );
			\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://.members/confirm', [ self::class, 'callbackConfirm' ] );
			\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://.members/logout', [ self::class, 'callbackLogout' ] );
			\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://.members/logout', [ self::class, 'callbackLogoutGet' ] );

			// The literal string, not Seo::EXCLUDE: this feature does not require
			// Seo, and a constant of a class that is not there is a fatal error
			// where a callback nobody fires costs one array entry
			\Nino\Callbacks::registerCallback( $appData, '/seo/exclude', [ self::class, 'callbackSeoExclude' ] );

			// No callback of '/nino/admin/restore', and that is on purpose: a restore
			// replaces the three files whole (the manifest's 'data'), and nothing is
			// to be merged - the accounts and the links that are out come back as
			// they were, and the epoch of the backup ends every session that was
			// signed in since, which is right

			self::_extendCacheBlacklist( $appData );
		}

		// The panel comes and goes with the feature: the kernel asks every
		// active module, in /nino/modules order (see \Nino\Modules::collect())
		public static function adminPanels( array &$appData ): array {

			return [ \Nino\Modules\Members\Admin::class ];
		}

		/**
		 *	The member who is signed in, as the account has it plus its id: an
		 *	array of id, email, name, group, active, created and login. Only while
		 *	the session's epoch is the current one and the account exists and is
		 *	active - a session from before 'sign everybody out', one of an account
		 *	that was deleted and one of an account that was switched off read as
		 *	nobody - and one that does not carry the epoch the account has now: a change of the
		 *	address gives the account an epoch of its own and ends every session of it but the one
		 *	that confirmed the change. An account that has none is the account of a session that carries none
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array|null								Null for a visitor
		 */
		public static function current( array &$appData ): ?array {

			$session = \Nino\Runtime::getSessionValue( $appData, self::SESSION_ACCOUNT );

			if( is_array( $session ) === false || is_string( $session['id'] ?? null ) === false || is_string( $session['epoch'] ?? null ) === false )
				return null;

			$epoch = self::_epoch( $appData );

			if( $epoch === '' || hash_equals( $epoch, $session['epoch'] ) === false )
				return null;

			$record = self::_record( $appData, $session['id'] );

			if( $record === null || $record['active'] === false || hash_equals( $record['epoch'] ?? '', is_string( $session['own'] ?? null ) === true ? $session['own'] : '' ) === false )
				return null;

			return [ 'id' => $session['id'] ] + self::_plain( $record );
		}

		/**
		 *	One account by its id
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id						12 hex characters
		 *
		 *	@return 	array|null								id, email, name, group, active, created and login - null for an id nobody has
		 */
		public static function account( array &$appData, string $id ): ?array {

			$record = self::_record( $appData, $id );

			return $record === null ? null : [ 'id' => $id ] + self::_plain( $record );
		}

		/**
		 *	Every account, sorted by name (the email address where an account has
		 *	none), each as account() has it
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array										A list of accounts
		 */
		public static function accounts( array &$appData ): array {

			$accounts = [];

			foreach( self::_accountsOf( \Nino\Filesystem::getFileContent( $appData, self::ACCOUNTS, [] ) ) as $id => $record )
				$accounts[] = [ 'id' => (string) $id ] + self::_plain( $record );

			usort( $accounts, static fn( array $a, array $b ): int => [ mb_strtolower( self::_label( $a ) ), $a['email'], $a['id'] ] <=> [ mb_strtolower( self::_label( $b ) ), $b['email'], $b['id'] ] );

			return $accounts;
		}

		/**
		 *	The groups that are in use, each once
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array										Slugs, sorted
		 */
		public static function groups( array &$appData ): array {

			$groups = array_values( array_unique( array_column( self::accounts( $appData ), 'group' ) ) );
			$groups = array_values( array_filter( $groups, static fn( string $group ): bool => $group !== '' ) );

			sort( $groups );

			return $groups;
		}

		/**
		 *	Whether $uri lies under one of the protected prefixes of the setting
		 *	paths - the prefix itself and everything below it - and no feature has opened it (open())
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$uri					A request path without the directory
		 *
		 *	@return 	bool
		 */
		public static function protects( array &$appData, string $uri ): bool {

			return \Nino\Http::matchesPath( $uri, array_map( static fn( string $prefix ): string => $prefix. '/*', self::prefixes( $appData ) ) ) === true
				&& \Nino\Http::matchesPath( $uri, self::open( $appData ) ) === false;
		}

		/**
		 *	The paths a feature has opened under a protected prefix: a file the browser fetches without a session - the manifest of a web app, its icon. The callback
		 *	'/members/open' is handed a list and adds to it the uris of its routes, in the spelling Http::matchesPath() reads (a path, or a prefix with /*); anything that is
		 *	no string, no path of this site or an endpoint of a module (/., /_) is dropped
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array										Uris and patterns
		 */
		public static function open( array &$appData ): array {

			$list = [];

			\Nino\Callbacks::doCallbacks( $appData, '/members/open', $list );

			return array_values( array_filter( is_array( $list ) === true ? $list : [], static fn( mixed $uri ): bool => is_string( $uri ) === true && preg_match( '~^/(?![._/])[^\s\x00-\x1f\x7f?#]*$~D', $uri ) === 1 ) );
		}

		/**
		 *	The configured protected prefixes, normalized and validated: one uri
		 *	prefix per line, which has to start with '/', must not start with '/_'
		 *	(the tools) or '/.' (module endpoints - this feature's own /.members
		 *	included), has its trailing slash stripped and is listed once. An entry
		 *	that fails any of this is skipped rather than refused - the settings
		 *	form took it as a 'lines' value, and there is nothing left to refuse it
		 *	with here
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array										Prefixes without a trailing slash
		 */
		public static function prefixes( array &$appData ): array {

			$prefixes = [];

			foreach( (array) \Nino\Features::setting( $appData, self::FEATURE_KEY, 'paths', [] ) as $path ) {

				if( is_string( $path ) === false )
					continue;

				// The front page is '' once its slash is off, and '' starts with no slash
				$path = rtrim( $path, '/' );

				if( str_starts_with( $path, '/' ) === false || str_starts_with( $path, '/_' ) === true || str_starts_with( $path, '/.' ) === true )
					continue;

				if( in_array( $path, $prefixes, true ) === false )
					$prefixes[] = $path;
			}

			return $prefixes;
		}

		/**
		 *	Add an account, active, with the address lower-cased. The address has to
		 *	be one (FILTER_VALIDATE_EMAIL) and unique without regard to case - the
		 *	check is made inside the write, so two requests at once cannot both add
		 *	it - and the name at most 120 characters. A group is made a slug, and a
		 *	text without a letter or a digit is no group
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$email
		 *	@param		string		$name
		 *	@param		string		$group
		 *
		 *	@return 	array|string							The account as account() has it; or why not: 'invalid' (the address), 'long' (the name), 'taken' (the address is a member's already), 'write' (the file could not be written)
		 */
		public static function add( array &$appData, string $email, string $name, string $group ): array|string {

			$email = self::_email( $email );
			$name = trim( $name );

			if( $email === '' )
				return 'invalid';

			if( mb_strlen( $name, 'UTF-8' ) > self::NAME_LENGTH )
				return 'long';

			$record = [ 'email' => $email, 'name' => $name, 'group' => \Nino\Feature::slug( $group ), 'active' => true, 'created' => gmdate( self::TIME ), 'login' => null ];
			$id = '';
			$taken = false;

			$written = \Nino\Filesystem::mutate( $appData, self::ACCOUNTS, static function( mixed $state ) use ( $record, &$id, &$taken ): ?array {

				$accounts = self::_accountsOf( $state );

				foreach( $accounts as $each )
					if( $each['email'] === $record['email'] ) {
						$taken = true;
						return null;
					}

				do
					$id = bin2hex( random_bytes( 6 ) );
				while( isset( $accounts[$id] ) === true );

				$accounts[$id] = $record;

				return [ 'accounts' => $accounts ];
			}, [] );

			if( $taken === true )
				return 'taken';

			return $written === true ? [ 'id' => $id ] + $record : 'write';
		}

		/**
		 *	Change the name, the group, whether an account is active and its address -
		 *	what $changes names and nothing else; the times and the id stay. The rules
		 *	of add() hold for the name, the group and the address, which is the owner's
		 *	to change here: the member's own way is askChange() and confirmChange(). The
		 *	address is looked at in the lock that writes it - one that another account
		 *	has is 'taken', whatever its case - and the address the account has already is
		 *	no change and writes nothing. A new address deletes every link and code the
		 *	account has out, gives the account a new epoch of its own, which ends every
		 *	session of it (see current()), mails the old address that it was changed and
		 *	fires '/members/changed' with by 'owner'
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *	@param		array 		$changes			Any of 'name' (string), 'group' (string), 'active' (bool) and 'email' (string)
		 *
		 *	@return 	array|string							The account as it is now; or why not: 'unknown' (no such id), 'long' (the name), 'invalid' (the address), 'taken' (the address is another account's), 'write'
		 */
		public static function change( array &$appData, string $id, array $changes ): array|string {

			$changed = self::_rewrite( $appData, $id, $changes, 'owner' );

			return is_array( $changed ) === true ? [ 'id' => $id ] + self::_plain( $changed ) : $changed;
		}

		/**
		 *	Ask for the address of the signed-in member's account to be another: the new
		 *	address gets six digits and nothing is changed until they are given back
		 *	(confirmChange()). The request is kept in the session of the member - the new
		 *	address, a random nonce, no tries yet and the time it is good until, the
		 *	setting linkMinutes - and the code under sha256( nonce | code ), so that it is
		 *	worth nothing outside that session. It is the same for an address that is
		 *	free and for one another account has: the request is kept either way, and only
		 *	a free address is mailed - nothing tells the member whose address it is, and
		 *	the time the request took is all there is to see. The visitor is counted
		 *	(the setting attempts, per hour) and so is the new address (3 per hour), as the
		 *	request for a login code counts them. An older request of the session is replaced
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$account			The member who is signed in, as current() has it
		 *	@param		string		$newEmail			As typed
		 *
		 *	@return 	string										'sent' (a code is on its way if the address is free), 'invalid' (no address, or $account is not the member of this session), 'same' (the address the account has), 'limit'
		 */
		public static function askChange( array &$appData, array $account, string $newEmail ): string {

			$email = self::_email( $newEmail );
			$record = self::_own( $appData, $account ) === true ? self::_record( $appData, (string) $account['id'] ) : null;

			if( $email === '' || $record === null )
				return 'invalid';

			if( $email === $record['email'] )
				return 'same';

			$attempts = (int) \Nino\Features::setting( $appData, self::FEATURE_KEY, 'attempts', self::DEFAULT_ATTEMPTS );

			if( \Nino\Feature::count( $appData, self::COUNTER, 'visitor|'. \Nino\Http::getClientIp( $appData ), $attempts, self::WINDOW ) === false
				|| \Nino\Feature::count( $appData, self::COUNTER, 'address|'. $email, self::ADDRESS_LIMIT, self::WINDOW ) === false )
				return 'limit';

			$nonce = bin2hex( random_bytes( 16 ) );
			$until = time() + self::_minutes( $appData ) * 60;

			\Nino\Runtime::setSessionValue( $appData, self::SESSION_CHANGE, [ 'account' => (string) $account['id'], 'email' => $email, 'nonce' => $nonce, 'tries' => 0, 'until' => $until ] );

			if( self::_byEmail( $appData, $email ) === null )
				self::_sendChange( $appData, (string) $account['id'], $email, $nonce, $until );

			return 'sent';
		}

		/**
		 *	The address a code was asked for, while the request of this session is live
		 *	and the member's own: what the second step of the form names
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$account			The member who is signed in, as current() has it
		 *
		 *	@return 	string|null								The new address; null for none, one that ran out and one of another account
		 */
		public static function changePending( array &$appData, array $account ): ?string {

			$pending = self::_changePending( $appData );

			return $pending !== null && self::_own( $appData, $account ) === true && $pending['account'] === $account['id'] ? $pending['email'] : null;
		}

		/**
		 *	Take the code of the mail askChange() sent: the request is the one in the
		 *	session and the account's own - without one, after its time, one of another
		 *	account or after the fifth wrong try it is 'gone' and over. The try is counted
		 *	first, the code - six digits, spaces left out; anything else is a wrong one -
		 *	is looked up as sha256( nonce | code ) and spent, and only a record of the kind
		 *	'change' for this account and this address counts. A good one ends the
		 *	request and writes the address the way change() does, from the member's side
		 *	(its links and codes die, the account's other sessions end, the old address is
		 *	told, '/members/changed' is fired with by 'member'); this session keeps its
		 *	place and carries the new epoch of the account. An address another account has
		 *	by now is 'gone' as well, as it would have been before: nobody is told whose it is
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$account			The member who is signed in, as current() has it
		 *	@param		string		$code					As typed
		 *
		 *	@return 	string										'ok', 'wrong', 'gone', or 'write' (the address could not be written - the code is spent)
		 */
		public static function confirmChange( array &$appData, array $account, string $code ): string {

			$pending = self::_changePending( $appData );

			if( $pending === null || self::_own( $appData, $account ) === false || $pending['account'] !== $account['id'] ) {
				\Nino\Runtime::unsetSessionValue( $appData, self::SESSION_CHANGE );
				return 'gone';
			}

			$pending['tries']++;
			\Nino\Runtime::setSessionValue( $appData, self::SESSION_CHANGE, $pending );

			$code = preg_replace( '/\s+/', '', $code ) ?? '';
			$spent = preg_match( '/^[0-9]{6}$/D', $code ) === 1 ? self::_spendHash( $appData, hash( 'sha256', $pending['nonce']. '|'. $code ), 'change' ) : null;

			if( $spent === null ) {

				if( $pending['tries'] >= self::CODE_TRIES ) {
					\Nino\Runtime::unsetSessionValue( $appData, self::SESSION_CHANGE );
					return 'gone';
				}

				return 'wrong';
			}

			\Nino\Runtime::unsetSessionValue( $appData, self::SESSION_CHANGE );

			if( $spent['account'] !== $account['id'] || ( $spent['email'] ?? '' ) !== $pending['email'] )
				return 'gone';

			$changed = self::_rewrite( $appData, $pending['account'], [ 'email' => $pending['email'] ], 'member' );

			if( is_array( $changed ) === false )
				return $changed === 'write' ? 'write' : 'gone';

			$session = \Nino\Runtime::getSessionValue( $appData, self::SESSION_ACCOUNT );

			\Nino\Runtime::setSessionValue( $appData, self::SESSION_ACCOUNT, [ 'id' => $pending['account'], 'epoch' => (string) ( $session['epoch'] ?? '' ) ] + ( ( $changed['epoch'] ?? '' ) !== '' ? [ 'own' => $changed['epoch'] ] : [] ) );

			return 'ok';
		}

		/**
		 *	Delete an account - that one and no other. The links it still has out
		 *	are good for nothing then: they name an account that is gone
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *
		 *	@return 	string										'ok', 'unknown' (no such id) or 'write'
		 */
		public static function remove( array &$appData, string $id ): string {

			$ran = false;
			$found = false;

			$written = \Nino\Filesystem::mutate( $appData, self::ACCOUNTS, static function( mixed $state ) use ( $id, &$ran, &$found ): ?array {

				$ran = true;
				$accounts = self::_accountsOf( $state );

				if( isset( $accounts[$id] ) === false )
					return null;

				unset( $accounts[$id] );
				$found = true;

				return [ 'accounts' => $accounts ];
			}, [] );

			if( $ran === true && $found === false )
				return 'unknown';

			return $written === true ? 'ok' : 'write';
		}

		/**
		 *	Send an account a link and keep its token: a link to sign in ('login',
		 *	valid for the setting linkMinutes), the one of an invitation ('invite',
		 *	valid for inviteDays) or one the owner sends to an account that is a member
		 *	already ('send', valid for inviteDays, with a mail of its own). The mail is absolute (the website's address is
		 *	needed) and goes out in the language of the request, or - for the panel,
		 *	which writes to somebody who is not in the room - in the site's native one.
		 *	A token the mail never carried is good for nobody: it is not found.
		 *	With $notify false nobody is mailed: the token is kept all the same and the link, as the
		 *	mail would have carried it, is the answer - once, since the index keeps the sha256 of the
		 *	token only. It is as good as a mailed one, for the same time, and whoever has it signs in
		 *	as the account: the owner who asks for it hands it over himself
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$account			As account() has it
		 *	@param		string		$kind					'login', 'invite' or 'send'
		 *	@param		string		$target				The path the member asked for, '' for none - where the link leads after the sign-in
		 *	@param		bool			$native				Write in the site's native language
		 *	@param		bool			$notify				Whether the account is mailed the link
		 *
		 *	@return 	bool|string							Whether the mail was delivered - false, with a warning that names no address, where there is no website address to build the link on, the token could not be stored or the mail was not taken; with $notify false the link, or false where it could not be made
		 */
		public static function link( array &$appData, array $account, string $kind, string $target = '', bool $native = false, bool $notify = true ): bool|string {

			if( in_array( $kind, self::LINKS, true ) === false || is_string( $account['id'] ?? null ) === false || is_string( $account['email'] ?? null ) === false )
				return false;

			// A site without an address has no link to put in the mail, and a mail
			// without its link is no better than none
			if( \Nino\Html::absoluteUrl( $appData ) === '' ) {
				trigger_error( 'Members: no link was sent, the site has no address (the fill /project/website/general/url)', E_USER_WARNING );
				return false;
			}

			// A link that is shown and not mailed is charged to the cap of mails as a mail would be - the configured one, '/nino/mail/cap', five by default, none at 0 - and refused over it as a mail is
			if( $notify === false ) {

				$appData['./nino/mail/ratelimited'] = false;

				if( \Nino\Feature::count( $appData, 'ratelimit', \Nino\Http::getClientIp( $appData ), (int) ( $appData['/nino/mail/cap'] ?? \Nino\AppData::DEFAULTS['/nino/mail/cap'] ), 3600 ) === false ) {
					$appData['./nino/mail/ratelimited'] = true;
					return false;
				}
			}

			$seconds = $kind === 'login' ? self::_minutes( $appData ) * 60 : self::_days( $appData ) * 86400;
			$token = self::_issue( $appData, $account['id'], $kind, $seconds, $target );

			if( $token === null ) {
				trigger_error( 'Members: a link could not be stored', E_USER_WARNING );
				return false;
			}

			if( $notify === false )
				return \Nino\Html::absoluteUrl( $appData, '/.members/login?t='. rawurlencode( $token ) );

			if( self::_mail( $appData, $account['email'], $kind, $token, $native ) === false ) {
				trigger_error( 'Members: a link could not be mailed', E_USER_WARNING );
				return false;
			}

			return true;
		}

		/**
		 *	Sign every member out at once: write a new session epoch, which no
		 *	session that signed in before it carries, and take every token of a device
		 *	that stays signed in (a cookie would sign the member in again). The panel's
		 *	'Sign everybody out' ends here - a member who left must not stay in because
		 *	the browser kept the session. Nobody is identified, so there is no one to
		 *	name; the epoch is all there is to rotate.
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	bool										False where a file could not be written
		 */
		public static function signOutAll( array &$appData ): bool {

			$written = \Nino\Filesystem::mutate( $appData, self::EPOCH, static fn(): array => [ 'epoch' => bin2hex( random_bytes( 16 ) ) ], [] );
			$ran = false;
			$dirty = false;

			$cleared = \Nino\Filesystem::mutate( $appData, self::ACCOUNTS, static function( mixed $state ) use ( &$ran, &$dirty ): ?array {

				$ran = true;
				$accounts = self::_accountsOf( $state );

				foreach( $accounts as $id => $record )
					if( isset( $record['remember'] ) === true ) {
						unset( $accounts[$id]['remember'] );
						$dirty = true;
					}

				return $dirty === true ? [ 'accounts' => $accounts ] : null;
			}, [] );

			return $written === true && $ran === true && ( $dirty === false || $cleared === true );
		}

		/**
		 *	Sign one account out of every device: the list of the tokens of devices that stay signed in is emptied
		 *	and the account gets an epoch of its own, as a change of the address gives it, so that every session of it
		 *	ends - and no token is left that would sign it in again. The session of the member who asks - where it is
		 *	the account's own - stays: it is written again with the new epoch, and the cookie of this device is
		 *	deleted. Anybody else's session, the owner's among them, is left alone
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id						12 hex characters
		 *
		 *	@return 	bool										False for an id nobody has and where the file could not be written
		 */
		public static function forgetAll( array &$appData, string $id ): bool {

			$mine = self::_own( $appData, [ 'id' => $id ] );
			$epoch = bin2hex( random_bytes( 16 ) );
			$found = false;

			$written = \Nino\Filesystem::mutate( $appData, self::ACCOUNTS, static function( mixed $state ) use ( $id, $epoch, &$found ): ?array {

				$accounts = self::_accountsOf( $state );

				if( isset( $accounts[$id] ) === false )
					return null;

				$found = true;
				unset( $accounts[$id]['remember'] );
				$accounts[$id]['epoch'] = $epoch;

				return [ 'accounts' => $accounts ];
			}, [] );

			if( $found === false || $written === false )
				return false;

			if( $mine === true ) {

				$session = \Nino\Runtime::getSessionValue( $appData, self::SESSION_ACCOUNT );

				\Nino\Runtime::setSessionValue( $appData, self::SESSION_ACCOUNT, [ 'id' => $id, 'epoch' => (string) ( is_array( $session ) === true ? ( $session['epoch'] ?? '' ) : '' ), 'own' => $epoch ] );
				self::_cookie( $appData, '', 0 );
			}

			return true;
		}

		/**
		 *	The gate: replace the response of a request under a protected path - or
		 *	for a page whose uri lies under one, at whichever address a route gives it
		 *	(see _behindTheGate()) - by somebody who is not signed in, with the login
		 *	page. A plain 401 - never a 200, so nothing caches it, and honest about the
		 *	fact that nothing was actually served. The path and the query of the page, as
		 *	they came, are kept in the session, for the code that is requested from this
		 *	page to lead back to it (see _wanted()). The workbench
		 *	and the endpoints of this feature are never protected (see prefixes()), and
		 *	a signed-in workbench user is no exception: the sign-in is the only way in.
		 *
		 *	A request of a signed-in member is kept out of the full-page cache as
		 *	well, whatever its path: a page that is rendered for them may carry their
		 *	name, and the cache would hand it to the next visitor. The cache does not
		 *	know members - it leaves out only the requests of the workbench's users -
		 *	so the path joins its blacklist for this request, ahead of its own
		 *	callback at priority 9
		 *
		 *	The gate is also where a device that stays signed in is signed in (see
		 *	_resume()): before anything reads the member, once for a request
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function callbackGate( array &$appData, array &$request ): void {

			$uri = (string) ( $request['/nino/http/request']['uri'] ?? '' );

			self::_resume( $appData, $request );

			if( \Nino\Runtime::getSessionValue( $appData, self::SESSION_ACCOUNT ) !== null )
				self::_keepOutOfCache( $appData, $uri );

			if( self::_behindTheGate( $appData, $request ) === false || self::current( $appData ) !== null )
				return;

			\Nino\Runtime::setSessionValue( $appData, self::SESSION_TARGET, self::_wanted( $uri, (string) ( $request['REQUEST_URI'] ?? '' ) ) );

			$request['/nino/http/response']['body']										= '[template /templates/page-members-login]';
			$request['/nino/http/response']['statusCode']							= 401;
			$request['/nino/http/response']['header']['Cache-Control']	= 'no-store';
		}

		/**
		 *	Whether a request is for a page behind the gate: the path asked for lies
		 *	under a protected prefix, or the uri of the route that answers it does. The
		 *	Routes panel gives a page one route for each language, every one with the
		 *	page's uri and an address of its own, and the setting names the page - /club,
		 *	say - not each of its addresses: /verein would be open while /club is shut. A
		 *	wildcard route's uri, /club/*, lies under the prefix as well
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$request			Current server request, its response already routed
		 *
		 *	@return 	bool
		 */
		private static function _behindTheGate( array &$appData, array $request ): bool {

			return self::protects( $appData, (string) ( $request['/nino/http/request']['uri'] ?? '' ) ) === true
				|| self::protects( $appData, (string) ( $request['/nino/http/response']['uri'] ?? '' ) ) === true;
		}

		/**
		 *	The page a visitor wanted, as a sign-in leads back to it: the path and the query as they came, without a fragment - no browser sends one. A query that makes
		 *	the whole no path _isPath() accepts (a control character, a blank), or one that makes it longer than TARGET_MAX, leaves the path alone
		 *
		 *	@param		string		$uri					The path of the request
		 *	@param		string		$raw					The address of the request as the server got it
		 *
		 *	@return 	string
		 */
		private static function _wanted( string $uri, string $raw ): string {

			$cut = strpos( $raw, '?' );
			$query = $cut === false ? '' : substr( $raw, $cut + 1 );
			$query = substr( $query, 0, strcspn( $query, '#' ) );
			$target = $query === '' ? $uri : $uri. '?'. $query;

			return strlen( $target ) <= self::TARGET_MAX && self::_isPath( $target ) === true ? $target : $uri;
		}

		/**
		 *	Where a sign-in leads: the page it was asked from or the link carries, else the start page - and the features that build on the sign-in may say another, through the callback
		 *	'/members/start', fired with the account, the target (the page of the sign-in, '' for none) and to (where it leads: the target or the start page). A callback that sets
		 *	to to a path _isPath() accepts moves the member there; any other value of to is the start page
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$account			As account() has it
		 *	@param		string		$target				The path the record of the code or of the link carries
		 *
		 *	@return 	string										A path of this site
		 */
		private static function _arrival( array &$appData, array $account, string $target ): string {

			$target = strlen( $target ) <= self::TARGET_MAX && self::_isPath( $target ) === true ? $target : '';
			$start = self::_start( $appData );
			$args = [ 'account' => $account, 'target' => $target, 'to' => $target !== '' ? $target : $start ];

			\Nino\Callbacks::doCallbacks( $appData, '/members/start', $args );

			$to = is_array( $args ) === true ? ( $args['to'] ?? null ) : null;

			return is_string( $to ) === true && strlen( $to ) <= self::TARGET_MAX && self::_isPath( $to ) === true ? $to : $start;
		}

		/**
		 *	POST /.members/login: ask for a code. The visitor is counted (the setting
		 *	attempts, per hour) and so is the address (3 per hour), by the kernel's
		 *	counter and before anything else, whoever the address belongs to; over
		 *	either the answer is a redirect with ?members=limit and nothing else
		 *	happens. Otherwise, for an address that is one, the session keeps the
		 *	request - the address, a random nonce, no tries yet and the time the code
		 *	is good until - whoever the address belongs to, and an address that belongs
		 *	to an active account is mailed six digits, kept as the sha256 of the nonce
		 *	and the code: the code is worth nothing outside this session. Any other
		 *	address is mailed nothing - and the answer is the same redirect to the page
		 *	the form was on, ?members=sent, for all of them, with the time the request
		 *	took the one difference: nothing may tell whether an address is a member's.
		 *	A text that is no address ends the request that was out, and leaves the form
		 *	to say that there is none. A mail that could not be sent is a warning for
		 *	the log and the same answer. A post the csrf guard refused - a session that
		 *	ran out under a form that stayed open - is answered with a redirect to the
		 *	same page and ?members=expired, in place of the 403 and the empty body the
		 *	guard leaves, and is not counted
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function callbackLoginRequest( array &$appData, array &$request ): void {

			$return = self::_returnPath( $appData, self::_posted( 'return' ) );

			// Respect a rejection from the earlier global Csrf callback - same guard
			// Form/Newsletter/Auth use - and say it to the visitor
			if( \Nino\Form::blocked( $request ) === true ) {
				self::_redirect( $appData, $request, $return, 'expired' );
				return;
			}

			$email = self::_email( self::_posted( 'email' ) );
			$attempts = (int) \Nino\Features::setting( $appData, self::FEATURE_KEY, 'attempts', self::DEFAULT_ATTEMPTS );

			// The visitor first, then - for a text that is an address - the address,
			// each in the lock of its own write: a check made first and a try
			// recorded afterwards is a cap every request of a burst walks through at
			// once. A count that cannot be recorded is a refusal, as the counter
			// answers it
			if( \Nino\Feature::count( $appData, self::COUNTER, 'visitor|'. \Nino\Http::getClientIp( $appData ), $attempts, self::WINDOW ) === false
				|| ( $email !== '' && \Nino\Feature::count( $appData, self::COUNTER, 'address|'. $email, self::ADDRESS_LIMIT, self::WINDOW ) === false ) ) {
				self::_redirect( $appData, $request, $return, 'limit' );
				return;
			}

			if( $email === '' )
				\Nino\Runtime::unsetSessionValue( $appData, self::SESSION_PENDING );
			else {

				// An address that is a member's and one that is not are kept alike: the
				// form asks for a code for both
				$nonce = bin2hex( random_bytes( 16 ) );
				$until = time() + self::_minutes( $appData ) * 60;

				\Nino\Runtime::setSessionValue( $appData, self::SESSION_PENDING, [ 'email' => $email, 'nonce' => $nonce, 'tries' => 0, 'until' => $until ] );

				$account = self::_byEmail( $appData, $email );

				if( $account !== null && $account['active'] === true ) {

					$target = \Nino\Runtime::getSessionValue( $appData, self::SESSION_TARGET );
					$target = is_string( $target ) === true && self::_isPath( $target ) === true ? $target : '';
					$target = self::_anchored( $target, self::_posted( 'return' ) );

					if( self::_sendCode( $appData, $account, $nonce, $until, $target ) === true )
						\Nino\Runtime::unsetSessionValue( $appData, self::SESSION_TARGET );
				}
			}

			self::_redirect( $appData, $request, $return, 'sent' );
		}

		/**
		 *	The place on the page a sign-in leads to: the target the gate kept - the page a visitor asked for, path and query, which no browser sends a fragment with - with the
		 *	anchor the form carries behind its 'return' (a script of the page appends the fragment of the address, #angebot-15). The anchor is kept only when the target is
		 *	the page the form stood on, and only as letters, digits, hyphens and underscores, 64 of them at most; anything else - a second #, a character that is
		 *	none of those, a form that names another page - leaves the target as it is
		 *
		 *	@param		string		$target				What the session kept, '' for none
		 *	@param		string		$posted				The field 'return' of the form
		 *
		 *	@return 	string
		 */
		private static function _anchored( string $target, string $posted ): string {

			$at = strpos( $posted, '#' );

			if( $target === '' || $at === false || preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', substr( $posted, $at + 1 ) ) !== 1 || substr( $posted, 0, strcspn( $posted, '?#' ) ) !== substr( $target, 0, strcspn( $target, '?#' ) ) )
				return $target;

			return strlen( $target. substr( $posted, $at ) ) <= self::TARGET_MAX ? $target. substr( $posted, $at ) : $target;
		}

		/**
		 *	POST /.members/code: the code of the mail. The request of this session is
		 *	the one in the session - without one, or after its time, the answer is a
		 *	redirect to the page the form was on with ?members=gone and the request is
		 *	over. A post the csrf guard refused is answered with ?members=expired and
		 *	leaves the request as it is, with no try used. Otherwise the try is counted
		 *	first, and the code - six digits, spaces left out; anything else is a code
		 *	that is wrong - is looked up as sha256( nonce | code ) and spent. A code
		 *	that is good ends the request, signs the member in (_signIn()), tells the
		 *	features that build on it and redirects, 303, to the path the record of the
		 *	code carries, else to the start page, as callbackConfirm() does - where the
		 *	callback '/members/start' may name another page (see _arrival()); one that is
		 *	good for an account that is gone or switched off ends the request with
		 *	?members=gone. One that is not found is ?members=wrong - and, the fifth time,
		 *	the end of the request and ?members=gone: a new code has to be asked for.
		 *	With the box remember posted, a good code also makes the token of this device and sets the cookie (see _remember())
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function callbackCode( array &$appData, array &$request ): void {

			$return = self::_returnPath( $appData, self::_posted( 'return' ) );

			if( \Nino\Form::blocked( $request ) === true ) {
				self::_redirect( $appData, $request, $return, 'expired' );
				return;
			}

			$pending = self::_pending( $appData );

			if( $pending === null ) {
				\Nino\Runtime::unsetSessionValue( $appData, self::SESSION_PENDING );
				self::_redirect( $appData, $request, $return, 'gone' );
				return;
			}

			$pending['tries']++;
			\Nino\Runtime::setSessionValue( $appData, self::SESSION_PENDING, $pending );

			$code = preg_replace( '/\s+/', '', self::_posted( 'code' ) ) ?? '';
			$spent = preg_match( '/^[0-9]{6}$/D', $code ) === 1 ? self::_spendHash( $appData, hash( 'sha256', $pending['nonce']. '|'. $code ), 'code' ) : null;

			if( $spent === null ) {

				if( $pending['tries'] >= self::CODE_TRIES )
					\Nino\Runtime::unsetSessionValue( $appData, self::SESSION_PENDING );

				self::_redirect( $appData, $request, $return, $pending['tries'] >= self::CODE_TRIES ? 'gone' : 'wrong' );
				return;
			}

			\Nino\Runtime::unsetSessionValue( $appData, self::SESSION_PENDING );

			$account = self::account( $appData, $spent['account'] );

			// A code asked for an address the account has left is no way in: the account still has the address the request was made for
			if( $account === null || $account['active'] === false || $account['email'] !== $pending['email'] ) {
				self::_redirect( $appData, $request, $return, 'gone' );
				return;
			}

			if( self::_signIn( $appData, $account ) === false ) {
				self::_refuse( $appData, $request, 500 );
				return;
			}

			\Nino\Callbacks::doCallbacks( $appData, '/members/login', $account );

			if( self::_posted( 'remember' ) === '1' )
				self::_remember( $appData, $account['id'] );

			\Nino\Http::redirect( $appData, $request, self::_arrival( $appData, $account, $spent['target'] ), 303 );
		}

		/**
		 *	GET /.members/login?t=<token>: follow a link. Nothing is changed by it - a mail
		 *	scanner or a browser that fetches the page ahead, a HEAD, uses nobody's link
		 *	up. A token that is unknown, expired, of no kind this feature makes or for an
		 *	account that is gone or switched off answers the login page in place (see
		 *	_refuse(): status 400, not stored by anyone) with the state 'invalid'. A good
		 *	one answers a page that names the account and holds one button (see
		 *	componentMembersConfirm()), which posts the token to /.members/confirm - status
		 *	200, not stored by anyone and with no referrer, since the address of the page
		 *	carries the token
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function callbackLoginLink( array &$appData, array &$request ): void {

			$query = $request['/nino/http/request']['query'] ?? [];
			$token = is_array( $query ) === true && is_string( $query['t'] ?? null ) === true ? $query['t'] : '';
			$found = self::_find( $appData, $token );
			$account = $found === null ? null : self::account( $appData, $found['account'] );

			if( $account === null || $account['active'] === false ) {
				self::_refuseLink( $appData, $request, $token );
				return;
			}

			self::_confirmPage( $appData, $request, $token, $account, '', 200 );
		}

		/**
		 *	POST /.members/confirm: the button of the page a link opens. The token is in
		 *	the post and nothing else of the link: where the member is sent after the
		 *	sign-in stays in the record of the token on the server, where nobody can change
		 *	it. A post the csrf guard refused leaves a 403 and an empty body; the page is
		 *	answered again instead, with a form that carries the token of the session as
		 *	it is now and the state 'expired' - the token of the link has not been used. A
		 *	token that is spent, has run out or is for an account that is gone or switched
		 *	off answers the login page in place, 400, with the state 'invalid' (an account
		 *	that is switched off has the token spent all the same). A good one is spent
		 *	(deleted), the member is signed in (_signIn()), the features that build on it are
		 *	told, and the answer is a redirect, 303, to the path the token carries, else to
		 *	the start page - where the callback '/members/start' may name another page (see _arrival())
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function callbackConfirm( array &$appData, array &$request ): void {

			$token = self::_posted( 'token' );

			if( \Nino\Form::blocked( $request ) === true ) {

				$found = self::_find( $appData, $token );
				$account = $found === null ? null : self::account( $appData, $found['account'] );

				if( $account === null || $account['active'] === false )
					self::_refuseLink( $appData, $request, $token );
				else
					self::_confirmPage( $appData, $request, $token, $account, 'expired', 403 );

				return;
			}

			$spent = self::_spend( $appData, $token );
			$account = $spent === null ? null : self::account( $appData, $spent['account'] );

			if( $account === null || $account['active'] === false ) {
				self::_refuseLink( $appData, $request, $token );
				return;
			}

			if( self::_signIn( $appData, $account ) === false ) {
				self::_refuse( $appData, $request, 500 );
				return;
			}

			\Nino\Callbacks::doCallbacks( $appData, '/members/login', $account );

			\Nino\Http::redirect( $appData, $request, self::_arrival( $appData, $account, $spent['target'] ), 303 );
		}

		/**
		 *	POST /.members/logout: sign the member out and go to the start page with
		 *	?members=out. A form with the csrf token, as every write of this feature is: a
		 *	link would let any other page sign a member out. A post the csrf guard refused
		 *	leaves the 403 and an empty body, and the member stays signed in. The token
		 *	of this device goes with the session, and the cookie that carried it
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function callbackLogout( array &$appData, array &$request ): void {

			if( \Nino\Form::blocked( $request ) === true )
				return;

			$account = self::current( $appData );

			self::_dropDevice( $appData );

			\Nino\Runtime::unsetSessionValue( $appData, self::SESSION_ACCOUNT );
			\Nino\Runtime::unsetSessionValue( $appData, self::SESSION_CHANGE );

			if( $account !== null )
				\Nino\Callbacks::doCallbacks( $appData, '/members/logout', $account );

			self::_redirect( $appData, $request, self::_start( $appData ), 'out' );
		}

		/**
		 *	GET /.members/logout: nothing is signed out by a request that is no post. The answer is 405 with the header Allow and a short page that links to the start page,
		 *	not stored by anyone
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function callbackLogoutGet( array &$appData, array &$request ): void {

			$request['/nino/http/response']['statusCode']							= 405;
			$request['/nino/http/response']['header']['Allow']					= 'POST';
			$request['/nino/http/response']['header']['Cache-Control']	= 'no-store';
			$request['/nino/http/response']['header']['Content-Type']	= 'text/html; charset=utf-8';
			$request['/nino/http/response']['body']										= '<!doctype html><html lang="'. \Nino\Modules\Components::escape( substr( \Nino\Locales::getCurrentLocale( $appData ), 0, 2 ) ). '"><meta charset="utf-8"><title>405</title><p><a href="'. ( $home = \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ). self::_start( $appData ) ) ). '">'. $home. '</a></p>';
		}

		/**
		 *	The addresses a crawler has no business listing, answered under
		 *	'/seo/exclude' for the Seo feature, where that is installed: every
		 *	protected prefix and everything below it, and the address of every route
		 *	whose page lies under one - Seo matches the addresses of the routes, and a
		 *	page has one for each language (see _behindTheGate())
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$list				(reference) Patterns, in the spelling of Seo's 'exclude' setting
		 *
		 *	@return 	void
		 */
		public static function callbackSeoExclude( array &$appData, array &$list ): void {

			foreach( self::prefixes( $appData ) as $prefix )
				$list[] = $prefix. '/*';

			foreach( \Nino\Http::pages( (array) ( $appData['/nino/http/routes'] ?? [] ) ) as $row )
				if( self::protects( $appData, $row['path'] ) === false && self::protects( $appData, $row['uri'] ) === true )
					$list[] = $row['wildcard'] === true ? $row['path']. '/*' : $row['path'];
		}

		/**
		 *	[members-login] - the form that asks for a code, for a visitor; for a member
		 *	the line that names them, with the link to sign out. The form is in two
		 *	steps. The first is the one POST /.members/login takes: a label and the
		 *	field for the address, the page it stands on as 'return' (where the answer
		 *	goes back to), [csrf] and the button. The second is the one POST
		 *	/.members/code takes (template members-code): the address the code went to,
		 *	the field for the code, the same 'return' and [csrf], the button and a link
		 *	back to the page, to ask for a new code. The second step is drawn while
		 *	the session holds a request that is live (_pending()) and the state is
		 *	'sent' or 'wrong'; a state of the two without such a request is said as
		 *	'gone', in the first step. Both say the state, if there is one, above the
		 *	button: what a callback of this request set, else what the query 'members'
		 *	names, of STATES and nothing else. inline puts the field and the button of
		 *	the first step on one line, where the label is read by screen readers only;
		 *	the second step is never inline. The words are the Text panel's
		 *	/feature/members/ keys, left in the template for the kernel to resolve.
		 *	Over the label of the first step stands the slot note, which the callback
		 *	'/members/login-form' may fill (it is fired with return, the path of the
		 *	page, and note, empty): the markup is the callback's and is put in as it
		 *	is, so a callback escapes whatever it took from the request
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array			$args					The resolved arguments (see \Nino\Modules\Components::dispatch())
		 *
		 *	@return 	string
		 */
		public static function componentMembersLogin( array &$appData, array $args ): string {

			$member = self::current( $appData );

			if( $member !== null )
				return str_replace(
					[ '[[name]]', '[[classes]]' ],
					[ \Nino\Modules\Components::escape( self::_label( $member ) ), \Nino\Feature::classes( $args ) ],
					\Nino\Feature::template( $appData, self::TEMPLATES, 'members-signed' )
				);

			$state = self::_state( $appData );
			$inline = ( $args['inline'] ?? '0' ) === '1';
			$request = \Nino\Http::getRequest( $appData );
			$return = \Nino\Modules\Components::escape( $request === false ? '' : (string) ( $request['/nino/http/request']['uri'] ?? '' ) );
			$pending = in_array( $state, [ 'sent', 'wrong' ], true ) === true ? self::_pending( $appData ) : null;

			// A request that is over - or that no session knows - is gone, and the form starts again
			if( $pending === null && in_array( $state, [ 'sent', 'wrong' ], true ) === true )
				$state = 'gone';

			// Every value escaped, and the markup that was built last: the message is a
			// template of its own - left out where there is none to say - and a token
			// after it would be looked for in it as well
			if( $pending !== null )
				return str_replace(
					[ '[[id]]', '[[return]]', '[[email]]', '[[classes]]', '[[remember]]', '[[state]]' ],
					[
						'members-code-'. ( $appData[self::FORMS] = (int) ( $appData[self::FORMS] ?? 0 ) + 1 ),
						$return,
						\Nino\Modules\Components::escape( $pending['email'] ),
						\Nino\Feature::classes( $args ),
						self::_box( $appData ),
						self::_say( $appData, $state ),
					],
					\Nino\Feature::template( $appData, self::TEMPLATES, 'members-code' )
				);

			// The slot over the field: a feature that builds on the sign-in may put a sentence there (markup it has escaped itself); a callback that does not answer leaves it empty
			$slot = [ 'return' => $request === false ? '' : (string) ( $request['/nino/http/request']['uri'] ?? '' ), 'note' => '' ];
			\Nino\Callbacks::doCallbacks( $appData, '/members/login-form', $slot );

			return str_replace(
				[ '[[inline]]', '[[hidden]]', '[[id]]', '[[return]]', '[[classes]]', '[[note]]', '[[state]]' ],
				[
					$inline === true ? ' nino-form--inline' : '',
					$inline === true ? ' nino-sr-only' : '',
					'members-login-'. ( $appData[self::FORMS] = (int) ( $appData[self::FORMS] ?? 0 ) + 1 ),
					$return,
					\Nino\Feature::classes( $args ),
					is_array( $slot ) === true && is_string( $slot['note'] ?? null ) === true ? $slot['note'] : '',
					self::_say( $appData, $state ),
				],
				\Nino\Feature::template( $appData, self::TEMPLATES, 'members-login' )
			);
		}

		/**
		 *	[members-logout] - a link that signs the member out; nothing for a
		 *	visitor, so that a frame can carry it unconditionally
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array			$args					The resolved arguments
		 *
		 *	@return 	string
		 */
		public static function componentMembersLogout( array &$appData, array $args ): string {

			if( self::current( $appData ) === null )
				return '';

			return str_replace( '[[classes]]', \Nino\Feature::classes( $args ), \Nino\Feature::template( $appData, self::TEMPLATES, 'members-logout' ) );
		}

		/**
		 *	[members-name] - the name of the member who is signed in, their email
		 *	address where the account has no name; nothing for a visitor
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array			$args					The resolved arguments
		 *
		 *	@return 	string
		 */
		public static function componentMembersName( array &$appData, array $args ): string {

			$member = self::current( $appData );

			if( $member === null )
				return '';

			return str_replace(
				[ '[[name]]', '[[classes]]' ],
				[ \Nino\Modules\Components::escape( self::_label( $member ) ), \Nino\Feature::classes( $args ) ],
				\Nino\Feature::template( $appData, self::TEMPLATES, 'members-name' )
			);
		}

		/**
		 *	[members-confirm] - the button of the page a link opens: the name of the
		 *	account the link signs in, the token of the link as a hidden field, [csrf]
		 *	and one button that posts them to /.members/confirm. It is the page of the
		 *	install unit that has it; anywhere else, and on every page that no link is
		 *	opened on, it shows nothing. Where it is shown again after a post the csrf
		 *	guard refused, the state 'expired' is said above the button, as the form of
		 *	[members-login] says it. The form is no .nino-form, for the reason the login
		 *	form is none: the kernel's script would post it by XHR
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array			$args					The resolved arguments
		 *
		 *	@return 	string
		 */
		public static function componentMembersConfirm( array &$appData, array $args ): string {

			$pending = $appData[self::CONFIRM] ?? null;

			if( is_array( $pending ) === false )
				return '';

			$state = (string) ( $pending['state'] ?? '' );

			return str_replace(
				[ '[[name]]', '[[token]]', '[[classes]]', '[[state]]' ],
				[
					\Nino\Modules\Components::escape( (string) ( $pending['label'] ?? '' ) ),
					\Nino\Modules\Components::escape( (string) ( $pending['token'] ?? '' ) ),
					\Nino\Feature::classes( $args ),
					self::_say( $appData, $state ),
				],
				\Nino\Feature::template( $appData, self::TEMPLATES, 'members-confirm' )
			);
		}

		/**
		 *	The write behind change() and confirmChange(): the rules of change(), for whoever asks. A new
		 *	address ends what the old one let in, in this order: the account's links and codes are deleted
		 *	first, then the address is written, then they are deleted once more - a write that fails leaves
		 *	the account as it was with nothing left that signs in, and a link or a code made between the two
		 *	deletions does not outlive the address - a code to sign in is taken only while the account still has the
		 *	address the request was made for (callbackCode()), so one that was asked for the old address after
		 *	the first deletion is gone as well. What the lock would refuse (an address another account
		 *	has) is refused before anything is deleted, so a change that does not happen takes nobody's
		 *	links away. The old address is mailed that it was changed - in the language of the request,
		 *	in the site's native one for the owner, who is not in the room - and '/members/changed' is
		 *	fired; a notice that could not be sent is a warning and the change stays
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *	@param		array 		$changes			See change()
		 *	@param		string		$by						'owner' or 'member': who asked, for the callback and the language of the notice
		 *
		 *	@return 	array|string							The account as the file has it, with its id and its epoch; or why not, see change()
		 */
		private static function _rewrite( array &$appData, string $id, array $changes, string $by ): array|string {

			$name = is_string( $changes['name'] ?? null ) === true ? trim( $changes['name'] ) : null;
			$email = null;

			if( array_key_exists( 'email', $changes ) === true ) {

				$email = is_string( $changes['email'] ) === true ? self::_email( $changes['email'] ) : '';

				if( $email === '' )
					return 'invalid';
			}

			if( $name !== null && mb_strlen( $name, 'UTF-8' ) > self::NAME_LENGTH )
				return 'long';

			$group = is_string( $changes['group'] ?? null ) === true ? \Nino\Feature::slug( $changes['group'] ) : null;
			$active = is_bool( $changes['active'] ?? null ) === true ? $changes['active'] : null;
			$current = $email === null ? null : self::_record( $appData, $id );

			if( $current !== null && $current['email'] !== $email ) {

				if( self::_byEmail( $appData, (string) $email ) !== null )
					return 'taken';

				if( self::_forget( $appData, $id ) === false )
					return 'write';
			}

			$ran = false;
			$found = null;
			$taken = false;
			$same = false;
			$from = '';

			$written = \Nino\Filesystem::mutate( $appData, self::ACCOUNTS, static function( mixed $state ) use ( $id, $name, $group, $active, $email, &$ran, &$found, &$taken, &$same, &$from ): ?array {

				$ran = true;
				$accounts = self::_accountsOf( $state );

				if( isset( $accounts[$id] ) === false )
					return null;

				$before = $accounts[$id];
				$accounts[$id] = array_merge( $before, array_filter( [ 'name' => $name, 'group' => $group, 'active' => $active ], static fn( mixed $value ): bool => $value !== null ) );

				// An account that is switched off keeps no device that would sign it in again
				if( $active === false )
					unset( $accounts[$id]['remember'] );

				if( $email !== null && $email !== $before['email'] ) {

					foreach( $accounts as $other => $each )
						if( (string) $other !== $id && $each['email'] === $email ) {
							$taken = true;
							return null;
						}

					$accounts[$id]['email'] = $email;
					$accounts[$id]['epoch'] = bin2hex( random_bytes( 16 ) );

					// The devices were signed in under the old address
					unset( $accounts[$id]['remember'] );
					$from = $before['email'];
				}

				$found = [ 'id' => $id ] + $accounts[$id];
				$same = $accounts[$id] === $before;

				return $same === true ? null : [ 'accounts' => $accounts ];
			}, [] );

			if( $ran === true && $found === null )
				return $taken === true ? 'taken' : 'unknown';

			if( $found === null || ( $written === false && $same === false ) )
				return 'write';

			if( $from === '' )
				return $found;

			if( self::_forget( $appData, $id ) === false )
				trigger_error( 'Members: the links of an account could not be deleted after its address was changed', E_USER_WARNING );

			if( self::_mail( $appData, $from, 'changed', '', $by === 'owner' ) === false )
				trigger_error( 'Members: the old address of an account could not be told that it was changed', E_USER_WARNING );

			$told = [ 'account' => [ 'id' => $id ] + self::_plain( $found ), 'old' => $from, 'by' => $by ];

			\Nino\Callbacks::doCallbacks( $appData, '/members/changed', $told );

			return $found;
		}

		/**
		 *	Sign an account in: the epoch is opened (written, when the first sign-in
		 *	of the project happens), the time of the sign-in is stamped on the
		 *	account, and the session gets the account and the epoch. The session id
		 *	and the csrf token are replaced first, now that the identity of the
		 *	session changes - the fixation defence \Nino\Auth::loginUser() makes, and
		 *	the same calls: startSession() answers false for a caller that has no
		 *	session to rotate (a test)
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$account			(reference) The account; its 'login' is the time now
		 *
		 *	@return 	bool										False where the epoch could not be written - nobody is signed in then
		 */
		private static function _signIn( array &$appData, array &$account ): bool {

			$epoch = self::_openEpoch( $appData );

			if( $epoch === '' )
				return false;

			$now = gmdate( self::TIME );
			$id = $account['id'];

			// A time that could not be stored is a warning and no reason to turn
			// a member away who holds a good link
			$stamped = \Nino\Filesystem::mutate( $appData, self::ACCOUNTS, static function( mixed $state ) use ( $id, $now ): ?array {

				$accounts = self::_accountsOf( $state );

				if( isset( $accounts[$id] ) === false )
					return null;

				$accounts[$id]['login'] = $now;

				return [ 'accounts' => $accounts ];
			}, [] );

			if( $stamped === false )
				trigger_error( 'Members: the time of a sign-in could not be stored', E_USER_WARNING );
			else
				$account['login'] = $now;

			if( \Nino\Runtime::startSession( $appData ) === true )
				session_regenerate_id( true );

			\Nino\Csrf::rotateToken( $appData );

			// A change of an address that was asked for belongs to the one who asked: whoever signs in here starts without it
			\Nino\Runtime::unsetSessionValue( $appData, self::SESSION_CHANGE );

			$own = (string) ( self::_record( $appData, $id )['epoch'] ?? '' );

			\Nino\Runtime::setSessionValue( $appData, self::SESSION_ACCOUNT, [ 'id' => $id, 'epoch' => $epoch ] + ( $own !== '' ? [ 'own' => $own ] : [] ) );

			return true;
		}

		/**
		 *	The devices of an account that stay signed in, each read for what it has to be: the sha256 of its token (64 hex characters), the time it was made, the time
		 *	it is good until (an integer) and the user agent it was made from (a string) - and, for thirty seconds after a use, the sha256 of the token it replaced and the time of the replacement. An entry that is none is left out, and so is one that has run out
		 *
		 *	@param		mixed			$list					As the account's record holds it
		 *
		 *	@return 	array										A list of hash, created, expires and agent, the oldest first; at most REMEMBER_MAX
		 */
		private static function _devicesOf( mixed $list ): array {

			$devices = [];
			$now = time();

			foreach( is_array( $list ) === true ? $list : [] as $entry )
				if( is_array( $entry ) === true && is_string( $entry['hash'] ?? null ) === true && preg_match( '/^[0-9a-f]{64}$/D', $entry['hash'] ) === 1
					&& is_string( $entry['created'] ?? null ) === true && is_int( $entry['expires'] ?? null ) === true && $entry['expires'] > $now && is_string( $entry['agent'] ?? null ) === true )
					$devices[] = [ 'hash' => $entry['hash'], 'created' => $entry['created'], 'expires' => $entry['expires'], 'agent' => mb_substr( $entry['agent'], 0, self::AGENT_LENGTH, 'UTF-8' ) ]
						+ ( is_string( $entry['previous'] ?? null ) === true && preg_match( '/^[0-9a-f]{64}$/D', $entry['previous'] ) === 1 && is_int( $entry['rotated'] ?? null ) === true && $now - $entry['rotated'] <= self::REMEMBER_GRACE
							? [ 'previous' => $entry['previous'], 'rotated' => $entry['rotated'] ] : [] );

			return array_slice( $devices, -self::REMEMBER_MAX );
		}

		/**
		 *	The user agent of the request, as it is kept with a device: control characters out, at most AGENT_LENGTH characters
		 *
		 *	@return 	string
		 */
		private static function _agent(): string {

			$agent = is_string( $_SERVER['HTTP_USER_AGENT'] ?? null ) === true ? $_SERVER['HTTP_USER_AGENT'] : '';

			return mb_substr( preg_replace( '/[\x00-\x1f\x7f]/', '', mb_scrub( $agent, 'UTF-8' ) ) ?? '', 0, self::AGENT_LENGTH, 'UTF-8' );
		}

		/**
		 *	A token of 32 random bytes as base64url, 43 characters
		 *
		 *	@return 	string
		 */
		private static function _deviceToken(): string {

			return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		}

		/**
		 *	The cookie of this request taken apart: the id of an account and a token, in the form _remember() writes them
		 *
		 *	@return 	array|null								[ id, token ]; null for no cookie, and for one that is not of that form
		 */
		private static function _deviceCookie(): ?array {

			$raw = is_string( $_COOKIE[self::REMEMBER] ?? null ) === true ? $_COOKIE[self::REMEMBER] : '';

			return preg_match( '/^([0-9a-f]{12})\.([A-Za-z0-9_-]{43})$/D', $raw, $found ) === 1 ? [ $found[1], $found[2] ] : null;
		}

		/**
		 *	Set the cookie of a device that stays signed in - or, with no seconds, delete it. HttpOnly, SameSite=Lax, Secure where the request is https (as the session cookie
		 *	is), for the whole site. The line is kept in the app data for the request ('./members/cookies') and sent unless the headers have gone: a header added, never one that
		 *	replaces another, since the session cookie is sent with it
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$value				The id of the account, a dot and the token; '' for a cookie that is deleted
		 *	@param		int				$seconds			How long the browser keeps it; 0 deletes it
		 *
		 *	@return 	void
		 */
		private static function _cookie( array &$appData, string $value, int $seconds ): void {

			$secure = ( $appData['/nino/session/force-secure-cookie'] ?? false ) === true || ( ( $_SERVER['HTTPS'] ?? '' ) !== '' && ( $_SERVER['HTTPS'] ?? '' ) !== 'off' );
			$line = self::REMEMBER. '='. $value. '; Max-Age='. max( 0, $seconds ). '; Path=/'. ( $seconds <= 0 ? '; Expires=Thu, 01 Jan 1970 00:00:00 GMT' : '' ). '; HttpOnly'. ( $secure === true ? '; Secure' : '' ). '; SameSite=Lax';

			$appData['./members/cookies'] = array_merge( (array) ( $appData['./members/cookies'] ?? [] ), [ $line ] );

			if( headers_sent() === false )
				header( 'Set-Cookie: '. $line, false );
		}

		/**
		 *	Make the token of this device after a code that was given with the box ticked: 32 random bytes, of which the account keeps the sha256, the time, the end of its
		 *	days (the setting rememberDays) and the user agent - in the list remember, with the entries that have run out gone and the oldest one going when there are
		 *	more than ten. The token of this device from an earlier time, if the browser brings one, is replaced. The cookie is set; nothing is set where the account could not be written
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id						The account that is signed in
		 *
		 *	@return 	bool											Whether the cookie was set
		 */
		private static function _remember( array &$appData, string $id ): bool {

			$token = self::_deviceToken();
			$days = self::_rememberDays( $appData );
			$now = time();
			$agent = self::_agent();
			$stale = self::_deviceCookie();
			$staleHash = $stale !== null && $stale[0] === $id ? hash( 'sha256', $stale[1] ) : '';
			$found = false;

			$written = \Nino\Filesystem::mutate( $appData, self::ACCOUNTS, static function( mixed $state ) use ( $id, $token, $days, $now, $agent, $staleHash, &$found ): ?array {

				$accounts = self::_accountsOf( $state );

				if( isset( $accounts[$id] ) === false || $accounts[$id]['active'] === false )
					return null;

				$found = true;
				$devices = array_values( array_filter( $accounts[$id]['remember'] ?? [], static fn( array $entry ): bool => $staleHash === '' || hash_equals( $entry['hash'], $staleHash ) === false ) );
				$devices[] = [ 'hash' => hash( 'sha256', $token ), 'created' => gmdate( self::TIME, $now ), 'expires' => $now + $days * 86400, 'agent' => $agent ];
				$accounts[$id]['remember'] = array_slice( $devices, -self::REMEMBER_MAX );

				return [ 'accounts' => $accounts ];
			}, [] );

			if( $found === false || $written === false )
				return false;

			self::_cookie( $appData, $id. '.'. $token, $days * 86400 );

			return true;
		}

		/**
		 *	Sign in a visitor who has no session and brings the cookie of a device that stays signed in. Only the request for a page that is read (a GET, no endpoint of this
		 *	feature, no answer that is no html - the manifest and the icon of a portal look at nobody), once for a request, as the gate is. A good token - the account is
		 *	there and active, the sha256 of the token is in its list, compared with hash_equals, and not run out - signs the member in as a code does (_signIn(), the callback
		 *	'/members/login') and is replaced by a new one in the same write (the old one is dead from then on, the days start again) and in the cookie. A cookie that is no good - not of
		 *	the form, an account that is gone, a token the account does not hold, one that has run out - is deleted and counts as a try of its address, as a wrong code does; an
		 *	address that has brought REMEMBER_TRIES of them in an hour is no longer counted and no longer has its cookie deleted - the count never stands in the way of a good token. The token a use
		 *	has just replaced signs in for REMEMBER_GRACE seconds more, with no new token, no cookie and no write: two tabs that open at once bring the same cookie. No token and no cookie is ever written down
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request, its response already routed
		 *
		 *	@return 	void
		 */
		private static function _resume( array &$appData, array &$request, bool $link = false ): void {

			if( is_string( $_COOKIE[self::REMEMBER] ?? null ) === false || $_COOKIE[self::REMEMBER] === '' )
				return;

			$uri = (string) ( $request['/nino/http/request']['uri'] ?? '' );

			if( ( $request['/nino/http/request']['method'] ?? '' ) !== 'GET' || ( $link === false && str_starts_with( $uri, '/.members' ) === true ) || str_starts_with( $uri, '/_' ) === true
				|| \Nino\Http::answersHtml( (array) ( $request['/nino/http/response'] ?? [] ) ) === false || self::current( $appData ) !== null )
				return;

			$who = self::REMEMBER_COUNTS. \Nino\Http::getClientIp( $appData );
			$device = self::_deviceCookie();
			$next = self::_deviceToken();
			$id = $device === null ? '' : $device[0];
			$hash = $device === null ? '' : hash( 'sha256', $device[1] );
			$days = self::_rememberDays( $appData );
			$now = time();
			$agent = self::_agent();
			$rotated = false;
			$grace = false;
			$written = false;

			if( $device !== null )
				$written = \Nino\Filesystem::mutate( $appData, self::ACCOUNTS, static function( mixed $state ) use ( $id, $hash, $next, $days, $now, $agent, &$rotated, &$grace ): ?array {

					$accounts = self::_accountsOf( $state );

					if( isset( $accounts[$id] ) === false || $accounts[$id]['active'] === false )
						return null;

					// Every entry is compared, whichever one matches: the time it takes does not say which place it was
					$at = null;
					$before = false;

					foreach( $accounts[$id]['remember'] ?? [] as $place => $entry ) {

						if( hash_equals( $entry['hash'], $hash ) === true )
							$at = $place;

						if( hash_equals( $entry['previous'] ?? str_repeat( '0', 64 ), $hash ) === true && isset( $entry['previous'] ) === true )
							$before = true;
					}

					if( $at === null ) {

						// The token a use has just replaced: a second request that brought it - two tabs that opened at once - is signed in as well, and changes nothing
						$grace = $before;

						return null;
					}

					$accounts[$id]['remember'][$at] = [ 'hash' => hash( 'sha256', $next ), 'created' => $accounts[$id]['remember'][$at]['created'], 'expires' => $now + $days * 86400, 'agent' => $agent, 'previous' => $hash, 'rotated' => $now ];
					$rotated = true;

					return [ 'accounts' => $accounts ];
				}, [] );

			if( $grace === false && ( $rotated === false || $written === false ) ) {

				// Not a way in: the cookie goes and the address has tried once - unless it has tried twenty times, when the count is left alone. The count never stands in the way of a good token
				if( \Nino\Feature::spent( $appData, self::COUNTER, $who, self::REMEMBER_TRIES ) === false ) {
					self::_cookie( $appData, '', 0 );
					\Nino\Feature::count( $appData, self::COUNTER, $who, self::REMEMBER_TRIES, self::WINDOW );
				}

				return;
			}

			if( $rotated === true )
				self::_cookie( $appData, $id. '.'. $next, $days * 86400 );

			$account = self::account( $appData, $id );

			if( $account === null || self::_signIn( $appData, $account ) === false )
				return;

			\Nino\Callbacks::doCallbacks( $appData, '/members/login', $account );
		}

		/**
		 *	Take the token of this device out of its account - the one the cookie of the request names, where the account holds it - and delete the cookie. Whoever is
		 *	signed in or not: the browser that signs out has no cookie left, and a token that is out of the list signs nobody in
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	void
		 */
		private static function _dropDevice( array &$appData ): void {

			if( is_string( $_COOKIE[self::REMEMBER] ?? null ) === false || $_COOKIE[self::REMEMBER] === '' )
				return;

			$device = self::_deviceCookie();

			if( $device !== null ) {

				$id = $device[0];
				$hash = hash( 'sha256', $device[1] );

				\Nino\Filesystem::mutate( $appData, self::ACCOUNTS, static function( mixed $state ) use ( $id, $hash ): ?array {

					$accounts = self::_accountsOf( $state );
					$kept = array_values( array_filter( $accounts[$id]['remember'] ?? [], static fn( array $entry ): bool => hash_equals( $entry['hash'], $hash ) === false && hash_equals( $entry['previous'] ?? $entry['hash'], $hash ) === false ) );

					if( isset( $accounts[$id] ) === false || $kept === ( $accounts[$id]['remember'] ?? [] ) )
						return null;

					if( $kept === [] )
						unset( $accounts[$id]['remember'] );
					else
						$accounts[$id]['remember'] = $kept;

					return [ 'accounts' => $accounts ];
				}, [] );
			}

			self::_cookie( $appData, '', 0 );
		}

		/**
		 *	Make a token for an account and keep its hash: 32 random bytes, base64url,
		 *	which is mailed once and never stored
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id						The account
		 *	@param		string		$kind					One of LINKS
		 *	@param		int				$seconds			How long it is good for
		 *	@param		string		$target				The path the member asked for, '' for none
		 *
		 *	@return 	string|null								The token; null where it could not be stored
		 */
		private static function _issue( array &$appData, string $id, string $kind, int $seconds, string $target ): ?string {

			$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );

			return self::_keep( $appData, hash( 'sha256', $token ), $id, $kind, time() + $seconds, $target ) === true ? $token : null;
		}

		/**
		 *	Keep the record of a token or of a code under its hash. The entries that
		 *	have run out go in the same write
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$hash					The sha256 of the token, or of the nonce, a bar and the code
		 *	@param		string		$id						The account
		 *	@param		string		$kind					One of KINDS
		 *	@param		int				$expires			The time it is good until
		 *	@param		string		$target				The path the member asked for, '' for none
		 *	@param		string		$email				For the code of a change: the new address, which the record keeps; '' for any other
		 *
		 *	@return 	bool											Whether it was written
		 */
		private static function _keep( array &$appData, string $hash, string $id, string $kind, int $expires, string $target, string $email = '' ): bool {

			$now = time();

			return \Nino\Filesystem::mutate( $appData, self::TOKENS, static function( mixed $state ) use ( $hash, $id, $kind, $expires, $target, $email, $now ): array {

				$tokens = self::_liveTokens( $state, $now );
				$tokens[$hash] = [ 'account' => $id, 'kind' => $kind, 'expires' => $expires, 'target' => $target ] + ( $kind === 'change' ? [ 'email' => $email ] : [] );

				return $tokens;
			}, [] );
		}

		/**
		 *	Delete every link and code an account has out, of every kind - what a new
		 *	address ends: the old address let them in. Nothing is written where it has none
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id						The account
		 *
		 *	@return 	bool											False where the file could not be written
		 */
		private static function _forget( array &$appData, string $id ): bool {

			$ran = false;
			$dirty = false;

			$written = \Nino\Filesystem::mutate( $appData, self::TOKENS, static function( mixed $state ) use ( $id, &$ran, &$dirty ): ?array {

				$ran = true;
				$kept = array_filter( self::_liveTokens( $state, time() ), static fn( array $entry ): bool => $entry['account'] !== $id );

				if( $kept === ( is_array( $state ) === true ? $state : [] ) )
					return null;

				$dirty = true;

				return $kept;
			}, [] );

			return $ran === true && ( $dirty === false || $written === true );
		}

		/**
		 *	Make a code for an account, keep it under the hash of the session's nonce
		 *	and the code - nothing else of it is stored - and mail it. Six digits, filled
		 *	up with zeros, from random_int(). Nothing is mailed where the record could
		 *	not be written, and a mail that no transport takes is false
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$account			As account() has it
		 *	@param		string		$nonce				32 hex characters of the request the session holds
		 *	@param		int				$until				The time the code is good until
		 *	@param		string		$target				The path the member asked for, '' for none - where the sign-in leads
		 *
		 *	@return 	bool											Whether the mail was delivered - false, with a warning that names no address, where the code could not be stored or the mail was not taken
		 */
		private static function _sendCode( array &$appData, array $account, string $nonce, int $until, string $target ): bool {

			$code = sprintf( '%06d', random_int( 0, 999999 ) );

			if( self::_keep( $appData, hash( 'sha256', $nonce. '|'. $code ), $account['id'], 'code', $until, $target ) === false ) {
				trigger_error( 'Members: a code could not be stored', E_USER_WARNING );
				return false;
			}

			if( self::_mail( $appData, $account['email'], 'code', $code, false ) === false ) {
				trigger_error( 'Members: a code could not be mailed', E_USER_WARNING );
				return false;
			}

			return true;
		}

		/**
		 *	Make the code of a change of address, keep it under the hash of the session's nonce
		 *	and the code, with the account and the new address, and mail it to the new
		 *	address: six digits, in the words of the request's language. Nothing is mailed
		 *	where the record could not be written
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id						The account
		 *	@param		string		$email				The new address, lower case
		 *	@param		string		$nonce				32 hex characters of the request the session holds
		 *	@param		int				$until				The time the code is good until
		 *
		 *	@return 	bool											Whether the mail was delivered - false, with a warning that names no address, where the code could not be stored or the mail was not taken
		 */
		private static function _sendChange( array &$appData, string $id, string $email, string $nonce, int $until ): bool {

			$code = sprintf( '%06d', random_int( 0, 999999 ) );

			if( self::_keep( $appData, hash( 'sha256', $nonce. '|'. $code ), $id, 'change', $until, '', $email ) === false ) {
				trigger_error( 'Members: a code for a change of address could not be stored', E_USER_WARNING );
				return false;
			}

			if( self::_mail( $appData, $email, 'change', $code, false ) === false ) {
				trigger_error( 'Members: a code for a change of address could not be mailed', E_USER_WARNING );
				return false;
			}

			return true;
		}

		/**
		 *	The change of an address the session holds, as long as it is live: well formed - an
		 *	account, the new address, a nonce of 32 hex characters, the tries used and the time it
		 *	is good until - not run out and with a try left
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array|null								account, email, nonce, tries and until - null for none, or one that is over
		 */
		private static function _changePending( array &$appData ): ?array {

			$pending = \Nino\Runtime::getSessionValue( $appData, self::SESSION_CHANGE );

			if( is_array( $pending ) === false || is_string( $pending['account'] ?? null ) === false || preg_match( '/^[0-9a-f]{12}$/D', $pending['account'] ) !== 1
				|| is_string( $pending['email'] ?? null ) === false || self::_email( $pending['email'] ) !== $pending['email'] || is_string( $pending['nonce'] ?? null ) === false
				|| preg_match( '/^[0-9a-f]{32}$/D', $pending['nonce'] ) !== 1 || is_int( $pending['tries'] ?? null ) === false || is_int( $pending['until'] ?? null ) === false )
				return null;

			if( $pending['until'] <= time() || $pending['tries'] >= self::CODE_TRIES )
				return null;

			return [ 'account' => $pending['account'], 'email' => $pending['email'], 'nonce' => $pending['nonce'], 'tries' => $pending['tries'], 'until' => $pending['until'] ];
		}

		/**
		 *	The request for a code the session holds, as long as it is live: well formed
		 *	- an address, a nonce of 32 hex characters, the tries used and the time it
		 *	is good until - not run out and with a try left
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array|null								email, nonce, tries and until - null for none, or one that is over
		 */
		private static function _pending( array &$appData ): ?array {

			$pending = \Nino\Runtime::getSessionValue( $appData, self::SESSION_PENDING );

			if( is_array( $pending ) === false || is_string( $pending['email'] ?? null ) === false || is_string( $pending['nonce'] ?? null ) === false
				|| preg_match( '/^[0-9a-f]{32}$/D', $pending['nonce'] ) !== 1 || is_int( $pending['tries'] ?? null ) === false || is_int( $pending['until'] ?? null ) === false )
				return null;

			if( $pending['until'] <= time() || $pending['tries'] >= self::CODE_TRIES )
				return null;

			return [ 'email' => $pending['email'], 'nonce' => $pending['nonce'], 'tries' => $pending['tries'], 'until' => $pending['until'] ];
		}

		/**
		 *	Look at a token without using it up: the record of a token that is good, as
		 *	_spend() would hand it over, and nothing written - a page that only asks
		 *	whether a link leads in must not be the one that spends it. An unknown,
		 *	expired or malformed one is nothing
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$token
		 *
		 *	@return 	array|null								account (the id), kind, expires and target - null for no token
		 */
		private static function _find( array &$appData, string $token ): ?array {

			if( preg_match( '/^[A-Za-z0-9_-]{43}$/D', $token ) !== 1 )
				return null;

			$now = time();
			$entry = self::_liveTokens( \Nino\Filesystem::getFileContent( $appData, self::TOKENS, [] ), $now )[ hash( 'sha256', $token ) ] ?? null;

			return $entry !== null && $entry['expires'] > $now ? $entry : null;
		}

		/**
		 *	Spend a token: delete it, and answer what it was for. An unknown, expired
		 *	or malformed one is nothing, and so is one whose deletion could not be
		 *	written - a link that is not spent is a link that can be used twice
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$token
		 *
		 *	@return 	array|null								account (the id), kind and target - null for no token
		 */
		private static function _spend( array &$appData, string $token ): ?array {

			if( preg_match( '/^[A-Za-z0-9_-]{43}$/D', $token ) !== 1 )
				return null;

			return self::_spendHash( $appData, hash( 'sha256', $token ) );
		}

		/**
		 *	Spend a record by its hash: delete it, and answer what it was for. An
		 *	unknown or expired one is nothing, and so is one whose deletion could not
		 *	be written. The entries that have run out go in the same write
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$hash					The sha256 of a token, or of a nonce, a bar and a code
		 *	@param		string		$kind					The kind the record has to be of, '' for any: a record of another kind is nothing, and stays
		 *
		 *	@return 	array|null								account (the id), kind, expires, target and - for a change - email; null for no record
		 */
		private static function _spendHash( array &$appData, string $hash, string $kind = '' ): ?array {

			$now = time();
			$found = null;

			$written = \Nino\Filesystem::mutate( $appData, self::TOKENS, static function( mixed $state ) use ( $hash, $kind, $now, &$found ): ?array {

				$tokens = is_array( $state ) === true ? $state : [];
				$live = self::_liveTokens( $state, $now );
				$entry = $live[$hash] ?? null;

				if( $entry !== null && $entry['expires'] > $now && ( $kind === '' || $entry['kind'] === $kind ) ) {
					$found = $entry;
					unset( $live[$hash] );
				}

				return $live === $tokens ? null : $live;
			}, [] );

			return $written === true && $found !== null ? $found : null;
		}

		/**
		 *	The tokens that are kept: an entry that is well formed - an account, a
		 *	kind of KINDS, a target that is a string, and for a change the new address -
		 *	and has not run out; the record of a link that ran out stays for LINK_KEEP
		 *	seconds, for _expiredTarget() alone: what asks for a token that is good looks at
		 *	'expires' itself (_find(), _spendHash())
		 *
		 *	@param		mixed			$state				As the file holds it
		 *	@param		int				$now
		 *
		 *	@return 	array										hash => account, kind, expires, target - and email, for a change
		 */
		private static function _liveTokens( mixed $state, int $now ): array {

			$live = [];

			foreach( is_array( $state ) === true ? $state : [] as $hash => $entry )
				if( is_array( $entry ) === true && is_string( $entry['account'] ?? null ) === true && in_array( $entry['kind'] ?? null, self::KINDS, true ) === true
					&& is_int( $entry['expires'] ?? null ) === true && $entry['expires'] > $now - ( in_array( $entry['kind'], self::LINKS, true ) === true ? self::LINK_KEEP : 0 ) && is_string( $entry['target'] ?? null ) === true
					&& ( $entry['kind'] !== 'change' || is_string( $entry['email'] ?? null ) === true ) )
					$live[(string) $hash] = [ 'account' => $entry['account'], 'kind' => $entry['kind'], 'expires' => $entry['expires'], 'target' => $entry['target'] ] + ( $entry['kind'] === 'change' ? [ 'email' => $entry['email'] ] : [] );

			return $live;
		}

		/**
		 *	Where a link led that has run out: the path its record carries, and nothing for a token that is not well formed, one the file does not know - a
		 *	record is kept for LINK_KEEP seconds after its end - or one that is still good. Only what the server stored with the token is answered, never
		 *	a value of the request
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$token
		 *
		 *	@return 	string										A path _isPath() accepts, '' for none
		 */
		private static function _expiredTarget( array &$appData, string $token ): string {

			if( preg_match( '/^[A-Za-z0-9_-]{43}$/D', $token ) !== 1 )
				return '';

			$now = time();
			$entry = self::_liveTokens( \Nino\Filesystem::getFileContent( $appData, self::TOKENS, [] ), $now )[ hash( 'sha256', $token ) ] ?? null;

			return $entry !== null && in_array( $entry['kind'], self::LINKS, true ) === true && $entry['expires'] <= $now && strlen( $entry['target'] ) <= self::TARGET_MAX && self::_isPath( $entry['target'] ) === true ? $entry['target'] : '';
		}

		/**
		 *	Mail a link or a code, or the notice of a change. The template is rendered first - its words are the
		 *	keys of the unit - and the link or the code, the validity, the day and the contact address of the
		 *	project are put in afterwards, in the result, so that nothing in them is ever read as a fill or
		 *	a shortcode (the order \Nino\Form::render() keeps). A code is set as two
		 *	groups of three digits. The reply goes to the project's contact address
		 *	where it has one
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$to
		 *	@param		string		$kind					'login', 'invite', 'send', 'code', 'change' (a code, to the new address) or 'changed' (the notice to the old address that it was changed, which names neither the new one nor a code)
		 *	@param		string		$token				The token of a link, the six digits of a code - nothing for a notice
		 *	@param		bool			$native				Write in the site's native language
		 *
		 *	@return 	bool										Whether the mail was delivered
		 */
		private static function _mail( array &$appData, string $to, string $kind, string $token, bool $native ): bool {

			$url = in_array( $kind, self::LINKS, true ) === true ? \Nino\Html::absoluteUrl( $appData, '/.members/login?t='. rawurlencode( $token ) ) : '';
			$code = in_array( $kind, [ 'code', 'change' ], true ) === true ? substr( $token, 0, 3 ). ' '. substr( $token, 3 ) : '';
			$locale = \Nino\Locales::getCurrentLocale( $appData );

			if( $native === true )
				\Nino\Locales::useLocale( $appData, \Nino\Locales::getNativeLocale( $appData ) );

			try {
				$date = str_starts_with( \Nino\Locales::getCurrentLocale( $appData ), 'de' ) === true ? date( 'd.m.Y' ) : date( 'Y-m-d' );
				$body = \Nino\Html::renderHtml( $appData, \Nino\Feature::template( $appData, self::TEMPLATES, 'mail-'. $kind ) );
				$subject = \Nino\Html::renderHtml( $appData, '[[/feature/members/mail/'. $kind. '-subject]]' );
				$replyTo = trim( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/email' ) );
			}
			finally {
				if( $native === true )
					\Nino\Locales::useLocale( $appData, $locale );
			}

			$replyTo = filter_var( $replyTo, FILTER_VALIDATE_EMAIL ) === false ? '' : $replyTo;
			$body = str_replace(
				[ '[[link]]', '[[code]]', '[[minutes]]', '[[days]]', '[[date]]', '[[contact]]' ],
				[ \Nino\Modules\Components::escape( $url ), \Nino\Modules\Components::escape( $code ), (string) self::_minutes( $appData ), (string) self::_days( $appData ), \Nino\Modules\Components::escape( $date ), \Nino\Modules\Components::escape( $replyTo ) ],
				$body
			);

			return \Nino\Mail::send( $appData, $to, $subject, $body, $replyTo );
		}

		/**
		 *	The session epoch the sign-ins are stamped with: the string signOutAll()
		 *	wrote or _openEpoch() opened, '' while there is none - nobody has signed
		 *	in then, and a file that holds anything else counts as none
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	string
		 */
		private static function _epoch( array &$appData ): string {

			return self::_epochOf( \Nino\Filesystem::getFileContent( $appData, self::EPOCH, [] ) );
		}

		/**
		 *	The epoch to sign in under: the current one, or - for the first sign-in
		 *	of the project - a new one, written in the same lock that found there
		 *	was none
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	string										'' where it could not be written
		 */
		private static function _openEpoch( array &$appData ): string {

			$epoch = '';
			$created = false;

			$written = \Nino\Filesystem::mutate( $appData, self::EPOCH, static function( mixed $state ) use ( &$epoch, &$created ): ?array {

				$epoch = self::_epochOf( $state );

				if( $epoch !== '' )
					return null;

				$epoch = bin2hex( random_bytes( 16 ) );
				$created = true;

				return [ 'epoch' => $epoch ];
			}, [] );

			return $created === true && $written === false ? '' : $epoch;
		}

		/**
		 *	@param		mixed			$state				As the file holds it
		 *
		 *	@return 	string										The epoch: 32 hex characters - '' for anything else
		 */
		private static function _epochOf( mixed $state ): string {

			$epoch = is_array( $state ) === true ? ( $state['epoch'] ?? null ) : null;

			return is_string( $epoch ) === true && preg_match( '/^[0-9a-f]{32}$/D', $epoch ) === 1 ? $epoch : '';
		}

		/**
		 *	The accounts a file holds, each read for what it has to be: the id is 12
		 *	hex characters, the address lower case, the strings strings, 'active' a
		 *	bool and the time of the last sign-in a string or null, and the epoch - 32 hex
		 *	characters - there where the account has one, and the list remember (see _devicesOf()) there where it has devices. A record that is
		 *	none is left out - the file is plain php and editable
		 *
		 *	@param		mixed			$state				As the file holds it
		 *
		 *	@return 	array										id => email, name, group, active, created, login - and epoch and remember, where there are some
		 */
		private static function _accountsOf( mixed $state ): array {

			$text = static fn( mixed $value ): string => is_string( $value ) === true ? $value : '';
			$accounts = [];

			foreach( is_array( $state ) === true && is_array( $state['accounts'] ?? null ) === true ? $state['accounts'] : [] as $id => $record ) {

				$id = (string) $id;

				if( preg_match( '/^[0-9a-f]{12}$/D', $id ) !== 1 || is_array( $record ) === false )
					continue;

				$accounts[$id] = [
					'email'		=> mb_strtolower( $text( $record['email'] ?? null ), 'UTF-8' ),
					'name'		=> $text( $record['name'] ?? null ),
					'group'		=> $text( $record['group'] ?? null ),
					'active'	=> ( $record['active'] ?? false ) === true,
					'created'	=> $text( $record['created'] ?? null ),
					'login'		=> is_string( $record['login'] ?? null ) === true ? $record['login'] : null,
				] + ( self::_epochOf( $record ) !== '' ? [ 'epoch' => self::_epochOf( $record ) ] : [] ) + ( ( $devices = self::_devicesOf( $record['remember'] ?? null ) ) !== [] ? [ 'remember' => $devices ] : [] );
			}

			return $accounts;
		}

		/**
		 *	One account as the file has it, its epoch among the fields
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *
		 *	@return 	array|null								email, name, group, active, created, login and - where there are some - epoch and remember; null for an id nobody has
		 */
		private static function _record( array &$appData, string $id ): ?array {

			return self::_accountsOf( \Nino\Filesystem::getFileContent( $appData, self::ACCOUNTS, [] ) )[$id] ?? null;
		}

		/**
		 *	An account without its epoch and its devices: what the account is to everybody outside this class
		 *
		 *	@param		array 		$record				As _record() has it
		 *
		 *	@return 	array
		 */
		private static function _plain( array $record ): array {

			unset( $record['epoch'], $record['remember'] );

			return $record;
		}

		/**
		 *	Whether an account is the member who is signed in in this session
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$account			As current() has it
		 *
		 *	@return 	bool
		 */
		private static function _own( array &$appData, array $account ): bool {

			$member = self::current( $appData );

			return $member !== null && is_string( $account['id'] ?? null ) === true && $member['id'] === $account['id'];
		}

		/**
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$email				Lower case
		 *
		 *	@return 	array|null								The account with that address, as account() has it
		 */
		private static function _byEmail( array &$appData, string $email ): ?array {

			foreach( self::_accountsOf( \Nino\Filesystem::getFileContent( $appData, self::ACCOUNTS, [] ) ) as $id => $record )
				if( $record['email'] === $email )
					return [ 'id' => (string) $id ] + self::_plain( $record );

			return null;
		}

		/**
		 *	@param		array 		$account
		 *
		 *	@return 	string										What names the member: the name, the email address where there is none
		 */
		private static function _label( array $account ): string {

			return $account['name'] !== '' ? $account['name'] : $account['email'];
		}

		/**
		 *	@param		string		$text					As typed
		 *
		 *	@return 	string										The address in lower case - '' for a text that is no email address
		 */
		private static function _email( string $text ): string {

			$email = mb_strtolower( trim( $text ), 'UTF-8' );

			return $email !== '' && filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false ? $email : '';
		}

		/**
		 *	One posted value, as a string. is_string() rather than a (string) cast:
		 *	nothing says a post carries strings, and 'email[]=x' would raise an
		 *	"Array to string conversion" - a level \Nino\Runtime treats as fatal, ie.
		 *	an unauthenticated 500 from a post anybody can send. Same reading as
		 *	\Nino\Form::posted(), whose docblock says why
		 *
		 *	@param		string		$name
		 *
		 *	@return 	string
		 */
		private static function _posted( string $name ): string {

			return is_string( $_POST[$name] ?? null ) === true ? $_POST[$name] : '';
		}

		/**
		 *	The page the answer of a login request goes back to: the posted 'return'
		 *	without its query, where it is a path of this site and no endpoint of a
		 *	module - the start page for anything else, an unsafe address among it.
		 *	The kernel's \Nino\Http::redirect() holds a path to the same rules and
		 *	leaves the response alone, with a warning, for one that breaks them
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$posted
		 *
		 *	@return 	string
		 */
		private static function _returnPath( array &$appData, string $posted ): string {

			$path = substr( $posted, 0, strcspn( $posted, '?#' ) );

			return self::_isPath( $path ) === true ? $path : self::_start( $appData );
		}

		/**
		 *	Whether a text is a path of this site that is safe to redirect to: it
		 *	starts with a single '/', has no whitespace or control character, no ':'
		 *	before the next '/' (a browser reads that as a scheme) and no backslash
		 *	after the slash (which it reads as a second one), and is no endpoint of a
		 *	module ('/.')
		 *
		 *	@param		string		$path
		 *
		 *	@return 	bool
		 */
		private static function _isPath( string $path ): bool {

			return str_starts_with( $path, '/' ) === true && str_starts_with( $path, '//' ) === false && str_starts_with( $path, '/\\' ) === false && str_starts_with( $path, '/.' ) === false
				&& preg_match( '/[\s\x00-\x1f\x7f]/', $path ) !== 1 && preg_match( '#^/[^/]*:#', $path ) !== 1;
		}

		/**
		 *	The start page: the setting, where it is a path of this site, else the
		 *	front page. The manifest's pattern keeps a query, a fragment and a
		 *	foreign address out of the setting (\Nino\Features::setting() hands over the
		 *	default for a value that fails it), but not an empty value, nor an
		 *	endpoint of a module
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	string
		 */
		private static function _start( array &$appData ): string {

			$start = (string) \Nino\Features::setting( $appData, self::FEATURE_KEY, 'start', '/' );

			return self::_isPath( $start ) === true ? $start : '/';
		}

		/**
		 *	Answer with a redirect to a page of the site, carrying the state the
		 *	form will say there
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *	@param		string		$path
		 *	@param		string		$state				One of STATES
		 *
		 *	@return 	void
		 */
		private static function _redirect( array &$appData, array &$request, string $path, string $state ): void {

			\Nino\Http::redirect( $appData, $request, $path. '?members='. $state, 303 );
		}

		/**
		 *	Answer a link that does not lead in (see _refuse(), 400): where the link led, if its record is still kept (_expiredTarget()), is put in the session as the page the
		 *	next sign-in asked for - the form of the page asks for a code and the code leads there, the way the gate does it. The record is the server's: the address of the
		 *	request names the token and nothing else
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *	@param		string		$token				What the request named
		 *
		 *	@return 	void
		 */
		private static function _refuseLink( array &$appData, array &$request, string $token ): void {

			$target = self::_expiredTarget( $appData, $token );

			// A device that is signed in by a good cookie has no use for the page that says the link does not work: it goes where the link led, with no form and no mail
			if( $target !== '' && ( $request['/nino/http/request']['method'] ?? '' ) === 'GET' && self::current( $appData ) === null ) {

				self::_resume( $appData, $request, true );

				$member = self::current( $appData );

				if( $member !== null ) {
					\Nino\Http::redirect( $appData, $request, self::_arrival( $appData, $member, $target ), 303 );
					return;
				}
			}

			if( $target !== '' )
				\Nino\Runtime::setSessionValue( $appData, self::SESSION_TARGET, $target );

			self::_refuse( $appData, $request, 400 );
		}

		/**
		 *	Answer a link that does not lead in: the login page in place, with the state
		 *	'invalid' for the form to say, and not stored by anyone
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *	@param		int				$status				400 for a link that is not valid, 500 for a sign-in that could not be made
		 *
		 *	@return 	void
		 */
		private static function _refuse( array &$appData, array &$request, int $status ): void {

			$appData[self::STATE] = 'invalid';

			self::_answerPage( $appData, $request, 'page-members-login', $status );
		}

		/**
		 *	Answer the page that asks the member to confirm a link: the page of the
		 *	install unit with the button of componentMembersConfirm() in it, handed the
		 *	token, the name of the account and - where there is one - a state. With no
		 *	referrer: the address of the page carries the token, and nothing the page
		 *	loads is to be told it
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *	@param		string		$token				A token that is good
		 *	@param		array 		$account			As account() has it
		 *	@param		string		$state				'' for none, else one of STATES
		 *	@param		int				$status
		 *
		 *	@return 	void
		 */
		private static function _confirmPage( array &$appData, array &$request, string $token, array $account, string $state, int $status ): void {

			$appData[self::CONFIRM] = [ 'token' => $token, 'label' => self::_label( $account ), 'state' => $state ];

			self::_answerPage( $appData, $request, 'page-members-confirm', $status );
		}

		/**
		 *	Answer with a page of the install unit in place, not stored by anyone. The
		 *	page is headed by the details of the start page - the response's uri is its
		 *	route's, since these endpoints have no details of a page of their own, and a
		 *	head that reads none would print the key as it stands
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *	@param		string		$template			The name of the page template: page-members-login or page-members-confirm
		 *	@param		int				$status
		 *
		 *	@return 	void
		 */
		private static function _answerPage( array &$appData, array &$request, string $template, int $status ): void {

			$route = \Nino\Http::requestRoute( $appData, self::_start( $appData ), 'GET' );

			if( is_array( $route ) === true && is_string( $route['uri'] ?? null ) === true )
				$request['/nino/http/response']['uri'] = $route['uri'];

			$request['/nino/http/response']['body']										= '[template /templates/'. $template. ']';
			$request['/nino/http/response']['statusCode']							= $status;
			$request['/nino/http/response']['header']['Cache-Control']	= 'no-store';

			// The address of such a page may hold a token - the confirm page's does,
			// and so does the page of a link that is dead - so no referrer leaves it
			$request['/nino/http/response']['header']['Referrer-Policy']	= 'no-referrer';
		}

		/**
		 *	What the form says: the state a callback of this request set, else what
		 *	the query 'members' names - one of STATES, nothing else is a state
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	string										'' for none
		 */
		private static function _state( array &$appData ): string {

			$set = $appData[self::STATE] ?? null;

			if( is_string( $set ) === true && in_array( $set, self::STATES, true ) === true )
				return $set;

			$request = \Nino\Http::getRequest( $appData );
			$query = $request === false ? null : ( $request['/nino/http/request']['query'] ?? null );
			$asked = is_array( $query ) === true ? ( $query['members'] ?? null ) : null;

			return is_string( $asked ) === true && in_array( $asked, self::STATES, true ) === true ? $asked : '';
		}

		/**
		 *	The paragraph a form says its state in, the template members-state with the
		 *	key of the state's words in it - the state's own name where STATE_KEYS has
		 *	none
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$state				'' for none, else one of STATES
		 *
		 *	@return 	string										'' for no state
		 */
		private static function _say( array &$appData, string $state ): string {

			return $state === '' ? '' : str_replace( '[[name]]', self::STATE_KEYS[$state] ?? $state, \Nino\Feature::template( $appData, self::TEMPLATES, 'members-state' ) );
		}

		/**
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	int												How many minutes a login code, and the link of a login, is good for
		 */
		private static function _minutes( array &$appData ): int {

			return (int) \Nino\Features::setting( $appData, self::FEATURE_KEY, 'linkMinutes', 15 );
		}

		/**
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	int												How many days an invitation is good for
		 */
		private static function _days( array &$appData ): int {

			return (int) \Nino\Features::setting( $appData, self::FEATURE_KEY, 'inviteDays', 7 );
		}

		/**
		 *	The box 'stay signed in on this device' of the second step of the form: drawn by the component from its own template, so that a project whose words
		 *	do not have the label yet (the install unit is applied add-only) shows no box with a bare key in place of it
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	string									The markup; '' where the label of the box has no text
		 */
		private static function _box( array &$appData ): string {

			return \Nino\Html::resolveTextfill( $appData, '/feature/members/code/remember' ) === null ? '' : \Nino\Feature::template( $appData, self::TEMPLATES, 'members-remember' );
		}

		/**
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	int											How many days a device that stays signed in is good for, from its last use
		 */
		private static function _rememberDays( array &$appData ): int {

			return (int) \Nino\Features::setting( $appData, self::FEATURE_KEY, 'rememberDays', self::DEFAULT_REMEMBER );
		}

		/**
		 *	Keep the kernel's full-page cache from both storing and serving a page
		 *	under a protected path: a page for a member would otherwise be stored and
		 *	handed to whoever asks next. Runtime only, appended to the live array and
		 *	never written to config.php - a later change to 'paths' takes effect on the
		 *	next request instead of leaving a stale entry behind
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	void
		 */
		private static function _extendCacheBlacklist( array &$appData ): void {

			$prefixes = self::prefixes( $appData );

			if( $prefixes === [] )
				return;

			$blacklist = (array) ( $appData['/nino/cache/blacklist'] ?? [] );

			// The subtree entry covers the prefix itself: the cache matches its
			// blacklist with \Nino\Http::matchesPath()
			foreach( $prefixes as $prefix )
				if( in_array( $prefix. '/*', $blacklist, true ) === false )
					$blacklist[] = $prefix. '/*';

			$appData['/nino/cache/blacklist'] = $blacklist;
		}

		/**
		 *	Put one path on the cache's blacklist for the rest of this request
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$uri
		 *
		 *	@return 	void
		 */
		private static function _keepOutOfCache( array &$appData, string $uri ): void {

			$blacklist = (array) ( $appData['/nino/cache/blacklist'] ?? [] );

			if( $uri !== '' && in_array( $uri, $blacklist, true ) === false ) {
				$blacklist[] = $uri;
				$appData['/nino/cache/blacklist'] = $blacklist;
			}
		}
	}
}
