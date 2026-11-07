<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Files		What the portal draws of the feature Files: the rows of files the member may see (the project page and the page Upload
 *											use the same row) and the component [crm-uploads], the files of the upload folder of the member's group. The rule
 *											of who may see a file is the feature's own (Files::allowed()), the size and the address are its too; nothing is
 *											stored here. Where the feature is off the component says so.
 *
 *											The project page lists titles, not files (titles()): the files of the folder that are versions of one title - the names say
 *											it, Title.03.ext, see \Nino\Modules\Files::version() - are one row with the newest version, how many versions there are
 *											and how many comments of Review are open over all of them, and the versions() of the title that is played are a select
 *											above its player. The page of the uploads lists files (rows()), each one a row.
 *
 *											A row of a medium (audio or video, what the player plays: Portal::medium()) has the download only where the project of the folder has it
 *											switched on (Projekte::state()) - the files a member put there are the member's and are always saved. Without it the row of a medium is
 *											for listening; the row of any other file has its name as a link to the download and the button Herunterladen whatever the switch
 *											says, and the route says the same (Portal::callbackFileAllowed()).
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
	 *	first: name, size, day and the download. A group without an upload folder - the module makes one with every client, so this is a client whose folder could not be made - reads the sentence that the area is being set up. Nothing
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
			return self::message( $appData, 'preparing', $classes );

		$rows = self::rows( $appData, self::visible( $appData, $folder['id'] ), '', '', true );

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

		return array_values( array_filter( \Nino\Modules\Files::files( $appData, $folder ), static function( array $file ) use ( &$appData ): bool {
			return \Nino\Modules\Files::allowed( $appData, $file ) === true;
		} ) );
	}

	/**
	 *	The rows of a list of files, one row for each file: the icon of the kind and the name - a link to the player for an audio or video file where
	 *	Review is on and a listen address is given, else to the download -, "neu" for a file younger than NEW_DAYS, the size, the day, the button that
	 *	plays and the download. The file that is being played is marked. A file that was removed from the server has its name, size and day and no way
	 *	to open it: the sentence that it is gone and that the studio can be written to stands in the place of the buttons
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$files				As Files::files() has them
	 *	@param		string		$listen				The address of the page the player stands on, up to and with 'review=' ('' for no link that plays)
	 *	@param		string		$playing			The id of the file the player plays ('' for none)
	 *	@param		bool			$download			Whether the media may be saved: the project of the folder has its download switched on, or the folder is the member's own to upload into; any other file may always be
	 *
	 *	@return 	string										'' for no file
	 */
	public static function rows( array &$appData, array $files, string $listen, string $playing, bool $download ): string {

		$words = self::units( $appData );
		$rows = '';

		foreach( $files as $file )
			$rows .= self::row( $appData, $file, $words, $listen, $playing !== '' && $file['id'] === $playing, [], 0, $download );

		return $rows;
	}

	/**
	 *	The rows of the titles of a folder's files, one row for each title (\Nino\Modules\Files::grouped()): the row of the newest version that is on the
	 *	server, as rows() draws a file, with the title in place of the name, the version, the extension as a pill (two titles of one name differ by it), how many versions the title has when more than one, and how many
	 *	comments over all of its versions are open. A title whose versions were all removed is the row of its newest, as a removed file. The title that
	 *	holds the file being played is marked. A file whose name says no version is a title of its own and is drawn as rows() draws it
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$files				The files of one folder as Files::files() has them
	 *	@param		string		$listen				See rows()
	 *	@param		string		$playing			The id of the file the player plays ('' for none)
	 *	@param		bool			$download			See rows()
	 *
	 *	@return 	string										'' for no file
	 */
	public static function titles( array &$appData, array $files, string $listen, string $playing, bool $download ): string {

		$words = self::units( $appData );
		$counts = self::counts( $appData, $files );
		$rows = '';

		foreach( \Nino\Modules\Files::grouped( $files ) as $title ) {

			$head = self::newest( $title );
			$open = array_sum( array_map( static fn( array $file ): int => $counts[$file['id']]['open'] ?? 0, $title['versions'] ) );
			$current = $playing !== '' && in_array( $playing, array_column( $title['versions'], 'id' ), true ) === true;

			// A file that says no version and has no other to go with is drawn as a file of its own, with its name
			$alone = count( $title['versions'] ) === 1 && $head['number'] === null;

			$rows .= self::row( $appData, $head, $words, $listen, $current, $alone === true ? [] : $title, $open, $download );
		}

		return $rows;
	}

	/**
	 *	The select of the versions of a title, over the player of the one that is played: one option for each version, the newest first - the number, the
	 *	day it was put there and, where comments are open, how many; a removed version says so -, the one being played selected. It is a form that asks
	 *	the page of the project for another version with GET and has a button of its own, which the script takes away and replaces by sending the form when
	 *	the choice changes. Nothing for a title of one version
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$title				As \Nino\Modules\Files::grouped() has it - the versions of one folder of the project, the only files an option can name
	 *	@param		string		$playing			The id of the version being played
	 *	@param		string		$project			The id of the project
	 *	@param		string		$page					The address of the page of the projects, from the directory the project is served from
	 *
	 *	@return 	string										'' for a title of one version
	 */
	public static function versions( array &$appData, array $title, string $playing, string $project, string $page ): string {

		if( count( $title['versions'] ) < 2 )
			return '';

		$counts = self::counts( $appData, $title['versions'] );
		$options = '';

		foreach( $title['versions'] as $file ) {

			$open = $counts[$file['id']]['open'] ?? 0;
			$options .= Portal::render( 'file-version-option', [
				'[[id]]'       => \Nino\Modules\Components::escape( $file['id'] ),
				'[[selected]]' => $file['id'] === $playing ? ' selected' : '',
				'[[text]]'     => Portal::render( 'file-version-text', [
					'[[version]]' => \Nino\Modules\Components::escape( $file['version'] !== '' ? $file['version'] : $file['name'] ),
					'[[date]]'    => \Nino\Modules\Components::escape( Documents\Render::day( substr( $file['created'], 0, 10 ) ) ),
					'[[state]]'   => isset( $file['gone'] ) === true ? Portal::template( 'file-version-gone' ) : ( $open > 0 ? Portal::render( 'file-version-open', [ '[[number]]' => (string) $open ] ) : '' ),
				] ),
			] );
		}

		return Portal::render( 'file-versions', [
			'[[action]]'  => \Nino\Modules\Components::escape( $page ),
			'[[project]]' => \Nino\Modules\Components::escape( $project ),
			'[[options]]' => $options,
		] );
	}

	/**
	 *	The newest version of a title that is on the server; the newest of all where every version was removed
	 *
	 *	@param		array 		$title				As \Nino\Modules\Files::grouped() has it
	 *
	 *	@return 	array											One of its versions
	 */
	public static function newest( array $title ): array {

		foreach( $title['versions'] as $file )
			if( isset( $file['gone'] ) === false )
				return $file;

		return $title['versions'][0];
	}

	/**
	 *	The player of Review as the page of a project has it: the paragraph that leads back to the list of the player is left out, because the list stands beside the player
	 *
	 *	@param		string		$player				The markup of Review::componentReviewPlayer()
	 *
	 *	@return 	string
	 */
	public static function beside( string $player ): string {

		return (string) preg_replace( '~<p class="review-player__back">.*?</p>\s*~s', '', $player, 1 );
	}

	/**
	 *	The comments of Review on files, all and open: nothing where Review is off
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$files				As Files::files() has them
	 *
	 *	@return 	array											As \Nino\Modules\Review::counts() has them, by id of the file; [] where Review is off
	 */
	private static function counts( array &$appData, array $files ): array {

		return Crm::featureOn( $appData, 'review' ) === true ? \Nino\Modules\Review::counts( $appData, array_column( $files, 'id' ) ) : [];
	}

	/**
	 *	The words of a size, as the feature Files writes it
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											b, kb, mb, gb and separator
	 */
	private static function units( array &$appData ): array {

		$words = [];

		foreach( [ 'b' => 'byte', 'kb' => 'kilobyte', 'mb' => 'megabyte', 'gb' => 'gigabyte', 'separator' => 'separator' ] as $key => $name )
			$words[$key] = (string) \Nino\Html::resolveTextfill( $appData, '/feature/files/unit/'. $name );

		return $words;
	}

	/**
	 *	One row of a list
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$file					As Files::files() has it
	 *	@param		array 		$words				See units()
	 *	@param		string		$listen				See rows()
	 *	@param		bool			$current			Whether the player plays this file - or, for a title, one of its versions
	 *	@param		array 		$title				[] for a file of its own; else the title of Files::grouped()
	 *	@param		int				$open					The open comments on the file - on all the versions of the title
	 *	@param		bool			$download			Whether a medium may be saved; a file that is none and a file the member signed in put there may always
	 *
	 *	@return 	string
	 */
	private static function row( array &$appData, array $file, array $words, string $listen, bool $current, array $title, int $open, bool $download ): string {

		if( \Nino\Modules\Files::url( $appData, $file['id'] ) === '' )
			return '';

		if( isset( $file['gone'] ) === true )
			return Portal::render( 'file-gone', [
				'[[id]]'      => \Nino\Modules\Components::escape( $file['id'] ),
				'[[mime]]'    => \Nino\Modules\Components::escape( $file['mime'] ),
				'[[icon]]'    => Icons::ofFile( $file['mime'] ),
				'[[name]]'    => \Nino\Modules\Components::escape( $file['name'] ),
				'[[size]]'    => \Nino\Modules\Components::escape( \Nino\Modules\Files\Store::size( $file['size'], $words ) ),
				'[[created]]' => \Nino\Modules\Components::escape( $file['created'] ),
				'[[date]]'    => \Nino\Modules\Components::escape( Documents\Render::day( substr( $file['created'], 0, 10 ) ) ),
				'[[gone]]'    => self::gone( $appData ),
			] );

		$url = \Nino\Modules\Files::url( $appData, $file['id'] );
		$created = strtotime( $file['created'] );
		$review = $listen !== '' && Crm::featureOn( $appData, 'review' ) === true;
		$plays = $review === true && \Nino\Modules\Review::kind( $file['mime'] ) !== '';
		$medium = Portal::medium( $appData, $file['mime'] );
		$name = \Nino\Modules\Components::escape( $title === [] ? $file['name'] : $title['title'] );
		$ext = $title === [] ? '' : \Nino\Modules\Components::escape( strtoupper( (string) ( $file['ext'] ?? '' ) ) );
		$label = ( $title === [] || $file['version'] === '' ? $name : $name. ' '. \Nino\Modules\Components::escape( $file['version'] ) ). ( $ext === '' ? '' : ' '. $ext );
		$href = \Nino\Modules\Components::escape( $listen. $file['id'] );
		$many = $title === [] ? 0 : count( $title['versions'] );
		$member = \Nino\Modules\Members::current( $appData );
		$saves = $download === true || $medium === false || ( $member !== null && ( $file['by']['kind'] ?? '' ) === 'member' && ( $file['by']['id'] ?? '' ) === $member['id'] );

		// The name is a link to the player or, where the file may be saved, to the download; a medium that can be neither played nor saved has its name alone
		$link = $plays === true ? $href. '#projekt-files' : ( $saves === true ? \Nino\Modules\Components::escape( $url ). '?download=1' : '' );

		return Portal::render( 'file', [
			'[[id]]'       => \Nino\Modules\Components::escape( $file['id'] ),
			'[[mime]]'     => \Nino\Modules\Components::escape( $file['mime'] ),
			'[[icon]]'     => Icons::ofFile( $file['mime'] ),
			'[[title]]'    => $link === '' ? Portal::render( 'file-name-plain', [ '[[name]]' => $name ] ) : Portal::render( 'file-name-link', [ '[[href]]' => $link, '[[name]]' => $name ] ),
			'[[version]]'  => $title === [] || $file['version'] === '' ? '' : Portal::render( 'file-version', [ '[[number]]' => \Nino\Modules\Components::escape( $file['version'] ) ] ),
			'[[ext]]'      => $ext === '' ? '' : Portal::render( 'file-ext', [ '[[ext]]' => $ext ] ),
			'[[versions]]' => $many > 1 ? Portal::render( 'file-count', [ '[[number]]' => (string) $many ] ) : '',
			'[[new]]'      => $created !== false && $created > time() - self::NEW_DAYS * 86400 ? Portal::template( 'file-new' ) : '',
			'[[mark]]'     => $current === true ? Portal::template( 'file-playing' ) : '',
			'[[open]]'     => $open > 0 ? Portal::render( 'file-open', [ '[[number]]' => (string) $open ] ) : '',
			'[[playing]]'  => $current === true ? ' crm-files__row--playing' : '',
			'[[current]]'  => $current === true ? ' aria-current="true"' : '',
			'[[size]]'     => \Nino\Modules\Components::escape( \Nino\Modules\Files\Store::size( $file['size'], $words ) ),
			'[[created]]'  => \Nino\Modules\Components::escape( $file['created'] ),
			'[[date]]'     => \Nino\Modules\Components::escape( Documents\Render::day( substr( $file['created'], 0, 10 ) ) ),
			'[[listen]]'   => $plays === true ? Portal::render( 'file-listen', [ '[[href]]' => $href, '[[label]]' => $label ] ) : '',
			'[[download]]' => $saves === true ? Portal::render( 'file-download', [ '[[url]]' => \Nino\Modules\Components::escape( $url ), '[[label]]' => $label ] ) : ( $plays === true ? '' : Portal::template( 'file-locked' ) ),
		] );
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
