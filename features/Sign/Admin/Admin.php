<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Nino\Modules\Sign\Admin		The /_admin panel of the Sign feature - see docs/features.md, "The panel"
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules\Sign {

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Sign\Admin				"Signatures" panel (Unterschriften): the owner's side of the feature on one screen.
	 *										A form asks a member for a signature - the member, a template of the tags the
	 *										setting lists with the free texts it asks for, a title -; the list is every
	 *										request, the newest first, with how it stands, who it is of and when it was
	 *										asked, viewed, signed or declined, and on a row the record of a signing (the
	 *										address, the browser, the hashes of the two PDFs, how it was signed, the
	 *										sentence agreed to) and the two PDFs; a request that waits is reminded or
	 *										cancelled, any request is deleted with its files.
	 *
	 *										The panel validates what it is posted and answers with the codes of the failures
	 *										a person can cause; the rules of the data and the writes are Sign\Store's, and
	 *										the request, the reminder and the mails are the class's. The panel has no action
	 *										that downloads: it hands every request the addresses of its PDFs and the screen
	 *										links them, and the route GET /.sign/pdf, which the kernel streams, answers the
	 *										workbench session of the owner by the permission of this panel.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Admin {

		public const string MANAGE_PERM = '/_admin/sign/manage';

		// The longest free text the panel takes for an input of a template
		public const int INPUT_LENGTH = 5000;

		// The id of the request an action made, by action: written by the action, read by log() in the same request
		private static array $_made = [];

		public static function perm(): string {
			return self::MANAGE_PERM;
		}

		public static function actions(): array {
			return [
				'sign/list'		=> [ self::class, 'apiList' ],
				'sign/options'	=> [ self::class, 'apiOptions' ],
				'sign/request'	=> [ self::class, 'apiRequest' ],
				'sign/remind'		=> [ self::class, 'apiRemind' ],
				'sign/cancel'		=> [ self::class, 'apiCancel' ],
				'sign/again'		=> [ self::class, 'apiAgain' ],
				'sign/delete'		=> [ self::class, 'apiDelete' ],
			];
		}

		// No group is named here: a panel a feature brought always lands in the rail's own "features" group
		public static function nav(): array {
			return [ 'sign', '/_admin/sign/nav', 57 ];
		}

		public static function icon(): string {
			return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 21h8"/><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/></svg>';
		}

		// The panel renders its own region (see templates/panel.tpl) rather than mount points a script fills
		public static function template(): string {
			return \Nino\Admin\Panels::relative( dirname( __DIR__ ). '/templates/panel' );
		}

		public static function assets(): array {
			return [ \Nino\Admin\Panels::relative( dirname( __DIR__ ). '/assets/admin.js' ), \Nino\Admin\Panels::relative( dirname( __DIR__ ). '/assets/admin.css' ) ];
		}

		// The panel's own strings, one <locale>.php per interface language
		public static function text(): string {
			return \Nino\Admin\Panels::relative( dirname( __DIR__ ). '/text' );
		}

		/**
		 *	The Dashboard's tile: how many requests are open - they wait for a signature, or are changed and wait for the owner to ask again
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array
		 */
		public static function summary( array &$appData ): array {

			return [ 'value' => count( array_filter( \Nino\Modules\Sign\Store::all( $appData ), static fn( array $request ): bool => in_array( $request['status'], [ 'requested', 'viewed', 'changed' ], true ) ) ), 'label' => '/_admin/sign/label/open' ];
		}

		/**
		 *	The activity-log line of a completed action: the id of the request where there is one, never the address, the
		 *	name or the title
		 *
		 *	@param		string		$action
		 *	@param		array 		$data					What the action was posted
		 *
		 *	@return 	string
		 */
		public static function log( string $action, array $data ): string {

			$id = self::_id( $data );
			$of = $id === '' ? '' : ' '. $id;

			return match( $action ) {
				'sign/request'	=> 'Asked for a signature'. ( self::$_made[$action] ?? '' ). self::_how( $data ),
				'sign/remind'		=> 'Reminded of signature request'. $of. self::_how( $data ),
				'sign/cancel'		=> 'Cancelled signature request'. $of,
				'sign/again'		=> 'Asked again for signature request'. $of. ( isset( self::$_made[$action] ) === true ? ', new request'. self::$_made[$action] : '' ). self::_how( $data ),
				'sign/delete'		=> 'Deleted signature request'. $of,
				default					=> '',
			};
		}

		/**
		 *	What the screen draws: every request, the newest first, each with the member it is of, the template's name, the
		 *	record of a signing, whether its two PDFs are there and the addresses they are opened at - the route's, below
		 *	the directory the project is served from - and the days a link is valid for
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiList( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$templates = \Nino\Modules\Pdf::templates( $appData );
			$members = [];
			$requests = [];

			foreach( \Nino\Modules\Sign\Store::all( $appData ) as $each ) {

				$account = $each['account'];

				if( isset( $members[$account] ) === false ) {
					$member = \Nino\Modules\Members::account( $appData, $account );
					$members[$account] = $member === null ? [ 'name' => '', 'email' => '', 'label' => '' ] : [ 'name' => $member['name'], 'email' => $member['email'], 'label' => $member['name'] !== '' ? $member['name'] : $member['email'] ];
				}

				$address = \Nino\Filesystem::getDir( $appData ). '/.sign/pdf?r='. $each['id']. '&kind=';

				$requests[] = [
					'id'				=> $each['id'],
					'title'			=> $each['title'],
					'template'		=> $each['template'],
					'templateName'	=> $templates[ $each['template'] ]['name'] ?? '',
					'account'		=> $account,
					'member'		=> $members[$account],
					'status'		=> $each['status'],
					'created'		=> $each['created'],
					'viewed'		=> $each['viewed'],
					'signed'		=> $each['signed'],
					'declined'		=> $each['declined'],
					'changed'		=> $each['changed'],
					'expires'		=> $each['expires'],
					'hashShown'		=> $each['hashShown'],
					'hashSigned'	=> $each['hashSigned'],
					'method'		=> $each['method'],
					'record'		=> $each['record'],
					'files'			=> [ 'shown' => \Nino\Modules\Sign\Store::path( $appData, $each['id'], 'shown' ) !== null, 'signed' => \Nino\Modules\Sign\Store::path( $appData, $each['id'], 'signed' ) !== null ],
					'urls'			=> [ 'shown' => $address. 'shown', 'signed' => $address. 'signed' ],
				];
			}

			\Nino\Http::ok( $request, [ 'requests' => $requests, 'days' => (int) \Nino\Features::setting( $appData, 'sign', 'days', 14 ) ] );
		}

		/**
		 *	What the form of a new request offers: the active accounts of the Members feature and the templates of the
		 *	Pdf feature that carry one of the tags of the setting tags and have a field for a member, each with the free
		 *	texts it asks for
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiOptions( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$accounts = [];

			foreach( \Nino\Modules\Members::accounts( $appData ) as $account )
				if( $account['active'] === true )
					$accounts[] = [ 'id' => $account['id'], 'name' => $account['name'], 'email' => $account['email'], 'group' => $account['group'] ];

			$templates = [];

			foreach( self::_tags( $appData ) as $tag )
				foreach( \Nino\Modules\Pdf::templates( $appData, $tag ) as $key => $template ) {

					$key = (string) $key;

					if( isset( $templates[$key] ) === true || count( array_filter( \Nino\Modules\Pdf::fields( $appData, $key ), static fn( array $field ): bool => $field['party'] === 'member' ) ) === 0 )
						continue;

					$templates[$key] = [ 'key' => $key, 'name' => $template['name'], 'tag' => $template['tag'], 'inputs' => \Nino\Modules\Pdf::inputs( $appData, $key ) ];
				}

			\Nino\Http::ok( $request, [ 'accounts' => $accounts, 'templates' => array_values( $templates ), 'limits' => [ 'title' => 120, 'input' => self::INPUT_LENGTH ] ] );
		}

		/**
		 *	Ask a member for a signature: the account, the template, the free texts of its inputs - only those it has - and
		 *	a title. The refusals a person can cause carry their codes, the field beside them: the template (sign_template,
		 *	sign_member - it has no field for a member), the account (sign_account), a title of more than 120 characters
		 *	(sign_long) and an input it asks for that is empty or too long (sign_input). The request is there when only its
		 *	mail could not be sent: a 500 with the code sign_mail, or a 429 with sign_ratelimit where the kernel's cap of
		 *	mails refused it - the screen asks for the list again, and the row has Remind
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiRequest( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$data = \Nino\Admin\Admin::postData();
			$template = trim( \Nino\Admin\Admin::postString( $data, 'template' ) );
			$title = trim( \Nino\Admin\Admin::postString( $data, 'title' ) );

			if( mb_strlen( $title ) > 120 ) {
				\Nino\Http::fail( $request, 400, 'the title is too long', 'sign_long', [ 120 ], 'title' );
				return;
			}

			$given = is_array( $data['inputs'] ?? null ) === true ? $data['inputs'] : [];
			$inputs = [];

			foreach( \Nino\Modules\Pdf::inputs( $appData, $template ) as $input ) {

				$text = is_string( $given[ $input['name'] ] ?? null ) === true ? $given[ $input['name'] ] : '';

				if( mb_strlen( $text ) > self::INPUT_LENGTH || ( $input['required'] === true && trim( $text ) === '' ) ) {
					\Nino\Http::fail( $request, 400, 'a free text of the template is empty or too long', 'sign_input', [ $input['label'] !== '' ? $input['label'] : $input['name'], self::INPUT_LENGTH ], 'input-'. $input['name'] );
					return;
				}

				$inputs[ $input['name'] ] = $text;
			}

			$made = \Nino\Modules\Sign::request( $appData, $template, [ 'inputs' => $inputs ], \Nino\Admin\Admin::postString( $data, 'account' ), ( $title === '' ? [] : [ 'title' => $title ] ) + [ 'notify' => self::_notify( $data ) ] );

			self::_answerMade( $appData, $request, $made, 'sign/request' );
		}

		/**
		 *	Ask again for a request that is changed: a new request of the same template, data, member, title and page, in
		 *	one click - the changed one stays as it is. Only a changed request can be (409, sign_state); what a new request
		 *	is refused for is what apiRequest() answers, a template that is gone among it
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiAgain( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$data = \Nino\Admin\Admin::postData();
			$id = self::_id( $data );
			$found = $id === '' ? null : ( \Nino\Modules\Sign\Store::read( $appData )[$id] ?? null );

			if( $found === null ) {
				\Nino\Http::fail( $request, 404, 'unknown request', 'sign_unknown' );
				return;
			}

			if( $found['status'] !== 'changed' ) {
				\Nino\Http::fail( $request, 409, 'only a changed request is asked again', 'sign_state' );
				return;
			}

			self::_answerMade( $appData, $request, \Nino\Modules\Sign::request( $appData, $found['template'], $found['data'], $found['account'], [ 'title' => $found['title'], 'page' => $found['page'], 'notify' => self::_notify( $data ) ] ), 'sign/again' );
		}

		/**
		 *	Answer what Sign::request() made: its refusals with their codes, or the id of the new request - and a request
		 *	whose mail could not be sent, which is there all the same, with the failure of the mail and a line of the
		 *	activity log, since something was made
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *	@param		array|string	$made				What Sign::request() answered
		 *	@param		string		$action				sign/request or sign/again
		 *
		 *	@return 	void
		 */
		private static function _answerMade( array &$appData, array &$request, array|string $made, string $action ): void {

			if( is_string( $made ) === true ) {

				match( $made ) {
					'template'	=> \Nino\Http::fail( $request, 400, 'there is no such template', 'sign_template', [], 'template' ),
					'member'		=> \Nino\Http::fail( $request, 400, 'the template has no field for a member', 'sign_member', [], 'template' ),
					'account'		=> \Nino\Http::fail( $request, 400, 'there is no such member, or the account is switched off', 'sign_account', [], 'account' ),
					'input'			=> \Nino\Http::fail( $request, 400, 'a free text of the template is empty', 'sign_input', [ '', self::INPUT_LENGTH ], 'template' ),
					'ratelimit'	=> \Nino\Http::fail( $request, 429, 'the kernel\'s cap of mails an hour from one address is reached', 'sign_ratelimit' ),
					default			=> \Nino\Http::fail( $request, 500, 'could not make the request' ),
				};

				return;
			}

			self::$_made[$action] = ' '. $made['id'];

			// Asked not to mail: the link is the answer, to the account that asked and to nobody else - and no line of the log holds it
			if( isset( $made['link'] ) === true ) {
				\Nino\Http::ok( $request, [ 'id' => $made['id'], 'link' => $made['link'] ] );
				return;
			}

			if( $made['mailed'] === false ) {

				$user = \Nino\Auth::getCurrentUser( $appData );

				if( $user !== false )
					\Nino\Admin\Admin::record( $appData, $user['mail'], ( $action === 'sign/again' ? 'Asked again for a signature' : 'Asked for a signature' ). ' - request'. self::$_made[$action]. ', its mail was not sent' );

				self::_unsent( $appData, $request, 'the request was made, but the mail could not be sent' );
				return;
			}

			\Nino\Http::ok( $request, [ 'id' => $made['id'] ] );
		}

		/**
		 *	Send the link of a request again: a new token, a new time, the mail. A request that is over - signed, declined
		 *	or cancelled - cannot be reminded (409, sign_state); the mail that could not be sent is a 500 with the code
		 *	sign_mail, or a 429 with sign_ratelimit where the kernel's cap of mails refused it - and the link the member has
		 *	is as it was then
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiRemind( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$data = \Nino\Admin\Admin::postData();
			$id = self::_id( $data );
			$found = $id === '' ? null : \Nino\Modules\Sign\Store::get( $appData, $id );

			if( $found === null ) {
				\Nino\Http::fail( $request, 404, 'unknown request', 'sign_unknown' );
				return;
			}

			if( in_array( $found['status'], [ 'requested', 'viewed', 'expired' ], true ) === false ) {
				\Nino\Http::fail( $request, 409, 'the request is over', 'sign_state' );
				return;
			}

			$notify = self::_notify( $data );
			$link = \Nino\Modules\Sign::remind( $appData, $id, $notify );

			if( $link === false ) {

				// A refused link is a refused mail to the caller: the cap speaks first, whatever was asked
				if( $notify === false && \Nino\Mail::rateLimited( $appData ) === false )
					\Nino\Http::fail( $request, 500, 'the request could not be given a new link' );
				else
					self::_unsent( $appData, $request, 'the reminder could not be sent' );

				return;
			}

			\Nino\Http::ok( $request, $notify === false ? [ 'sent' => false, 'link' => (string) $link ] : [ 'sent' => true ] );
		}

		/**
		 *	Take a request back. One that was signed or declined cannot be (409, sign_state)
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiCancel( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$id = self::_id( \Nino\Admin\Admin::postData() );
			$found = $id === '' ? null : \Nino\Modules\Sign\Store::get( $appData, $id );

			if( $found === null ) {
				\Nino\Http::fail( $request, 404, 'unknown request', 'sign_unknown' );
				return;
			}

			if( \Nino\Modules\Sign::cancel( $appData, $id ) === false ) {
				\Nino\Http::fail( $request, 409, 'the request is over', 'sign_state' );
				return;
			}

			\Nino\Http::ok( $request );
		}

		/**
		 *	Delete a request, the files with it
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiDelete( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$id = self::_id( \Nino\Admin\Admin::postData() );

			if( $id === '' || \Nino\Modules\Sign\Store::get( $appData, $id ) === null ) {
				\Nino\Http::fail( $request, 404, 'unknown request', 'sign_unknown' );
				return;
			}

			if( \Nino\Modules\Sign::remove( $appData, $id ) === false ) {
				\Nino\Http::fail( $request, 500, 'could not delete the request' );
				return;
			}

			\Nino\Http::ok( $request );
		}

		/**
		 *	The tags the setting lists: the lines that are slugs, each once
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array
		 */
		private static function _tags( array &$appData ): array {

			$tags = [];

			foreach( (array) \Nino\Features::setting( $appData, 'sign', 'tags', [] ) as $tag )
				if( is_string( $tag ) === true && preg_match( \Nino\Modules\Pdf::SLUG, $tag ) === 1 && in_array( $tag, $tags, true ) === false )
					$tags[] = $tag;

			return $tags;
		}

		/**
		 *	Answer a mail that was not sent. The kernel's cap of mails an hour from one address,
		 *	\Nino\Mail::rateLimited() - is a failure of its own: sign_ratelimit, a 429, so that whoever is at the screen waits
		 *	an hour instead of looking for a fault in the mail settings. Any other is sign_mail, a 500
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *	@param		string		$error				The English sentence for the log of a mail that was attempted
		 *
		 *	@return 	void
		 */
		private static function _unsent( array &$appData, array &$request, string $error ): void {

			if( \Nino\Mail::rateLimited( $appData ) === true ) {
				\Nino\Http::fail( $request, 429, 'the kernel\'s cap of mails an hour from one address is reached', 'sign_ratelimit' );
				return;
			}

			\Nino\Http::fail( $request, 500, $error, 'sign_mail' );
		}

		/**
		 *	What the line of the log says of the mail: nothing where it was sent, that it was not where the owner chose so. Never the link
		 *
		 *	@param		array 		$data					What the action was posted
		 *
		 *	@return 	string
		 */
		private static function _how( array $data ): string {

			return self::_notify( $data ) === true ? '' : ', without mail';
		}

		/**
		 *	Whether the member is mailed for this action: true unless the post says false
		 *
		 *	@param		array 		$data					What the action was posted
		 *
		 *	@return 	bool
		 */
		private static function _notify( array $data ): bool {

			return ( $data['notify'] ?? true ) !== false;
		}

		/**
		 *	The posted id of a request: 12 hex characters or nothing. Anything else is an id nobody has
		 *
		 *	@param		array 		$data					What the action was posted
		 *
		 *	@return 	string
		 */
		private static function _id( array $data ): string {

			$id = \Nino\Admin\Admin::postString( $data, 'id' );

			return preg_match( \Nino\Modules\Sign\Store::ID, $id ) === 1 ? $id : '';
		}
	}
}
