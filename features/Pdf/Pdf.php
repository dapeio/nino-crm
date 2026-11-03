<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Modules\Pdf					A PDF builder and the render API of the Pdf feature
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules {

	use Nino\Modules\Pdf\Document\Document;
	use Nino\Modules\Pdf\Images\Image;
	use Nino\Modules\Pdf\Layout\Engine;
	use Nino\Modules\Pdf\Layout\Tokens;

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Pdf								PDF files written by the feature itself - no library: a layout
	 *										the owner sets once, a library of text blocks and templates
	 *										composed of them, and render(), which turns a template and what
	 *										a caller knows into the bytes of a PDF.
	 *
	 *										The data is the project's, below /data/pdf/: layout.php (the
	 *										paper, the margins, the font and size, the header and footer
	 *										HTML, the file names of the logo and of the owner's signature),
	 *										blocks.php (key => title and html), templates/<key>.php (a name,
	 *										a tag of the project's list and the blocks of the template, see
	 *										template()) and images/ (the logo and the signature).
	 *
	 *										The API, all of it static:
	 *
	 *										  render( $appData, $template, $data, $options )   the bytes, or null
	 *										  templates( $appData, $tag )                      key => name, tag
	 *										  fields( $appData, $template )                    the signature blocks
	 *										  inputs( $appData, $template )                    the input blocks
	 *										  tags( $appData )                                 the project's tags
	 *										  layout( $appData )                               the layout, defaults in
	 *
	 *										A document is laid out by \Nino\Modules\Pdf\Layout\Engine from the
	 *										HTML of its blocks - a small subset, see \Nino\Modules\Pdf\Layout\Html -
	 *										and written by \Nino\Modules\Pdf\Document\Document, with the
	 *										standard fonts and no library. Two renders of the same template
	 *										with the same arguments are the same bytes.
	 *
	 *										The one thing init() registers is a callback that lets the
	 *										workbench's own responses frame a blob: address, which is how the
	 *										panel shows a preview. Nothing of it reaches a visitor, and the
	 *										feature has no route and puts nothing on the site.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Pdf {

		public const string KEY = 'pdf';

		// What the feature keeps, below /data/ (the manifest names the directory)
		public const string DIR = '/data/pdf';
		public const string LAYOUT = '/data/pdf/layout.php';
		public const string BLOCKS = '/data/pdf/blocks.php';
		public const string TEMPLATES = '/data/pdf/templates';
		public const string IMAGES = '/data/pdf/images';

		// A key of a template or of a block, and a tag: a slug of 2 to 40 characters
		public const string SLUG = '/^[a-z][a-z0-9-]{1,39}$/D';

		// What a layout holds when nothing is saved: the paper, the margins in mm (top, right, bottom, left), the font, the base size in pt
		public const array DEFAULTS = [
			'format'		=> 'a4',
			'margins'		=> [ 20, 20, 20, 20 ],
			'font'			=> 'helvetica',
			'size'			=> 10,
			'header'		=> '',
			'footer'		=> '',
			'logo'			=> '',
			'signature'	=> '',
		];

		public const int MARGIN_MIN = 5;
		public const int MARGIN_MAX = 50;
		public const int SIZE_MIN = 8;
		public const int SIZE_MAX = 14;
		public const int MAX_BAND = 4000;
		public const int MAX_TITLE = 120;
		public const int MAX_HTML = 20000;
		public const int MAX_LABEL = 120;
		public const int MAX_BLOCKS = 60;

		// The types of a template's blocks
		public const array TYPES = [ 'html', 'slot', 'input', 'signature', 'pagebreak' ];

		// The file a picture of the layout is kept in: logo.png, signature.jpg
		public const string PICTURE = '/^(logo|signature)\.(png|jpg)$/D';

		/**
		 *	Registers what the workbench needs to show a preview, and nothing else
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	void
		 */
		public static function init( array &$appData ): void {

			\Nino\Callbacks::registerCallback( $appData, '/nino/http/output', [ self::class, 'callbackOutput' ] );
		}

		/**
		 *	Let the workbench frame the blob: address a preview is shown at. The policy of the workbench
		 *	has no frame-src, so its default-src 'self' refuses the frame - a browser does not count
		 *	blob: as 'self' - and the preview stays empty. frame-src is widened by that one scheme, for
		 *	the responses of /_admin and below alone; a page of the site keeps its policy byte for byte
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current request and its response
		 *
		 *	@return 	void
		 */
		public static function callbackOutput( array &$appData, array &$request ): void {

			foreach( [ $request['/nino/http/request']['uri'] ?? '', $request['/nino/http/response']['uri'] ?? '' ] as $uri )
				if( is_string( $uri ) === true && ( $uri === '/_admin' || str_starts_with( $uri, '/_admin/' ) === true ) ) {
					\Nino\Http::allowSource( $request, 'frame-src', 'blob:' );
					return;
				}
		}

		/**
		 *	The panel, while the feature is active
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array										Panel class names
		 */
		public static function adminPanels( array &$appData ): array {
			return [ \Nino\Modules\Pdf\Admin::class ];
		}

		/**
		 *	The bytes of a PDF
		 *
		 *	$data: tokens (name => string, nested arrays allowed, what [[name]] and [[a.b]] stand for -
		 *	text, whatever it holds), slots (name => html, what a slot block holds - held to the list of
		 *	\Nino\Modules\Pdf\Layout\Allow when the document is laid out, as a text a person saved is),
		 *	inputs (name => text, what an input block holds). $options: signatures (name => the bytes
		 *	of a PNG or JPEG or the absolute path of one, drawn in the box of the signature block of that
		 *	name), title, date (Y-m-d: the only date the file names), append (HTML, held to the list of
		 *	\Nino\Modules\Pdf\Layout\Allow like a slot: laid out after the last block on a page of its
		 *	own, with the header and the footer of the layout, and counted by [[pages]]; the signature
		 *	fields stay on the pages of the template - the last page is the last before it - and
		 *	nothing is added where it is empty or holds nothing to draw).
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$template			The key of a template
		 *	@param		array 		$data
		 *	@param		array 		$options
		 *
		 *	@return 	string|null									Null, with an E_USER_WARNING that says why, for a template that is not there
		 */
		public static function render( array &$appData, string $template, array $data = [], array $options = [] ): ?string {

			$loaded = self::template( $appData, $template );

			if( $loaded === null ) {
				trigger_error( 'Pdf::render(): there is no template "'. $template. '"', E_USER_WARNING );
				return null;
			}

			return self::compose( $appData, $loaded, $data, $options, false );
		}

		/**
		 *	The templates, by key, sorted by name
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string|null	$tag					Only the templates of this tag
		 *
		 *	@return 	array											key => [ name, tag ]
		 */
		public static function templates( array &$appData, ?string $tag = null ): array {

			$list = [];

			foreach( self::keys( $appData ) as $key ) {

				$template = self::template( $appData, $key );

				if( $template !== null && ( $tag === null || $template['tag'] === $tag ) )
					$list[$key] = [ 'name' => $template['name'], 'tag' => $template['tag'] ];
			}

			uksort( $list, static fn( string $a, string $b ): int => [ mb_strtolower( $list[$a]['name'] ), $a ] <=> [ mb_strtolower( $list[$b]['name'] ), $b ] );

			return $list;
		}

		/**
		 *	The signature blocks of a template, in order
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$template
		 *
		 *	@return 	array											name, party, label, page, x, y, w, h, line each; [] for a template that is not there
		 */
		public static function fields( array &$appData, string $template ): array {

			$fields = [];

			foreach( self::template( $appData, $template )['blocks'] ?? [] as $block )
				if( $block['type'] === 'signature' )
					$fields[] = array_diff_key( $block, [ 'type' => 0 ] );

			return $fields;
		}

		/**
		 *	The input blocks of a template, in order
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$template
		 *
		 *	@return 	array											name, label, multiline, required each; [] for a template that is not there
		 */
		public static function inputs( array &$appData, string $template ): array {

			$inputs = [];

			foreach( self::template( $appData, $template )['blocks'] ?? [] as $block )
				if( $block['type'] === 'input' )
					$inputs[] = array_diff_key( $block, [ 'type' => 0 ] );

			return $inputs;
		}

		/**
		 *	The tags of the project, which the setting lists - the lines that are slugs
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array											A list of slugs
		 */
		public static function tags( array &$appData ): array {

			return array_values( array_filter( (array) \Nino\Features::setting( $appData, self::KEY, 'tags', [] ), static fn( mixed $tag ): bool => is_string( $tag ) === true && preg_match( self::SLUG, $tag ) === 1 ) );
		}

		/**
		 *	The layout with its defaults filled in: what is saved where it is valid, the default where it is not
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array											format, margins, font, size, header, footer, logo, signature
		 */
		public static function layout( array &$appData ): array {

			$stored = \Nino\Filesystem::getFileContent( $appData, self::LAYOUT, [] );
			$stored = is_array( $stored ) === true ? $stored : [];
			$layout = self::DEFAULTS;

			if( in_array( $stored['format'] ?? null, array_keys( Engine::PAPER ), true ) === true )
				$layout['format'] = $stored['format'];

			if( in_array( $stored['font'] ?? null, \Nino\Modules\Pdf\Fonts\Metrics::FAMILIES, true ) === true )
				$layout['font'] = $stored['font'];

			if( is_int( $stored['size'] ?? null ) === true && $stored['size'] >= self::SIZE_MIN && $stored['size'] <= self::SIZE_MAX )
				$layout['size'] = $stored['size'];

			if( is_array( $stored['margins'] ?? null ) === true && count( $stored['margins'] ) === 4 && array_is_list( $stored['margins'] ) === true
				&& count( array_filter( $stored['margins'], static fn( mixed $mm ): bool => is_int( $mm ) === true && $mm >= self::MARGIN_MIN && $mm <= self::MARGIN_MAX ) ) === 4 )
				$layout['margins'] = $stored['margins'];

			foreach( [ 'header', 'footer' ] as $band )
				if( is_string( $stored[$band] ?? null ) === true && mb_strlen( $stored[$band] ) <= self::MAX_BAND )
					$layout[$band] = $stored[$band];

			foreach( [ 'logo', 'signature' ] as $kind )
				if( is_string( $stored[$kind] ?? null ) === true && preg_match( self::PICTURE, $stored[$kind], $found ) === 1 && $found[1] === $kind )
					$layout[$kind] = $stored[$kind];

			return $layout;
		}

		/**
		 *	The text blocks of the library
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array											key => [ title, html ], in the order of the file
		 */
		public static function blocks( array &$appData ): array {

			$stored = \Nino\Filesystem::getFileContent( $appData, self::BLOCKS, [] );
			$blocks = [];

			foreach( is_array( $stored ) === true ? $stored : [] as $key => $block )
				if( is_string( $key ) === true && preg_match( self::SLUG, $key ) === 1 && is_array( $block ) === true && is_string( $block['title'] ?? null ) === true && is_string( $block['html'] ?? null ) === true )
					$blocks[$key] = [ 'title' => $block['title'], 'html' => $block['html'] ];

			return $blocks;
		}

		/**
		 *	The keys of the templates there are, sorted
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array
		 */
		public static function keys( array &$appData ): array {

			$dir = \Nino\Filesystem::path( $appData, self::TEMPLATES );
			$keys = [];

			foreach( is_dir( $dir ) === true ? ( @scandir( $dir ) ?: [] ) : [] as $file )
				if( str_ends_with( $file, '.php' ) === true && preg_match( self::SLUG, substr( $file, 0, -4 ) ) === 1 && is_file( $dir. '/'. $file ) === true && is_link( $dir. '/'. $file ) === false )
					$keys[] = substr( $file, 0, -4 );

			return $keys;
		}

		/**
		 *	One template as the file holds it, each block rebuilt from what it may hold.
		 *
		 *	The template is [ name, tag, blocks ]. A block is [ type => ... ] of one of TYPES:
		 *
		 *	  html       block (the key of a block of the library) or html (a text of its own) - never both
		 *	  slot       name
		 *	  input      name, label, multiline, required
		 *	  signature  name, party (owner or member), label, page (last, or a number from 1),
		 *	             x, y, w, h (mm, one decimal), line
		 *	  pagebreak
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$key
		 *
		 *	@return 	array|null								Null for a key that is none or a file that is not a template
		 */
		public static function template( array &$appData, string $key ): ?array {

			if( preg_match( self::SLUG, $key ) !== 1 )
				return null;

			$path = \Nino\Filesystem::path( $appData, self::TEMPLATES. '/'. $key. '.php' );

			// is_file() first: reading a .php file through the Filesystem is an include
			if( $path === '' || is_file( $path ) === false || is_link( $path ) === true )
				return null;

			$raw = \Nino\Filesystem::getFileContent( $appData, self::TEMPLATES. '/'. $key. '.php', null );

			if( is_array( $raw ) === false || is_string( $raw['name'] ?? null ) === false || is_string( $raw['tag'] ?? null ) === false )
				return null;

			$blocks = [];

			foreach( is_array( $raw['blocks'] ?? null ) === true ? $raw['blocks'] : [] as $block ) {

				$clean = \Nino\Modules\Pdf\Store::block( $block );

				if( is_array( $clean ) === true )
					$blocks[] = $clean;
			}

			return [ 'name' => $raw['name'], 'tag' => $raw['tag'], 'blocks' => $blocks ];
		}

		/**
		 *	The bytes of a layout picture, '' where there is none
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$kind					logo or signature
		 *
		 *	@return 	string
		 */
		public static function picture( array &$appData, string $kind ): string {

			$file = self::layout( $appData )[$kind] ?? '';

			return is_string( $file ) === true && $file !== '' ? (string) \Nino\Filesystem::getFileContent( $appData, self::IMAGES. '/'. $file, '' ) : '';
		}

		/**
		 *	A template, as it stands, made into a PDF
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$template			See template()
		 *	@param		array 		$data					See render()
		 *	@param		array 		$options			See render()
		 *	@param		bool			$preview			Sample data where the caller has none: [name] for a token, the label for an input, a table for a slot, the sample text for a document without a body
		 *
		 *	@return 	string
		 */
		public static function compose( array &$appData, array $template, array $data, array $options, bool $preview ): string {

			$layout = self::layout( $appData );
			$library = self::blocks( $appData );
			$tokens = is_array( $data['tokens'] ?? null ) === true ? $data['tokens'] : [];
			$slots = is_array( $data['slots'] ?? null ) === true ? $data['slots'] : [];
			$inputs = is_array( $data['inputs'] ?? null ) === true ? $data['inputs'] : [];
			$signatures = is_array( $options['signatures'] ?? null ) === true ? $options['signatures'] : [];
			$parts = [];
			$fields = [];
			$owner = false;

			foreach( $template['blocks'] as $block ) {

				switch( $block['type'] ) {

					case 'html':
						$html = isset( $block['block'] ) === true ? (string) ( $library[ $block['block'] ]['html'] ?? '' ) : (string) ( $block['html'] ?? '' );
						$parts[] = [ 'k' => 'html', 'html' => Tokens::fill( $appData, $html, $tokens, $preview ) ];
						break;

					case 'slot':
						$html = $preview === true ? \Nino\Feature::template( $appData, '/features/Pdf/templates', 'preview-slot' ) : ( is_string( $slots[ $block['name'] ] ?? null ) === true ? $slots[ $block['name'] ] : '' );
						$parts[] = [ 'k' => 'html', 'html' => $html ];
						break;

					case 'input':
						$text = is_string( $inputs[ $block['name'] ] ?? null ) === true ? $inputs[ $block['name'] ] : '';
						$parts[] = [ 'k' => 'text', 'text' => $preview === true ? '['. $block['label']. ']' : $text, 'multiline' => $block['multiline'] ];
						break;

					case 'signature':
						$image = self::_picture( $signatures[ $block['name'] ] ?? null );

						// The owner's signature is decoded once, however many fields of the owner there are
						if( $image === null && $block['party'] === 'owner' ) {
							$owner = $owner === false ? Image::fromBytes( self::picture( $appData, 'signature' ) ) : $owner;
							$image = $owner;
						}

						if( $block['page'] === 'last' )
							$parts[] = [ 'k' => 'mark' ];

						$fields[] = [ 'page' => $block['page'], 'x' => $block['x'], 'y' => $block['y'], 'w' => $block['w'], 'h' => $block['h'], 'line' => $block['line'], 'label' => $block['label'], 'image' => $image ];
						break;

					case 'pagebreak':
						$parts[] = [ 'k' => 'break' ];
						break;
				}
			}

			// A preview of a template with no body shows the layout around some text
			if( $preview === true && array_filter( $parts, static fn( array $part ): bool => $part['k'] !== 'break' ) === [] )
				$parts[] = [ 'k' => 'html', 'html' => \Nino\Feature::template( $appData, '/features/Pdf/templates', 'preview-sample' ) ];

			// The part a caller appends: its own page after the last block, whatever the blocks were
			if( is_string( $options['append'] ?? null ) === true && trim( $options['append'] ) !== '' )
				$parts[] = [ 'k' => 'append', 'html' => $options['append'] ];

			$date = is_string( $options['date'] ?? null ) === true ? $options['date'] : '';
			$parsed = $date === '' ? false : \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
			$date = $parsed !== false && $parsed->format( 'Y-m-d' ) === $date ? $date : '';

			$header = Tokens::fill( $appData, $layout['header'], $tokens, $preview, true );
			$footer = Tokens::fill( $appData, $layout['footer'], $tokens, $preview, true );

			// The logo is decoded when some HTML of the document names it
			$logo = null;

			foreach( array_merge( [ $header, $footer ], array_column( $parts, 'html' ) ) as $html )
				if( str_contains( strtolower( $html ), 'pdf:logo' ) === true ) {
					$logo = Image::fromBytes( self::picture( $appData, 'logo' ) );
					break;
				}

			$document = new Document( self::_producer(), is_string( $options['title'] ?? null ) === true ? $options['title'] : '', $date );

			( new Engine( $document, $layout, $logo ) )->run( $parts, $header, $footer, $fields );

			return $document->output();
		}

		/**
		 *	A picture a caller handed in: the bytes of a PNG or a JPEG, or the absolute path of a file
		 *
		 *	@param		mixed			$given
		 *
		 *	@return 	Image|null
		 */
		private static function _picture( mixed $given ): ?Image {

			if( is_string( $given ) === false || $given === '' )
				return null;

			if( str_starts_with( $given, "\x89PNG" ) === false && str_starts_with( $given, "\xFF\xD8" ) === false ) {

				if( preg_match( '#^(/|[A-Za-z]:[/\\\\])#', $given ) !== 1 || str_contains( $given, "\0" ) === true || is_file( $given ) === false || (int) @filesize( $given ) > \Nino\Images::MAX_UPLOAD_BYTES )
					return null;

				$given = (string) @file_get_contents( $given );
			}

			return Image::fromBytes( $given );
		}

		/**
		 *	The /Producer of a file: the framework and the feature, with no version - a file made again after an update
		 *	of the feature is the same bytes as before it, so that a document kept with its hash can be checked
		 *
		 *	@return 	string
		 */
		private static function _producer(): string {

			return 'Nino PDF';
		}
	}
}
