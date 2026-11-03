<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Nino\Modules\Members\Admin		The /_admin panel of the Members feature - see docs/features.md, "The panel"
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules\Members {

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Members\Admin			"Members" panel: the accounts of the members' area, as
	 *										\Nino\Modules\Members keeps them in /data/members.php, on
	 *										one screen - the list, sorted by name and filtered by
	 *										group, a form to add an account, and for each account
	 *										the three things somebody who runs a members' area does:
	 *										change the name, the group and whether it is active, send
	 *										a link to sign in, delete it. A button above the list
	 *										signs every member out at once. The panel validates what
	 *										it is posted and answers with the codes of the failures a
	 *										person can cause; the rules of an account, its writes and
	 *										its mails are the class's, which this calls.
	 *
	 *										The screen has no password to show, because there is
	 *										none: a member signs in with a link, and the panel can
	 *										send one. The protected pages and the lifetime of a link
	 *										are settings of the feature, in the Features panel.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Admin {

		public const string MANAGE_PERM = '/_admin/members/manage';

		public static function perm(): string {
			return self::MANAGE_PERM;
		}

		public static function actions(): array {
			return [
				'members/list'		=> [ self::class, 'apiList' ],
				'members/add'			=> [ self::class, 'apiAdd' ],
				'members/save'		=> [ self::class, 'apiSave' ],
				'members/delete'	=> [ self::class, 'apiDelete' ],
				'members/link'		=> [ self::class, 'apiLink' ],
				'members/signout'	=> [ self::class, 'apiSignout' ],
			];
		}

		public static function nav(): array {
			return [ 'members', '/_admin/members/nav', 50 ];
		}

		public static function icon(): string {
			return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 11 2 2 4-4"/><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>';
		}

		// The panel renders its own regions (see templates/panel.tpl) rather than
		// mount points a script fills
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
		 *	The Dashboard's tile: how many accounts there are
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array
		 */
		public static function summary( array &$appData ): array {
			return [ 'value' => count( \Nino\Modules\Members::accounts( $appData ) ), 'label' => '/_admin/members/label/count' ];
		}

		/**
		 *	The activity-log line of a completed action. The id of the account where
		 *	there is one, never its address or its name: the line says what was done
		 *	and not to whom
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
				'members/add'			=> 'Added a member',
				'members/save'		=> 'Changed member'. $of,
				'members/delete'	=> 'Deleted member'. $of,
				'members/link'		=> 'Sent a login link to member'. $of,
				'members/signout'	=> 'Signed every member out',
				default						=> '',
			};
		}

		/**
		 *	What the screen draws: every account, sorted by name, and the groups in use
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiList( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			\Nino\Http::ok( $request, [ 'accounts' => \Nino\Modules\Members::accounts( $appData ), 'groups' => \Nino\Modules\Members::groups( $appData ) ] );
		}

		/**
		 *	Add an account, and send the invitation where the form says so. An address
		 *	that is none, one that is a member's already (without regard to case) and a
		 *	name that is too long are refused with their codes, the field they were
		 *	posted in beside them. An invitation that could not be sent is a 500 with
		 *	the code members_mail, or a 429 with members_ratelimit where the kernel's cap
		 *	of mails refused it (see _unsent()) - the account is there all the same, and
		 *	the screen asks for the list again, since sending the link again is one press
		 *	on its row
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiAdd( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$data = \Nino\Admin\Admin::postData();
			$added = \Nino\Modules\Members::add( $appData, \Nino\Admin\Admin::postString( $data, 'email' ), \Nino\Admin\Admin::postString( $data, 'name' ), \Nino\Admin\Admin::postString( $data, 'group' ) );

			if( is_string( $added ) === true ) {
				self::_failed( $request, $added );
				return;
			}

			$invite = ( $data['invite'] ?? false ) === true;

			if( $invite === true && \Nino\Modules\Members::link( $appData, $added, 'invite', '', true ) === false ) {
				self::_unsent( $appData, $request, 'the member was added, but the invitation could not be sent' );
				return;
			}

			\Nino\Http::ok( $request, [ 'account' => $added, 'invited' => $invite ] );
		}

		/**
		 *	Change an account's name, group and whether it is active - what is posted
		 *	of the three, and nothing else of the account
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiSave( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$data = \Nino\Admin\Admin::postData();
			$changes = [];

			foreach( [ 'name', 'group' ] as $field )
				if( is_string( $data[$field] ?? null ) === true )
					$changes[$field] = $data[$field];

			if( is_bool( $data['active'] ?? null ) === true )
				$changes['active'] = $data['active'];

			$saved = \Nino\Modules\Members::change( $appData, self::_id( $data ), $changes );

			if( is_string( $saved ) === true ) {
				self::_failed( $request, $saved );
				return;
			}

			\Nino\Http::ok( $request, [ 'account' => $saved ] );
		}

		/**
		 *	Delete an account - that one and no other
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiDelete( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$removed = \Nino\Modules\Members::remove( $appData, self::_id( \Nino\Admin\Admin::postData() ) );

			if( $removed !== 'ok' ) {
				self::_failed( $request, $removed );
				return;
			}

			\Nino\Http::ok( $request );
		}

		/**
		 *	Send an account a link to sign in. An account that is switched off has
		 *	nothing to sign in to: it is a 400 with the code members_inactive, and
		 *	a mail that did not go out is a 500 with members_mail - a 429 with
		 *	members_ratelimit where the kernel's cap of mails refused it
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiLink( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$account = \Nino\Modules\Members::account( $appData, self::_id( \Nino\Admin\Admin::postData() ) );

			if( $account === null ) {
				self::_failed( $request, 'unknown' );
				return;
			}

			if( $account['active'] === false ) {
				\Nino\Http::fail( $request, 400, 'the account is switched off', 'members_inactive' );
				return;
			}

			if( \Nino\Modules\Members::link( $appData, $account, 'login', '', true ) === false ) {
				self::_unsent( $appData, $request, 'the link could not be sent' );
				return;
			}

			\Nino\Http::ok( $request, [ 'sent' => true ] );
		}

		/**
		 *	Sign every member out at once: a new session epoch
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiSignout( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			if( \Nino\Modules\Members::signOutAll( $appData ) === false ) {
				\Nino\Http::fail( $request, 500, 'could not sign everybody out' );
				return;
			}

			\Nino\Http::ok( $request, [ 'signedOut' => true ] );
		}

		/**
		 *	Answer a mail that was not sent. The kernel's cap of mails an hour from one
		 *	address, \Nino\Mail::rateLimited() - is a failure of its own: members_ratelimit,
		 *	a 429, so that whoever is at the screen waits an hour instead of looking for a
		 *	fault in the mail settings. Any other is members_mail, a 500
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *	@param		string		$error					The English sentence for the log of a mail that was attempted
		 *
		 *	@return 	void
		 */
		private static function _unsent( array &$appData, array &$request, string $error ): void {

			if( \Nino\Mail::rateLimited( $appData ) === true ) {
				\Nino\Http::fail( $request, 429, 'the kernel\'s cap of mails an hour from one address is reached', 'members_ratelimit' );
				return;
			}

			\Nino\Http::fail( $request, 500, $error, 'members_mail' );
		}

		/**
		 *	Answer a refusal of the class: the failures a person can cause carry a
		 *	code (and its sentence is in both languages, see text/), the failure of
		 *	a write is the server's and has none
		 *
		 *	@param		array 		&$request			(reference) Current server request
		 *	@param		string		$why					What Members answered: invalid, long, taken, unknown or write
		 *
		 *	@return 	void
		 */
		private static function _failed( array &$request, string $why ): void {

			match( $why ) {
				'invalid'	=> \Nino\Http::fail( $request, 400, 'not an email address', 'members_invalid', [], 'email' ),
				'taken'		=> \Nino\Http::fail( $request, 400, 'the address belongs to a member already', 'members_taken', [], 'email' ),
				'long'		=> \Nino\Http::fail( $request, 400, 'the name is too long', 'members_long', [ 120 ], 'name' ),
				'unknown'	=> \Nino\Http::fail( $request, 404, 'unknown member', 'members_unknown' ),
				default		=> \Nino\Http::fail( $request, 500, 'could not write the members' ),
			};
		}

		/**
		 *	The posted id of an account: 12 hex characters or nothing. Anything else is
		 *	an id nobody has
		 *
		 *	@param		array 		$data					What the action was posted
		 *
		 *	@return 	string
		 */
		private static function _id( array $data ): string {

			$id = \Nino\Admin\Admin::postString( $data, 'id' );

			return preg_match( '/^[0-9a-f]{12}$/D', $id ) === 1 ? $id : '';
		}
	}
}
