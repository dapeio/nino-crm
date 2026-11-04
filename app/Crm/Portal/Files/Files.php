<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Files		What the portal draws of the feature Files: the rows of files the member may see (the project page and the page Upload
 *											use the same row) and the component [crm-uploads], the files of the upload folder of the member's group. The rule
 *											of who may see a file is the feature's own (Files::allowed()), the size and the address are its too; nothing is
 *											stored here. Where the feature is off the component says so.
 */
namespace Crm\Portal;

use Crm\Crm;
use Crm\Documents;
use Crm\Portal;

class Files {

	// A file younger than this many days is called new
	public const int NEW_DAYS = 14;

	/**
	 *	[crm-uploads] - the files of the upload folder of the member's group that the member may see (their own and the visible ones), the newest
	 *	first: name, size, day and the download. A group without an upload folder reads the sentence that the owner has to make one. Nothing
	 *	for a visitor
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$args
	 *
	 *	@return 	string
	 */
	public static function componentCrmUploads( array &$appData, array $args ): string {

		if( Crm::featureOn( $appData, 'members' ) === false || \Nino\Modules\Members::current( $appData ) === null )
			return '';

		Portal::words( $appData );

		$classes = \Nino\Feature::classes( $args );

		if( Crm::featureOn( $appData, 'files' ) === false )
			return self::message( 'off', $classes );

		$folder = self::uploadFolder( $appData );

		if( $folder === null )
			return self::message( 'none', $classes );

		$rows = self::rows( $appData, self::visible( $appData, $folder['id'] ), '' );

		return str_replace(
			[ '[[classes]]', '[[folder]]', '[[rows]]' ],
			[ $classes, \Nino\Modules\Components::escape( $folder['id'] ), $rows !== '' ? $rows : self::template( 'uploads-empty' ) ],
			self::template( 'uploads' )
		);
	}

	/**
	 *	The folder the member's group uploads into
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array|null								As Files::folders() has it; null for a member without a group and for a group without an upload folder
	 */
	public static function uploadFolder( array &$appData ): ?array {

		$member = \Nino\Modules\Members::current( $appData );

		if( $member === null || $member['group'] === '' )
			return null;

		foreach( \Nino\Modules\Files::folders( $appData, $member['group'] ) as $folder )
			if( $folder['upload'] === true )
				return $folder;

		return null;
	}

	/**
	 *	The files of a folder that the member signed in may see, the newest first
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$folder				The id of a folder
	 *
	 *	@return 	array											As Files::files() has them
	 */
	public static function visible( array &$appData, string $folder ): array {

		return array_values( array_filter( \Nino\Modules\Files::files( $appData, $folder ), static fn( array $file ): bool => \Nino\Modules\Files::allowed( $appData, $file ) === true ) );
	}

	/**
	 *	The rows of a list of files: the name as a link to the file, "neu" for a file younger than NEW_DAYS, the size, the day, the link that
	 *	plays an audio or video file where Review is on and a listen address is given, and the download
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$files				As Files::files() has them
	 *	@param		string		$listen				The address of the page the player stands on, up to and with 'review=' ('' for no link that plays)
	 *
	 *	@return 	string										'' for no file
	 */
	public static function rows( array &$appData, array $files, string $listen ): string {

		$words = [];

		foreach( [ 'b' => 'byte', 'kb' => 'kilobyte', 'mb' => 'megabyte', 'gb' => 'gigabyte', 'separator' => 'separator' ] as $key => $name )
			$words[$key] = (string) \Nino\Html::resolveTextfill( $appData, '/feature/files/unit/'. $name );

		$review = $listen !== '' && Crm::featureOn( $appData, 'review' ) === true;
		$limit = time() - self::NEW_DAYS * 86400;
		$rows = '';

		foreach( $files as $file ) {

			$url = \Nino\Modules\Files::url( $appData, $file['id'] );

			if( $url === '' )
				continue;

			$created = strtotime( $file['created'] );
			$plays = $review === true && \Nino\Modules\Review::kind( $file['mime'] ) !== '';

			$rows .= str_replace(
				[ '[[id]]', '[[mime]]', '[[name]]', '[[new]]', '[[size]]', '[[created]]', '[[date]]', '[[listen]]', '[[url]]' ],
				[
					\Nino\Modules\Components::escape( $file['id'] ),
					\Nino\Modules\Components::escape( $file['mime'] ),
					\Nino\Modules\Components::escape( $file['name'] ),
					$created !== false && $created > $limit ? self::template( 'file-new' ) : '',
					\Nino\Modules\Components::escape( \Nino\Modules\Files\Store::size( $file['size'], $words ) ),
					\Nino\Modules\Components::escape( $file['created'] ),
					\Nino\Modules\Components::escape( Documents\Render::day( substr( $file['created'], 0, 10 ) ) ),
					$plays === true ? str_replace( '[[href]]', \Nino\Modules\Components::escape( $listen. $file['id'] ), self::template( 'file-listen' ) ) : '',
					\Nino\Modules\Components::escape( $url ),
				],
				self::template( 'file' )
			);
		}

		return $rows;
	}

	/**
	 *	A sentence of the uploads where there is no list
	 *
	 *	@param		string		$name					off or none
	 *	@param		string		$classes
	 *
	 *	@return 	string
	 */
	private static function message( string $name, string $classes ): string {

		return str_replace( [ '[[classes]]', '[[message]]' ], [ $classes, '[[/project/crm-portal/uploads/'. $name. ']]' ], self::template( 'uploads-message' ) );
	}

	/**
	 *	@param		string		$name
	 *
	 *	@return 	string
	 */
	private static function template( string $name ): string {

		return Portal::template( $name );
	}
}
