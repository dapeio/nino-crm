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
			return self::message( $appData, 'off', $classes );

		$folder = self::uploadFolder( $appData );

		if( $folder === null )
			return self::message( $appData, 'none', $classes );

		$rows = self::rows( $appData, self::visible( $appData, $folder['id'] ), '' );

		return Portal::render( 'uploads', [
			'[[classes]]' => $classes,
			'[[folder]]'  => \Nino\Modules\Components::escape( $folder['id'] ),
			'[[list]]'    => $rows !== '' ? Portal::render( 'uploads-list', [ '[[rows]]' => $rows ] ) : Portal::template( 'uploads-empty' ),
		] );
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
	 *	The rows of a list of files: the icon of the kind and the name - a link to the player for an audio or video file where Review is on and a
	 *	listen address is given, else to the download -, "neu" for a file younger than NEW_DAYS, the size, the day, the button that plays and the
	 *	download. The file that is being played is marked. A file that was removed from the server has its name, size and day and no way to open it:
	 *	the sentence that it is gone and that the studio can be written to stands in the place of the buttons
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$files				As Files::files() has them
	 *	@param		string		$listen				The address of the page the player stands on, up to and with 'review=' ('' for no link that plays)
	 *	@param		string		$playing			The id of the file the player plays ('' for none)
	 *
	 *	@return 	string										'' for no file
	 */
	public static function rows( array &$appData, array $files, string $listen, string $playing = '' ): string {

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

			if( isset( $file['gone'] ) === true ) {

				$rows .= Portal::render( 'file-gone', [
					'[[id]]'      => \Nino\Modules\Components::escape( $file['id'] ),
					'[[mime]]'    => \Nino\Modules\Components::escape( $file['mime'] ),
					'[[icon]]'    => Icons::ofFile( $file['mime'] ),
					'[[name]]'    => \Nino\Modules\Components::escape( $file['name'] ),
					'[[size]]'    => \Nino\Modules\Components::escape( \Nino\Modules\Files\Store::size( $file['size'], $words ) ),
					'[[created]]' => \Nino\Modules\Components::escape( $file['created'] ),
					'[[date]]'    => \Nino\Modules\Components::escape( Documents\Render::day( substr( $file['created'], 0, 10 ) ) ),
					'[[gone]]'    => self::gone( $appData ),
				] );

				continue;
			}

			$created = strtotime( $file['created'] );
			$plays = $review === true && \Nino\Modules\Review::kind( $file['mime'] ) !== '';
			$name = \Nino\Modules\Components::escape( $file['name'] );
			$href = \Nino\Modules\Components::escape( $listen. $file['id'] );
			$current = $playing !== '' && $file['id'] === $playing;

			$rows .= Portal::render( 'file', [
				'[[id]]'      => \Nino\Modules\Components::escape( $file['id'] ),
				'[[mime]]'    => \Nino\Modules\Components::escape( $file['mime'] ),
				'[[icon]]'    => Icons::ofFile( $file['mime'] ),
				'[[name]]'    => $name,
				'[[primary]]' => $plays === true ? $href. '#projekt-files' : \Nino\Modules\Components::escape( $url ). '?download=1',
				'[[new]]'     => $created !== false && $created > $limit ? Portal::template( 'file-new' ) : '',
				'[[mark]]'    => $current === true ? Portal::template( 'file-playing' ) : '',
				'[[playing]]' => $current === true ? ' crm-files__row--playing' : '',
				'[[current]]' => $current === true ? ' aria-current="true"' : '',
				'[[size]]'    => \Nino\Modules\Components::escape( \Nino\Modules\Files\Store::size( $file['size'], $words ) ),
				'[[created]]' => \Nino\Modules\Components::escape( $file['created'] ),
				'[[date]]'    => \Nino\Modules\Components::escape( Documents\Render::day( substr( $file['created'], 0, 10 ) ) ),
				'[[listen]]'  => $plays === true ? Portal::render( 'file-listen', [ '[[href]]' => $href, '[[name]]' => $name ] ) : '',
				'[[url]]'     => \Nino\Modules\Components::escape( $url ),
			] );
		}

		return $rows;
	}

	/**
	 *	The sentence for a file that was removed from the server: that it is gone, and - where the site has a contact address - the way to ask for it again as a link to
	 *	write to that address
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	string										Markup
	 */
	public static function gone( array &$appData ): string {

		$mail = Portal::contactMail( $appData );

		return Portal::render( 'file-gone-text', [ '[[ask]]' => $mail !== '' ? Portal::render( 'file-gone-mail', [ '[[address]]' => \Nino\Modules\Components::escape( $mail ) ] ) : Portal::template( 'file-gone-ask' ) ] );
	}

	/**
	 *	The sentence for a removed file as a note, which stands over the comments of a file that has no medium any more
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	string										Markup
	 */
	public static function goneNote( array &$appData ): string {

		return Portal::render( 'file-gone-note', [ '[[gone]]' => self::gone( $appData ) ] );
	}

	/**
	 *	A sentence of the uploads where there is no list: an empty state that says what happens next
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$name					off or none
	 *	@param		string		$classes
	 *
	 *	@return 	string
	 */
	private static function message( array &$appData, string $name, string $classes ): string {

		return Portal::render( 'uploads-message', [ '[[classes]]' => $classes, '[[empty]]' => Portal::emptyState( $appData, 'inbox', '[[/project/crm-portal/uploads/'. $name. ']]', 'crm-uploads__empty' ) ] );
	}
}
