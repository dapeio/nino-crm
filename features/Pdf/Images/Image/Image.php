<?php
declare(strict_types=1);
/**
 *	Nino									A compact filesystembased php framework
 *	Modules\Pdf\Images\Image	A picture as the PDF holds it: an image XObject and, where there is
 *												an alpha channel, its soft mask
 *
 *	@package							Dape/Nino
 *	@author								David Perchermeier <mail@dape.io>
 *	@link									https://github.com/dapeio/nino
 */
namespace Nino\Modules\Pdf\Images {

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Image							Bytes of a picture made ready for \Nino\Modules\Pdf\Document\Document.
	 *
	 *										A JPEG that a PDF reader decodes itself passes through as it is,
	 *										behind /DCTDecode: baseline or progressive, 8 bits, in DeviceRGB or
	 *										DeviceGray and upright. A CMYK JPEG is refused - its colours need an
	 *										inversion some readers apply and some do not - and so is a picture
	 *										that is none, or larger than 4000 by 4000 pixels or than the
	 *										kernel's pixel cap. Everything else - a PNG, a GIF, a WebP, a JPEG
	 *										whose EXIF orientation turns it - is decoded with gd to raw RGB
	 *										samples, Flate-compressed, with an /SMask of the alpha channel where
	 *										the picture has one. A picture wider than 2000 pixels is scaled down
	 *										to that on the way (a logo or a signature printed at 300 dpi is not
	 *										wider than 170 mm), so decoding stays a matter of milliseconds.
	 *
	 *										The only thing this class does with the kernel is
	 *										\Nino\Images::size() - the type, the pixels and the orientation of
	 *										a file the way the upload checks read them.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Image {

		public const int MAX_SIDE = 4000;

		// The longest side a decoded picture keeps
		public const int KEEP_SIDE = 2000;

		// GD's alpha runs 0 (opaque) to 127; a PDF's 255 (opaque) to 0
		private static array $_alpha = [];

		private function __construct(
			private int $_width,
			private int $_height,
			private string $_space,
			private string $_filter,
			private string $_data,
			private ?string $_alphaData,
			private string $_hash
		) {
		}

		/**
		 *	Read a picture
		 *
		 *	@param		string		$bytes				A file's content
		 *
		 *	@return 	self|null									Null for what is no picture this class can place
		 */
		public static function fromBytes( string $bytes ): ?self {

			$size = \Nino\Images::size( $bytes );

			if( $size === false || $size['width'] < 1 || $size['height'] < 1 || $size['width'] > self::MAX_SIDE || $size['height'] > self::MAX_SIDE
				|| $size['width'] * $size['height'] > \Nino\Images::MAX_SOURCE_PIXELS )
				return null;

			$hash = sha1( $bytes );

			if( $size['type'] === IMAGETYPE_JPEG && $size['orientation'] === 1 ) {

				$info = @getimagesizefromstring( $bytes );
				$channels = is_array( $info ) === true ? (int) ( $info['channels'] ?? 0 ) : 0;
				$bits = is_array( $info ) === true ? (int) ( $info['bits'] ?? 0 ) : 0;

				if( $bits !== 8 || in_array( $channels, [ 1, 3 ], true ) === false )
					return null;

				return new self( $size['width'], $size['height'], $channels === 1 ? 'DeviceGray' : 'DeviceRGB', 'DCTDecode', $bytes, null, $hash );
			}

			return self::_decoded( $bytes, $size['orientation'], $hash );
		}

		public function width(): int {
			return $this->_width;
		}

		public function height(): int {
			return $this->_height;
		}

		public function hash(): string {
			return $this->_hash;
		}

		public function data(): string {
			return $this->_data;
		}

		public function alpha(): ?string {
			return $this->_alphaData;
		}

		/**
		 *	The dictionary entries of the image's XObject
		 *
		 *	@param		int				$mask					The object number of its soft mask, 0 for none
		 *
		 *	@return 	string
		 */
		public function entries( int $mask ): string {

			return '/Type /XObject /Subtype /Image /Width '. $this->_width. ' /Height '. $this->_height. ' /ColorSpace /'. $this->_space. ' /BitsPerComponent 8 /Filter /'. $this->_filter
				. ( $mask > 0 ? ' /SMask '. $mask. ' 0 R' : '' );
		}

		/**
		 *	The dictionary entries of the soft mask's XObject
		 *
		 *	@return 	string
		 */
		public function alphaEntries(): string {

			return '/Type /XObject /Subtype /Image /Width '. $this->_width. ' /Height '. $this->_height. ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode';
		}

		/**
		 *	A picture decoded with gd, upright, as RGB samples and an alpha channel
		 *
		 *	@param		string		$bytes
		 *	@param		int				$orientation	The EXIF orientation, 1 to 8
		 *	@param		string		$hash
		 *
		 *	@return 	self|null
		 */
		private static function _decoded( string $bytes, int $orientation, string $hash ): ?self {

			$image = @imagecreatefromstring( $bytes );

			if( $image === false )
				return null;

			if( imageistruecolor( $image ) === false && @imagepalettetotruecolor( $image ) === false )
				return null;

			$image = self::_upright( $image, $orientation );

			if( $image === null )
				return null;

			$width = imagesx( $image );
			$height = imagesy( $image );

			if( max( $width, $height ) > self::KEEP_SIDE ) {

				$scale = self::KEEP_SIDE / max( $width, $height );
				$kept = imagecreatetruecolor( max( 1, (int) round( $width * $scale ) ), max( 1, (int) round( $height * $scale ) ) );

				if( $kept === false )
					return null;

				imagealphablending( $kept, false );
				imagesavealpha( $kept, true );
				imagefill( $kept, 0, 0, (int) imagecolorallocatealpha( $kept, 0, 0, 0, 127 ) );
				imagecopyresampled( $kept, $image, 0, 0, 0, 0, imagesx( $kept ), imagesy( $kept ), $width, $height );

				$image = $kept;
				$width = imagesx( $image );
				$height = imagesy( $image );
			}

			if( self::$_alpha === [] )
				for( $a = 0; $a < 128; $a++ )
					self::$_alpha[ chr( $a ) ] = chr( 255 - (int) round( $a * 255 / 127 ) );

			$rgb = '';
			$alpha = '';
			$opaque = true;

			for( $y = 0; $y < $height; $y++ ) {

				$row = [];

				for( $x = 0; $x < $width; $x++ )
					$row[] = imagecolorat( $image, $x, $y );

				$packed = pack( 'N*', ...$row );
				$rgb .= (string) preg_replace( '/.(...)/s', '$1', $packed );
				$line = (string) preg_replace( '/(.).../s', '$1', $packed );

				if( $opaque === true && trim( $line, "\0" ) !== '' )
					$opaque = false;

				$alpha .= $line;
			}

			return new self( $width, $height, 'DeviceRGB', 'FlateDecode', (string) gzcompress( $rgb, 9 ), $opaque === true ? null : (string) gzcompress( strtr( $alpha, self::$_alpha ), 9 ), $hash );
		}

		/**
		 *	Turn a picture upright by its EXIF orientation
		 *
		 *	@param		\GdImage	$image
		 *	@param		int				$orientation	1 to 8
		 *
		 *	@return 	\GdImage|null
		 */
		private static function _upright( \GdImage $image, int $orientation ): ?\GdImage {

			$steps = match( $orientation ) {
				2				=> [ [ 'flip', IMG_FLIP_HORIZONTAL ] ],
				3				=> [ [ 'turn', 180 ] ],
				4				=> [ [ 'flip', IMG_FLIP_VERTICAL ] ],
				5				=> [ [ 'turn', -90 ], [ 'flip', IMG_FLIP_HORIZONTAL ] ],
				6				=> [ [ 'turn', -90 ] ],
				7				=> [ [ 'turn', 90 ], [ 'flip', IMG_FLIP_HORIZONTAL ] ],
				8				=> [ [ 'turn', 90 ] ],
				default	=> [],
			};

			foreach( $steps as [ $what, $value ] ) {

				if( $what === 'flip' ) {
					imageflip( $image, $value );
					continue;
				}

				$turned = imagerotate( $image, (float) $value, (int) imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );

				if( $turned === false )
					return null;

				imagealphablending( $turned, false );
				imagesavealpha( $turned, true );
				$image = $turned;
			}

			return $image;
		}
	}
}
