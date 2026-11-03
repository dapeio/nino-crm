<?php
declare(strict_types=1);
/**
 *	Nino										A compact filesystembased php framework
 *	Modules\Pdf\Document\Document	The writer: objects, pages, fonts, images, the xref table
 *
 *	@package								Dape/Nino
 *	@author									David Perchermeier <mail@dape.io>
 *	@link										https://github.com/dapeio/nino
 */
namespace Nino\Modules\Pdf\Document {

	use Nino\Modules\Pdf\Images\Image;

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Document					One PDF 1.4 file, built in memory. The pages hold content streams
	 *										a layout wrote into them, the fonts are the Type1 standard fonts
	 *										with WinAnsiEncoding (nothing is embedded), the images are XObjects
	 *										an \Nino\Modules\Pdf\Images\Image made, and output() answers the
	 *										bytes: header, numbered objects, the xref table and the trailer.
	 *
	 *										The PDF syntax this class writes - dictionaries, streams, the
	 *										table of offsets - is a document format built from data: a
	 *										serialisation and not a view, the case AGENTS.md names for
	 *										sitemap.xml. There is no markup in it.
	 *
	 *										Deterministic on purpose: an object is numbered when it is
	 *										created (the catalogue, the page tree and the info dictionary
	 *										take 1 to 3, then the pages, the fonts and the images as they
	 *										come), every stream is compressed with one level, nothing reads
	 *										a clock or a random source, and the info dictionary names a
	 *										date only when the caller hands one in. Two documents built by
	 *										the same calls are the same bytes - which is what lets a
	 *										signature feature render a document again with the signature in
	 *										place.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Document {

		// One millimetre in the points a PDF counts in
		public const float MM = 72 / 25.4;

		// The comment after the header marks the file as binary for the tools that guess
		private const string HEAD = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";

		// id => the body of the object, from the dictionary on
		private array $_objects = [];

		// A page: its objects, its size, the chunks of its content stream and the resources it uses
		private array $_pages = [];

		// BaseFont => [ id, name ] and image hash => [ id, name ]
		private array $_fonts = [];
		private array $_images = [];

		// The last object number given out: 1 to 3 are the catalogue, the page tree and the info dictionary
		private int $_last = 3;

		/**
		 *	@param		string		$_producer		The /Producer of the info dictionary
		 *	@param		string		$_title				The /Title; '' leaves it out
		 *	@param		string		$_date				Y-m-d for /CreationDate and /ModDate; '' leaves both out
		 */
		public function __construct( private string $_producer, private string $_title = '', private string $_date = '' ) {
		}

		/**
		 *	A number as a PDF writes it: three decimals at most, no exponent, whatever the locale
		 *
		 *	@param		float			$number
		 *
		 *	@return 	string
		 */
		public static function num( float $number ): string {

			$text = rtrim( rtrim( sprintf( '%.3F', $number ), '0' ), '.' );

			return $text === '' || $text === '-0' ? '0' : $text;
		}

		/**
		 *	Bytes as a literal string: the delimiters, the backslash and every byte outside printable
		 *	ASCII escaped, so the string survives any reader
		 *
		 *	@param		string		$bytes				WinAnsi bytes
		 *
		 *	@return 	string
		 */
		public static function literal( string $bytes ): string {

			return '('. (string) preg_replace_callback( '/[\x00-\x1F\\\\()\x7F-\xFF]/', static fn( array $match ): string => sprintf( '\\%03o', ord( $match[0] ) ), $bytes ). ')';
		}

		/**
		 *	A text string of a dictionary: a literal for plain ASCII, UTF-16BE with its byte order mark
		 *	for anything else
		 *
		 *	@param		string		$text					UTF-8
		 *
		 *	@return 	string
		 */
		public static function text( string $text ): string {

			if( preg_match( '/^[\x20-\x7E]*$/D', $text ) === 1 )
				return self::literal( $text );

			return '<FEFF'. strtoupper( bin2hex( (string) mb_convert_encoding( $text, 'UTF-16BE', 'UTF-8' ) ) ). '>';
		}

		/**
		 *	Start a page
		 *
		 *	@param		float			$width				In points
		 *	@param		float			$height				In points
		 *
		 *	@return 	int											The page's index, from 0
		 */
		public function page( float $width, float $height ): int {

			$this->_pages[] = [ 'id' => ++$this->_last, 'content' => ++$this->_last, 'width' => $width, 'height' => $height, 'ops' => [], 'fonts' => [], 'images' => [] ];

			return count( $this->_pages ) - 1;
		}

		/**
		 *	How many pages there are
		 *
		 *	@return 	int
		 */
		public function pages(): int {

			return count( $this->_pages );
		}

		/**
		 *	Append operators to the content stream of a page
		 *
		 *	@param		int				$page
		 *	@param		string		$operators
		 *
		 *	@return 	void
		 */
		public function draw( int $page, string $operators ): void {

			$this->_pages[$page]['ops'][] = $operators;
		}

		/**
		 *	The resource name of a standard font on a page, the font made on first use
		 *
		 *	@param		int				$page
		 *	@param		string		$baseFont			eg. Helvetica-BoldOblique
		 *
		 *	@return 	string										eg. /F2, for the Tf operator
		 */
		public function font( int $page, string $baseFont ): string {

			if( isset( $this->_fonts[$baseFont] ) === false ) {

				$id = ++$this->_last;
				$this->_fonts[$baseFont] = [ 'id' => $id, 'name' => 'F'. ( count( $this->_fonts ) + 1 ) ];
				$this->_objects[$id] = '<< /Type /Font /Subtype /Type1 /BaseFont /'. $baseFont. ' /Encoding /WinAnsiEncoding >>';
			}

			$this->_pages[$page]['fonts'][ $this->_fonts[$baseFont]['name'] ] = $this->_fonts[$baseFont]['id'];

			return '/'. $this->_fonts[$baseFont]['name'];
		}

		/**
		 *	The resource name of an image on a page; its XObject - and the soft mask of its alpha
		 *	channel - are made on first use
		 *
		 *	@param		int				$page
		 *	@param		Image			$image
		 *
		 *	@return 	string										eg. /Im1, for the Do operator
		 */
		public function image( int $page, Image $image ): string {

			$hash = $image->hash();

			if( isset( $this->_images[$hash] ) === false ) {

				$mask = 0;

				if( $image->alpha() !== null ) {
					$mask = ++$this->_last;
					$this->_objects[$mask] = self::_stream( $image->alphaEntries(), (string) $image->alpha() );
				}

				$id = ++$this->_last;
				$this->_objects[$id] = self::_stream( $image->entries( $mask ), $image->data() );
				$this->_images[$hash] = [ 'id' => $id, 'name' => 'Im'. ( count( $this->_images ) + 1 ) ];
			}

			$this->_pages[$page]['images'][ $this->_images[$hash]['name'] ] = $this->_images[$hash]['id'];

			return '/'. $this->_images[$hash]['name'];
		}

		/**
		 *	The file
		 *
		 *	@return 	string
		 */
		public function output(): string {

			$objects = $this->_objects;
			$kids = [];

			foreach( $this->_pages as $page ) {

				$kids[] = $page['id']. ' 0 R';
				$objects[ $page['content'] ] = self::_stream( '/Filter /FlateDecode', (string) gzcompress( implode( '', $page['ops'] ), 9 ) );

				$resources = '/ProcSet [ /PDF /Text /ImageB /ImageC ]';

				foreach( [ 'Font' => $page['fonts'], 'XObject' => $page['images'] ] as $kind => $used ) {

					if( $used === [] )
						continue;

					ksort( $used, SORT_NATURAL );
					$list = '';

					foreach( $used as $name => $id )
						$list .= ' /'. $name. ' '. $id. ' 0 R';

					$resources .= ' /'. $kind. ' <<'. $list. ' >>';
				}

				$objects[ $page['id'] ] = '<< /Type /Page /Parent 2 0 R /MediaBox [ 0 0 '. self::num( $page['width'] ). ' '. self::num( $page['height'] ). ' ] /Resources << '. $resources. ' >> /Contents '. $page['content']. ' 0 R >>';
			}

			$info = '/Producer '. self::text( $this->_producer );

			if( $this->_title !== '' )
				$info .= ' /Title '. self::text( $this->_title );

			if( $this->_date !== '' )
				$info .= ' /CreationDate (D:'. str_replace( '-', '', $this->_date ). '000000Z) /ModDate (D:'. str_replace( '-', '', $this->_date ). '000000Z)';

			$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
			$objects[2] = '<< /Type /Pages /Kids [ '. implode( ' ', $kids ). ' ] /Count '. count( $kids ). ' >>';
			$objects[3] = '<< '. $info. ' >>';
			ksort( $objects );

			$out = self::HEAD;
			$offsets = [];

			foreach( $objects as $id => $body ) {
				$offsets[$id] = strlen( $out );
				$out .= $id. " 0 obj\n". $body. "\nendobj\n";
			}

			$start = strlen( $out );
			$out .= "xref\n0 ". ( $this->_last + 1 ). "\n0000000000 65535 f \n";

			for( $id = 1; $id <= $this->_last; $id++ )
				$out .= sprintf( "%010d 00000 n \n", $offsets[$id] );

			return $out. 'trailer'. "\n". '<< /Size '. ( $this->_last + 1 ). ' /Root 1 0 R /Info 3 0 R >>'. "\n". 'startxref'. "\n". $start. "\n". '%%EOF';
		}

		/**
		 *	One stream object: its dictionary, with the length, and the bytes as they are filtered
		 *
		 *	@param		string		$entries			The dictionary entries, without << >> and without /Length
		 *	@param		string		$data
		 *
		 *	@return 	string
		 */
		private static function _stream( string $entries, string $data ): string {

			return '<< '. $entries. ' /Length '. strlen( $data ). " >>\nstream\n". $data. "\nendstream";
		}
	}
}
