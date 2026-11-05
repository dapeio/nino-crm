<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Modules\\Sign						see _nino/Nino/Modules/Modules.php for the
 *											package-level docblock
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules {

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Sign							A member signs a document of the Pdf feature on a page of the website. The
	 *										owner asks (request(), or the panel): the document is rendered through Pdf with
	 *										the owner's own signature on its fields, kept as shown.pdf with its sha256, and
	 *										the member is mailed a link, GET /.sign?t=<token>. The link opens a page that
	 *										shows the document, the sentence the member agrees to with a box to tick, a pad
	 *										to draw the signature on - or a field to type a name - and two buttons; it changes
	 *										nothing but the mark that it was viewed, so that a mail scanner uses nobody's
	 *										link up. POST /.sign signs or declines. Signing renders the document again with
	 *										the signature on every field of the member and, as a page of its own at the end,
	 *										the record of the signing - who, when, from where, which document (its sha256),
	 *										how, and the sentence agreed to (Pdf's option append) -, and keeps it as
	 *										signed.pdf with its sha256; both files are kept and never rewritten. What this
	 *										is: a simple electronic signature (eIDAS, Art. 25) with the record of its
	 *										circumstances, no more.
	 *
	 *										The document is rendered in the site's native language, so that the bytes can be
	 *										made again: before it is signed, the unsigned document is rendered once more with
	 *										the day of the request and has to be the one that was shown, byte for byte (the
	 *										sha256 of shown.pdf). The file carries no version of the Pdf feature, so an update
	 *										of it that leaves the layout of a document as it was does not end a pending request;
	 *										a change to the template, to a block, to the layout (header, footer, logo, the
	 *										owner's picture) or to a site text the document reads does. Then the request is
	 *										over, in the state 'changed' that is written with its time: nothing is signed, the
	 *										page says so and shows no form - not Decline either, the document is not the one
	 *										shown -, the owner is mailed, and the panel's "Ask again" makes a new request of the
	 *										same template, data, member and page, the old one staying as it is. The two renders
	 *										of a signing run inside the lock of the index, which holds every other write of it
	 *										for the time they take.
	 *
	 *										GET /.sign/pdf?t=|r=&kind=shown|signed answers the PDF as the file response of the
	 *										kernel (\Nino\Http, "Answering a File") to the holder of the token, to the member
	 *										it was asked of and to a workbench session that holds the permission of the panel;
	 *										everybody else is the same 404.
	 *
	 *										The data is Sign\Store's: /data/sign/requests.php and a directory for each request.
	 *										Two components put the page's form and a member's list on any page the Builder
	 *										composes (componentSignForm() and componentSignList(); the manifest declares
	 *										them), and the panel in Admin/Admin.php asks, reminds, cancels and reads. Other
	 *										features build on it through request(), requests(), get(), path(), remind(),
	 *										cancel() and remove(), and the callbacks '/sign/signed' and '/sign/declined', each
	 *										fired with the request.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Sign {

		// Where this feature's own templates are, as \Nino\Filesystem resolves them
		public const string TEMPLATES = '/features/Sign/templates';

		private const string FEATURE_KEY = 'sign';

		// The counter of the posts, \Nino\Feature::count() under this name: the file /data/sign-tries.php. Not 'sign' - that is the
		// directory of the requests - and not in the manifest's 'data': a window of an hour is nothing a backup carries
		private const string COUNTER = 'sign-tries';

		private const int WINDOW = 3600;
		private const int DEFAULT_ATTEMPTS = 20;
		private const int DEFAULT_DAYS = 14;
		private const int TITLE_LENGTH = 120;

		// The request the page of this request is about, and how many forms it has drawn
		private const string REQUEST = './sign/request';
		private const string FORMS = './sign/forms';

		// What a page can say besides nothing, and the states of a request that is over
		private const array STATES = [ 'signed', 'declined', 'cancelled', 'expired', 'invalid', 'consent', 'empty', 'limit', 'changed', 'write', 'again' ];
		private const array CLOSED = [ 'signed', 'declined', 'cancelled', 'expired', 'invalid', 'changed' ];

		/**
		 *	Register the three routes this feature owns with their handlers, and the merge of a restore
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	void
		 */
		public static function init( array &$appData ): void {

			$appData['/nino/http/routes']['GET://.sign']			= [ 'uri' => '/.sign' ];
			$appData['/nino/http/routes']['POST://.sign']			= [ 'uri' => '/.sign' ];
			$appData['/nino/http/routes']['GET://.sign/pdf']	= [ 'uri' => '/.sign/pdf' ];

			\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://.sign', [ self::class, 'callbackPage' ] );
			\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://.sign', [ self::class, 'callbackPost' ] );
			\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://.sign/pdf', [ self::class, 'callbackPdf' ] );

			// A restore of the daily backup brings the index of a day, which may name requests whose files are not there
			\Nino\Callbacks::registerCallback( $appData, '/nino/admin/restore', [ self::class, 'callbackRestore' ] );
		}

		// The panel comes and goes with the feature
		public static function adminPanels( array &$appData ): array {

			return [ \Nino\Modules\Sign\Admin::class ];
		}

		/**
		 *	Ask a member to sign a document: the template of the Pdf feature is rendered with the data and the
		 *	owner's own signature on its fields (the day is today's), the PDF is kept as shown.pdf with its
		 *	sha256, the request is put in the index with a token that is mailed once and kept as its sha256, and
		 *	the member is mailed the link in the site's native language. The mail is the last thing: a request
		 *	whose mail was not delivered is there all the same, and remind() sends it again
		 *
		 *	$data is what Pdf::render() takes: tokens, slots and inputs. $options: title (the name of the
		 *	template where left out, 120 characters at most), days (how long the link is good for, 1 to 90, the
		 *	setting days where left out) and page - a path of this site the link takes the member back to after
		 *	the signing - kept on the request, so that a reminder and the panel's "Ask again" use it - which
		 *	has to be one by the rules of a return path of the Members feature: it starts with a single
		 *	slash, is no endpoint of a module and holds no whitespace, and its query goes; any other is
		 *	left out - and notify, true unless it says false: with false the request is made and nobody is
		 *	mailed, and the answer carries the link as the mail would have carried it, as 'link'. The index
		 *	keeps the sha256 of the token only, so the link is there in this answer and nowhere else; it is as
		 *	good as a mailed one, for the same days, and whoever has it signs as the account
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$template			The key of a template of the Pdf feature
		 *	@param		array 		$data
		 *	@param		string		$account			The id of an account of the Members feature
		 *	@param		array 		$options
		 *
		 *	@return 	array|string							The request as get() has it, and 'mailed' - whether the mail was delivered - and, with notify false, 'link'; or why not: 'template' (no such template), 'member' (it has no field for a member), 'account' (no such account, or one that is switched off), 'input' (a free text it asks for is not given), 'render' (the PDF could not be made), 'write' (the request could not be kept) or 'ratelimit' (notify false, and the cap of mails an hour is spent: the link is charged to it as a mail is)
		 */
		public static function request( array &$appData, string $template, array $data, string $account, array $options = [] ): array|string {

			$template = trim( $template );
			$templates = \Nino\Modules\Pdf::templates( $appData );

			if( isset( $templates[$template] ) === false )
				return 'template';

			if( count( array_filter( \Nino\Modules\Pdf::fields( $appData, $template ), static fn( array $field ): bool => $field['party'] === 'member' ) ) === 0 )
				return 'member';

			$member = preg_match( \Nino\Modules\Sign\Store::ID, $account ) === 1 ? \Nino\Modules\Members::account( $appData, $account ) : null;

			if( $member === null || $member['active'] !== true )
				return 'account';

			$data = \Nino\Modules\Sign\Store::data( $data );

			foreach( \Nino\Modules\Pdf::inputs( $appData, $template ) as $input )
				if( $input['required'] === true && trim( (string) ( $data['inputs'][ $input['name'] ] ?? '' ) ) === '' )
					return 'input';

			// A request whose link is shown and not mailed is charged to the cap of mails as a mail would be
			if( ( $options['notify'] ?? true ) === false && self::_charge( $appData ) === false )
				return 'ratelimit';

			$title = self::_title( is_string( $options['title'] ?? null ) === true ? $options['title'] : '' );
			$title = $title === '' ? self::_title( $templates[$template]['name'] ) : $title;
			$days = is_int( $options['days'] ?? null ) === true ? max( 1, min( 90, $options['days'] ) ) : self::_days( $appData );
			$page = self::_path( is_string( $options['page'] ?? null ) === true ? $options['page'] : '' );
			$now = time();

			$bytes = self::_render( $appData, $template, $data, [ 'date' => gmdate( 'Y-m-d', $now ), 'title' => $title ] );

			if( $bytes === '' )
				return 'render';

			$id = \Nino\Modules\Sign\Store::newId( $appData );
			$token = \Nino\Modules\Sign\Store::newToken();

			if( \Nino\Filesystem::putFileContent( $appData, \Nino\Modules\Sign\Store::file( $id, 'shown' ), $bytes ) === false )
				return 'write';

			$entry = [
				'template'		=> $template,
				'data'			=> $data,
				'title'			=> $title,
				'account'		=> $member['id'],
				'status'		=> 'requested',
				'token'			=> hash( 'sha256', $token ),
				'expires'		=> $now + $days * 86400,
				'created'		=> gmdate( \Nino\Modules\Sign\Store::TIME, $now ),
				'viewed'		=> null,
				'signed'		=> null,
				'declined'	=> null,
				'changed'	=> null,
				'hashShown'	=> hash( 'sha256', $bytes ),
				'hashSigned'	=> '',
				'method'		=> '',
				'record'		=> [ 'ip' => '', 'agent' => '', 'consent' => '' ],
				'page'		=> $page,
			];

			if( \Nino\Modules\Sign\Store::add( $appData, $id, $entry ) === false ) {
				\Nino\Modules\Sign\Store::drop( $appData, $id );
				return 'write';
			}

			if( ( $options['notify'] ?? true ) === false )
				return \Nino\Modules\Sign\Store::view( $id, $entry ) + [ 'mailed' => false, 'link' => self::_link( $appData, $token, $page ) ];

			$mailed = self::_mailRequest( $appData, $member, \Nino\Modules\Sign\Store::view( $id, $entry ), $token, $page, $days );

			return \Nino\Modules\Sign\Store::view( $id, $entry ) + [ 'mailed' => $mailed ];
		}

		/**
		 *	The requests, the newest first
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string|null	$account			Only those of this account
		 *
		 *	@return 	array										A list of requests, each as get() has it
		 */
		public static function requests( array &$appData, ?string $account = null ): array {

			return \Nino\Modules\Sign\Store::all( $appData, $account );
		}

		/**
		 *	One request: id, template, data, title, account, status, expires, created, viewed, signed, declined, changed,
		 *	hashShown, hashSigned, method, record (ip, agent, consent) and page. The status is the one the request has
		 *	now - requested, viewed, signed, declined, cancelled, changed or expired - and the hash of the token is not
		 *	given out
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *
		 *	@return 	array|null								Null for an id nobody has
		 */
		public static function get( array &$appData, string $id ): ?array {

			return \Nino\Modules\Sign\Store::get( $appData, $id );
		}

		/**
		 *	The absolute path of a PDF of a request
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *	@param		string		$kind					shown or signed
		 *
		 *	@return 	string|null								Null where there is no such request or the file is not there - signed.pdf is not before the signing
		 */
		public static function path( array &$appData, string $id, string $kind ): ?string {

			if( in_array( $kind, [ 'shown', 'signed' ], true ) === false || \Nino\Modules\Sign\Store::get( $appData, $id ) === null )
				return null;

			return \Nino\Modules\Sign\Store::path( $appData, $id, $kind );
		}

		/**
		 *	Send the link of a request again, with a new token and the time the setting days gives from now: the
		 *	old link is dead. The mail goes first and the new token is kept only once it was delivered, so that a
		 *	mail that is not leaves the link the member has as it was. The link takes the member back to the page the
		 *	request named. A request that is over - signed, declined, cancelled or changed - is not reminded; one whose time is up is, and is good again.
		 *	With $notify false nobody is mailed: the request gets the new token all the same - the link the member has is dead - and
		 *	the new link, as the mail would have carried it, is the answer, once, since the index keeps the sha256 of the token only
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *	@param		bool			$notify				Whether the member is mailed
		 *
		 *	@return 	bool|string							Whether the member was mailed and the request holds the new token; with $notify false the new link, or false where the request could not be given one
		 */
		public static function remind( array &$appData, string $id, bool $notify = true ): bool|string {

			$link = '';

			$request = \Nino\Modules\Sign\Store::get( $appData, $id );

			if( $request === null || in_array( $request['status'], [ 'requested', 'viewed', 'expired' ], true ) === false )
				return false;

			$member = \Nino\Modules\Members::account( $appData, $request['account'] );

			if( $member === null || $member['active'] !== true )
				return false;

			if( $notify === false && self::_charge( $appData ) === false )
				return false;

			$days = self::_days( $appData );
			$token = \Nino\Modules\Sign\Store::newToken();

			if( $notify === false )
				$link = self::_link( $appData, $token, $request['page'] );
			else if( self::_mailRequest( $appData, $member, $request, $token, $request['page'], $days ) === false )
				return false;

			$until = time() + $days * 86400;

			$kept = is_array( \Nino\Modules\Sign\Store::change( $appData, $id, static function( array $entry ) use ( $token, $until ): ?array {

				if( in_array( $entry['status'], [ 'requested', 'viewed' ], true ) === false )
					return null;

				return [ 'token' => hash( 'sha256', $token ), 'expires' => $until ] + $entry;
			} ) );

			if( $kept === false )
				return false;

			// A link of a token the request does not hold is no link to give: it is given only here, once the token is kept
			return $notify === false ? $link : true;
		}

		/**
		 *	Take a request back: the link is dead and the page says so. Only a request that is waiting - its time
		 *	up or not - can be, one that was signed or declined cannot
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *
		 *	@return 	bool
		 */
		public static function cancel( array &$appData, string $id ): bool {

			return is_array( \Nino\Modules\Sign\Store::change( $appData, $id, static function( array $entry ): ?array {

				if( in_array( $entry['status'], [ 'requested', 'viewed' ], true ) === false )
					return null;

				return [ 'status' => 'cancelled' ] + $entry;
			} ) );
		}

		/**
		 *	Delete a request, whatever its state: the entry first, then its directory with the PDFs and the
		 *	picture of the signature
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *
		 *	@return 	bool
		 */
		public static function remove( array &$appData, string $id ): bool {

			return \Nino\Modules\Sign\Store::remove( $appData, $id ) === 'ok';
		}

		/**
		 *	GET /.sign: the page of a request. The request is the one the token t belongs to, or the one the
		 *	signed-in member r owns - an id that is none, one of another member's and one a visitor asks for are the
		 *	same as a token nobody has. A request that is waiting answers the page with the form (200, kept out of
		 *	every cache and with no referrer: the address carries the token) and is marked as viewed - the one write
		 *	a GET makes, and a HEAD makes none. A request that is signed, declined, cancelled or run out answers the
		 *	page with that state and no form; one that is not found answers it with 'invalid', a 404, so that
		 *	nothing tells whether an id exists. A signing or a decline is redirected to the page with ?sign=<id>
		 *	and ?sign-state=: with no key at all the page says that state, and the link to the signed document too
		 *	to the member it belongs to
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function callbackPage( array &$appData, array &$request ): void {

			$query = self::_query( $request );
			$view = self::_view( $appData, self::_resolve( $appData, $query['t'], $query['r'] ) );

			if( $view['entry'] === null && $query['t'] === '' && $query['r'] === '' && in_array( $query['sign-state'], [ 'signed', 'declined' ], true ) === true )
				$view = self::_flash( $appData, $query );

			if( $view['state'] === 'live' && ( $request['/nino/http/request']['rawMethod'] ?? '' ) === 'GET' ) {

				$now = gmdate( \Nino\Modules\Sign\Store::TIME );

				\Nino\Modules\Sign\Store::change( $appData, $view['id'], static function( array $entry ) use ( $now ): ?array {

					if( $entry['status'] !== 'requested' || \Nino\Modules\Sign\Store::live( $entry ) === false )
						return null;

					return [ 'status' => 'viewed', 'viewed' => $now ] + $entry;
				} );
			}

			self::_page( $appData, $request, $view, self::_path( $query['return'] ), '', self::_status( $view ) );
		}

		/**
		 *	POST /.sign: the button of the page - sign or decline - with the key of the request, t or r, the
		 *	return page and [csrf]. In this order: a post the csrf guard refused is the page again with a form of the
		 *	session as it is now and the state 'again' (403), and counts for nothing; the visitor is counted (the
		 *	setting attempts per hour) and over it the state is 'limit' (429); a request that is not waiting any more
		 *	is the page with its state, nothing is rewritten - a second signing leaves the files and the hashes as they
		 *	are. To sign the box has to be ticked ('consent', 400) and a signature given - the picture of the canvas
		 *	in 'image', else the name typed in 'name' ('empty', 400). The unsigned document is made again and has to
		 *	be the one that was shown ('changed', 409). Then, in the lock of the index, signature.png and signed.pdf are
		 *	written and the status turns to signed in one write; the callback '/sign/signed' and the mails (the member's,
		 *	and the owner's where the setting notifyOwner is on) follow, and the answer is a redirect, 303, to the
		 *	return page - the signing page where there is none - with ?sign=<id>&sign-state=signed. A decline is the same
		 *	with the status declined, the callback '/sign/declined' and ?sign-state=declined. The refusals are the page
		 *	in place: nothing in a redirect carries the token
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function callbackPost( array &$appData, array &$request ): void {

			$view = self::_view( $appData, self::_resolve( $appData, self::_posted( 't' ), self::_posted( 'r' ) ) );
			$return = self::_path( self::_posted( 'return' ) );

			if( \Nino\Form::blocked( $request ) === true ) {
				self::_page( $appData, $request, $view, $return, 'again', $view['state'] === 'live' ? 403 : self::_status( $view ) );
				return;
			}

			$attempts = (int) \Nino\Features::setting( $appData, self::FEATURE_KEY, 'attempts', self::DEFAULT_ATTEMPTS );

			if( \Nino\Feature::count( $appData, self::COUNTER, 'visitor|'. \Nino\Http::getClientIp( $appData ), $attempts, self::WINDOW ) === false ) {
				self::_page( $appData, $request, $view, $return, 'limit', $view['state'] === 'live' ? 429 : self::_status( $view ) );
				return;
			}

			$action = self::_posted( 'action' );

			if( $view['state'] !== 'live' || in_array( $action, [ 'sign', 'decline' ], true ) === false ) {
				self::_page( $appData, $request, $view, $return, '', $view['state'] === 'live' ? 400 : self::_status( $view ) );
				return;
			}

			if( $action === 'decline' )
				self::_decline( $appData, $request, $view, $return );
			else
				self::_sign( $appData, $request, $view, $return );
		}

		/**
		 *	GET /.sign/pdf: a PDF of a request, as the file response of the kernel. The request is named by the
		 *	token t or the id r, and the kind is shown or signed: the holder of the token and the signed-in member
		 *	the request is of get the document while the request is waiting, and both documents once it is signed;
		 *	a workbench session that holds the permission of the panel (\Nino\Auth::checkPermission(), which a parent of
		 *	it such as '/_admin/*' holds as well) gets any file there is, in any state. The path is the one this
		 *	feature resolved from the id, never one the request said. All the rest is the same 404 with a plain body
		 *	- a stranger, a token nobody has, a request that was declined or cancelled or ran out, and signed.pdf
		 *	before the signing
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function callbackPdf( array &$appData, array &$request ): void {

			$query = self::_query( $request );
			$kind = $query['kind'];
			$view = self::_view( $appData, self::_resolve( $appData, $query['t'], $query['t'] === '' ? $query['r'] : '' ) );
			$entry = $view['entry'];
			$id = $view['id'];
			$owner = false;

			// The owner, with the permission of the panel: any request there is, in any state
			if( $entry === null && $query['t'] === '' && preg_match( \Nino\Modules\Sign\Store::ID, $query['r'] ) === 1 && \Nino\Auth::checkPermission( $appData, \Nino\Modules\Sign\Admin::MANAGE_PERM ) === true ) {
				$id = $query['r'];
				$entry = \Nino\Modules\Sign\Store::read( $appData )[$id] ?? null;
				$owner = $entry !== null;
			}

			// A token's holder and the member have the document while the request waits, and both once it is signed
			$path = null;

			if( $entry !== null && in_array( $kind, [ 'shown', 'signed' ], true ) === true && ( $owner === true || $view['state'] === 'signed' || ( $view['state'] === 'live' && $kind === 'shown' ) ) )
				$path = \Nino\Modules\Sign\Store::path( $appData, $id, $kind );

			if( $path === null || $entry === null ) {

				$request['/nino/http/response']['statusCode']								= 404;
				$request['/nino/http/response']['body']										= 'Not Found';
				$request['/nino/http/response']['header']['Content-Type']		= 'text/plain; charset=utf-8';
				$request['/nino/http/response']['header']['Cache-Control']	= 'no-store';

				return;
			}

			$request['/nino/http/response']['file']															= $path;
			$request['/nino/http/response']['header']['Content-Type']						= 'application/pdf';
			$request['/nino/http/response']['header']['Content-Disposition']		= self::_disposition( $entry['title'], $kind );
			$request['/nino/http/response']['header']['Cache-Control']					= 'private, no-store';
			$request['/nino/http/response']['header']['X-Content-Type-Options']	= 'nosniff';
			$request['/nino/http/response']['header']['Referrer-Policy']				= 'no-referrer';
		}

		/**
		 *	Rewrite the staged index of a backup before /_admin's Restore copies it over the live one. A backup
		 *	carries the directory of the requests whole, the PDFs with the index, but a request that is in the index
		 *	of a day has to have its shown.pdf - in the backup or on the disk now - or it is dropped, so that nobody
		 *	is offered a document that is not there. Reached through the '/nino/admin/restore' callback init()
		 *	registers, with the live /data directory and the extracted backup as arguments; a no-op if the backup
		 *	carries no index. The write is not silenced: one that fails is php's own warning, and the staged index
		 *	stays as it was
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$args				(reference) { dataDir: live /data (read only), staging: extracted backup }
		 *
		 *	@return 	void
		 */
		public static function callbackRestore( array &$appData, array &$args ): void {

			$dataDir = (string) ( $args['dataDir'] ?? '' );
			$staging = (string) ( $args['staging'] ?? '' );
			$staged = $staging. '/data/sign/requests.php';

			if( $dataDir === '' || $staging === '' || is_file( $staged ) === false )
				return;

			$requests = \Nino\Modules\Sign\Store::parse( include $staged );

			foreach( array_keys( $requests ) as $id )
				if( is_file( $staging. '/data/sign/'. $id. '/shown.pdf' ) === false && is_file( $dataDir. '/sign/'. $id. '/shown.pdf' ) === false )
					unset( $requests[$id] );

			file_put_contents( $staged, '<?php return '. var_export( $requests, true ). ';' );
		}

		/**
		 *	[sign-form] - on the signing page, which the route fills (the request is './sign/request'): for a request
		 *	that is waiting, the document in a frame with a link to open it, the title, the sentence the member agrees
		 *	to with its box, the pad to draw the signature on and under it the field to type a name - the way for a
		 *	browser without the script or a pen -, the key of the request, the return page, [csrf], the two buttons and
		 *	the state a refusal left; for any other request, its state and, once it is signed, the link to the signed
		 *	document. Nothing on any other page. The form is no .nino-form on purpose: the kernel's script would post
		 *	it by XHR, and it is answered by a redirect
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array			$args					The resolved arguments (see \Nino\Modules\Components::dispatch())
		 *
		 *	@return 	string
		 */
		public static function componentSignForm( array &$appData, array $args ): string {

			$view = $appData[self::REQUEST] ?? null;

			if( is_array( $view ) === false )
				return '';

			$escape = static fn( string $value ): string => \Nino\Modules\Components::escape( $value );
			$key = in_array( $view['key'], [ 't', 'r' ], true ) === true ? $view['key'] : '';
			$flash = in_array( $view['flash'], self::STATES, true ) === true ? $view['flash'] : '';
			$heading = $view['title'] === '' ? '' : str_replace( '[[document]]', $escape( $view['title'] ), \Nino\Feature::template( $appData, self::TEMPLATES, 'sign-heading' ) );

			// A request that is not waiting: what it is, and what can be had of it
			if( $view['state'] !== 'live' ) {

				$state = in_array( $view['state'], self::CLOSED, true ) === true ? $view['state'] : 'invalid';
				$link = $state === 'signed' && $key !== '' ? str_replace( [ '[[key]]', '[[value]]' ], [ $key, $escape( $view['value'] ) ], \Nino\Feature::template( $appData, self::TEMPLATES, 'sign-link' ) ) : '';

				return str_replace(
					[ '[[classes]]', '[[heading]]', '[[state]]', '[[link]]' ],
					[ \Nino\Feature::classes( $args ), $heading, str_replace( '[[name]]', $state, \Nino\Feature::template( $appData, self::TEMPLATES, 'sign-state' ) ), $link ],
					\Nino\Feature::template( $appData, self::TEMPLATES, 'sign-closed' )
				);
			}

			return str_replace(
				[ '[[key]]', '[[value]]', '[[return]]', '[[id]]', '[[classes]]', '[[heading]]', '[[state]]' ],
				[
					$key,
					$escape( $view['value'] ),
					$escape( $view['return'] ),
					'sign-form-'. ( $appData[self::FORMS] = (int) ( $appData[self::FORMS] ?? 0 ) + 1 ),
					\Nino\Feature::classes( $args ),
					$heading,
					$flash === '' ? '' : str_replace( '[[name]]', $flash, \Nino\Feature::template( $appData, self::TEMPLATES, 'sign-state' ) ),
				],
				\Nino\Feature::template( $appData, self::TEMPLATES, 'sign-form' )
			);
		}

		/**
		 *	[sign-list] - for the member who is signed in, the requests that were made of them, the newest first: the
		 *	title, how it stands, the day, a link to the page of the request (/.sign?r=<id>) and, once it is signed, a
		 *	link to the signed document. With the attribute open on - it is - the signed ones are left out. A line
		 *	says that there are none where there are none, and the state of a signing or a decline that was redirected
		 *	to the page, ?sign-state=, is said above the list. Nothing for a visitor
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array			$args					The resolved arguments
		 *
		 *	@return 	string
		 */
		public static function componentSignList( array &$appData, array $args ): string {

			$member = \Nino\Modules\Members::current( $appData );

			if( $member === null )
				return '';

			$open = ( $args['open'] ?? '1' ) === '1';
			$rows = '';

			foreach( \Nino\Modules\Sign\Store::all( $appData, $member['id'] ) as $request ) {

				if( $open === true && $request['status'] === 'signed' )
					continue;

				$rows .= str_replace(
					[ '[[id]]', '[[document]]', '[[created]]', '[[date]]', '[[state]]', '[[signed]]' ],
					[
						$request['id'],
						\Nino\Modules\Components::escape( $request['title'] ),
						\Nino\Modules\Components::escape( $request['created'] ),
						\Nino\Modules\Components::escape( substr( $request['created'], 0, 10 ) ),
						$request['status'],
						$request['status'] === 'signed' ? str_replace( '[[id]]', $request['id'], \Nino\Feature::template( $appData, self::TEMPLATES, 'sign-list-signed' ) ) : '',
					],
					\Nino\Feature::template( $appData, self::TEMPLATES, 'sign-list-row' )
				);
			}

			$request = \Nino\Http::getRequest( $appData );
			$query = $request === false ? null : ( $request['/nino/http/request']['query'] ?? null );
			$asked = is_array( $query ) === true && is_string( $query['sign-state'] ?? null ) === true ? $query['sign-state'] : '';
			$state = in_array( $asked, [ 'signed', 'declined' ], true ) === true ? str_replace( '[[name]]', $asked, \Nino\Feature::template( $appData, self::TEMPLATES, 'sign-state' ) ) : '';

			return str_replace(
				[ '[[classes]]', '[[state]]', '[[rows]]' ],
				[ \Nino\Feature::classes( $args ), $state, $rows !== '' ? $rows : \Nino\Feature::template( $appData, self::TEMPLATES, 'sign-list-none' ) ],
				\Nino\Feature::template( $appData, self::TEMPLATES, 'sign-list' )
			);
		}

		/**
		 *	Decline: the status turns to declined; the callback, the owner's mail and the redirect follow
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *	@param		array 		$view					The request, as _view() has it, and waiting
		 *	@param		string		$return
		 *
		 *	@return 	void
		 */
		private static function _decline( array &$appData, array &$request, array $view, string $return ): void {

			$now = gmdate( \Nino\Modules\Sign\Store::TIME );

			$declined = \Nino\Modules\Sign\Store::change( $appData, $view['id'], static function( array $entry ) use ( $now ): ?array {

				if( \Nino\Modules\Sign\Store::live( $entry ) === false )
					return null;

				return [ 'status' => 'declined', 'declined' => $now ] + $entry;
			} );

			if( is_array( $declined ) === false ) {
				$again = self::_view( $appData, self::_resolve( $appData, self::_posted( 't' ), self::_posted( 'r' ) ) );
				self::_page( $appData, $request, $again, $return, $declined === 'write' ? 'write' : '', $declined === 'write' ? 500 : self::_status( $again ) );
				return;
			}

			\Nino\Callbacks::doCallbacks( $appData, '/sign/declined', $declined );
			self::_mailOwner( $appData, $declined, 'declined' );
			self::_redirect( $appData, $request, $return, $view['id'], 'declined' );
		}

		/**
		 *	Sign: see callbackPost() for what is checked and in which order
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *	@param		array 		$view					The request, as _view() has it, and waiting
		 *	@param		string		$return
		 *
		 *	@return 	void
		 */
		private static function _sign( array &$appData, array &$request, array $view, string $return ): void {

			if( in_array( self::_posted( 'consent' ), [ '1', 'on', 'yes', 'true' ], true ) === false ) {
				self::_page( $appData, $request, $view, $return, 'consent', 400 );
				return;
			}

			$image = self::_posted( 'image' );
			$png = $image !== '' ? \Nino\Modules\Sign\Signature::drawn( $image ) : null;
			$method = $png !== null ? 'drawn' : 'typed';
			$png ??= $image === '' ? \Nino\Modules\Sign\Signature::typed( self::_posted( 'name' ) ) : null;

			if( $png === null ) {
				self::_page( $appData, $request, $view, $return, 'empty', 400 );
				return;
			}

			$id = $view['id'];
			$now = time();
			$header = $request['/nino/http/request']['header'] ?? [];
			$agent = is_array( $header ) === true && is_string( $header['User-Agent'] ?? null ) === true ? $header['User-Agent'] : '';
			$proof = [
				'ip'			=> \Nino\Http::getClientIp( $appData ),
				'agent'		=> mb_substr( trim( (string) preg_replace( '/[\s\p{C}]+/u', ' ', $agent ) ), 0, \Nino\Modules\Sign\Store::AGENT_LENGTH ),
				'consent'	=> self::_sentence( $appData ),
			];
			$why = '';

			$signed = \Nino\Modules\Sign\Store::change( $appData, $id, function( array $stored, array &$appData ) use ( $png, $method, $proof, $now, $id, &$why ): ?array {

				if( \Nino\Modules\Sign\Store::live( $stored ) === false ) {
					$why = 'state';
					return null;
				}

				// The document that was shown: its file is what it was, and made again with the day of the request it is the same bytes
				$shown = \Nino\Modules\Sign\Store::path( $appData, $id, 'shown' );
				$again = self::_render( $appData, $stored['template'], $stored['data'], [ 'date' => substr( $stored['created'], 0, 10 ), 'title' => $stored['title'] ] );

				if( $shown === null || hash_file( 'sha256', $shown ) !== $stored['hashShown'] || $again === '' || hash( 'sha256', $again ) !== $stored['hashShown'] ) {
					$why = 'changed';
					return [ 'status' => 'changed', 'changed' => gmdate( \Nino\Modules\Sign\Store::TIME, $now ) ] + $stored;
				}

				$account = \Nino\Modules\Members::account( $appData, $stored['account'] );
				$names = array_column( array_filter( \Nino\Modules\Pdf::fields( $appData, $stored['template'] ), static fn( array $field ): bool => $field['party'] === 'member' ), 'name' );

				if( $account === null || $names === [] ) {
					$why = 'state';
					return null;
				}

				$record = self::_record( $appData, [
					'[[document]]'		=> $stored['title'],
					'[[id]]'			=> $id,
					'[[name]]'		=> $account['name'] !== '' ? $account['name'] : $account['email'],
					'[[email]]'		=> $account['email'],
					'[[time]]'		=> gmdate( 'Y-m-d H:i:s', $now ). ' UTC',
					'[[ip]]'			=> $proof['ip'],
					'[[agent]]'		=> $proof['agent'],
					'[[hash1]]'		=> substr( $stored['hashShown'], 0, 32 ),
					'[[hash2]]'		=> substr( $stored['hashShown'], 32 ),
					'[[consent]]'	=> $proof['consent'],
				], $method );

				$bytes = self::_render( $appData, $stored['template'], $stored['data'], [
					'date'			=> gmdate( 'Y-m-d', $now ),
					'title'			=> $stored['title'],
					'signatures'	=> array_fill_keys( $names, $png ),
					'append'		=> $record,
				] );

				if( $bytes === '' ) {
					$why = 'render';
					return null;
				}

				// Written once: a file of an earlier try that did not come to the end belongs to no signing
				$files = [ \Nino\Modules\Sign\Store::file( $id, 'signature' ) => $png, \Nino\Modules\Sign\Store::file( $id, 'signed' ) => $bytes ];

				foreach( array_keys( $files ) as $file )
					@unlink( \Nino\Filesystem::path( $appData, $file ) );

				foreach( $files as $file => $content )
					if( \Nino\Filesystem::putFileContent( $appData, $file, $content ) === false ) {

						foreach( array_keys( $files ) as $each )
							@unlink( \Nino\Filesystem::path( $appData, $each ) );

						$why = 'write';
						return null;
					}

				return [
					'status'		=> 'signed',
					'signed'		=> gmdate( \Nino\Modules\Sign\Store::TIME, $now ),
					'hashSigned'	=> hash( 'sha256', $bytes ),
					'method'		=> $method,
					'record'		=> $proof,
				] + $stored;
			} );

			// The document was not the one that was shown: the request is over and written so, the owner is told
			if( is_array( $signed ) === true && $why === 'changed' ) {

				self::_mailOwner( $appData, $signed, 'changed' );
				self::_page( $appData, $request, self::_view( $appData, self::_resolve( $appData, self::_posted( 't' ), self::_posted( 'r' ) ) ), $return, '', 409 );
				return;
			}

			if( is_array( $signed ) === false ) {

				$again = self::_view( $appData, self::_resolve( $appData, self::_posted( 't' ), self::_posted( 'r' ) ) );
				$flash = $signed === 'write' || $why === 'write' || $why === 'render' ? 'write' : '';

				self::_page( $appData, $request, $again, $return, $flash, $flash === 'write' ? 500 : self::_status( $again ) );
				return;
			}

			\Nino\Callbacks::doCallbacks( $appData, '/sign/signed', $signed );
			self::_mailSigned( $appData, $signed );
			self::_redirect( $appData, $request, $return, $id, 'signed' );
		}

		/**
		 *	The request a token or an id names, and by which key it was named: a token is any holder's, an id the
		 *	signed-in member's it is of. A token that is none, an id that is none, one of another member and one a
		 *	visitor names are alike: no request
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$token
		 *	@param		string		$id
		 *
		 *	@return 	array										id, entry (as the index has it, or null), key (t, r or '') and value (what the key was given, '' where there is none)
		 */
		private static function _resolve( array &$appData, string $token, string $id ): array {

			if( $token !== '' ) {

				$found = \Nino\Modules\Sign\Store::byToken( $appData, $token );

				return $found === null ? [ 'id' => '', 'entry' => null, 'key' => '', 'value' => '' ] : [ 'id' => $found[0], 'entry' => $found[1], 'key' => 't', 'value' => $token ];
			}

			if( preg_match( \Nino\Modules\Sign\Store::ID, $id ) === 1 ) {

				$member = \Nino\Modules\Members::current( $appData );
				$entry = \Nino\Modules\Sign\Store::read( $appData )[$id] ?? null;

				if( $member !== null && $entry !== null && $entry['account'] === $member['id'] )
					return [ 'id' => $id, 'entry' => $entry, 'key' => 'r', 'value' => $id ];
			}

			return [ 'id' => '', 'entry' => null, 'key' => '', 'value' => '' ];
		}

		/**
		 *	What a page is told of a request: live while it is waiting and its account is there and active, else the
		 *	state it is in, or invalid where there is no request
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$resolved			As _resolve() has it
		 *
		 *	@return 	array										id, entry, title, state (live, signed, declined, cancelled, expired or invalid), key, value
		 */
		private static function _view( array &$appData, array $resolved ): array {

			$entry = $resolved['entry'];

			if( $entry === null )
				return [ 'id' => '', 'entry' => null, 'title' => '', 'state' => 'invalid', 'key' => '', 'value' => '' ];

			$state = \Nino\Modules\Sign\Store::status( $entry );

			if( $state === 'requested' || $state === 'viewed' ) {
				$account = \Nino\Modules\Members::account( $appData, $entry['account'] );
				$state = $account !== null && $account['active'] === true ? 'live' : 'invalid';
			}

			return $resolved + [ 'title' => $state === 'invalid' ? '' : $entry['title'], 'state' => $state ];
		}

		/**
		 *	The page of a signing or a decline that was redirected to: with no key it says the state and nothing of any
		 *	request - and to the member the request is of, who is signed in, it gives the link to the signed document
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$query				As _query() has it
		 *
		 *	@return 	array										As _view() has it
		 */
		private static function _flash( array &$appData, array $query ): array {

			$member = \Nino\Modules\Members::current( $appData );
			$entry = preg_match( \Nino\Modules\Sign\Store::ID, $query['sign'] ) === 1 ? ( \Nino\Modules\Sign\Store::read( $appData )[ $query['sign'] ] ?? null ) : null;
			$mine = $member !== null && $entry !== null && $entry['account'] === $member['id'] && $entry['status'] === $query['sign-state'];

			return [ 'id' => $mine === true ? $query['sign'] : '', 'entry' => null, 'title' => $mine === true ? $entry['title'] : '', 'state' => $query['sign-state'],
				'key' => $mine === true && $entry['status'] === 'signed' ? 'r' : '', 'value' => $mine === true ? $query['sign'] : '' ];
		}

		/**
		 *	Answer with the signing page in place: what the form and the component say is left for them as
		 *	'./sign/request'. Headed by the details of the page of the route itself, /.sign, whose words the unit
		 *	brings, kept out of every cache and with no referrer, since the address of the page holds the token
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *	@param		array 		$view					As _view() has it
		 *	@param		string		$return				The path the form takes the member back to, '' for none
		 *	@param		string		$flash				What the form says above its buttons, one of STATES, '' for nothing
		 *	@param		int				$status
		 *
		 *	@return 	void
		 */
		private static function _page( array &$appData, array &$request, array $view, string $return, string $flash, int $status ): void {

			$appData[self::REQUEST] = [ 'id' => $view['id'], 'title' => $view['title'], 'state' => $view['state'], 'key' => $view['key'], 'value' => $view['value'], 'return' => $return, 'flash' => $flash ];

			// The page is the route's own: its details are the unit's /_nino/webpage/.sign/ keys
			$request['/nino/http/response']['uri']									= '/.sign';
			$request['/nino/http/response']['body']										= '[template /templates/page-sign]';
			$request['/nino/http/response']['statusCode']							= $status;
			$request['/nino/http/response']['header']['Cache-Control']	= 'no-store';
			$request['/nino/http/response']['header']['Referrer-Policy']	= 'no-referrer';
		}

		/**
		 *	@param		array 		$view					As _view() has it
		 *
		 *	@return 	int												The status of a page about a request that is not being acted on: 404 where there is none, else 200
		 */
		private static function _status( array $view ): int {

			return $view['state'] === 'invalid' ? 404 : 200;
		}

		/**
		 *	Answer a signing or a decline with the redirect to the return page - the signing page where there is none -
		 *	and the id and the state in the query. No token is in it
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *	@param		string		$return
		 *	@param		string		$id
		 *	@param		string		$state				signed or declined
		 *
		 *	@return 	void
		 */
		private static function _redirect( array &$appData, array &$request, string $return, string $id, string $state ): void {

			\Nino\Http::redirect( $appData, $request, ( $return !== '' ? $return : '/.sign' ). '?sign='. $id. '&sign-state='. $state, 303 );
		}

		/**
		 *	The PDF of a template made in the site's native language, so that the bytes are the same whoever asks and
		 *	whenever: the fills a document names are read in that language
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$template
		 *	@param		array 		$data
		 *	@param		array 		$options			As Pdf::render() has them
		 *
		 *	@return 	string										'' where it could not be made
		 */
		private static function _render( array &$appData, string $template, array $data, array $options ): string {

			$locale = \Nino\Locales::getCurrentLocale( $appData );

			\Nino\Locales::useLocale( $appData, \Nino\Locales::getNativeLocale( $appData ) );

			try {
				$bytes = \Nino\Modules\Pdf::render( $appData, $template, $data, $options );
			}
			finally {
				\Nino\Locales::useLocale( $appData, $locale );
			}

			return is_string( $bytes ) === true ? $bytes : '';
		}

		/**
		 *	The record of a signing as the HTML the Pdf feature appends: the template is rendered first - its words are
		 *	the keys of the unit, in the language of the signing - and the values are put in afterwards, escaped, so that
		 *	nothing in them is ever read as a fill or a shortcode
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$values				token => value
		 *	@param		string		$method				drawn or typed
		 *
		 *	@return 	string
		 */
		private static function _record( array &$appData, array $values, string $method ): string {

			$html = \Nino\Html::renderHtml( $appData, str_replace( '[[method]]', $method, \Nino\Feature::template( $appData, self::TEMPLATES, 'record' ) ) );

			return str_replace( array_keys( $values ), array_map( static fn( string $value ): string => \Nino\Modules\Components::escape( $value ), array_values( $values ) ), $html );
		}

		/**
		 *	The sentence a member agrees to, as the page shows it in the language of the signing: plain text
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	string
		 */
		private static function _sentence( array &$appData ): string {

			$sentence = html_entity_decode( strip_tags( (string) \Nino\Html::resolveTextfill( $appData, '/feature/sign/form/consent' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', $sentence ) ), 0, \Nino\Modules\Sign\Store::CONSENT_LENGTH );
		}

		/**
		 *	A link that is made without a mail is charged to the same counter a mail would be: the kernel's cap of mails an hour from one address, the configured one ('/nino/mail/cap', five by default, no cap at 0). Over it
		 *	the call is refused and flagged in './nino/mail/ratelimited', as a refused mail is, so that the callers answer with the codes of the cap
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	bool										Whether the link may be made
		 */
		private static function _charge( array &$appData ): bool {

			$appData['./nino/mail/ratelimited'] = false;

			if( \Nino\Feature::count( $appData, 'ratelimit', \Nino\Http::getClientIp( $appData ), (int) ( $appData['/nino/mail/cap'] ?? \Nino\AppData::DEFAULTS['/nino/mail/cap'] ), 3600 ) === true )
				return true;

			$appData['./nino/mail/ratelimited'] = true;

			return false;
		}

		/**
		 *	The link of a request as the mail carries it: the address of the site, /.sign with the token and the page to return to. Where the
		 *	site has no address of its own the path alone - a link that works on the site it is opened on
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$token
		 *	@param		string		$page					The path the link takes the member back to after the signing, '' for none
		 *
		 *	@return 	string
		 */
		private static function _link( array &$appData, string $token, string $page ): string {

			$path = '/.sign?t='. rawurlencode( $token ). ( $page !== '' ? '&return='. rawurlencode( $page ) : '' );
			$url = \Nino\Html::absoluteUrl( $appData, $path );

			return $url === '' ? $path : $url;
		}

		/**
		 *	Mail a member the link of a request, in the site's native language: the title, the link with the time it is
		 *	good for, and that the page shows the document and asks for one signature
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$member				The account, as Members::account() has it
		 *	@param		array 		$request			As get() has it
		 *	@param		string		$token
		 *	@param		string		$page					The path the link takes the member back to after the signing, '' for none
		 *	@param		int				$days
		 *
		 *	@return 	bool										Whether the mail was delivered - false, with a warning that names no address, where the site has no address of its own to build the link on
		 */
		private static function _mailRequest( array &$appData, array $member, array $request, string $token, string $page, int $days ): bool {

			$url = \Nino\Html::absoluteUrl( $appData, '/.sign?t='. rawurlencode( $token ). ( $page !== '' ? '&return='. rawurlencode( $page ) : '' ) );

			if( $url === '' ) {
				trigger_error( 'Sign: no mail was sent, the site has no address (the fill /project/website/general/url)', E_USER_WARNING );
				return false;
			}

			$mail = self::_compose( $appData, 'mail-request', 'request', true, [
				'[[name]]'	=> $member['name'] !== '' ? $member['name'] : $member['email'],
				'[[document]]'	=> $request['title'],
				'[[link]]'	=> $url,
				'[[days]]'	=> (string) $days,
			] );

			if( \Nino\Mail::send( $appData, $member['email'], $mail['subject'], $mail['body'], self::_contact( $appData ) ) === true )
				return true;

			trigger_error( 'Sign: the mail about the request '. $request['id']. ( \Nino\Mail::rateLimited( $appData ) === true ? ' was refused by the cap of mails an hour' : ' was not delivered' ), E_USER_WARNING );

			return false;
		}

		/**
		 *	Tell a member and the owner of a signing, in one call of the kernel's mail cap, which counts a call and not a
		 *	mail: the member gets the link to the signed document on the site (/.sign?r=<id>), in the language of the
		 *	page; the owner, where the setting notifyOwner is on, the title and who signed in the site's native one
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$signed				The request, as get() has it
		 *
		 *	@return 	bool										Whether every mail was delivered - true where there was none to send
		 */
		private static function _mailSigned( array &$appData, array $signed ): bool {

			$member = \Nino\Modules\Members::account( $appData, $signed['account'] );
			$link = \Nino\Html::absoluteUrl( $appData, '/.sign?r='. $signed['id'] );
			$replyTo = self::_contact( $appData );
			$mails = [];

			if( $member !== null && $link !== '' ) {
				$mail = self::_compose( $appData, 'mail-signed', 'signed', false, [
					'[[name]]'	=> $member['name'] !== '' ? $member['name'] : $member['email'],
					'[[document]]'	=> $signed['title'],
					'[[link]]'	=> $link,
				] );
				$mails[] = [ 'to' => $member['email'], 'subject' => $mail['subject'], 'body' => $mail['body'], 'replyTo' => $replyTo ];
			}
			else
				trigger_error( 'Sign: no mail was sent to the member, the account is gone or the site has no address', E_USER_WARNING );

			$owner = self::_ownerMail( $appData, $signed, 'signed' );

			if( $owner !== null )
				$mails[] = $owner;

			return self::_send( $appData, $mails, $signed['id'] );
		}

		/**
		 *	Tell the owner that a member declined - or that a document was not the one shown when it was to be signed -, where the setting notifyOwner is on
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$request			As get() has it
		 *	@param		string		$outcome			declined or changed
		 *
		 *	@return 	bool										Whether a mail was delivered
		 */
		private static function _mailOwner( array &$appData, array $request, string $outcome ): bool {

			$owner = self::_ownerMail( $appData, $request, $outcome );

			return $owner !== null && self::_send( $appData, [ $owner ], $request['id'] );
		}

		/**
		 *	The mail to the owner about a signing or a decline: to the contact address of the website, with the title,
		 *	who, and a link to the panel; none where the setting notifyOwner is off, and none, with a warning that names
		 *	no address, where the site has no contact address or none of its own
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$request			As get() has it
		 *	@param		string		$outcome			signed, declined or changed
		 *
		 *	@return 	array|null								to, subject, body and replyTo
		 */
		private static function _ownerMail( array &$appData, array $request, string $outcome ): ?array {

			if( \Nino\Features::setting( $appData, self::FEATURE_KEY, 'notifyOwner', true ) !== true )
				return null;

			$to = self::_contact( $appData );
			$link = \Nino\Html::absoluteUrl( $appData, '/_admin#sign' );
			$member = \Nino\Modules\Members::account( $appData, $request['account'] );

			if( $to === '' || $link === '' ) {
				trigger_error( 'Sign: no mail was sent to the owner, the site has no contact address or no address of its own', E_USER_WARNING );
				return null;
			}

			$mail = self::_compose( $appData, 'mail-owner', 'owner-'. $outcome, true, [
				'[[document]]'	=> $request['title'],
				'[[who]]'		=> $member === null ? '' : ( $member['name'] !== '' ? $member['name'] : $member['email'] ),
				'[[link]]'	=> $link,
			], [ '[[outcome]]' => $outcome ] );

			return [ 'to' => $to, 'subject' => $mail['subject'], 'body' => $mail['body'], 'replyTo' => '' ];
		}

		/**
		 *	Send mails in one call of the kernel's mail cap; what is not delivered is a warning that names the request by
		 *	its id and never an address
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$mails				to, subject, body and replyTo each
		 *	@param		string		$id
		 *
		 *	@return 	bool										Whether every mail was delivered - true where there was none
		 */
		private static function _send( array &$appData, array $mails, string $id ): bool {

			if( $mails === [] || \Nino\Mail::sendAll( $appData, $mails ) === true )
				return true;

			trigger_error( 'Sign: the mails about the request '. $id. ( \Nino\Mail::rateLimited( $appData ) === true ? ' were refused by the cap of mails an hour' : ' were not all delivered' ), E_USER_WARNING );

			return false;
		}

		/**
		 *	A mail rendered: the template and the subject are rendered first - their words are the keys of the unit,
		 *	in the site's native language or in the one of the request - and the values are put in afterwards, in the
		 *	result and escaped, so that nothing in them is ever read as a fill or a shortcode (the order
		 *	\Nino\Form::render() keeps)
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$template			mail-request, mail-signed or mail-owner
		 *	@param		string		$subject			The name of the subject: /feature/sign/mail/<name>-subject
		 *	@param		bool			$native				In the site's native language
		 *	@param		array 		$values				token => value, unescaped
		 *	@param		array 		$words				token => the name of a word of the unit, put into the template before it is rendered: [[outcome]] in a key
		 *
		 *	@return 	array										[ 'subject' => ..., 'body' => ... ]
		 */
		private static function _compose( array &$appData, string $template, string $subject, bool $native, array $values, array $words = [] ): array {

			$locale = \Nino\Locales::getCurrentLocale( $appData );

			if( $native === true )
				\Nino\Locales::useLocale( $appData, \Nino\Locales::getNativeLocale( $appData ) );

			try {
				$body = \Nino\Html::renderHtml( $appData, str_replace( array_keys( $words ), array_values( $words ), \Nino\Feature::template( $appData, self::TEMPLATES, $template ) ) );
				$line = \Nino\Html::renderHtml( $appData, '[[/feature/sign/mail/'. $subject. '-subject]]' );
			}
			finally {
				if( $native === true )
					\Nino\Locales::useLocale( $appData, $locale );
			}

			return [ 'subject' => $line, 'body' => str_replace( array_keys( $values ), array_map( static fn( string $value ): string => \Nino\Modules\Components::escape( $value ), array_values( $values ) ), $body ) ];
		}

		/**
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	string										The contact address of the website where it is one, else ''
		 */
		private static function _contact( array &$appData ): string {

			$address = trim( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/email' ) );

			return filter_var( $address, FILTER_VALIDATE_EMAIL ) === false ? '' : $address;
		}

		/**
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	int												How many days a link is good for: the setting days
		 */
		private static function _days( array &$appData ): int {

			return max( 1, min( 90, (int) \Nino\Features::setting( $appData, self::FEATURE_KEY, 'days', self::DEFAULT_DAYS ) ) );
		}

		/**
		 *	A title cleaned: one line of single spaces, at most 120 characters
		 *
		 *	@param		string		$title
		 *
		 *	@return 	string
		 */
		private static function _title( string $title ): string {

			return mb_substr( \Nino\Modules\Sign\Signature::clean( $title ), 0, self::TITLE_LENGTH );
		}

		/**
		 *	The value of Content-Disposition for a PDF: shown in the browser, the name twice - the plain one cut down to
		 *	letters, digits and a few signs, and the one of RFC 5987 with the whole UTF-8 name percent-encoded
		 *
		 *	@param		string		$title
		 *	@param		string		$kind					shown or signed
		 *
		 *	@return 	string
		 */
		private static function _disposition( string $title, string $kind ): string {

			$name = ( $title === '' ? 'document' : $title ). ( $kind === 'signed' ? '-signed' : '' ). '.pdf';
			$plain = trim( (string) preg_replace( '/[^A-Za-z0-9._ -]+/', '_', $name ) );

			return 'inline; filename="'. ( $plain === '' ? 'document.pdf' : $plain ). '"; filename*=UTF-8\'\''. rawurlencode( $name );
		}

		/**
		 *	The query of the request as strings: t, r, return, kind, sign and sign-state - '' for what is absent or no
		 *	string, which a query can be (r[]=x)
		 *
		 *	@param		array 		$request
		 *
		 *	@return 	array
		 */
		private static function _query( array $request ): array {

			$query = $request['/nino/http/request']['query'] ?? [];
			$read = [];

			foreach( [ 't', 'r', 'return', 'kind', 'sign', 'sign-state' ] as $name )
				$read[$name] = is_array( $query ) === true && is_string( $query[$name] ?? null ) === true ? $query[$name] : '';

			return $read;
		}

		/**
		 *	One posted value, as a string - nothing says a post carries strings, and 't[]=x' would be an "Array to string
		 *	conversion", a level the runtime treats as fatal
		 *
		 *	@param		string		$name
		 *
		 *	@return 	string
		 */
		private static function _posted( string $name ): string {

			return is_string( $_POST[$name] ?? null ) === true ? $_POST[$name] : '';
		}

		/**
		 *	The page a link takes a member back to: the path without its query, where it is a path of this site and no
		 *	endpoint of a module - the rules of a return path of the Members feature, whose own check is its private. ''
		 *	for anything else: it starts with a single slash, not '//' and not '/\', not '/.', has no whitespace or
		 *	control character and no ':' before its next '/'
		 *
		 *	@param		string		$posted
		 *
		 *	@return 	string
		 */
		private static function _path( string $posted ): string {

			$path = substr( $posted, 0, strcspn( $posted, '?#' ) );

			return str_starts_with( $path, '/' ) === true && str_starts_with( $path, '//' ) === false && str_starts_with( $path, '/\\' ) === false && str_starts_with( $path, '/.' ) === false
				&& preg_match( '/[\s\x00-\x1f\x7f]/', $path ) !== 1 && preg_match( '#^/[^/]*:#', $path ) !== 1 ? $path : '';
		}
	}
}
