<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Approval		The customer accepts a project in the portal. Under the list of the files of a project - the tab Dateien of its page - stands the block
 *												Abnahme while the project can be approved (it is open, shows at least one file to the member and no offer of it waits for the order): the sentence
 *												"Ich habe die Dateien geprüft und nehme das Projekt <title> in diesem Stand ab." as the label of a box that must be ticked, and the button. A project
 *												that was approved says when and by whom instead; when a file came into its folder after that, the form stands under the line again with the
 *												sentence that there are new files, and a second approval is possible. The approval is the owner's evidence of the Stand the customer accepted;
 *												whether the project is done stays the owner's step.
 *
 *												POST /.crm/abnahme takes the form. Only the signed-in member of a client may post (a visitor and a member of no client are answered 404, as the
 *												other forms of the portal answer a stranger). Every answer is a redirect to the tab Dateien of the project with a state in the query: approved,
 *												invalid (the project is none of the client's, is done, shows no file, waits for an order, or was approved already and no file came since),
 *												unchecked (the box was not ticked), expired (the token of the form was not the session's) or changed (the files are not the ones the page
 *												showed). The record (kind approval) holds the JSON the page was drawn from - the project and its visible files with their SHA-256 -, so what
 *												the customer saw is what is kept. Then the owner is mailed; a mail that does not go out does not undo the approval (nomail).
 */
namespace Crm\Portal;

use Crm\Db;
use Crm\Documents;
use Crm\Portal;
use Crm\Projekte;
use Crm\Records;

class Approval {

	// The route the form posts to, the page it goes back to
	public const string ROUTE = '/.crm/abnahme';
	public const string PAGE = '/portal/projekte';

	// The states the tab Dateien says - each with the tone and the icon of its note and whether it is a status or an alert
	public const array STATES = [
		'approved'  => [ 'success', 'circle-check', 'status' ],
		'nomail'    => [ 'success', 'circle-check', 'status' ],
		'invalid'   => [ 'error', 'circle-alert', 'alert' ],
		'unchecked' => [ 'error', 'circle-alert', 'alert' ],
		'expired'   => [ 'error', 'circle-alert', 'alert' ],
		'changed'   => [ 'error', 'circle-alert', 'alert' ],
	];

	/**
	 *	Where a project stands with its approval
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client				As Clients::get() has it
	 *	@param		array 		$project			One of the client's, as Projekte::get() has it
	 *	@param		array 		$files				The files of the project's folder the member may see, as Files::files() has them
	 *
	 *	@return 	array											record (the newest approval of the client's project, null for none), files (those that are on the server), fresh (a file is younger than that approval) and open (the project can be approved now)
	 */
	public static function standing( array &$appData, array $client, array $project, array $files ): array {

		$record = self::approved( $appData, $client, $project );
		$kept = array_values( array_filter( $files, static fn( array $file ): bool => isset( $file['gone'] ) === false ) );
		$fresh = $record !== null && array_filter( $kept, static fn( array $file ): bool => (string) $file['created'] > (string) $record['at'] ) !== [];
		$open = $project['done'] === false && $kept !== [] && ( $record === null || $fresh === true )
			&& Portal::pending( $appData, $client ) === '' && Projekte::waiting( $appData, (string) $project['id'], (int) $client['id'] ) === null;

		return [ 'record' => $record, 'files' => $kept, 'fresh' => $fresh, 'open' => $open ];
	}

	/**
	 *	The newest approval of a project of the client
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *	@param		array 		$project
	 *
	 *	@return 	array|null								As Records::of() has it; null for a project nobody of the client approved
	 */
	public static function approved( array &$appData, array $client, array $project ): ?array {

		$record = Records::approval( $appData, (string) $project['id'] );

		return $record !== null && $record['client_id'] === (int) $client['id'] ? $record : null;
	}

	/**
	 *	The block Abnahme under the list of files: the sentence of the state the query names, the line that says the project was approved, the form while it can be approved
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *	@param		array 		$project
	 *	@param		array 		$files				The files the member may see
	 *	@param		string		$dir					The directory the project is served from, escaped
	 *
	 *	@return 	string										'' where there is nothing to say and nothing to ask
	 */
	public static function block( array &$appData, array $client, array $project, array $files, string $dir ): string {

		$standing = self::standing( $appData, $client, $project, $files );
		$state = self::state( $appData );
		$done = '';
		$form = '';

		if( $standing['record'] !== null )
			$done = Portal::render( 'approval-done', [
				'[[id]]'   => \Nino\Modules\Components::escape( (string) $project['id'] ),
				'[[iso]]'  => \Nino\Modules\Components::escape( substr( (string) $standing['record']['at'], 0, 10 ) ),
				'[[date]]' => \Nino\Modules\Components::escape( Documents\Render::day( substr( (string) $standing['record']['at'], 0, 10 ) ) ),
				'[[name]]' => \Nino\Modules\Components::escape( (string) $standing['record']['member_name'] !== '' ? (string) $standing['record']['member_name'] : (string) $standing['record']['member_email'] ),
			] );

		if( $standing['open'] === true ) {

			$sentence = self::sentence( $appData, $project );
			$form = Portal::render( 'approval-form', [
				'[[id]]'       => \Nino\Modules\Components::escape( (string) $project['id'] ),
				'[[dir]]'      => $dir,
				'[[hash]]'     => Records::hash( Records::approvalShown( $project, $standing['files'], $sentence ) ),
				'[[news]]'     => $standing['fresh'] === true ? Portal::template( 'approval-news' ) : '',
				'[[sentence]]' => \Nino\Modules\Components::escape( $sentence ),
			] );
		}

		if( $state === '' && $done === '' && $form === '' )
			return '';

		return Portal::render( 'approval', [ '[[state]]' => $state, '[[done]]' => $done, '[[form]]' => $form ] );
	}

	/**
	 *	The sentence of the box, as the customer reads it and as the record keeps it
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$project			id and title
	 *
	 *	@return 	string										Plain text, the title of the project without a null byte
	 */
	public static function sentence( array &$appData, array $project ): string {

		Portal::words( $appData );

		$name = str_replace( "\0", '', (string) $project['title'] !== '' ? (string) $project['title'] : (string) $project['id'] );

		return str_replace( '{title}', $name, (string) \Nino\Html::resolveTextfill( $appData, '/project/crm-portal/approval/sentence' ) );
	}

	/**
	 *	The sentence about the state the query names, for the tab Dateien: the states of the approval are those of the query on the tab Dateien (?tab=dateien), as the
	 *	states of the order are those of the tab Angebot
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	string										'' where the query names no state of the approval
	 */
	public static function state( array &$appData ): string {

		$query = Portal::query( $appData );
		$state = is_string( $query['state'] ?? null ) === true && isset( self::STATES[$query['state']] ) === true ? $query['state'] : '';

		if( $state === '' || self::asked( $appData ) === false )
			return '';

		return Portal::render( 'approval-state', [ '[[state]]' => $state, '[[tone]]' => self::STATES[$state][0], '[[icon]]' => self::STATES[$state][1], '[[role]]' => self::STATES[$state][2] ] );
	}

	/**
	 *	Whether the request is for the tab Dateien (?tab=dateien): where the states of the approval stand, and where those of the order do not
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	bool
	 */
	public static function asked( array &$appData ): bool {

		return ( Portal::query( $appData )['tab'] ?? null ) === 'dateien';
	}

	/**
	 *	'/nino/http/response/POST://.crm/abnahme': the form under the files of a project. A visitor and a member of no client are answered 404; a token that is not the
	 *	session's is the redirect with ?state=expired; a project that is not the client's, is done, shows no file, waits for the order of an offer or was approved already
	 *	with no file since is ?state=invalid; files that are not the ones the form showed are ?state=changed; a box that is not ticked is ?state=unchecked. Else the approval
	 *	is kept, the owner is mailed and the answer is ?state=approved
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackApprove( array &$appData, array &$request ): void {

		$client = Portal::client( $appData );
		$member = $client === null ? null : \Nino\Modules\Members::current( $appData );

		if( $client === null || $member === null ) {

			$request['/nino/http/response']['statusCode']								= 404;
			$request['/nino/http/response']['body']										= 'Not Found';
			$request['/nino/http/response']['header']['Content-Type']		= 'text/plain; charset=utf-8';
			$request['/nino/http/response']['header']['Cache-Control']	= 'no-store';

			return;
		}

		$asked = is_string( $_POST['project'] ?? null ) === true && preg_match( Projekte::ID, $_POST['project'] ) === 1 ? $_POST['project'] : '';
		$project = $asked === '' ? null : Projekte::get( $appData, $asked );

		// The client's project, worked out once for every path below: another client's is not told apart from one that is not there
		if( $project !== null && $project['client'] !== $client['number'] )
			$project = null;

		if( \Nino\Form::blocked( $request ) === true ) {
			self::redirect( $appData, $request, 'expired', $asked );

			return;
		}

		$files = $project === null ? null : Portal\Projects::visibleOf( $appData, $project );
		$standing = $project === null || $files === null ? null : self::standing( $appData, $client, $project, $files );

		if( $project === null || $standing === null || $standing['open'] === false ) {
			self::redirect( $appData, $request, 'invalid', $asked );

			return;
		}

		// What the customer read is what is kept: the form carries the hash of the files as the page showed them (and the sentence), and files that changed meanwhile are not approved by a click on the old page
		$sentence = self::sentence( $appData, $project );
		$shown = Records::approvalShown( $project, $standing['files'], $sentence );

		if( ( $_POST['shown'] ?? null ) !== Records::hash( $shown ) ) {
			self::redirect( $appData, $request, 'changed', $asked );

			return;
		}

		if( ( $_POST['agree'] ?? null ) !== '1' ) {
			self::redirect( $appData, $request, 'unchecked', $asked );

			return;
		}

		$record = self::keep( $appData, $client, $project, $standing['files'], [ 'id' => $member['id'], 'email' => $member['email'], 'name' => $member['name'] ], $shown, Records::source( $appData, $request ) );

		if( is_array( $record ) === false ) {
			self::redirect( $appData, $request, 'invalid', $asked );

			return;
		}

		// The page promises nothing about the mail to the owner except that the approval is kept; it says so where the mail did not go out
		$mailed = Documents\Mail::approved( $appData, $project, $record, $client, count( $standing['files'] ) );
		self::redirect( $appData, $request, is_int( $mailed ) === true && $mailed > 0 ? 'approved' : 'nomail', $asked );
	}

	/**
	 *	Keep the approval: looked at again inside the transaction, so that two clicks on one page do not approve a project twice
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *	@param		array 		$project
	 *	@param		array 		$files				The files that were shown, on the server
	 *	@param		array 		$member				id, email and name of the account
	 *	@param		string		$shown				The JSON
	 *	@param		array 		$source				ip and agent
	 *
	 *	@return 	array|string							The record as Records::of() has it; why not as a string
	 */
	private static function keep( array &$appData, array $client, array $project, array $files, array $member, string $shown, array $source ): array|string {

		$pdo = Db::pdo( $appData );
		$pdo->exec( 'BEGIN IMMEDIATE' );

		try {
			$last = Records::approval( $appData, (string) $project['id'] );

			if( $last !== null && array_filter( $files, static fn( array $file ): bool => (string) $file['created'] > (string) $last['at'] ) === [] ) {
				$pdo->exec( 'ROLLBACK' );

				return 'state';
			}

			$record = Records::insert( $appData, $pdo, [
				'kind' => 'approval', 'client_id' => $client['id'], 'member' => $member, 'project' => $project['id'], 'shown' => $shown,
				'ip' => (string) ( $source['ip'] ?? '' ), 'agent' => (string) ( $source['agent'] ?? '' ),
			] );

			if( is_string( $record ) === true ) {
				$pdo->exec( 'ROLLBACK' );

				return $record;
			}

			$pdo->exec( 'COMMIT' );
		}
		catch( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			throw $e;
		}

		return $record;
	}

	/**
	 *	The redirect to the tab Dateien of the project, with a state, at the block
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *	@param		string		$state				A key of STATES
	 *	@param		string		$project			The id as posted, in the form of an id; '' where it was none
	 *
	 *	@return 	void
	 */
	private static function redirect( array &$appData, array &$request, string $state, string $project ): void {

		$target = $project === '' ? self::PAGE. '?state='. $state : self::PAGE. '?projekt='. rawurlencode( $project ). '&tab=dateien&state='. $state. '#abnahme';

		\Nino\Http::redirect( $appData, $request, $target, 303 );
	}
}
