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
	 *										/data/members.php. A member signs in with a link: POST
	 *										/.members/login mails one to an address that belongs to an
	 *										active account (and answers every address alike), and
	 *										GET /.members/login?t=<token> opens a page that names the
	 *										account and asks for a button to be pressed, which POSTs to
	 *										/.members/confirm and spends the token: a GET changes
	 *										nothing, so a mail scanner or a prefetch that follows the link
	 *										uses nobody's link up. The token is 32 random bytes, mailed
	 *										once and kept only as its sha256 in /data/members-tokens.php,
	 *										so it is good for one confirmation and for as long as the
	 *										setting linkMinutes (inviteDays for an invitation) allows. The
	 *										sign-in is a value of the kernel's
	 *										session, stamped with the session epoch of
	 *										/data/members-session.php (see _openEpoch()): signOutAll() -
	 *										the panel's button - writes a new one, and every session
	 *										from before it reads signed out. No cookie of the
	 *										feature's own.
	 *
	 *										A path under one of the protected prefixes of the
	 *										setting paths - or an address of a page whose uri lies under
	 *										one, in whichever language its routes give it - shows the
	 *										login page, in place and with the status 401, to everyone who
	 *										is not signed in (the gate,
	 *										see callbackGate(), priority 1 on '/nino/http/response' -
	 *										before Modules\Cache's 9, so a page for a member is never
	 *										the one that gets cached). The page asked for is kept in
	 *										the session, and the link requested from that page takes
	 *										the member back to it.
	 *
	 *										Three components put the form, a link to sign out and
	 *										the member's name on any page the Builder composes
	 *										(componentMembersLogin(), componentMembersLogout() and
	 *										componentMembersName(); the manifest declares them), a
	 *										fourth - componentMembersConfirm() - is the button of the page
	 *										a link opens, and the panel in Admin/Admin.php manages the
	 *										accounts.
	 *
	 *										Other features build on it through current(), account(),
	 *										accounts() and groups(), and by the callbacks
	 *										'/members/login' and '/members/logout', which are fired
	 *										with the account as their argument.
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

		// The accounts: [ 'accounts' => [ <id> => [ email, name, group, active, created, login ] ] ]
		public const string ACCOUNTS = '/data/members.php';

		// The links that are out: [ <sha256 of the token> => [ account, kind, expires, target ] ]
		public const string TOKENS = '/data/members-tokens.php';

		// The session epoch - what 'sign everybody out' rewrites: [ 'epoch' => <32 hex> ]
		public const string EPOCH = '/data/members-session.php';

		private const string FEATURE_KEY = 'members';

		// A runtime session value - who is signed in, and under which epoch - and
		// the path a protected page was asked for until a link is requested. The
		// kernel's session carries both: the feature has no cookie of its own
		private const string SESSION_ACCOUNT = './members/account';
		private const string SESSION_TARGET = './members/target';

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
		private const string TIME = 'Y-m-d\TH:i:s\Z';

		// What the form may say besides nothing, and what a token may be for
		private const array STATES = [ 'sent', 'limit', 'invalid', 'out', 'expired' ];
		private const array KINDS = [ 'login', 'invite' ];

		/**
		 *	Register the four routes this feature owns with their handlers,
		 *	the gate and the exclusion for the Seo feature, and keep the full-page
		 *	cache away from the protected paths
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	void
		 */
		public static function init( array &$appData ): void {

			$appData['/nino/http/routes']['POST://.members/login']	= [ 'uri' => '/.members/login' ];
			$appData['/nino/http/routes']['GET://.members/login']		= [ 'uri' => '/.members/login' ];
			$appData['/nino/http/routes']['GET://.members/logout']	= [ 'uri' => '/.members/logout' ];
			$appData['/nino/http/routes']['POST://.members/confirm']	= [ 'uri' => '/.members/confirm' ];

			// Priority 1: before Modules\Cache's own callback (9) decides whether
			// this response may be stored or served from the cache, and before the
			// render, so the login page is what actually renders for a visitor
			\Nino\Callbacks::registerCallback( $appData, '/nino/http/response', [ self::class, 'callbackGate' ], 1 );

			\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://.members/login', [ self::class, 'callbackLoginRequest' ] );
			\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://.members/login', [ self::class, 'callbackLoginLink' ] );
			\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://.members/confirm', [ self::class, 'callbackConfirm' ] );
			\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://.members/logout', [ self::class, 'callbackLogout' ] );

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
		 *	nobody
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

			$account = self::account( $appData, $session['id'] );

			return $account !== null && $account['active'] === true ? $account : null;
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

			$record = self::_accountsOf( \Nino\Filesystem::getFileContent( $appData, self::ACCOUNTS, [] ) )[$id] ?? null;

			return $record === null ? null : [ 'id' => $id ] + $record;
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
				$accounts[] = [ 'id' => (string) $id ] + $record;

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
		 *	paths - the prefix itself and everything below it
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$uri					A request path without the directory
		 *
		 *	@return 	bool
		 */
		public static function protects( array &$appData, string $uri ): bool {

			return \Nino\Http::matchesPath( $uri, array_map( static fn( string $prefix ): string => $prefix. '/*', self::prefixes( $appData ) ) );
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
		 *	Change the name, the group and whether an account is active - what
		 *	$changes names and nothing else; the address, the times and the id stay.
		 *	The rules of add() hold for the name and the group
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *	@param		array 		$changes			Any of 'name' (string), 'group' (string) and 'active' (bool)
		 *
		 *	@return 	array|string							The account as it is now; or why not: 'unknown' (no such id), 'long' (the name), 'write'
		 */
		public static function change( array &$appData, string $id, array $changes ): array|string {

			$name = is_string( $changes['name'] ?? null ) === true ? trim( $changes['name'] ) : null;

			if( $name !== null && mb_strlen( $name, 'UTF-8' ) > self::NAME_LENGTH )
				return 'long';

			$group = is_string( $changes['group'] ?? null ) === true ? \Nino\Feature::slug( $changes['group'] ) : null;
			$active = is_bool( $changes['active'] ?? null ) === true ? $changes['active'] : null;
			$ran = false;
			$found = null;

			$written = \Nino\Filesystem::mutate( $appData, self::ACCOUNTS, static function( mixed $state ) use ( $id, $name, $group, $active, &$ran, &$found ): ?array {

				$ran = true;
				$accounts = self::_accountsOf( $state );

				if( isset( $accounts[$id] ) === false )
					return null;

				$accounts[$id] = array_merge( $accounts[$id], array_filter( [ 'name' => $name, 'group' => $group, 'active' => $active ], static fn( mixed $value ): bool => $value !== null ) );
				$found = [ 'id' => $id ] + $accounts[$id];

				return [ 'accounts' => $accounts ];
			}, [] );

			if( $ran === true && $found === null )
				return 'unknown';

			return $written === true && $found !== null ? $found : 'write';
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
		 *	valid for the setting linkMinutes) or the one of an invitation ('invite',
		 *	valid for inviteDays). The mail is absolute (the website's address is
		 *	needed) and goes out in the language of the request, or - for the panel,
		 *	which writes to somebody who is not in the room - in the site's native one.
		 *	A token the mail never carried is good for nobody: it is not found
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$account			As account() has it
		 *	@param		string		$kind					'login' or 'invite'
		 *	@param		string		$target				The path the member asked for, '' for none - where the link leads after the sign-in
		 *	@param		bool			$native				Write in the site's native language
		 *
		 *	@return 	bool										Whether the mail was delivered - false, with a warning that names no address, where there is no website address to build the link on, the token could not be stored or the mail was not taken
		 */
		public static function link( array &$appData, array $account, string $kind, string $target = '', bool $native = false ): bool {

			if( in_array( $kind, self::KINDS, true ) === false || is_string( $account['id'] ?? null ) === false || is_string( $account['email'] ?? null ) === false )
				return false;

			// A site without an address has no link to put in the mail, and a mail
			// without its link is no better than none
			if( \Nino\Html::absoluteUrl( $appData ) === '' ) {
				trigger_error( 'Members: no link was sent, the site has no address (the fill /project/website/general/url)', E_USER_WARNING );
				return false;
			}

			$seconds = $kind === 'invite' ? self::_days( $appData ) * 86400 : self::_minutes( $appData ) * 60;
			$token = self::_issue( $appData, $account['id'], $kind, $seconds, $target );

			if( $token === null ) {
				trigger_error( 'Members: a link could not be stored', E_USER_WARNING );
				return false;
			}

			if( self::_mail( $appData, $account['email'], $kind, $token, $native ) === false ) {
				trigger_error( 'Members: a link could not be mailed', E_USER_WARNING );
				return false;
			}

			return true;
		}

		/**
		 *	Sign every member out at once: write a new session epoch, which no
		 *	session that signed in before it carries. The panel's 'Sign everybody
		 *	out' ends here - a member who left must not stay in because the browser
		 *	kept the session. Nobody is identified, so there is no one to name; the
		 *	epoch is all there is to rotate.
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	bool										False where the file could not be written
		 */
		public static function signOutAll( array &$appData ): bool {

			return \Nino\Filesystem::mutate( $appData, self::EPOCH, static fn(): array => [ 'epoch' => bin2hex( random_bytes( 16 ) ) ], [] );
		}

		/**
		 *	The gate: replace the response of a request under a protected path - or
		 *	for a page whose uri lies under one, at whichever address a route gives it
		 *	(see _behindTheGate()) - by somebody who is not signed in, with the login
		 *	page. A plain 401 - never a 200, so nothing caches it, and honest about the
		 *	fact that nothing was actually served. The path is kept in the session, for
		 *	the link that is requested from this page to lead back to it. The workbench
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
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function callbackGate( array &$appData, array &$request ): void {

			$uri = (string) ( $request['/nino/http/request']['uri'] ?? '' );

			if( \Nino\Runtime::getSessionValue( $appData, self::SESSION_ACCOUNT ) !== null )
				self::_keepOutOfCache( $appData, $uri );

			if( self::_behindTheGate( $appData, $request ) === false || self::current( $appData ) !== null )
				return;

			\Nino\Runtime::setSessionValue( $appData, self::SESSION_TARGET, $uri );

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
		 *	POST /.members/login: ask for a link. The visitor is counted (the setting
		 *	attempts, per hour) and so is the address (3 per hour), by the kernel's
		 *	counter and before anything else, whoever the address belongs to; over
		 *	either the answer is a redirect with ?members=limit and nothing else
		 *	happens. Otherwise an address that belongs to an active account is
		 *	mailed a link and any other is not - and the answer is the same
		 *	redirect to the page the form was on, ?members=sent, for both, with the
		 *	time the request took the one difference: nothing may tell whether an
		 *	address is a member's. A mail that could not be sent is a warning for
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

			$account = $email === '' ? null : self::_byEmail( $appData, $email );

			if( $account !== null && $account['active'] === true ) {

				$target = \Nino\Runtime::getSessionValue( $appData, self::SESSION_TARGET );
				$target = is_string( $target ) === true && self::_isPath( $target ) === true ? $target : '';

				if( self::link( $appData, $account, 'login', $target ) === true )
					\Nino\Runtime::unsetSessionValue( $appData, self::SESSION_TARGET );
			}

			self::_redirect( $appData, $request, $return, 'sent' );
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
				self::_refuse( $appData, $request, 400 );
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
		 *	the start page
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
					self::_refuse( $appData, $request, 400 );
				else
					self::_confirmPage( $appData, $request, $token, $account, 'expired', 403 );

				return;
			}

			$spent = self::_spend( $appData, $token );
			$account = $spent === null ? null : self::account( $appData, $spent['account'] );

			if( $account === null || $account['active'] === false ) {
				self::_refuse( $appData, $request, 400 );
				return;
			}

			if( self::_signIn( $appData, $account ) === false ) {
				self::_refuse( $appData, $request, 500 );
				return;
			}

			\Nino\Callbacks::doCallbacks( $appData, '/members/login', $account );

			$target = $spent['target'];

			\Nino\Http::redirect( $appData, $request, self::_isPath( $target ) === true ? $target : self::_start( $appData ), 303 );
		}

		/**
		 *	GET /.members/logout: sign the member out and go to the start page with
		 *	?members=out. A plain link rather than a form on purpose - it discloses
		 *	nothing a visitor could not already tell from being on the page, so it
		 *	needs no csrf token to be safe, as ProtectedArea's logout has none
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function callbackLogout( array &$appData, array &$request ): void {

			$account = self::current( $appData );

			\Nino\Runtime::unsetSessionValue( $appData, self::SESSION_ACCOUNT );

			if( $account !== null )
				\Nino\Callbacks::doCallbacks( $appData, '/members/logout', $account );

			self::_redirect( $appData, $request, self::_start( $appData ), 'out' );
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
		 *	[members-login] - the form that asks for a link, for a visitor; for a
		 *	member the line that names them, with the link to sign out. The form is
		 *	the one POST /.members/login takes: a label and the field for the
		 *	address, the page it stands on as 'return' (where the answer goes
		 *	back to), [csrf] and the button. It says the state, if there is one,
		 *	above the button: what a callback of this request set, else what the query
		 *	'members' names, of STATES and nothing else. inline puts the field
		 *	and the button on one line, where the label is read by screen readers only.
		 *	The words are the Text panel's /feature/members/ keys, left in the template for
		 *	the kernel to resolve
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

			// Every value escaped, and the markup that was built last: the message is a
			// template of its own - left out where there is none to say - and a token
			// after it would be looked for in it as well
			return str_replace(
				[ '[[inline]]', '[[hidden]]', '[[id]]', '[[return]]', '[[classes]]', '[[state]]' ],
				[
					$inline === true ? ' nino-form--inline' : '',
					$inline === true ? ' nino-sr-only' : '',
					'members-login-'. ( $appData[self::FORMS] = (int) ( $appData[self::FORMS] ?? 0 ) + 1 ),
					\Nino\Modules\Components::escape( $request === false ? '' : (string) ( $request['/nino/http/request']['uri'] ?? '' ) ),
					\Nino\Feature::classes( $args ),
					$state === '' ? '' : str_replace( '[[name]]', $state, \Nino\Feature::template( $appData, self::TEMPLATES, 'members-state' ) ),
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
					$state === '' ? '' : str_replace( '[[name]]', $state, \Nino\Feature::template( $appData, self::TEMPLATES, 'members-state' ) ),
				],
				\Nino\Feature::template( $appData, self::TEMPLATES, 'members-confirm' )
			);
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
			\Nino\Runtime::setSessionValue( $appData, self::SESSION_ACCOUNT, [ 'id' => $id, 'epoch' => $epoch ] );

			return true;
		}

		/**
		 *	Make a token for an account and keep its hash: 32 random bytes, base64url,
		 *	which is mailed once and never stored. The entries that have run out go in
		 *	the same write
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id						The account
		 *	@param		string		$kind					'login' or 'invite'
		 *	@param		int				$seconds			How long it is good for
		 *	@param		string		$target				The path the member asked for, '' for none
		 *
		 *	@return 	string|null								The token; null where it could not be stored
		 */
		private static function _issue( array &$appData, string $id, string $kind, int $seconds, string $target ): ?string {

			$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
			$hash = hash( 'sha256', $token );
			$now = time();

			$written = \Nino\Filesystem::mutate( $appData, self::TOKENS, static function( mixed $state ) use ( $hash, $id, $kind, $seconds, $target, $now ): array {

				$tokens = self::_liveTokens( $state, $now );
				$tokens[$hash] = [ 'account' => $id, 'kind' => $kind, 'expires' => $now + $seconds, 'target' => $target ];

				return $tokens;
			}, [] );

			return $written === true ? $token : null;
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

			return self::_liveTokens( \Nino\Filesystem::getFileContent( $appData, self::TOKENS, [] ), time() )[ hash( 'sha256', $token ) ] ?? null;
		}

		/**
		 *	Spend a token: delete it, and answer what it was for. An unknown, expired
		 *	or malformed one is nothing, and so is one whose deletion could not be
		 *	written - a link that is not spent is a link that can be used twice. The
		 *	entries that have run out go in the same write
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$token
		 *
		 *	@return 	array|null								account (the id), kind and target - null for no token
		 */
		private static function _spend( array &$appData, string $token ): ?array {

			if( preg_match( '/^[A-Za-z0-9_-]{43}$/D', $token ) !== 1 )
				return null;

			$hash = hash( 'sha256', $token );
			$now = time();
			$found = null;

			$written = \Nino\Filesystem::mutate( $appData, self::TOKENS, static function( mixed $state ) use ( $hash, $now, &$found ): ?array {

				$tokens = is_array( $state ) === true ? $state : [];
				$live = self::_liveTokens( $state, $now );
				$entry = $live[$hash] ?? null;

				if( $entry !== null ) {
					$found = $entry;
					unset( $live[$hash] );
				}

				return $live === $tokens ? null : $live;
			}, [] );

			return $written === true && $found !== null ? $found : null;
		}

		/**
		 *	The tokens that are good: an entry that is well formed - an account, a
		 *	kind of KINDS, a target that is a string - and has not run out
		 *
		 *	@param		mixed			$state				As the file holds it
		 *	@param		int				$now
		 *
		 *	@return 	array										hash => account, kind, expires, target
		 */
		private static function _liveTokens( mixed $state, int $now ): array {

			$live = [];

			foreach( is_array( $state ) === true ? $state : [] as $hash => $entry )
				if( is_array( $entry ) === true && is_string( $entry['account'] ?? null ) === true && in_array( $entry['kind'] ?? null, self::KINDS, true ) === true
					&& is_int( $entry['expires'] ?? null ) === true && $entry['expires'] > $now && is_string( $entry['target'] ?? null ) === true )
					$live[(string) $hash] = [ 'account' => $entry['account'], 'kind' => $entry['kind'], 'expires' => $entry['expires'], 'target' => $entry['target'] ];

			return $live;
		}

		/**
		 *	Mail a link. The template is rendered first - its words are the keys of
		 *	the unit - and the link and the validity are put in afterwards, in the
		 *	result, so that nothing in them is ever read as a fill or a shortcode (the
		 *	order \Nino\Form::render() keeps). The reply goes to the project's contact
		 *	address where it has one
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$to
		 *	@param		string		$kind					'login' or 'invite'
		 *	@param		string		$token
		 *	@param		bool			$native				Write in the site's native language
		 *
		 *	@return 	bool										Whether the mail was delivered
		 */
		private static function _mail( array &$appData, string $to, string $kind, string $token, bool $native ): bool {

			$url = \Nino\Html::absoluteUrl( $appData, '/.members/login?t='. rawurlencode( $token ) );
			$locale = \Nino\Locales::getCurrentLocale( $appData );

			if( $native === true )
				\Nino\Locales::useLocale( $appData, \Nino\Locales::getNativeLocale( $appData ) );

			try {
				$body = \Nino\Html::renderHtml( $appData, \Nino\Feature::template( $appData, self::TEMPLATES, 'mail-'. $kind ) );
				$subject = \Nino\Html::renderHtml( $appData, $kind === 'invite' ? '[[/feature/members/mail/invite-subject]]' : '[[/feature/members/mail/login-subject]]' );
				$replyTo = trim( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/email' ) );
			}
			finally {
				if( $native === true )
					\Nino\Locales::useLocale( $appData, $locale );
			}

			$body = str_replace(
				[ '[[link]]', '[[minutes]]', '[[days]]' ],
				[ \Nino\Modules\Components::escape( $url ), (string) self::_minutes( $appData ), (string) self::_days( $appData ) ],
				$body
			);

			return \Nino\Mail::send( $appData, $to, $subject, $body, filter_var( $replyTo, FILTER_VALIDATE_EMAIL ) === false ? '' : $replyTo );
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
		 *	bool and the time of the last sign-in a string or null. A record that is
		 *	none is left out - the file is plain php and editable
		 *
		 *	@param		mixed			$state				As the file holds it
		 *
		 *	@return 	array										id => email, name, group, active, created, login
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
				];
			}

			return $accounts;
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
					return [ 'id' => (string) $id ] + $record;

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
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	int												How many minutes a login link is good for
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
