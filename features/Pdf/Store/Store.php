<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Modules\Pdf\Store		What the panel may write: the layout, its pictures, the blocks and the templates
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules\Pdf {

	use Nino\Modules\Pdf as Feature;
	use Nino\Modules\Pdf\Images\Image;
	use Nino\Modules\Pdf\Layout\Allow;
	use Nino\Modules\Pdf\Layout\Engine;

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Store							The checks and the writes behind the panel's actions, so that
	 *										what the panel stores is what \Nino\Modules\Pdf reads back.
	 *
	 *										Every method answers a map: ok, and what it made - or fail, the
	 *										arguments of \Nino\Http::fail() for a refusal a person can cause:
	 *										the status, the English sentence, the code - which the panel's
	 *										text has the sentence of in both languages - its params and the
	 *										field. Every write goes through \Nino\Filesystem::mutate(), or
	 *										putFileContent() for a picture; a file that is replaced is
	 *										replaced whole, and a picture is put in place - and the one it
	 *										replaces deleted - only once the layout that names it is written.
	 *
	 *										The HTML of a block, of a template's own text and of the header and
	 *										the footer is held to the list of \Nino\Modules\Pdf\Layout\Allow
	 *										when it is saved, and what is stored is the text that comes out of
	 *										it. A text may have as many characters as its field takes both as
	 *										it was typed and as it is stored.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Store {

		/**
		 *	One block of a template, rebuilt from what a block of its type may hold
		 *
		 *	With a context ([ library => the keys of the library, format => the paper ]) the checks a
		 *	write needs are made as well - the block of the library is there, the signature box lies on
		 *	the page - and a text of the block's own is held to the list of Allow; without one a block
		 *	is read as it is stored.
		 *
		 *	@param		mixed			$raw
		 *	@param		array|null	$context
		 *
		 *	@return 	array|string								The block, or the code of what is wrong with it
		 */
		public static function block( mixed $raw, ?array $context = null ): array|string {

			if( is_array( $raw ) === false || is_string( $raw['type'] ?? null ) === false || in_array( $raw['type'], Feature::TYPES, true ) === false )
				return 'pdf_block_type';

			$type = $raw['type'];

			if( $type === 'pagebreak' )
				return [ 'type' => 'pagebreak' ];

			if( $type === 'html' ) {

				$ref = is_string( $raw['block'] ?? null ) === true ? $raw['block'] : '';
				$own = is_string( $raw['html'] ?? null ) === true ? $raw['html'] : '';

				if( ( $ref !== '' && $own !== '' ) || ( $ref === '' && $own === '' && $context !== null ) )
					return 'pdf_block_html';

				if( $ref !== '' )
					return preg_match( Feature::SLUG, $ref ) === 1 && ( $context === null || in_array( $ref, $context['library'], true ) === true ) ? [ 'type' => 'html', 'block' => $ref ] : 'pdf_block_ref';

				$own = $context === null ? ( mb_strlen( $own ) > Feature::MAX_HTML ? null : $own ) : self::_html( $own, Feature::MAX_HTML );

				return $own === null ? 'pdf_html_long' : [ 'type' => 'html', 'html' => $own ];
			}

			$name = is_string( $raw['name'] ?? null ) === true ? $raw['name'] : '';

			if( preg_match( Feature::SLUG, $name ) !== 1 )
				return 'pdf_block_name';

			if( $type === 'slot' )
				return [ 'type' => 'slot', 'name' => $name ];

			$label = is_string( $raw['label'] ?? null ) === true ? trim( $raw['label'] ) : '';

			if( mb_strlen( $label ) > Feature::MAX_LABEL )
				return 'pdf_block_label';

			if( $type === 'input' )
				return [ 'type' => 'input', 'name' => $name, 'label' => $label, 'multiline' => ( $raw['multiline'] ?? false ) === true, 'required' => ( $raw['required'] ?? false ) === true ];

			if( in_array( $raw['party'] ?? null, [ 'owner', 'member' ], true ) === false )
				return 'pdf_signature_party';

			$page = $raw['page'] ?? null;
			$page = is_string( $page ) === true && preg_match( '/^[1-9]\d{0,3}$/D', $page ) === 1 ? (int) $page : $page;

			if( $page !== 'last' && ( is_int( $page ) === false || $page < 1 || $page > 9999 ) )
				return 'pdf_signature_page';

			$box = [];

			foreach( [ 'x', 'y', 'w', 'h' ] as $edge ) {

				$number = self::_number( $raw[$edge] ?? null );

				if( $number === null )
					return 'pdf_signature_box';

				$box[$edge] = $number;
			}

			if( $context !== null && self::_lies( $box, $context['format'] ) === false )
				return 'pdf_signature_box';

			return [ 'type' => 'signature', 'name' => $name, 'party' => $raw['party'], 'label' => $label, 'page' => $page ] + $box + [ 'line' => ( $raw['line'] ?? false ) === true ];
		}

		/**
		 *	A template as the editor posts it, checked as a write would: the name, a tag of the project's
		 *	list, at most 60 blocks, each block as block() checks it with a context
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$data					name, tag, blocks
		 *
		 *	@return 	array											[ ok, template ] or [ fail ]
		 */
		public static function template( array &$appData, array $data ): array {

			$name = trim( \Nino\Admin\Admin::postString( $data, 'name' ) );

			if( $name === '' || mb_strlen( $name ) > Feature::MAX_TITLE )
				return self::_fail( 400, 'the name has 1 to '. Feature::MAX_TITLE. ' characters', 'pdf_title', [ Feature::MAX_TITLE ], 'name' );

			$tag = \Nino\Admin\Admin::postString( $data, 'tag' );

			if( in_array( $tag, Feature::tags( $appData ), true ) === false )
				return self::_fail( 400, 'there is no such tag', 'pdf_tag', [], 'tag' );

			$posted = is_array( $data['blocks'] ?? null ) === true && array_is_list( $data['blocks'] ) === true ? $data['blocks'] : [];

			if( count( $posted ) > Feature::MAX_BLOCKS )
				return self::_fail( 400, 'a template has at most '. Feature::MAX_BLOCKS. ' blocks', 'pdf_blocks_many', [ Feature::MAX_BLOCKS ], 'blocks' );

			$context = [ 'library' => array_keys( Feature::blocks( $appData ) ), 'format' => Feature::layout( $appData )['format'] ];
			$blocks = [];

			foreach( $posted as $at => $block ) {

				$clean = self::block( $block, $context );

				if( is_string( $clean ) === true )
					return self::_fail( 400, 'block '. ( $at + 1 ). ' is wrong: '. $clean, $clean, $clean === 'pdf_html_long' ? [ Feature::MAX_HTML ] : [ $at + 1 ], 'blocks' );

				$blocks[] = $clean;
			}

			return [ 'ok' => true, 'template' => [ 'name' => $name, 'tag' => $tag, 'blocks' => $blocks ] ];
		}

		/**
		 *	Write one template, new or replaced whole
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$data					key, isNew, name, tag, blocks
		 *
		 *	@return 	array											[ ok, key ] or [ fail ]
		 */
		public static function saveTemplate( array &$appData, array $data ): array {

			$key = \Nino\Admin\Admin::postString( $data, 'key' );
			$new = ( $data['isNew'] ?? false ) === true;

			if( preg_match( Feature::SLUG, $key ) !== 1 )
				return self::_fail( 400, 'a key is a slug of 2 to 40 characters: lower case letters, digits and hyphens, starting with a letter', 'pdf_key', [], 'key' );

			if( $new === false && Feature::template( $appData, $key ) === null )
				return self::_fail( 404, 'there is no such template', 'pdf_template_missing' );

			$checked = self::template( $appData, $data );

			if( isset( $checked['fail'] ) === true )
				return $checked;

			$refused = false;

			$written = \Nino\Filesystem::mutate( $appData, Feature::TEMPLATES. '/'. $key. '.php', static function( mixed $state ) use ( $new, $checked, &$refused ): ?array {

				if( $new === true && is_array( $state ) === true ) {
					$refused = true;
					return null;
				}

				return $checked['template'];
			}, null );

			if( $refused === true )
				return self::_fail( 400, 'a template with this key exists already', 'pdf_key_taken', [], 'key' );

			return $written === true ? [ 'ok' => true, 'key' => $key ] : self::_fail( 500, 'the template could not be written' );
		}

		/**
		 *	Make a copy of a template under a key of its own
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$key					The template to copy
		 *	@param		string		$name					What the copy is called: the caller's word for a copy, in the language of the person who asks - there is no default, for a default would be a word of the server's
		 *
		 *	@return 	array											[ ok, key ] or [ fail ]
		 */
		public static function duplicateTemplate( array &$appData, string $key, string $name ): array {

			$source = Feature::template( $appData, $key );

			if( $source === null )
				return self::_fail( 404, 'there is no such template', 'pdf_template_missing' );

			$name = trim( $name );

			if( $name === '' )
				return self::_fail( 400, 'the copy needs a name of 1 to '. Feature::MAX_TITLE. ' characters', 'pdf_title', [ Feature::MAX_TITLE ], 'name' );

			$source['name'] = mb_substr( $name, 0, Feature::MAX_TITLE );

			for( $number = 1; $number < 1000; $number++ ) {

				$suffix = $number === 1 ? '-copy' : '-copy-'. $number;
				$copy = substr( $key, 0, 40 - strlen( $suffix ) ). $suffix;

				if( Feature::template( $appData, $copy ) !== null )
					continue;

				$taken = false;

				$written = \Nino\Filesystem::mutate( $appData, Feature::TEMPLATES. '/'. $copy. '.php', static function( mixed $state ) use ( $source, &$taken ): ?array {

					if( is_array( $state ) === true ) {
						$taken = true;
						return null;
					}

					return $source;
				}, null );

				if( $taken === true )
					continue;

				return $written === true ? [ 'ok' => true, 'key' => $copy ] : self::_fail( 500, 'the template could not be written' );
			}

			return self::_fail( 400, 'there are too many copies of this template', 'pdf_key_taken', [], 'key' );
		}

		/**
		 *	Remove one template
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$key
		 *
		 *	@return 	array											[ ok ] or [ fail ]
		 */
		public static function deleteTemplate( array &$appData, string $key ): array {

			if( Feature::template( $appData, $key ) === null )
				return self::_fail( 404, 'there is no such template', 'pdf_template_missing' );

			$path = \Nino\Filesystem::path( $appData, Feature::TEMPLATES. '/'. $key. '.php' );
			@unlink( $path );
			clearstatcache( true, $path );

			return is_file( $path ) === false ? [ 'ok' => true ] : self::_fail( 500, 'the template could not be removed' );
		}

		/**
		 *	Write one block of the library, new or replaced
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$data					key, isNew, title, html
		 *
		 *	@return 	array											[ ok, key, html ] or [ fail ] - html is the text as it is stored, held to the list of Allow
		 */
		public static function saveBlock( array &$appData, array $data ): array {

			$key = \Nino\Admin\Admin::postString( $data, 'key' );
			$new = ( $data['isNew'] ?? false ) === true;
			$title = trim( \Nino\Admin\Admin::postString( $data, 'title' ) );
			$html = \Nino\Admin\Admin::postString( $data, 'html' );

			if( preg_match( Feature::SLUG, $key ) !== 1 )
				return self::_fail( 400, 'a key is a slug of 2 to 40 characters: lower case letters, digits and hyphens, starting with a letter', 'pdf_key', [], 'key' );

			if( $title === '' || mb_strlen( $title ) > Feature::MAX_TITLE )
				return self::_fail( 400, 'the title has 1 to '. Feature::MAX_TITLE. ' characters', 'pdf_title', [ Feature::MAX_TITLE ], 'title' );

			$html = self::_html( $html, Feature::MAX_HTML );

			if( $html === null )
				return self::_fail( 400, 'the text has at most '. Feature::MAX_HTML. ' characters', 'pdf_html_long', [ Feature::MAX_HTML ], 'html' );

			$refusal = null;

			$written = \Nino\Filesystem::mutate( $appData, Feature::BLOCKS, static function( mixed $state ) use ( $key, $new, $title, $html, &$refusal ): ?array {

				$blocks = is_array( $state ) === true ? $state : [];

				if( $new === true && isset( $blocks[$key] ) === true )
					$refusal = self::_fail( 400, 'a block with this key exists already', 'pdf_key_taken', [], 'key' );
				else if( $new === false && isset( $blocks[$key] ) === false )
					$refusal = self::_fail( 404, 'there is no such block', 'pdf_block_missing' );

				if( $refusal !== null )
					return null;

				$blocks[$key] = [ 'title' => $title, 'html' => $html ];

				return $blocks;
			}, [] );

			if( $refusal !== null )
				return $refusal;

			return $written === true ? [ 'ok' => true, 'key' => $key, 'html' => $html ] : self::_fail( 500, 'the block could not be written' );
		}

		/**
		 *	Remove one block of the library - unless a template uses it
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$key
		 *
		 *	@return 	array											[ ok ] or [ fail ]
		 */
		public static function deleteBlock( array &$appData, string $key ): array {

			$refusal = null;

			$written = \Nino\Filesystem::mutate( $appData, Feature::BLOCKS, static function( mixed $state, array &$appData ) use ( $key, &$refusal ): ?array {

				$blocks = is_array( $state ) === true ? $state : [];

				if( isset( $blocks[$key] ) === false ) {
					$refusal = self::_fail( 404, 'there is no such block', 'pdf_block_missing' );
					return null;
				}

				$using = [];

				foreach( Feature::keys( $appData ) as $template ) {

					$loaded = Feature::template( $appData, $template );

					if( $loaded !== null && count( array_filter( $loaded['blocks'], static fn( array $block ): bool => ( $block['block'] ?? null ) === $key ) ) > 0 )
						$using[] = $loaded['name'];
				}

				if( $using !== [] ) {
					$refusal = self::_fail( 409, 'templates use this block: '. implode( ', ', $using ), 'pdf_block_used', [ implode( ', ', $using ) ] );
					return null;
				}

				unset( $blocks[$key] );

				return $blocks;
			}, [] );

			if( $refusal !== null )
				return $refusal;

			return $written === true ? [ 'ok' => true ] : self::_fail( 500, 'the block could not be removed' );
		}

		/**
		 *	Write the layout's own values; the names of its two pictures stay as they are. The header and
		 *	the footer are held to the list of Allow. A change of the paper is refused, with the names of
		 *	the templates, while a signature box of a template would lie outside the new page.
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$data					format, margins, font, size, header, footer
		 *
		 *	@return 	array											[ ok ] or [ fail ]
		 */
		public static function saveLayout( array &$appData, array $data ): array {

			$format = \Nino\Admin\Admin::postString( $data, 'format' );
			$font = \Nino\Admin\Admin::postString( $data, 'font' );
			$margins = [];

			if( array_key_exists( $format, Engine::PAPER ) === false )
				return self::_fail( 400, 'the paper is a4 or letter', 'pdf_format', [], 'format' );

			if( in_array( $font, \Nino\Modules\Pdf\Fonts\Metrics::FAMILIES, true ) === false )
				return self::_fail( 400, 'the font is helvetica, times or courier', 'pdf_font', [], 'font' );

			$size = \Nino\Admin\Admin::cleanInt( $data['size'] ?? null, [ 'min' => Feature::SIZE_MIN, 'max' => Feature::SIZE_MAX ] );

			if( $size === null )
				return self::_fail( 400, 'the size is a whole number from '. Feature::SIZE_MIN. ' to '. Feature::SIZE_MAX, 'pdf_size', [ Feature::SIZE_MIN, Feature::SIZE_MAX ], 'size' );

			foreach( is_array( $data['margins'] ?? null ) === true && array_is_list( $data['margins'] ) === true && count( $data['margins'] ) === 4 ? $data['margins'] : [] as $margin ) {

				$clean = \Nino\Admin\Admin::cleanInt( $margin, [ 'min' => Feature::MARGIN_MIN, 'max' => Feature::MARGIN_MAX ] );

				if( $clean !== null )
					$margins[] = $clean;
			}

			if( count( $margins ) !== 4 )
				return self::_fail( 400, 'a margin is a whole number of millimetres from '. Feature::MARGIN_MIN. ' to '. Feature::MARGIN_MAX, 'pdf_margin', [ Feature::MARGIN_MIN, Feature::MARGIN_MAX ], 'margins' );

			$bands = [];

			foreach( [ 'header', 'footer' ] as $band ) {

				$bands[$band] = self::_html( \Nino\Admin\Admin::postString( $data, $band ), Feature::MAX_BAND );

				if( $bands[$band] === null )
					return self::_fail( 400, 'the '. $band. ' has at most '. Feature::MAX_BAND. ' characters', 'pdf_band_long', [ Feature::MAX_BAND ], $band );
			}

			// The paper is the templates' as well: it is not changed while a signature box of a template would lie off the new page
			$unfit = $format === Feature::layout( $appData )['format'] ? [] : self::_unfit( $appData, $format );

			if( $unfit !== [] )
				return self::_fail( 409, 'signature boxes of these templates would lie outside the page: '. implode( ', ', $unfit ), 'pdf_format_boxes', [ implode( ', ', $unfit ) ], 'format' );

			$written = \Nino\Filesystem::mutate( $appData, Feature::LAYOUT, static function( mixed $state ) use ( $format, $font, $size, $margins, $bands ): array {

				$state = is_array( $state ) === true ? $state : [];

				return [ 'format' => $format, 'margins' => $margins, 'font' => $font, 'size' => $size ] + $bands + [ 'logo' => $state['logo'] ?? '', 'signature' => $state['signature'] ?? '' ];
			}, [] );

			return $written === true ? [ 'ok' => true ] : self::_fail( 500, 'the layout could not be written' );
		}

		/**
		 *	Take a picture in as the logo or the owner's signature: checked the way an upload to a gallery
		 *	is - the kernel's refusals, the size of a picture at most 4000 by 4000 - a PNG or a JPEG, kept
		 *	as logo.png or signature.jpg. The bytes are written under a name of their own first; the layout
		 *	names the picture once it is written, and only then is the file put in its place - over the one
		 *	of the same name, if there is one - and the file it replaces under another name removed. A
		 *	layout that cannot be written leaves the pictures as they were.
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$kind					logo or signature
		 *	@param		string		$bytes
		 *
		 *	@return 	array											[ ok, file ] or [ fail ]
		 */
		public static function saveImage( array &$appData, string $kind, string $bytes ): array {

			if( in_array( $kind, [ 'logo', 'signature' ], true ) === false )
				return self::_fail( 400, 'a picture is the logo or the signature', 'pdf_image_kind', [], 'kind' );

			$rejected = \Nino\Images::reject( $bytes );

			if( $rejected !== null )
				return self::_fail( 400, match( $rejected['code'] ) {
					'image_too_large'	=> 'the file is larger than the kernel takes ('. $rejected['params'][0]. ' MB)',
					'image_type'			=> 'the file is not a PNG or JPEG image',
					default						=> 'the image has more than '. $rejected['params'][0]. ' megapixels',
				}, $rejected['code'], $rejected['params'], 'file' );

			$size = \Nino\Images::size( $bytes );

			if( $size === false || in_array( $size['type'], [ IMAGETYPE_PNG, IMAGETYPE_JPEG ], true ) === false )
				return self::_fail( 400, 'the file is not a PNG or JPEG image', 'pdf_image_type', [], 'file' );

			if( $size['width'] > Image::MAX_SIDE || $size['height'] > Image::MAX_SIDE )
				return self::_fail( 400, 'the picture is at most '. Image::MAX_SIDE. ' by '. Image::MAX_SIDE. ' pixels', 'pdf_image_pixels', [ Image::MAX_SIDE ], 'file' );

			if( Image::fromBytes( $bytes ) === null )
				return self::_fail( 400, 'the picture cannot be placed in a PDF (a CMYK JPEG, for one)', 'pdf_image_place', [], 'file' );

			$file = $kind. ( $size['type'] === IMAGETYPE_PNG ? '.png' : '.jpg' );
			$before = (string) ( Feature::layout( $appData )[$kind] ?? '' );
			$pending = Feature::IMAGES. '/'. $kind. '-'. bin2hex( random_bytes( 6 ) ). '.part';

			// A name nobody else writes and nothing reads: there is no lock to take for it - and none left behind in /data/.locks
			if( \Nino\Filesystem::putFileContent( $appData, $pending, $bytes, true ) === false )
				return self::_fail( 500, 'the picture could not be written' );

			$from = \Nino\Filesystem::path( $appData, $pending );

			// Nothing names it yet: the file goes again, and the picture of the same name, if any, is as it was
			if( self::_name( $appData, $kind, $file ) === false ) {
				@unlink( $from );
				return self::_fail( 500, 'the layout could not be written' );
			}

			if( @rename( $from, \Nino\Filesystem::path( $appData, Feature::IMAGES. '/'. $file ) ) === false ) {
				self::_name( $appData, $kind, $before );
				@unlink( $from );
				return self::_fail( 500, 'the picture could not be written' );
			}

			if( $before !== '' && $before !== $file )
				self::_remove( $appData, $before );

			return [ 'ok' => true, 'file' => $file ];
		}

		/**
		 *	Take the logo or the signature away
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$kind
		 *
		 *	@return 	array											[ ok ] or [ fail ]
		 */
		public static function deleteImage( array &$appData, string $kind ): array {

			if( in_array( $kind, [ 'logo', 'signature' ], true ) === false )
				return self::_fail( 400, 'a picture is the logo or the signature', 'pdf_image_kind', [], 'kind' );

			$before = (string) ( Feature::layout( $appData )[$kind] ?? '' );

			if( self::_name( $appData, $kind, '' ) === false )
				return self::_fail( 500, 'the layout could not be written' );

			if( $before !== '' )
				self::_remove( $appData, $before );

			return [ 'ok' => true ];
		}

		/**
		 *	A text a person typed, held to the list of Allow - or null where it has more characters than
		 *	the field takes, as it was typed (a text this long is not parsed) or as it is stored
		 *
		 *	@param		string		$html
		 *	@param		int				$max
		 *
		 *	@return 	string|null
		 */
		private static function _html( string $html, int $max ): ?string {

			if( mb_strlen( $html ) > $max )
				return null;

			$clean = Allow::html( $html );

			return mb_strlen( $clean ) > $max ? null : $clean;
		}

		/**
		 *	Whether a box lies on the page of a paper: inside it, with a width and a height
		 *
		 *	@param		array 		$box					x, y, w, h in millimetres
		 *	@param		string		$format				A key of the engine's papers
		 *
		 *	@return 	bool
		 */
		private static function _lies( array $box, string $format ): bool {

			[ $width, $height ] = Engine::PAPER[$format];

			return $box['x'] >= 0 && $box['y'] >= 0 && $box['w'] > 0 && $box['h'] > 0 && $box['x'] + $box['w'] <= $width + 0.001 && $box['y'] + $box['h'] <= $height + 0.001;
		}

		/**
		 *	The names of the templates with a signature box that does not lie on the page of a paper
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$format
		 *
		 *	@return 	array											The names, in the order of the keys
		 */
		private static function _unfit( array &$appData, string $format ): array {

			$names = [];

			foreach( Feature::keys( $appData ) as $key ) {

				$template = Feature::template( $appData, $key );

				if( $template !== null && count( array_filter( $template['blocks'], static fn( array $block ): bool => $block['type'] === 'signature' && self::_lies( $block, $format ) === false ) ) > 0 )
					$names[] = $template['name'];
			}

			return $names;
		}

		/**
		 *	The layout names a picture - or none
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$kind
		 *	@param		string		$file
		 *
		 *	@return 	bool
		 */
		private static function _name( array &$appData, string $kind, string $file ): bool {

			return \Nino\Filesystem::mutate( $appData, Feature::LAYOUT, static function( mixed $state ) use ( $kind, $file ): array {

				$state = is_array( $state ) === true ? $state : [];
				$state[$kind] = $file;

				return $state;
			}, [] );
		}

		/**
		 *	Delete a picture of the layout: a name of the shape the feature writes, in its own directory
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$file
		 *
		 *	@return 	void
		 */
		private static function _remove( array &$appData, string $file ): void {

			if( preg_match( Feature::PICTURE, $file ) === 1 )
				@unlink( \Nino\Filesystem::path( $appData, Feature::IMAGES. '/'. $file ) );
		}

		/**
		 *	A refusal, as the arguments of \Nino\Http::fail()
		 *
		 *	@param		int				$status
		 *	@param		string		$error
		 *	@param		string		$code
		 *	@param		array 		$params
		 *	@param		string		$field
		 *
		 *	@return 	array
		 */
		private static function _fail( int $status, string $error, string $code = '', array $params = [], string $field = '' ): array {

			return [ 'fail' => [ $status, $error, $code, $params, $field ] ];
		}

		/**
		 *	A number of millimetres with one decimal, or null for anything else
		 *
		 *	@param		mixed			$value
		 *
		 *	@return 	float|null
		 */
		private static function _number( mixed $value ): ?float {

			if( is_string( $value ) === true && preg_match( '/^-?\d{1,4}(?:\.\d+)?$/D', trim( $value ) ) === 1 )
				$value = (float) $value;

			return ( is_int( $value ) === true || is_float( $value ) === true ) && is_finite( (float) $value ) === true && abs( (float) $value ) < 10000 ? round( (float) $value, 1 ) : null;
		}
	}
}
