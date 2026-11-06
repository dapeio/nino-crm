<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Nino\Modules\Review\Admin		The /_admin panel of the Review feature - see docs/features.md, "The panel"
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules\Review {

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Review\Admin			"Review" panel: the owner's side of the feature on one screen. The list of the files
	 *										that have comments, by group and folder, with the number of comments and of the open
	 *										ones - a member's comment the owner has not marked as done; the comments on one file,
	 *										in the order of the track, with a player that plays the file through the route of the
	 *										Files feature (GET /.files/<id>, which answers the workbench session of the owner by the
	 *										permission of the Files panel) and a time of each comment that takes it there; and what the
	 *										owner does with a comment: answer (a comment of their own, or an answer to one, with a
	 *										time or without), mark as done or open again, and delete it with its answers.
	 *
	 *										The panel validates what it is posted and answers with the codes of the failures a
	 *										person can cause; the rules of a comment and its writes are Review\Store's and the
	 *										API of Review, and the mail to a member is the class's. A comment on a file that
	 *										Files no longer has is not listed.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Admin {

		public const string MANAGE_PERM = '/_admin/review/manage';

		// What an action made or changed, by action: written by the action, read by log() in the same request
		private static array $_made = [];

		public static function perm(): string {
			return self::MANAGE_PERM;
		}

		public static function actions(): array {
			return [
				'review/list'			=> [ self::class, 'apiList' ],
				'review/comments'	=> [ self::class, 'apiComments' ],
				'review/answer'		=> [ self::class, 'apiAnswer' ],
				'review/done'			=> [ self::class, 'apiDone' ],
				'review/delete'		=> [ self::class, 'apiDelete' ],
			];
		}

		// No group is named here: a panel a feature brought always lands in the rail's own "features" group
		public static function nav(): array {
			return [ 'review', '/_admin/review/nav', 56 ];
		}

		public static function icon(): string {
			return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 5a2 2 0 0 1 3.008-1.728l11.997 6.998a2 2 0 0 1 .003 3.458l-12 7A2 2 0 0 1 5 19z"/></svg>';
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
		 *	The Dashboard's tile: how many comments of members are open
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array
		 */
		public static function summary( array &$appData ): array {

			return [ 'value' => array_sum( array_column( self::_rows( $appData ), 'open' ) ), 'label' => '/_admin/review/label/open' ];
		}

		/**
		 *	The activity-log line of a completed action: the id of the file and the id of the comment where there is one, never
		 *	the address or the name of a member and never a text
		 *
		 *	@param		string		$action
		 *	@param		array 		$data					What the action was posted
		 *
		 *	@return 	string
		 */
		public static function log( string $action, array $data ): string {

			$file = self::_id( $data, 'file' );
			$comment = self::_id( $data, 'id' );
			$on = $file === '' ? '' : ' of file '. $file;

			return match( $action ) {
				'review/answer'	=> ( self::$_made[$action] ?? '' ) === '' ? '' : 'Wrote comment '. self::$_made[$action]. ( ( $data['notify'] ?? null ) === false ? ', member not mailed' : '' ),
				'review/done'		=> 'Marked comment '. $comment. $on. ( self::$_made[$action] ?? '' ),
				'review/delete'	=> 'Deleted comment '. $comment. $on,
				default					=> '',
			};
		}

		/**
		 *	What the screen draws first: the files that have comments - and that Files still has, as audio or video - each with
		 *	its folder and group, its number of comments and its number of open ones. A file whose folder is not among those of the
		 *	groups the members have is listed with no group
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiList( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			\Nino\Http::ok( $request, [ 'files' => self::_rows( $appData ) ] );
		}

		/**
		 *	The comments on one file, in the order of the track with their answers, each with the name of its author - the name
		 *	of the account, else its address, which the owner knows; '' for the owner and for an account that is gone - and the
		 *	address the file is played at (Files::url()); the longest comment, for the form; and whether the account may play
		 *	files at all - the route of the Files feature answers a workbench account by the permission of the Files panel, which
		 *	the permission of this one does not include, so the screen draws no player for an account that lacks it. A file
		 *	nobody has, one that is no audio or video and an id that is none are refused
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiComments( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$entry = self::_media( $appData, self::_id( \Nino\Admin\Admin::postData(), 'file' ) );

			if( $entry === null ) {
				self::_failed( $appData, $request, 'file' );
				return;
			}

			$labels = [];

			$label = static function( array $by ) use ( &$appData, &$labels ): string {

				if( $by['kind'] === 'owner' )
					return '';

				if( isset( $labels[ $by['id'] ] ) === false ) {
					$account = \Nino\Modules\Members::account( $appData, $by['id'] );
					$labels[ $by['id'] ] = $account === null ? '' : ( $account['name'] !== '' ? $account['name'] : $account['email'] );
				}

				return $labels[ $by['id'] ];
			};

			$comments = array_map( static function( array $comment ) use ( $label ): array {

				$comment['by']['label'] = $label( $comment['by'] );
				$comment['answers'] = array_map( static function( array $answer ) use ( $label ): array {
					$answer['by']['label'] = $label( $answer['by'] );
					return $answer;
				}, $comment['answers'] );

				return $comment;
			}, \Nino\Modules\Review::comments( $appData, $entry['id'] ) );

			\Nino\Http::ok( $request, [
				'file'			=> [
					'id'				=> $entry['id'],
					'name'			=> $entry['name'],
					'kind'			=> \Nino\Modules\Review::kind( $entry['mime'] ),
					'url'				=> \Nino\Modules\Files::url( $appData, $entry['id'] ),
					'playable'	=> \Nino\Modules\Files::path( $appData, $entry['id'] ) !== null,
				],
				'comments'	=> $comments,
				'length'		=> \Nino\Modules\Review::length( $appData ),
				'notify'		=> \Nino\Features::setting( $appData, 'review', 'notifyMembers', false ) === true,
				'files'			=> \Nino\Auth::checkPermission( $appData, \Nino\Modules\Files\Admin::MANAGE_PERM ),
			] );
		}

		/**
		 *	The owner's comment on a file, or the owner's answer to a comment (the posted parent), with a time or without one
		 *	(at: empty, seconds, m:ss or h:mm:ss). The rules of the API hold: the text is at most the setting length and not empty,
		 *	the parent is a comment of the file; notify, a bool, says whether the member is mailed (absent: the setting decides). The callback '/review/comment' is told of it and, where the setting notifyMembers is
		 *	on - or notify says true; false mails nobody - the member who is answered - or, for a comment of its own on a file that is visible, the group - is mailed
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiAnswer( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			self::$_made['review/answer'] = '';

			$data = \Nino\Admin\Admin::postData();
			$entry = self::_media( $appData, self::_id( $data, 'file' ) );

			if( $entry === null ) {
				self::_failed( $appData, $request, 'file' );
				return;
			}

			$typed = trim( \Nino\Admin\Admin::postString( $data, 'at' ) );
			$at = $typed === '' ? null : \Nino\Modules\Review::seconds( $typed );

			if( $typed !== '' && $at === null ) {
				self::_failed( $appData, $request, 'time' );
				return;
			}

			$parent = \Nino\Admin\Admin::postString( $data, 'parent' );
			$record = $parent === '' ? null : ( Store::of( $appData, $entry['id'] )[$parent] ?? null );
			$answered = $record === null ? null : [ 'id' => $parent ] + $record;
			$made = \Nino\Modules\Review::add( $appData, $entry['id'], [ 'kind' => 'owner', 'id' => '' ], $at, \Nino\Admin\Admin::postString( $data, 'text' ), $parent );

			if( is_string( $made ) === true ) {
				self::_failed( $appData, $request, $made );
				return;
			}

			self::$_made['review/answer'] = $made['id']. ' of file '. $entry['id'];

			\Nino\Callbacks::doCallbacks( $appData, '/review/comment', $made );
			\Nino\Modules\Review::notifyMembers( $appData, $entry, $made, $answered, is_bool( $data['notify'] ?? null ) === true ? $data['notify'] : null );

			\Nino\Http::ok( $request, [ 'comment' => $made ] );
		}

		/**
		 *	Turn done around: a comment the owner has dealt with is open again, an open one is done
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiDone( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$data = \Nino\Admin\Admin::postData();
			$comment = Store::toggle( $appData, self::_id( $data, 'file' ), self::_id( $data, 'id' ) );

			if( is_string( $comment ) === true ) {
				self::_failed( $appData, $request, $comment );
				return;
			}

			self::$_made['review/done'] = $comment['done'] === true ? ' done' : ' open';

			\Nino\Http::ok( $request, [ 'comment' => [ 'id' => $comment['id'], 'done' => $comment['done'] ] ] );
		}

		/**
		 *	Delete a comment and the answers to it
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiDelete( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$data = \Nino\Admin\Admin::postData();
			$gone = Store::remove( $appData, self::_id( $data, 'file' ), self::_id( $data, 'id' ) );

			if( is_string( $gone ) === true ) {
				self::_failed( $appData, $request, $gone );
				return;
			}

			\Nino\Http::ok( $request, [ 'deleted' => count( $gone ) ] );
		}

		/**
		 *	The files the screen lists: those that have comments and that Files still has as audio or video, by group, folder and
		 *	name
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array										A list of: id, name, kind, folder, folderName, group, count and open
		 */
		private static function _rows( array &$appData ): array {

			$places = \Nino\Modules\Review::places( $appData );
			$rows = [];

			foreach( Store::read( $appData ) as $file => $comments ) {

				$entry = \Nino\Modules\Files::file( $appData, (string) $file );

				if( $entry === null || \Nino\Modules\Review::kind( $entry['mime'] ) === '' )
					continue;

				$rows[] = [
					'id'				=> (string) $file,
					'name'			=> $entry['name'],
					'kind'			=> \Nino\Modules\Review::kind( $entry['mime'] ),
					'folder'		=> $entry['folder'],
					'folderName'	=> $places[ $entry['folder'] ]['name'] ?? '',
					'group'			=> $places[ $entry['folder'] ]['group'] ?? '',
					'count'			=> count( $comments ),
					'open'			=> Store::open( $comments ),
				];
			}

			usort( $rows, static fn( array $a, array $b ): int => [ $a['group'] === '' ? 1 : 0, $a['group'], mb_strtolower( $a['folderName'] ), mb_strtolower( $a['name'] ), $a['id'] ]
				<=> [ $b['group'] === '' ? 1 : 0, $b['group'], mb_strtolower( $b['folderName'] ), mb_strtolower( $b['name'] ), $b['id'] ] );

			return $rows;
		}

		/**
		 *	The entry of a file the panel can show comments of: there in Files and audio or video
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *
		 *	@return 	array|null								null for an id that is none, for a file nobody has and for one that is no audio or video
		 */
		private static function _media( array &$appData, string $id ): ?array {

			$entry = $id === '' ? null : \Nino\Modules\Files::file( $appData, $id );

			return $entry !== null && \Nino\Modules\Review::kind( $entry['mime'] ) !== '' ? $entry : null;
		}

		/**
		 *	Answer a refusal: the failures a person can cause carry a code (and its sentence is in both languages, see text/), the
		 *	failure of a write is the server's and has none
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *	@param		string		$why					What the API or the store answered: file, empty, long, time, parent, unknown, by or write
		 *
		 *	@return 	void
		 */
		private static function _failed( array &$appData, array &$request, string $why ): void {

			match( $why ) {
				'empty'		=> \Nino\Http::fail( $request, 400, 'the comment is empty', 'review_empty', [], 'text' ),
				'long'		=> \Nino\Http::fail( $request, 400, 'the comment is too long', 'review_long', [ \Nino\Modules\Review::length( $appData ) ], 'text' ),
				'time'		=> \Nino\Http::fail( $request, 400, 'the time is none', 'review_time', [], 'at' ),
				'file',
				'parent',
				'unknown'	=> \Nino\Http::fail( $request, 404, 'unknown file or comment', 'review_unknown' ),
				default		=> \Nino\Http::fail( $request, 500, 'could not write the comments' ),
			};
		}

		/**
		 *	A posted id of a file or a comment: 12 hex characters or nothing. Anything else is an id nobody has
		 *
		 *	@param		array 		$data					What the action was posted
		 *	@param		string		$name					The field
		 *
		 *	@return 	string
		 */
		private static function _id( array $data, string $name ): string {

			$id = \Nino\Admin\Admin::postString( $data, $name );

			return preg_match( Store::ID, $id ) === 1 ? $id : '';
		}
	}
}
