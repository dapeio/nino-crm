<?php
declare(strict_types=1);
/**
 *	Crm\Surface\Activity		What the card Aus dem Portal on the first screen of the surface reads: the latest things the customers did in the portal, newest first, at most
 *													LIMIT of them. Two sources, one query or read each. The records - every kind the module keeps, taken from Records::KINDS as they are, so that
 *													a kind that is added later is listed without a change here - with the client, the account that agreed and the thing it was about; and the comments
 *													of members in Review (an answer of a member is one), where the file lies in the folder of a client's group.
 *
 *													crm-portal/activity		rows: each with at (the time), kind (a kind of Records, or comment), client_id, client_title, account (the name of the
 *																								account, else its address; '' where there is none) and object - type document (id, label: the number, else the title), project
 *																								(id, label: the title), file (id, label: the name; project and project_title: the project whose folder it lies in, '' for none)
 *																								or client (the card of the client holds it). A record names a document first, else a project, else the client.
 *																								Read under the permission of the CRM. Nothing here changes anything, and the answer carries no address and no browser:
 *																								those stay in the record.
 */
namespace Crm\Surface;

use Crm\Db;

class Activity {

	// How many events the card lists
	public const int LIMIT = 20;

	/**
	 *	@return 	array											action => [ class, method ]
	 */
	public static function actions(): array {

		return [ 'crm-portal/activity' => [ self::class, 'apiActivity' ] ];
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function apiActivity( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, \Crm\Crm::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request ): void {
			\Nino\Http::ok( $request, [ 'rows' => self::read( $appData ) ] );
		} );
	}

	/**
	 *	The latest events of both sources, merged
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											As the class has them, the newest first
	 */
	public static function read( array &$appData ): array {

		$rows = array_merge( self::records( $appData ), self::comments( $appData ) );

		usort( $rows, static fn( array $a, array $b ): int => $b['at'] <=> $a['at'] );

		return array_slice( $rows, 0, self::LIMIT );
	}

	/**
	 *	The latest records, one query
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array
	 */
	private static function records( array &$appData ): array {

		$titles = \Crm\Projekte::missing( $appData ) === true ? [] : \Crm\Projekte::titles( $appData );
		$kinds = array_map( static fn( string $kind ): string => '\''. $kind. '\'', \Crm\Records::KINDS );
		$rows = [];

		foreach( Db::rows( $appData, 'SELECT r.kind, r.at, r.client_id, r.member_email, r.member_name, r.document_id, r.project, c.company, c.name, c.email, d.number, d.title
			FROM records r JOIN clients c ON c.id = r.client_id LEFT JOIN documents d ON d.id = r.document_id WHERE r.kind IN ( '. implode( ', ', $kinds ). ' )
			ORDER BY r.at DESC, r.id DESC LIMIT '. self::LIMIT ) as $row ) {

			if( $row['document_id'] !== null )
				$object = [ 'type' => 'document', 'id' => (string) $row['document_id'], 'label' => (string) ( $row['number'] ?? '' ) !== '' ? (string) $row['number'] : (string) $row['title'] ];
			elseif( (string) $row['project'] !== '' )
				$object = [ 'type' => 'project', 'id' => (string) $row['project'], 'label' => (string) ( $titles[(string) $row['project']] ?? $row['project'] ) ];
			else
				$object = [ 'type' => 'client', 'id' => (string) $row['client_id'], 'label' => '' ];

			$rows[] = [
				'at'           => (string) $row['at'],
				'kind'         => (string) $row['kind'],
				'client_id'    => (int) $row['client_id'],
				'client_title' => \Crm\Clients::title( $row ),
				'account'      => trim( (string) $row['member_name'] ) !== '' ? trim( (string) $row['member_name'] ) : trim( (string) $row['member_email'] ),
				'object'       => $object,
			];
		}

		return $rows;
	}

	/**
	 *	The latest comments of members on the files in the folders of the clients, one read of the comments: the newest first, a file that is not a client's is passed over
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array
	 */
	private static function comments( array &$appData ): array {

		if( Review::available() === false || \Crm\Crm::featureOn( $appData, 'review' ) === false || \Crm\Crm::featureOn( $appData, 'files' ) === false )
			return [];

		$found = [];

		foreach( \Nino\Modules\Review\Store::read( $appData ) as $file => $comments )
			foreach( $comments as $id => $comment )
				if( $comment['by']['kind'] === 'member' && $comment['created'] !== '' )
					$found[] = [ 'file' => (string) $file, 'comment' => (string) $id, 'member' => $comment['by']['id'], 'at' => $comment['created'] ];

		usort( $found, static fn( array $a, array $b ): int => [ $b['at'], $b['comment'] ] <=> [ $a['at'], $a['comment'] ] );

		$members = \Crm\Crm::featureOn( $appData, 'members' ) === true;
		$projects = null;
		$rows = [];

		foreach( $found as $one ) {

			if( count( $rows ) >= self::LIMIT )
				break;

			$file = \Nino\Modules\Files::file( $appData, $one['file'] );
			$folder = $file === null ? null : \Nino\Modules\Files\Store::folder( $appData, (string) $file['folder'] );
			$number = $folder === null ? null : Files::clientOf( $appData, (string) $folder['group'] );
			$client = $number === null ? null : \Crm\Clients::byNumber( $appData, $number );

			if( $file === null || $folder === null || $client === null )
				continue;

			if( $projects === null ) {
				$projects = [];

				if( \Crm\Projekte::missing( $appData ) === false )
					foreach( \Crm\Projekte::list( $appData ) as $project )
						if( $project['folder'] !== '' )
							$projects[$project['folder']] = $project;
			}

			$account = $members === true ? \Nino\Modules\Members::account( $appData, $one['member'] ) : null;

			$rows[] = [
				'at'           => $one['at'],
				'kind'         => 'comment',
				'client_id'    => (int) $client['id'],
				'client_title' => (string) $client['title'],
				'account'      => $account === null ? '' : ( trim( (string) $account['name'] ) !== '' ? trim( (string) $account['name'] ) : (string) $account['email'] ),
				'object'       => [ 'type' => 'file', 'id' => $one['file'], 'label' => (string) $file['name'], 'project' => (string) ( $projects[(string) $file['folder']]['id'] ?? '' ), 'project_title' => (string) ( $projects[(string) $file['folder']]['title'] ?? '' ) ],
			];
		}

		return $rows;
	}
}
