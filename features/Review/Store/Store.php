<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Nino\Modules\Review\Store		The data of the Review feature: the comments on the files
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules\Review {

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Review\Store			What the feature keeps and how it keeps it. One file, /data/review.php, that
	 *										holds the comments by the file they are on:
	 *
	 *											[ <file id> => [ <comment id> => [ by, at, text, parent, done, created, page ] ] ]
	 *
	 *										by is the author - [ kind => owner|member, id ] with the id of the account for a member
	 *										and '' for the owner -, at the second in the track (a float of one decimal, null for a
	 *										comment without a time), text a plain text with its line breaks, parent the id of the
	 *										comment this one answers ('' for a comment of its own; one level only), done whether
	 *										the owner has dealt with it, created the time it was written and page the path of the
	 *										page that showed the player ('' for none). Every write is a Filesystem::mutate() of
	 *										that file, and the file is plain php anybody can edit, so parse() reads what is there
	 *										for what it has to be and leaves out an entry that is not.
	 *
	 *										The class knows nothing of the files the comments are on: whether a file is there, and
	 *										whether it is audio or video, is Review's to ask of the Files feature.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Store {

		public const string DATA = '/data/review.php';

		// The shape of an id of a file and of a comment: 12 hex characters, as Files and Members have theirs
		public const string ID = '/^[a-f0-9]{12}$/D';

		public const string TIME = 'Y-m-d\TH:i:s\Z';

		// The latest second a comment can name: 99:59:59 and nine tenths
		public const float LAST = 359999.9;

		/**
		 *	The file read for what it has to be. A file is an id of 12 hex characters and a comment too; a comment has an
		 *	author of the kinds there are (a member by the id of the account), a text that is not empty, a time that is a
		 *	number between 0 and LAST - or none -, and a parent that is a comment of the same file and no answer itself: an
		 *	answer whose parent is not that is left out, since nothing can reach it
		 *
		 *	@param		mixed			$state				As the file holds it
		 *
		 *	@return 	array										[ <file id> => [ <comment id> => comment ] ], the comments in the order they were written
		 */
		public static function parse( mixed $state ): array {

			$text = static fn( mixed $value ): string => is_string( $value ) === true ? $value : '';
			$files = [];

			foreach( is_array( $state ) === true ? $state : [] as $file => $records ) {

				$file = (string) $file;

				if( preg_match( self::ID, $file ) !== 1 || is_array( $records ) === false )
					continue;

				$comments = [];

				foreach( $records as $id => $record ) {

					$id = (string) $id;
					$by = is_array( $record ) === true && is_array( $record['by'] ?? null ) === true ? $record['by'] : [];
					$kind = $by['kind'] ?? null;
					$who = $text( $by['id'] ?? null );

					if( preg_match( self::ID, $id ) !== 1 || is_array( $record ) === false || in_array( $kind, [ 'owner', 'member' ], true ) === false
						|| ( $kind === 'member' && preg_match( self::ID, $who ) !== 1 ) || trim( $text( $record['text'] ?? null ) ) === '' )
						continue;

					$at = $record['at'] ?? null;
					$parent = $text( $record['parent'] ?? null );

					$comments[$id] = [
						'by'			=> [ 'kind' => $kind, 'id' => $kind === 'owner' ? '' : $who ],
						'at'			=> ( is_int( $at ) === true || is_float( $at ) === true ) && is_finite( $at ) === true && $at >= 0 && $at <= self::LAST ? round( (float) $at, 1 ) : null,
						'text'		=> $record['text'],
						'parent'	=> preg_match( self::ID, $parent ) === 1 ? $parent : '',
						'done'		=> ( $record['done'] ?? false ) === true,
						'created'	=> $text( $record['created'] ?? null ),
						'page'		=> $text( $record['page'] ?? null ),
					];
				}

				// An answer needs a parent of the same file that is a comment of its own: judged on the file as it was read
				$read = $comments;

				foreach( $read as $id => $comment )
					if( $comment['parent'] !== '' && ( isset( $read[ $comment['parent'] ] ) === false || $read[ $comment['parent'] ]['parent'] !== '' ) )
						unset( $comments[$id] );

				if( $comments !== [] )
					$files[$file] = $comments;
			}

			return $files;
		}

		/**
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array										The file as parse() has it
		 */
		public static function read( array &$appData ): array {

			return self::parse( \Nino\Filesystem::getFileContent( $appData, self::DATA, [] ) );
		}

		/**
		 *	The comments on one file, in the order they were written
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$file					The id of a file
		 *
		 *	@return 	array										[ <comment id> => comment ]
		 */
		public static function of( array &$appData, string $file ): array {

			return self::read( $appData )[$file] ?? [];
		}

		/**
		 *	Put a comment on a file. An answer to an answer is an answer to the comment that one answers; a parent that is not a
		 *	comment of the file is refused - decided inside the write, so that a comment deleted at the same moment cannot be
		 *	answered
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$file					The id of a file
		 *	@param		array 		$record				by, at, text, done, created and page
		 *	@param		string		$parent				The comment answered, '' for none
		 *
		 *	@return 	array|string							The comment with its id and its parent; or why not: 'parent', 'write'
		 */
		public static function insert( array &$appData, string $file, array $record, string $parent ): array|string {

			$refused = '';
			$made = null;

			$written = \Nino\Filesystem::mutate( $appData, self::DATA, static function( mixed $state ) use ( $file, $record, $parent, &$refused, &$made ): ?array {

				$files = self::parse( $state );
				$comments = $files[$file] ?? [];

				if( $parent !== '' && isset( $comments[$parent] ) === false ) {
					$refused = 'parent';
					return null;
				}

				do
					$id = bin2hex( random_bytes( 6 ) );
				while( isset( $comments[$id] ) === true );

				$comment = [
					'by'			=> $record['by'],
					'at'			=> $record['at'],
					'text'		=> $record['text'],
					'parent'	=> $parent === '' ? '' : ( $comments[$parent]['parent'] !== '' ? $comments[$parent]['parent'] : $parent ),
					'done'		=> $record['done'],
					'created'	=> $record['created'],
					'page'		=> $record['page'],
				];

				$made = [ 'id' => $id ] + $comment;
				$comments[$id] = $comment;
				$files[$file] = $comments;

				return $files;
			}, [] );

			if( $written === true && $made !== null )
				return $made;

			return $refused !== '' ? $refused : 'write';
		}

		/**
		 *	Turn done around: a comment the owner has dealt with is open again, an open one is done
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$file					The id of a file
		 *	@param		string		$id						The id of a comment
		 *
		 *	@return 	array|string							The comment as it is now, with its id; or why not: 'unknown', 'write'
		 */
		public static function toggle( array &$appData, string $file, string $id ): array|string {

			$ran = false;
			$after = null;

			$written = \Nino\Filesystem::mutate( $appData, self::DATA, static function( mixed $state ) use ( $file, $id, &$ran, &$after ): ?array {

				$ran = true;
				$files = self::parse( $state );

				if( isset( $files[$file][$id] ) === false )
					return null;

				$files[$file][$id]['done'] = $files[$file][$id]['done'] === false;
				$after = [ 'id' => $id ] + $files[$file][$id];

				return $files;
			}, [] );

			if( $ran === true && $after === null )
				return 'unknown';

			return $written === true && $after !== null ? $after : 'write';
		}

		/**
		 *	Delete a comment and the answers to it
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$file					The id of a file
		 *	@param		string		$id						The id of a comment
		 *
		 *	@return 	array|string							The comments that are gone, each with its id; or why not: 'unknown', 'write'
		 */
		public static function remove( array &$appData, string $file, string $id ): array|string {

			$ran = false;
			$gone = null;

			$written = \Nino\Filesystem::mutate( $appData, self::DATA, static function( mixed $state ) use ( $file, $id, &$ran, &$gone ): ?array {

				$ran = true;
				$files = self::parse( $state );

				if( isset( $files[$file][$id] ) === false )
					return null;

				$gone = [];

				foreach( $files[$file] as $each => $comment )
					if( (string) $each === $id || $comment['parent'] === $id ) {
						$gone[] = [ 'id' => (string) $each ] + $comment;
						unset( $files[$file][$each] );
					}

				if( $files[$file] === [] )
					unset( $files[$file] );

				return $files;
			}, [] );

			if( $ran === true && $gone === null )
				return 'unknown';

			return $written === true && $gone !== null ? $gone : 'write';
		}

		/**
		 *	Drop the comments on a file: the file is gone, and nothing can reach them any more
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$file					The id of a file
		 *
		 *	@return 	bool											Whether the comments are gone - true where there were none
		 */
		public static function purge( array &$appData, string $file ): bool {

			return \Nino\Filesystem::mutate( $appData, self::DATA, static function( mixed $state ) use ( $file ): ?array {

				$files = self::parse( $state );

				if( isset( $files[$file] ) === false )
					return null;

				unset( $files[$file] );

				return $files;
			}, [] ) || self::of( $appData, $file ) === [];
		}

		/**
		 *	The text of a comment as it is kept: no invalid byte, no control character but the line break and the tab, the
		 *	line breaks of every system as one, and no blank space at its ends
		 *
		 *	@param		string		$raw
		 *
		 *	@return 	string
		 */
		public static function clean( string $raw ): string {

			$text = str_replace( [ "\r\n", "\r" ], "\n", mb_scrub( $raw, 'UTF-8' ) );

			return trim( (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $text ) );
		}
	}
}
