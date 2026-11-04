<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Nino\Modules\Files\Upload		One chunk of an upload, for the panel and for a member alike
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules\Files {

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Files\Upload			The protocol of an upload in chunks, written once. A file is cut into
	 *										chunks - the first one sets the size of the chunk of its upload, from one
	 *										byte up to what the setting chunk gives (Store::chunkBytes()); every
	 *										chunk but the last is exactly as long as the first, the last is what is
	 *										left, and count is the number of chunks that size makes. A script that
	 *										finds the web server takes less than the setting starts again with a
	 *										smaller chunk. The chunks are posted in order, each with the fields
	 *
	 *											folder, name, size (of the whole file, in bytes), upload, index, count
	 *
	 *										and the bytes as the file part `chunk`. The first chunk (index 0) has
	 *										no upload id and is answered with one, 16 hex characters of random_bytes(8),
	 *										which every chunk after it names. The chunks are appended to
	 *										/data/files/.upload/<upload id>.part under a lock; beside it, the file
	 *										<upload id>.chunk keeps the length of the first chunk, which every later
	 *										request is checked against (its index, its length and its count).
	 *
	 *										Nothing is kept of an upload but the part: every request is checked
	 *										whole, by what it says and by the size the part has when it comes - the
	 *										part holds index chunks, so its size over index is the chunk of the upload.
	 *										That is the order - the part has to be exactly index chunks long
	 *										before chunk index is appended, so a chunk out of order, a chunk
	 *										twice and a chunk of an upload that is gone are all one refusal -
	 *										and it is the length: the chunk has to be as long as its place in
	 *										the file asks. The last chunk assembles: the part has to be as long as
	 *										size says, finfo has to agree with the extension of the name, the
	 *										sha256 is taken, the part is renamed to /data/files/<id>.<ext> and the
	 *										entry goes into the index. Whatever fails removes the part.
	 *
	 *										Who may upload into the folder is the caller's to have decided; the
	 *										limits - the types, the size of the file, the quota of the group - are
	 *										checked here, on every chunk, since nothing says a later request is
	 *										the one an earlier one was.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Upload {

		public const string UPLOAD = '/^[a-f0-9]{16}$/D';

		/**
		 *	Take one chunk
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$folder				The folder the file goes into, with its id
		 *	@param		array 		$post					The fields of the request: name, size, upload, index and count
		 *	@param		mixed			$file					The file part of the request, $_FILES['chunk'] - whatever is there
		 *	@param		array 		$by						kind and id: the owner, or the member by the id of the account
		 *	@param		bool			$visible			Whether the file is shown to the members once it is in
		 *
		 *	@return 	array										[ 'fail' => [ status, error, code, params, field ] ], or [ 'part' => [ upload, index, count ] ] for a chunk that is taken, or [ 'file' => the entry with its id ] for the last
		 */
		public static function receive( array &$appData, array $folder, array $post, mixed $file, array $by, bool $visible ): array {

			[ $name, $ext ] = Store::cleanName( self::_text( $post['name'] ?? null ) );
			$size = self::_number( $post['size'] ?? null );
			$count = self::_number( $post['count'] ?? null );
			$index = self::_number( $post['index'] ?? null );
			$upload = self::_text( $post['upload'] ?? null );

			if( $name === '' || in_array( $ext, Store::types( $appData ), true ) === false )
				return self::_fail( 400, 'this kind of file is not accepted', 'files_type', [], 'name' );

			if( $size === null || $size < 1 || $count === null || $index === null || $index >= $count )
				return self::_fail( 400, 'the chunk does not fit the upload', 'files_chunk' );

			$max = Store::maxBytes( $appData );

			if( $size > $max )
				return self::_fail( 400, 'the file is larger than allowed', 'files_size', [ intdiv( $max, Store::MEGABYTE ) ], 'size' );

			$quota = Store::quotaBytes( $appData );

			if( $quota > 0 && Store::used( $appData, $folder['group'] ) + $size > $quota )
				return self::_fail( 400, 'the quota of the group is used up', 'files_quota', [ intdiv( $quota, Store::MEGABYTE ) ] );

			$limit = Store::chunkBytes( $appData );
			$has = is_array( $file ) === true && ( $file['error'] ?? null ) === UPLOAD_ERR_OK && is_string( $file['tmp_name'] ?? null ) === true && is_int( $file['size'] ?? null ) === true;

			if( $index === 0 ) {

				if( $upload !== '' )
					return self::_fail( 400, 'the first chunk has no upload yet', 'files_chunk' );

				// The first chunk is the chunk of the upload: as long as it is, so is every one after it but the last
				$chunk = $has === true ? $file['size'] : 0;

				if( $chunk > $limit )
					return self::_fail( 400, 'the first chunk is larger than the setting allows', 'files_chunk' );

				if( $chunk < 1 )
					return self::_fail( 400, 'the chunk did not arrive whole', 'files_chunk' );
			}
			else {

				if( preg_match( self::UPLOAD, $upload ) !== 1 || is_file( self::_part( $appData, $upload ) ) === false )
					return self::_fail( 400, 'there is no such upload', 'files_chunk' );

				// The first chunk's length is kept with the part; a part with none is no upload of this protocol
				$chunk = (int) @file_get_contents( self::_size( $appData, $upload ) );

				if( $chunk < 1 || $chunk > $limit )
					return self::_fail( 400, 'there is no such upload', 'files_chunk' );

				clearstatcache( true, self::_part( $appData, $upload ) );

				if( (int) @filesize( self::_part( $appData, $upload ) ) !== $index * $chunk )
					return self::_fail( 400, 'the chunk is not the next of the upload', 'files_chunk' );
			}

			if( $count !== intdiv( $size + $chunk - 1, $chunk ) )
				return self::_fail( 400, 'the number of chunks does not fit the size of the file and the chunk of the upload', 'files_chunk' );

			$length = $index < $count - 1 ? $chunk : $size - ( $count - 1 ) * $chunk;

			if( $has === false )
				return self::_fail( 400, 'the chunk did not arrive whole', 'files_chunk' );

			if( $file['size'] !== $length )
				return self::_fail( 400, $index === $count - 1 && $index > 0 ? 'the last chunk is not what is left of the file' : 'the chunk is not as long as the first one', 'files_chunk' );

			if( $index === 0 ) {

				Store::sweep( $appData );
				\Nino\Filesystem::forceDir( $appData, Store::PARTS );

				do
					$upload = bin2hex( random_bytes( 8 ) );
				while( is_file( self::_part( $appData, $upload ) ) === true );
			}

			$part = self::_part( $appData, $upload );
			$appended = self::_append( $part, (string) $file['tmp_name'], $index * $chunk, $length, $index === 0 );

			// The length of the first chunk stays with the part, for every request after it
			if( $appended === '' && $index === 0 && @file_put_contents( self::_size( $appData, $upload ), (string) $chunk ) === false ) {
				self::_drop( $part );
				return self::_fail( 500, 'the chunk could not be stored', 'files_write' );
			}

			if( $appended !== '' )
				return $appended === 'order' ? self::_fail( 400, 'the chunk is not the next of the upload', 'files_chunk' ) : self::_fail( 500, 'the chunk could not be stored', 'files_write' );

			if( $index < $count - 1 )
				return [ 'part' => [ 'upload' => $upload, 'index' => $index, 'count' => $count ] ];

			return self::_assemble( $appData, $folder, $part, [ 'name' => $name, 'ext' => $ext, 'size' => $size ], $by, $visible, $quota );
		}

		/**
		 *	Append the bytes of a chunk to the part, if the part is as long as it has to be before them
		 *
		 *	@param		string		$part					The path of the part
		 *	@param		string		$source				The path of the chunk php stored
		 *	@param		int				$before				How long the part has to be
		 *	@param		int				$length				How long the chunk is
		 *	@param		bool			$first				Whether this makes the part
		 *
		 *	@return 	string										'' when it is appended; 'order' when the part is not as long as it has to be; 'write' when the bytes could not be written
		 */
		private static function _append( string $part, string $source, int $before, int $length, bool $first ): string {

			$from = @fopen( $source, 'rb' );
			$to = $from === false ? false : @fopen( $part, $first === true ? 'xb' : 'ab' );

			if( $from === false || $to === false ) {

				if( $from !== false )
					fclose( $from );

				return 'write';
			}

			$result = 'write';

			if( flock( $to, LOCK_EX ) === true ) {

				clearstatcache( true, $part );

				if( (int) @filesize( $part ) !== $before )
					$result = 'order';
				else if( @stream_copy_to_stream( $from, $to ) === $length && fflush( $to ) === true )
					$result = '';
				else
					@ftruncate( $to, $before );

				flock( $to, LOCK_UN );
			}

			fclose( $to );
			fclose( $from );

			// A part this request made and could not fill is no part
			if( $result !== '' && $first === true )
				@unlink( $part );

			return $result;
		}

		/**
		 *	The last chunk is in: make the part a file
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$folder
		 *	@param		string		$part
		 *	@param		array 		$name					name, ext and size, as the request said and the checks passed them
		 *	@param		array 		$by
		 *	@param		bool			$visible
		 *	@param		int				$quota
		 *
		 *	@return 	array										See receive()
		 */
		private static function _assemble( array &$appData, array $folder, string $part, array $name, array $by, bool $visible, int $quota ): array {

			clearstatcache( true, $part );

			if( (int) @filesize( $part ) !== $name['size'] ) {
				self::_drop( $part );
				return self::_fail( 400, 'the upload is not as long as the file says', 'files_chunk' );
			}

			$mime = Store::sniff( $part, $name['ext'] );

			if( $mime === '' ) {
				self::_drop( $part );
				return self::_fail( 400, 'the bytes are not what the name says', 'files_type', [], 'name' );
			}

			$sha256 = hash_file( 'sha256', $part );

			if( $sha256 === false ) {
				self::_drop( $part );
				return self::_fail( 500, 'the file could not be read', 'files_write' );
			}

			\Nino\Filesystem::forceDir( $appData, Store::BYTES );

			do
				$id = bin2hex( random_bytes( 6 ) );
			while( Store::file( $appData, $id ) !== null || is_file( (string) Store::bytes( $appData, $id, $name['ext'] ) ) === true );

			$target = (string) Store::bytes( $appData, $id, $name['ext'] );

			if( @rename( $part, $target ) === false ) {
				self::_drop( $part );
				return self::_fail( 500, 'the file could not be stored', 'files_write' );
			}

			@unlink( substr( $part, 0, -5 ). '.chunk' );

			$added = Store::addFile( $appData, $id, [
				'folder'	=> $folder['id'],
				'name'		=> $name['name'],
				'ext'			=> $name['ext'],
				'size'		=> $name['size'],
				'mime'		=> $mime,
				'sha256'	=> $sha256,
				'by'			=> $by,
				'visible'	=> $visible,
				'created'	=> gmdate( Store::TIME ),
			], $quota );

			if( is_string( $added ) === false )
				return [ 'file' => $added ];

			@unlink( $target );

			return match( $added ) {
				'folder'	=> self::_fail( 404, 'there is no such folder', 'files_folder' ),
				'quota'		=> self::_fail( 400, 'the quota of the group is used up', 'files_quota', [ intdiv( $quota, Store::MEGABYTE ) ] ),
				default		=> self::_fail( 500, 'the file could not be stored', 'files_write' ),
			};
		}

		/**
		 *	@param		array 		&$appData
		 *	@param		string		$upload
		 *
		 *	@return 	string										The path of the file that keeps the length of the first chunk of an upload, beside its part (Store::sweep() takes both)
		 */
		private static function _size( array &$appData, string $upload ): string {

			return \Nino\Filesystem::path( $appData, Store::PARTS ). '/'. $upload. '.chunk';
		}

		/**
		 *	Remove a part and the length kept with it
		 *
		 *	@param		string		$part
		 *
		 *	@return 	void
		 */
		private static function _drop( string $part ): void {

			@unlink( $part );
			@unlink( substr( $part, 0, -5 ). '.chunk' );
		}

		/**
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$upload
		 *
		 *	@return 	string										The path of the part of an upload
		 */
		private static function _part( array &$appData, string $upload ): string {

			return \Nino\Filesystem::path( $appData, Store::PARTS ). '/'. $upload. '.part';
		}

		/**
		 *	A posted value as a string: nothing says it is one (a list is posted as one), and a cast of a list is a
		 *	level the runtime treats as fatal
		 *
		 *	@param		mixed			$value
		 *
		 *	@return 	string
		 */
		private static function _text( mixed $value ): string {

			return is_string( $value ) === true ? $value : '';
		}

		/**
		 *	@param		mixed			$value
		 *
		 *	@return 	int|null									A whole number of up to 15 digits, from a number or from digits; null for anything else
		 */
		private static function _number( mixed $value ): ?int {

			if( is_int( $value ) === true )
				return $value >= 0 ? $value : null;

			return is_string( $value ) === true && preg_match( '/^[0-9]{1,15}$/D', $value ) === 1 ? (int) $value : null;
		}

		/**
		 *	@param		int				$status
		 *	@param		string		$error				The sentence for a log, in English
		 *	@param		string		$code
		 *	@param		array 		$params
		 *	@param		string		$field
		 *
		 *	@return 	array										[ 'fail' => [ status, error, code, params, field ] ]
		 */
		private static function _fail( int $status, string $error, string $code, array $params = [], string $field = '' ): array {

			return [ 'fail' => [ $status, $error, $code, $params, $field ] ];
		}
	}
}
