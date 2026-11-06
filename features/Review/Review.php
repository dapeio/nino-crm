<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Modules\\Review					see _nino/Nino/Modules/Modules.php for the
 *											package-level docblock
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules {

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Review						A player for the audio and video files that the Files feature holds for the groups of
	 *										the Members feature, with comments at a time in the track. A member opens a page that
	 *										carries [review-player]: without a file chosen it lists the audio and video files the
	 *										member may see (what Files::allowed() lets them), each a link to the same page with
	 *										?review=<id>; with one chosen it plays it - the source is the route of the Files
	 *										feature, which the kernel streams with its ranges, so that the player can seek - and
	 *										shows the comments on it in the order of their time in the track, with the answers
	 *										under them and a form to write one: at the second the player is at, or at a time typed
	 *										as m:ss, or at none.
	 *
	 *										A comment is POST /.review/comment, a form post - a plain one that reloads the page
	 *										without the script, and with it sent by fetch() so that the player plays on, the script
	 *										taking the list and the state from the page the redirect leads to: a refusal or the
	 *										success is answered with a redirect to the page the form was on,
	 *										?review=<id>&review-state=<state>. The owner
	 *										reads, answers, marks as done and deletes in the panel (Review\Admin). The data is
	 *										Review\Store's, /data/review.php.
	 *
	 *										Other features build on it through comments(), counts(), add(), media() and seconds(), and the
	 *										callback '/review/comment', which is fired with the entry of every comment a member
	 *										posts through the route and the owner answers with in the panel.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Review {

		// Where this feature's own templates are, as \Nino\Filesystem resolves them
		public const string TEMPLATES = '/features/Review/templates';

		// The counter of the comments, \Nino\Feature::count() under this name: the file /data/review-tries.php. Not 'review' -
		// that is the file of the comments, and a counter drops whatever in its file is no entry of its own. It is not in the
		// manifest's 'data' either: a window of an hour is nothing a backup carries
		private const string COUNTER = 'review-tries';

		private const int WINDOW = 3600;
		private const int DEFAULT_ATTEMPTS = 60;
		private const int DEFAULT_LENGTH = 2000;
		private const string FEATURE_KEY = 'review';

		// How many forms this request has drawn
		private const string FORMS = './review/forms';

		// What the form may say besides nothing: the query 'review-state' is one of these
		private const array STATES = [ 'sent', 'long', 'limit', 'expired', 'invalid' ];

		/**
		 *	Register the route this feature owns with its handler, the merge of a restore, and tell the Jstext module the
		 *	words the script reads
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	void
		 */
		public static function init( array &$appData ): void {

			$appData['/nino/http/routes']['POST://.review/comment'] = [ 'uri' => '/.review/comment' ];

			\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://.review/comment', [ self::class, 'callbackComment' ] );

			// A restore of the daily backup brings the comments and the index of the files of a day - see callbackRestore()
			\Nino\Callbacks::registerCallback( $appData, '/nino/admin/restore', [ self::class, 'callbackRestore' ] );

			// A file that goes takes its comments with it - the Files feature tells each file it deletes, a folder's among them
			\Nino\Callbacks::registerCallback( $appData, '/files/delete', [ self::class, 'callbackFileDeleted' ] );

			// A published key is public, so the list is the words the script reads and nothing else
			if( class_exists( '\\Nino\\Modules\\Jstext' ) === true )
				\Nino\Modules\Jstext::publish( $appData, [ '/feature/review/script/' ] );
		}

		// The panel comes and goes with the feature
		public static function adminPanels( array &$appData ): array {

			return [ \Nino\Modules\Review\Admin::class ];
		}

		/**
		 *	A time typed by a person - or written by the script - as seconds: m:ss, h:mm:ss or a plain number, with a point or
		 *	a comma before the decimals, round to one decimal. The seconds of a clock are below 60, and a time is at most
		 *	99:59:59 and nine tenths
		 *
		 *	@param		string		$input				eg. '1:05', '0:03.5', '75', '1:02:03'
		 *
		 *	@return 	float|null								null for nothing, and for anything that is no time
		 */
		public static function seconds( string $input ): ?float {

			$text = str_replace( ',', '.', trim( $input ) );

			if( preg_match( '/^(\d+):([0-5]\d):([0-5]\d)(\.\d+)?$/D', $text, $found ) === 1 )
				$value = (int) $found[1] * 3600 + (int) $found[2] * 60 + (int) $found[3] + (float) ( $found[4] ?? 0 );
			else if( preg_match( '/^(\d+):([0-5]?\d)(\.\d+)?$/D', $text, $found ) === 1 )
				$value = (int) $found[1] * 60 + (int) $found[2] + (float) ( $found[3] ?? 0 );
			else if( preg_match( '/^\d+(?:\.\d+)?$/D', $text ) === 1 )
				$value = (float) $text;
			else
				return null;

			return $value <= Review\Store::LAST ? round( $value, 1 ) : null;
		}

		/**
		 *	A time in the form the page shows it: m:ss, the minutes as many as there are and the decimals left out
		 *
		 *	@param		float			$seconds
		 *
		 *	@return 	string										eg. '1:05'
		 */
		public static function clock( float $seconds ): string {

			$whole = (int) floor( max( 0.0, $seconds ) );

			return intdiv( $whole, 60 ). ':'. sprintf( '%02d', $whole % 60 );
		}

		/**
		 *	@param		string		$mime					The type of a file, as the index of the Files feature has it
		 *
		 *	@return 	string										'audio' or 'video'; '' for anything else
		 */
		public static function kind( string $mime ): string {

			return preg_match( '#^(audio|video)/#', $mime, $found ) === 1 ? $found[1] : '';
		}

		/**
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	int												The longest comment, in characters: the setting length
		 */
		public static function length( array &$appData ): int {

			return (int) \Nino\Features::setting( $appData, self::FEATURE_KEY, 'length', self::DEFAULT_LENGTH );
		}

		/**
		 *	The comments on a file in the order of the track: those with a time by that time, then those without one in the
		 *	order they were written, each with its answers under it in the order they were written
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$file					The id of a file
		 *
		 *	@return 	array										A list of comments: id, by, at, text, parent, done, created, page and answers - a list of the same without the answers
		 */
		public static function comments( array &$appData, string $file ): array {

			$top = [];
			$answers = [];

			foreach( Review\Store::of( $appData, $file ) as $id => $comment ) {

				$item = [ 'id' => (string) $id ] + $comment;

				if( $comment['parent'] === '' )
					$top[] = $item + [ 'answers' => [] ];
				else
					$answers[ $comment['parent'] ][] = $item;
			}

			// A stable sort: comments of the same time keep the order they were written in
			usort( $top, static fn( array $a, array $b ): int => [ $a['at'] === null ? 1 : 0, $a['at'] ?? 0.0, $a['created'] ] <=> [ $b['at'] === null ? 1 : 0, $b['at'] ?? 0.0, $b['created'] ] );

			foreach( $top as $position => $item ) {

				$list = $answers[ $item['id'] ] ?? [];
				usort( $list, static fn( array $a, array $b ): int => $a['created'] <=> $b['created'] );
				$top[$position]['answers'] = $list;
			}

			return $top;
		}

		/**
		 *	How many comments files have and how many of them are open - a member's comment the owner has not marked as done -,
		 *	read once for all of them
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$files				The ids of files
		 *
		 *	@return 	array										[ <id> => [ 'total' => ..., 'open' => ... ] ] - zeros for a file without comments, and for an id that is none
		 */
		public static function counts( array &$appData, array $files ): array {

			$all = Review\Store::read( $appData );
			$counts = [];

			foreach( $files as $file )
				if( is_string( $file ) === true )
					$counts[$file] = [ 'total' => count( $all[$file] ?? [] ), 'open' => Review\Store::open( $all[$file] ?? [] ) ];

			return $counts;
		}

		/**
		 *	Write a comment on a file. The file has to be there and to be audio or video, the text is cleaned and has to be
		 *	there and at most the setting length, the time is none or between 0 and 99:59:59, and a parent has to be a comment
		 *	of the same file - an answer to an answer is an answer to the comment that one answers. Whether the author may
		 *	see the file is the caller's to ask (Files::allowed())
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$file					The id of a file
		 *	@param		array 		$by						[ 'kind' => 'member', 'id' => the id of the account ] or [ 'kind' => 'owner', 'id' => '' ]
		 *	@param		float|null	$at						The second in the track; null for none
		 *	@param		string		$text
		 *	@param		string		$parent				The comment this one answers; '' for none
		 *	@param		string		$page					The path of the page that showed the player, for the link of a mail; '' for none
		 *
		 *	@return 	array|string							The comment as comments() has it - without the answers, with the id of the file; or why not: 'file', 'by', 'empty', 'long', 'time', 'parent', 'write'
		 */
		public static function add( array &$appData, string $file, array $by, ?float $at, string $text, string $parent = '', string $page = '' ): array|string {

			$entry = \Nino\Modules\Files::file( $appData, $file );

			if( $entry === null || self::kind( $entry['mime'] ) === '' )
				return 'file';

			$kind = $by['kind'] ?? null;
			$who = is_string( $by['id'] ?? null ) === true ? $by['id'] : '';

			if( ( $kind !== 'owner' && $kind !== 'member' ) || ( $kind === 'member' && preg_match( Review\Store::ID, $who ) !== 1 ) )
				return 'by';

			$text = Review\Store::clean( $text );

			if( $text === '' )
				return 'empty';

			if( mb_strlen( $text, 'UTF-8' ) > self::length( $appData ) )
				return 'long';

			if( $at !== null && ( is_finite( $at ) === false || $at < 0 || $at > Review\Store::LAST ) )
				return 'time';

			if( $parent !== '' && preg_match( Review\Store::ID, $parent ) !== 1 )
				return 'parent';

			$made = Review\Store::insert( $appData, $file, [
				'by'			=> [ 'kind' => $kind, 'id' => $kind === 'owner' ? '' : $who ],
				'at'			=> $at === null ? null : round( $at, 1 ),
				'text'		=> $text,
				'done'		=> false,
				'created'	=> gmdate( Review\Store::TIME ),
				'page'		=> self::_pathOf( $page ),
			], $parent );

			return is_array( $made ) === true ? [ 'id' => $made['id'], 'file' => $file ] + $made : $made;
		}

		/**
		 *	The audio and video files of the member who is signed in: those of the folders of their group that Files lets them
		 *	see (Files::allowed()), in the order the folders and the files are listed in
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array										A list of the files as Files::files() has them, each with kind (audio or video) and folderName - a file that was removed from the server is no medium and is not among them; none for a visitor and for a member without a group
		 */
		public static function media( array &$appData ): array {

			$member = \Nino\Modules\Members::current( $appData );

			if( $member === null || $member['group'] === '' )
				return [];

			$list = [];

			foreach( \Nino\Modules\Files::folders( $appData, $member['group'] ) as $folder )
				foreach( \Nino\Modules\Files::files( $appData, $folder['id'] ) as $file ) {

					$kind = self::kind( $file['mime'] );

					if( $kind !== '' && isset( $file['gone'] ) === false && \Nino\Modules\Files::allowed( $appData, $file ) === true )
						$list[] = $file + [ 'kind' => $kind, 'folderName' => $folder['name'] ];
				}

			return $list;
		}

		/**
		 *	The folders of the groups the members have, by their ids: where a file is, for a panel that lists by group and for
		 *	the mail to a group. A folder of a group that has no member any more is not among them
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array										[ <folder id> => [ 'group' => ..., 'name' => ... ] ]
		 */
		public static function places( array &$appData ): array {

			$places = [];

			foreach( \Nino\Modules\Members::groups( $appData ) as $group )
				foreach( \Nino\Modules\Files::folders( $appData, $group ) as $folder )
					$places[ $folder['id'] ] = [ 'group' => $group, 'name' => $folder['name'] ];

			return $places;
		}

		/**
		 *	POST /.review/comment: a member's comment, the fields file, text, at, parent and return, with [csrf] in the form. A
		 *	post the csrf guard refused - a session that ran out under a form that stayed open - is answered with the redirect and
		 *	the state 'expired' and counts for nothing. The visitor is counted first (the setting attempts, per hour), whoever
		 *	they are: over the cap the state is 'limit'. Then the member has to be signed in and the file one they may see
		 *	(Files::allowed()) - a visitor, a member of another group and an id nobody has are the same 404 with a plain body, so
		 *	that nothing tells that a file exists. A file that is no audio or video or that was removed from the server, a text that is empty, a time that is typed
		 *	and is none and a comment that is not there to answer are 'invalid', a text over the setting length is 'long'. Else
		 *	the comment is stored, the callback '/review/comment' is told, the owner is mailed where the setting says so and the
		 *	answer is the redirect with 'sent'. Every redirect goes to the page the form was on, ?review=<id>&review-state=<state>
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function callbackComment( array &$appData, array &$request ): void {

			$file = self::_posted( 'file' );
			$page = self::_pathOf( self::_posted( 'return' ) );
			$return = $page !== '' ? $page : self::_start( $appData );

			if( \Nino\Form::blocked( $request ) === true ) {
				self::_redirect( $appData, $request, $return, $file, 'expired' );
				return;
			}

			$attempts = (int) \Nino\Features::setting( $appData, self::FEATURE_KEY, 'attempts', self::DEFAULT_ATTEMPTS );

			// Counted before anything is looked at: a visitor who is no member is counted as well
			if( \Nino\Feature::count( $appData, self::COUNTER, 'visitor|'. \Nino\Http::getClientIp( $appData ), $attempts, self::WINDOW ) === false ) {
				self::_redirect( $appData, $request, $return, $file, 'limit' );
				return;
			}

			$member = \Nino\Modules\Members::current( $appData );
			$entry = $member === null ? null : \Nino\Modules\Files::file( $appData, $file );

			if( $member === null || $entry === null || \Nino\Modules\Files::allowed( $appData, $entry ) === false ) {

				$request['/nino/http/response']['statusCode']								= 404;
				$request['/nino/http/response']['body']										= 'Not Found';
				$request['/nino/http/response']['header']['Content-Type']		= 'text/plain; charset=utf-8';
				$request['/nino/http/response']['header']['Cache-Control']	= 'no-store';

				return;
			}

			$typed = trim( self::_posted( 'at' ) );
			$at = $typed === '' ? null : self::seconds( $typed );

			if( self::kind( $entry['mime'] ) === '' || isset( $entry['gone'] ) === true || ( $typed !== '' && $at === null ) ) {
				self::_redirect( $appData, $request, $return, $file, 'invalid' );
				return;
			}

			$comment = self::add( $appData, $file, [ 'kind' => 'member', 'id' => $member['id'] ], $at, self::_posted( 'text' ), self::_posted( 'parent' ), $page );

			if( $comment === 'write' ) {
				\Nino\Http::fail( $request, 500, 'could not store the comment' );
				return;
			}

			if( is_string( $comment ) === true ) {
				self::_redirect( $appData, $request, $return, $file, $comment === 'long' ? 'long' : 'invalid' );
				return;
			}

			\Nino\Callbacks::doCallbacks( $appData, '/review/comment', $comment );
			self::notifyOwner( $appData, $entry, $comment, $member );

			self::_redirect( $appData, $request, $return, $file, 'sent' );
		}

		/**
		 *	[review-player] - for a member who is signed in. Without ?review= in the query: the list of the audio and video files
		 *	they may see (the attribute kind narrows it to one of the two), each a link to this page with ?review=<id>. With it:
		 *	the player of that file - the audio or video element with the address of the Files feature as its source - the comments
		 *	on it as comments() orders them, each timed one with its time as a link and its second in data-review-at, and the
		 *	form that posts to /.review/comment. A file that was removed from the server (Files) has its comments and no
		 *	medium and no form: the page that stands the player says why. A file the member may not see, one that is no audio or
		 *	video, one whose bytes are gone and an id that is none are the list with the state 'invalid'. The query 'review-state' is said above the form (or
		 *	the list) when it is one of STATES. Nothing for a visitor
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array			$args					The resolved arguments (see \Nino\Modules\Components::dispatch()); a caller in php may add 'versions', the markup of its own that stands between the title and the medium of the player, 'file', the id of the file to play where the request does not carry it as ?review=, and 'path', the page the form returns to where that is not the path of the request - a shortcode has no such argument, only the attributes of the schema reach this function
		 *
		 *	@return 	string
		 */
		public static function componentReviewPlayer( array &$appData, array $args ): string {

			if( \Nino\Modules\Members::current( $appData ) === null )
				return '';

			$request = \Nino\Http::getRequest( $appData );
			$query = $request === false || is_array( $request['/nino/http/request']['query'] ?? null ) === false ? [] : $request['/nino/http/request']['query'];
			$path = is_string( $args['path'] ?? null ) === true ? $args['path'] : ( $request === false ? '' : (string) ( $request['/nino/http/request']['uri'] ?? '' ) );
			$asked = is_string( $args['file'] ?? null ) === true ? $args['file'] : ( is_string( $query['review'] ?? null ) === true ? $query['review'] : '' );
			$said = is_string( $query['review-state'] ?? null ) === true && in_array( $query['review-state'], self::STATES, true ) === true ? $query['review-state'] : '';

			if( $asked !== '' ) {

				$file = \Nino\Modules\Files::file( $appData, $asked );

				if( $file !== null && self::kind( $file['mime'] ) !== '' && \Nino\Modules\Files::allowed( $appData, $file ) === true && ( isset( $file['gone'] ) === true || \Nino\Modules\Files::path( $appData, $asked ) !== null ) )
					return self::_player( $appData, $args, $file, $path, $said );

				$said = 'invalid';
			}

			return self::_list( $appData, $args, $path, $said );
		}

		/**
		 *	Tell the owner of a member's comment, where the setting notifyOwner is on: one mail to the contact address of the
		 *	website with the name of the file, the time, the text, who wrote it and a link to the panel. A site without an
		 *	address, or without a contact address that is one, sends none and says so in a warning that names neither; a mail
		 *	that is not delivered, or that the kernel's cap of mails an hour refuses, is a warning too, naming the comment and
		 *	the file by their ids and never an address
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$file					The entry of the file, as Files::file() has it
		 *	@param		array 		$comment			The comment, as add() answers it
		 *	@param		array 		$member				The account that wrote it
		 *
		 *	@return 	bool										Whether a mail was delivered
		 */
		public static function notifyOwner( array &$appData, array $file, array $comment, array $member ): bool {

			if( \Nino\Features::setting( $appData, self::FEATURE_KEY, 'notifyOwner', false ) !== true )
				return false;

			$to = trim( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/email' ) );
			$link = \Nino\Html::absoluteUrl( $appData, '/_admin#review' );

			if( filter_var( $to, FILTER_VALIDATE_EMAIL ) === false || $link === '' ) {
				trigger_error( 'Review: no mail was sent to the owner, the site has no contact address or no address of its own', E_USER_WARNING );
				return false;
			}

			$mail = self::_render( $appData, 'mail-comment', 'comment', function( array $words ) use ( &$appData, $file, $comment, $member, $link ): array {

				return [
					'[[file]]'	=> \Nino\Modules\Components::escape( $file['name'] ),
					'[[time]]'	=> \Nino\Modules\Components::escape( $comment['at'] === null ? $words['none'] : self::clock( $comment['at'] ) ),
					'[[who]]'		=> \Nino\Modules\Components::escape( $member['name'] !== '' ? $member['name'] : $member['email'] ),
					'[[link]]'	=> \Nino\Modules\Components::escape( $link ),
					'[[body]]'	=> self::_lines( $appData, $comment['text'] ),
				];
			} );

			if( \Nino\Mail::send( $appData, $to, $mail['subject'], $mail['body'], '' ) === true )
				return true;

			trigger_error( 'Review: the mail to the owner about the comment '. $comment['id']. ' on the file '. $file['id']. ( \Nino\Mail::rateLimited( $appData ) === true ? ' was refused by the cap of mails an hour' : ' was not delivered' ), E_USER_WARNING );

			return false;
		}

		/**
		 *	Tell members of a comment of the owner's, where the setting notifyMembers is on or the owner chose so for this comment. An answer goes to the member whose
		 *	comment it answers - if that is a member, and their account is there and active; a comment of the owner's on the
		 *	file goes to every active member of the group of the folder the file is in, if the file is visible to them. The
		 *	link leads to the page that showed the player when the comment answered was written, with ?review=<id>, else to
		 *	the start page of the Members feature. The mails go out in one call of the kernel's mail cap, which counts a call
		 *	and not a mail. Mails that are not all delivered, or that the cap refuses, are a warning that names the comment and
		 *	the file by their ids and never an address
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$file					The entry of the file, as Files::file() has it
		 *	@param		array 		$comment			The comment of the owner, as add() answers it
		 *	@param		array|null	$answered			The comment it answers, as comments() has it; null for a comment of its own
		 *	@param		bool|null	$notify				The owner's choice for this comment: true mails whatever the setting says, false mails nobody, null (not asked) is the setting
		 *
		 *	@return 	bool										Whether every mail was delivered - true where there was none to send
		 */
		public static function notifyMembers( array &$appData, array $file, array $comment, ?array $answered, ?bool $notify = null ): bool {

			if( ( $notify ?? \Nino\Features::setting( $appData, self::FEATURE_KEY, 'notifyMembers', false ) ) !== true )
				return true;

			$recipients = [];

			if( $answered !== null ) {

				$account = $answered['by']['kind'] === 'member' ? \Nino\Modules\Members::account( $appData, $answered['by']['id'] ) : null;
				$recipients = $account !== null && $account['active'] === true ? [ $account ] : [];
			}
			else if( $file['visible'] === true ) {

				$group = self::places( $appData )[ $file['folder'] ]['group'] ?? '';
				$recipients = $group === '' ? [] : array_values( array_filter( \Nino\Modules\Members::accounts( $appData ), static fn( array $account ): bool => $account['group'] === $group && $account['active'] === true ) );
			}

			if( $recipients === [] )
				return true;

			$page = $answered !== null ? $answered['page'] : '';
			$link = \Nino\Html::absoluteUrl( $appData, $page !== '' ? $page. '?review='. $file['id'] : self::_start( $appData ) );

			if( $link === '' ) {
				trigger_error( 'Review: no mail was sent to the members, the site has no address of its own', E_USER_WARNING );
				return false;
			}

			$replyTo = trim( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/email' ) );
			$replyTo = filter_var( $replyTo, FILTER_VALIDATE_EMAIL ) === false ? '' : $replyTo;
			$mails = [];

			foreach( $recipients as $account ) {

				$mail = self::_render( $appData, 'mail-answer', $answered !== null ? 'answer' : 'note', function( array $words ) use ( &$appData, $file, $comment, $link, $account, $answered ): array {

					return [
						'[[name]]'	=> \Nino\Modules\Components::escape( $account['name'] !== '' ? $account['name'] : $account['email'] ),
						'[[intro]]'	=> \Nino\Modules\Components::escape( $words[ $answered !== null ? 'answer-intro' : 'note-intro' ] ),
						'[[file]]'	=> \Nino\Modules\Components::escape( $file['name'] ),
						'[[time]]'	=> \Nino\Modules\Components::escape( $comment['at'] === null ? $words['none'] : self::clock( $comment['at'] ) ),
						'[[link]]'	=> \Nino\Modules\Components::escape( $link ),
						'[[body]]'	=> self::_lines( $appData, $comment['text'] ),
					];
				} );

				$mails[] = [ 'to' => $account['email'], 'subject' => $mail['subject'], 'body' => $mail['body'], 'replyTo' => $replyTo ];
			}

			if( \Nino\Mail::sendAll( $appData, $mails ) === true )
				return true;

			trigger_error( 'Review: the mails about the comment '. $comment['id']. ' on the file '. $file['id']. ( \Nino\Mail::rateLimited( $appData ) === true ? ' were refused by the cap of mails an hour' : ' were not all delivered' ), E_USER_WARNING );

			return false;
		}

		/**
		 *	Rewrite the staged comments of a backup before /_admin's Restore copies them over the live ones: a comment on a file
		 *	that Files no longer has is dropped. Files has the file where the index of the backup names it and its bytes are in
		 *	the live directory - the rule of the restore of the Files feature itself - or, where the backup carries no index of
		 *	the files, where the live Files has it. A no-op if the backup carries no comments. Reached through the
		 *	'/nino/admin/restore' callback init() registers, with the live /data directory and the extracted backup as arguments.
		 *	The write is not silenced: one that fails is php's own warning, and the staged file stays as it was
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$args				(reference) { dataDir: live /data (read only), staging: extracted backup }
		 *
		 *	@return 	void
		 */
		public static function callbackRestore( array &$appData, array &$args ): void {

			$dataDir = (string) ( $args['dataDir'] ?? '' );
			$staging = (string) ( $args['staging'] ?? '' );
			$staged = $staging. '/data/review.php';

			if( $dataDir === '' || $staging === '' || is_file( $staged ) === false )
				return;

			$comments = Review\Store::parse( include $staged );
			$index = is_file( $staging. '/data/files.php' ) === true ? include $staging. '/data/files.php' : null;
			$live = is_file( $dataDir. '/files.php' ) === true ? include $dataDir. '/files.php' : null;
			$kept = $comments;

			foreach( array_keys( $comments ) as $file ) {

				$file = (string) $file;

				// A file that was removed from the server has no bytes and keeps its comments: its entry says so, in the backup or in the live index
				$removed = static fn( mixed $source ): bool => is_array( $source ) === true && is_string( $source['files'][$file]['gone'] ?? null ) === true && $source['files'][$file]['gone'] !== '';

				if( is_array( $index ) === true )
					$there = is_array( $index['files'][$file] ?? null ) === true && ( $removed( $index ) === true || $removed( $live ) === true || ( is_string( $index['files'][$file]['ext'] ?? null ) === true
						&& preg_match( '/^[a-z0-9]{1,8}$/D', $index['files'][$file]['ext'] ) === 1 && is_file( $dataDir. '/files/'. $file. '.'. $index['files'][$file]['ext'] ) === true ) );
				else
					$there = isset( ( \Nino\Modules\Files::file( $appData, $file ) ?? [] )['gone'] ) === true || \Nino\Modules\Files::path( $appData, $file ) !== null;

				if( $there === false )
					unset( $kept[$file] );
			}

			if( $kept !== $comments )
				file_put_contents( $staged, '<?php return '. var_export( $kept, true ). ';' );
		}

		/**
		 *	The callback '/files/delete' of the Files feature, fired with the entry of a file that was deleted - in the panel, or with
		 *	its folder: the comments on the file are dropped with it, since nothing can show them any more. A failed write is a warning
		 *	that names the file by its id; the file is deleted all the same, and the panel does not list the comments of a file Files
		 *	does not have
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$entry				(reference) The entry of the file as it was
		 *
		 *	@return 	void
		 */
		public static function callbackFileDeleted( array &$appData, array &$entry ): void {

			$file = (string) ( $entry['id'] ?? '' );

			if( preg_match( Review\Store::ID, $file ) === 1 && Review\Store::purge( $appData, $file ) === false )
				trigger_error( 'Review: the comments on the deleted file '. $file. ' could not be dropped', E_USER_WARNING );
		}

		/**
		 *	The list of the files a member may see: audio and video, as the attribute kind says
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$args
		 *	@param		string		$path					The page this stands on
		 *	@param		string		$state				One of STATES, '' for none
		 *
		 *	@return 	string
		 */
		private static function _list( array &$appData, array $args, string $path, string $state ): string {

			$kind = in_array( $args['kind'] ?? '', [ 'audio', 'video' ], true ) === true ? $args['kind'] : 'all';
			$items = '';

			foreach( self::media( $appData ) as $file ) {

				if( $kind !== 'all' && $file['kind'] !== $kind )
					continue;

				$items .= str_replace(
					[ '[[id]]', '[[kind]]', '[[name]]', '[[folder]]', '[[href]]' ],
					[
						$file['id'],
						$file['kind'],
						\Nino\Modules\Components::escape( $file['name'] ),
						\Nino\Modules\Components::escape( $file['folderName'] ),
						\Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ). $path. '?review='. $file['id'] ),
					],
					\Nino\Feature::template( $appData, self::TEMPLATES, 'review-item' )
				);
			}

			return str_replace(
				[ '[[classes]]', '[[state]]', '[[items]]' ],
				[ \Nino\Feature::classes( $args ), self::_state( $appData, $state ), $items !== '' ? $items : \Nino\Feature::template( $appData, self::TEMPLATES, 'review-empty' ) ],
				\Nino\Feature::template( $appData, self::TEMPLATES, 'review-list' )
			);
		}

		/**
		 *	The player of one file with its comments and the form - a file that was removed from the server has its comments, no medium and no form
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$args
		 *	@param		array 		$file					The entry of the file, which the member may see and which is audio or video
		 *	@param		string		$path					The page this stands on
		 *	@param		string		$state				One of STATES, '' for none
		 *
		 *	@return 	string
		 */
		private static function _player( array &$appData, array $args, array $file, string $path, string $state ): string {

			$kind = self::kind( $file['mime'] );
			$removed = isset( $file['gone'] ) === true;
			$source = $removed === true ? '' : \Nino\Modules\Components::escape( \Nino\Modules\Files::url( $appData, $file['id'] ) );
			$comments = self::comments( $appData, $file['id'] );
			$items = '';
			$count = 0;

			foreach( $comments as $comment ) {

				$answers = '';

				foreach( $comment['answers'] as $answer ) {
					$answers .= self::_item( $appData, 'review-answer', $answer, $source, '' );
					$count++;
				}

				$items .= self::_item( $appData, 'review-comment', $comment, $source, $answers === '' ? '' : str_replace( '[[items]]', $answers, \Nino\Feature::template( $appData, self::TEMPLATES, 'review-answers' ) ) );
				$count++;
			}

			// Every value escaped, and the markup that was built last: a token after it would be looked for in it as well; a file that was removed takes no comment
			$form = $removed === true ? '' : str_replace(
				[ '[[id]]', '[[file]]', '[[return]]', '[[length]]', '[[state]]' ],
				[
					'review-form-'. ( $appData[self::FORMS] = (int) ( $appData[self::FORMS] ?? 0 ) + 1 ),
					$file['id'],
					\Nino\Modules\Components::escape( $path ),
					(string) self::length( $appData ),
					self::_state( $appData, $state ),
				],
				\Nino\Feature::template( $appData, self::TEMPLATES, 'review-form' )
			);

			return str_replace(
				[ '[[classes]]', '[[file]]', '[[kind]]', '[[back]]', '[[name]]', '[[count]]', '[[media]]', '[[comments]]', '[[form]]', '[[versions]]' ],
				[
					\Nino\Feature::classes( $args ). ( $removed === true ? ' review-player--removed' : '' ),
					$file['id'],
					$kind,
					\Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ). $path ),
					\Nino\Modules\Components::escape( $file['name'] ),
					(string) $count,
					$removed === true ? '' : str_replace( [ '[[src]]', '[[name]]' ], [ $source, \Nino\Modules\Components::escape( $file['name'] ) ], \Nino\Feature::template( $appData, self::TEMPLATES, 'review-'. $kind ) ),
					$items !== '' ? str_replace( '[[items]]', $items, \Nino\Feature::template( $appData, self::TEMPLATES, 'review-comments' ) ) : \Nino\Feature::template( $appData, self::TEMPLATES, 'review-none' ),
					$form,
					is_string( $args['versions'] ?? null ) === true ? $args['versions'] : '',
				],
				\Nino\Feature::template( $appData, self::TEMPLATES, 'review-player' )
			);
		}

		/**
		 *	One comment or answer of the page: who wrote it, when, the text with its line breaks, the time as a link where there
		 *	is one - the second in the attribute data-review-at of the item, which the script seeks to - and the mark that the
		 *	owner has dealt with it
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$template			review-comment or review-answer
		 *	@param		array 		$comment			As comments() has it
		 *	@param		string		$source				The address of the file, escaped: a timed comment links it at its second - '' for a file that has no medium, whose time is no link and which has no button to answer with
		 *	@param		string		$answers			The markup of the answers under it
		 *
		 *	@return 	string
		 */
		private static function _item( array &$appData, string $template, array $comment, string $source, string $answers ): string {

			$at = $comment['at'] === null ? '' : (string) $comment['at'];

			return str_replace(
				[ '[[modifier]]', '[[id]]', '[[who]]', '[[created]]', '[[date]]', '[[text]]', '[[at]]', '[[time]]', '[[mark]]', '[[reply]]', '[[answers]]' ],
				[
					$comment['done'] === true ? ' '. $template. '--done' : '',
					$comment['id'],
					\Nino\Modules\Components::escape( self::_name( $appData, $comment['by'] ) ),
					\Nino\Modules\Components::escape( $comment['created'] ),
					\Nino\Modules\Components::escape( substr( $comment['created'], 0, 10 ) ),
					self::_lines( $appData, $comment['text'] ),
					$at === '' ? '' : str_replace( '[[at]]', $at, \Nino\Feature::template( $appData, self::TEMPLATES, 'review-at' ) ),
					$at === '' ? '' : str_replace( [ '[[src]]', '[[at]]', '[[clock]]' ], [ $source, $at, self::clock( (float) $comment['at'] ) ], \Nino\Feature::template( $appData, self::TEMPLATES, $source === '' ? 'review-clock' : 'review-time' ) ),
					$comment['done'] === true ? \Nino\Feature::template( $appData, self::TEMPLATES, 'review-done' ) : '',
					$source === '' ? '' : \Nino\Feature::template( $appData, self::TEMPLATES, 'review-reply' ),
					$answers,
				],
				\Nino\Feature::template( $appData, self::TEMPLATES, $template )
			);
		}

		/**
		 *	What names the author of a comment on the page: the owner, the name of a member - never the address, which another
		 *	member is not to learn from a comment -, and a word for a member without a name and for one whose account is gone
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$by
		 *
		 *	@return 	string
		 */
		private static function _name( array &$appData, array $by ): string {

			if( $by['kind'] === 'owner' )
				return (string) \Nino\Html::resolveTextfill( $appData, '/feature/review/comment/owner' );

			$account = \Nino\Modules\Members::account( $appData, $by['id'] );

			if( $account !== null && $account['name'] !== '' )
				return $account['name'];

			return (string) \Nino\Html::resolveTextfill( $appData, $account === null ? '/feature/review/comment/gone' : '/feature/review/comment/member' );
		}

		/**
		 *	A text with its line breaks: every line escaped, the breaks between them the template's - php writes no tag
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$text
		 *
		 *	@return 	string
		 */
		private static function _lines( array &$appData, string $text ): string {

			return implode(
				\Nino\Feature::template( $appData, self::TEMPLATES, 'review-break' ),
				array_map( static fn( string $line ): string => \Nino\Modules\Components::escape( $line ), explode( "\n", $text ) )
			);
		}

		/**
		 *	The line that says a state, where there is one to say
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$state				One of STATES, '' for none
		 *
		 *	@return 	string										The word is the template's, left for the kernel to resolve
		 */
		private static function _state( array &$appData, string $state ): string {

			return $state === '' ? '' : str_replace( '[[name]]', $state, \Nino\Feature::template( $appData, self::TEMPLATES, 'review-state' ) );
		}

		/**
		 *	A mail rendered in the site's native language, whoever asked: the template and the subject are rendered first -
		 *	their words are the keys of the unit - and the values are put in afterwards, in the result, so that nothing in them
		 *	is ever read as a fill or a shortcode (the order \Nino\Form::render() keeps)
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$template			mail-comment or mail-answer
		 *	@param		string		$kind					comment, answer or note: the subject is /feature/review/mail/<kind>-subject
		 *	@param		callable	$values				( array $words ): array - token => value, the values escaped, the markup last; $words are the few words the values are made of
		 *
		 *	@return 	array										[ 'subject' => ..., 'body' => ... ]
		 */
		private static function _render( array &$appData, string $template, string $kind, callable $values ): array {

			$locale = \Nino\Locales::getCurrentLocale( $appData );

			\Nino\Locales::useLocale( $appData, \Nino\Locales::getNativeLocale( $appData ) );

			try {
				$body = \Nino\Html::renderHtml( $appData, \Nino\Feature::template( $appData, self::TEMPLATES, $template ) );
				$subject = \Nino\Html::renderHtml( $appData, '[[/feature/review/mail/'. $kind. '-subject]]' );
				$filled = $values( [
					'none'					=> (string) \Nino\Html::resolveTextfill( $appData, '/feature/review/mail/no-time' ),
					'answer-intro'	=> (string) \Nino\Html::resolveTextfill( $appData, '/feature/review/mail/answer-intro' ),
					'note-intro'		=> (string) \Nino\Html::resolveTextfill( $appData, '/feature/review/mail/note-intro' ),
				] );
			}
			finally {
				\Nino\Locales::useLocale( $appData, $locale );
			}

			return [ 'subject' => $subject, 'body' => str_replace( array_keys( $filled ), array_values( $filled ), $body ) ];
		}

		/**
		 *	Answer a post with a redirect to the page it was on, carrying the file and the state the page will say
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *	@param		string		$path					A path of this site
		 *	@param		string		$file					The posted id of the file: it is in the query only where it is the shape of one
		 *	@param		string		$state				One of STATES
		 *
		 *	@return 	void
		 */
		private static function _redirect( array &$appData, array &$request, string $path, string $file, string $state ): void {

			\Nino\Http::redirect( $appData, $request, $path. '?'. ( preg_match( Review\Store::ID, $file ) === 1 ? 'review='. $file. '&' : '' ). 'review-state='. $state, 303 );
		}

		/**
		 *	One posted value, as a string - nothing says a post carries strings, and 'file[]=x' would be an "Array to string
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
		 *	A path of this site as a posted return address gives it: without its query and fragment, and only where it is safe to
		 *	redirect to and is no endpoint of a module (Members keeps the same rules for its own return address)
		 *
		 *	@param		string		$posted
		 *
		 *	@return 	string										'' for anything else
		 */
		private static function _pathOf( string $posted ): string {

			$path = substr( $posted, 0, strcspn( $posted, '?#' ) );

			return str_starts_with( $path, '/' ) === true && str_starts_with( $path, '//' ) === false && str_starts_with( $path, '/\\' ) === false && str_starts_with( $path, '/.' ) === false
				&& preg_match( '/[\s\x00-\x1f\x7f]/', $path ) !== 1 && preg_match( '#^/[^/]*:#', $path ) !== 1 ? $path : '';
		}

		/**
		 *	The start page of the Members feature, where it is a path of this site, else the front page: where a post goes back
		 *	to that named no page of its own
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	string
		 */
		private static function _start( array &$appData ): string {

			$start = self::_pathOf( (string) \Nino\Features::setting( $appData, 'members', 'start', '/' ) );

			return $start === '' ? '/' : $start;
		}
	}
}
