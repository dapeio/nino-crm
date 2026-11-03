<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Nino\Modules\Sign\Signature	The picture of a signature: the one a member drew, checked, and the one a name is made into
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules\Sign {

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Sign\Signature		A signature is a PNG. A member either draws it on the canvas of the page, which
	 *										posts it as a data URL, or types a name, which is drawn here with the built-in
	 *										font 5 of GD, three times as large, on a transparent ground. Both are bytes the
	 *										Pdf feature can place in the box of a signature field.
	 *
	 *										What comes from a browser is untrusted: the data URL has to be a PNG, no larger
	 *										than the kernel takes an upload (\Nino\Images::reject()), at most 2000 by 1000
	 *										pixels, decodable by GD and with something drawn on it - a canvas nobody
	 *										touched is a transparent picture, and no signature. A typed name is cleaned of
	 *										control characters, whitespace is one space, and it is at most 60 characters;
	 *										the font has the letters of ISO 8859-1, and a character it lacks is a question
	 *										mark.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Signature {

		public const string PREFIX = 'data:image/png;base64,';

		public const int MAX_WIDTH = 2000;
		public const int MAX_HEIGHT = 1000;
		public const int MAX_NAME = 60;

		// The built-in font GD draws a name with, the pixels one of its letters takes, and how many times larger the picture is made
		private const int FONT = 5;
		private const int LETTER_WIDTH = 9;
		private const int LETTER_HEIGHT = 15;
		private const int SCALE = 3;

		// The most characters of a data URL that are looked at: the kernel's cap of an upload, as base64
		private const int MAX_URL = 11184812;

		/**
		 *	The picture a member drew
		 *
		 *	@param		string		$url					The data URL the canvas made: data:image/png;base64,...
		 *
		 *	@return 	string|null								The bytes of the PNG; null for anything that is not a PNG of the kind the page makes, that has nothing drawn on it, or is too large
		 */
		public static function drawn( string $url ): ?string {

			if( str_starts_with( $url, self::PREFIX ) === false || strlen( $url ) > self::MAX_URL )
				return null;

			$bytes = base64_decode( substr( $url, strlen( self::PREFIX ) ), true );

			if( $bytes === false || $bytes === '' || \Nino\Images::reject( $bytes ) !== null )
				return null;

			$size = \Nino\Images::size( $bytes );

			if( $size === false || $size['type'] !== IMAGETYPE_PNG || $size['width'] < 1 || $size['height'] < 1 || $size['width'] > self::MAX_WIDTH || $size['height'] > self::MAX_HEIGHT )
				return null;

			return self::_inked( $bytes ) === true ? $bytes : null;
		}

		/**
		 *	A name as the text of a signature: cleaned, one line of single spaces
		 *
		 *	@param		string		$name					As typed
		 *
		 *	@return 	string										'' for a name with nothing in it
		 */
		public static function clean( string $name ): string {

			// A byte that is not UTF-8 is the replacement character, as the engine of the Pdf feature does it
			$substitute = mb_substitute_character();
			mb_substitute_character( 0xFFFD );
			$name = (string) mb_convert_encoding( $name, 'UTF-8', 'UTF-8' );
			mb_substitute_character( $substitute );

			return trim( (string) preg_replace( '/[\s\p{C}]+/u', ' ', $name ) );
		}

		/**
		 *	The picture of a typed name
		 *
		 *	@param		string		$name					As typed
		 *
		 *	@return 	string|null								The bytes of the PNG; null for a name with nothing in it or one of more than 60 characters
		 */
		public static function typed( string $name ): ?string {

			$text = self::clean( $name );

			if( $text === '' || mb_strlen( $text, 'UTF-8' ) > self::MAX_NAME )
				return null;

			// Letters the font does not have are a question mark - the substitute character is the default's, and put back
			$substitute = mb_substitute_character();
			mb_substitute_character( 0x3F );
			$latin = (string) mb_convert_encoding( $text, 'ISO-8859-1', 'UTF-8' );
			mb_substitute_character( $substitute );

			$width = self::LETTER_WIDTH * strlen( $latin );
			$small = imagecreatetruecolor( $width, self::LETTER_HEIGHT );
			$large = imagecreatetruecolor( $width * self::SCALE, self::LETTER_HEIGHT * self::SCALE );

			if( $small === false || $large === false )
				return null;

			foreach( [ $small, $large ] as $ground ) {
				imagealphablending( $ground, false );
				imagesavealpha( $ground, true );
				imagefill( $ground, 0, 0, (int) imagecolorallocatealpha( $ground, 0, 0, 0, 127 ) );
			}

			imagestring( $small, self::FONT, 0, 0, $latin, (int) imagecolorallocatealpha( $small, 20, 30, 90, 0 ) );
			imagecopyresampled( $large, $small, 0, 0, 0, 0, $width * self::SCALE, self::LETTER_HEIGHT * self::SCALE, $width, self::LETTER_HEIGHT );

			ob_start();
			$written = imagepng( $large );
			$bytes = (string) ob_get_clean();

			return $written === true && $bytes !== '' ? $bytes : null;
		}

		/**
		 *	Whether anything is drawn on a picture: some pixel of it, seen at a small size, is not fully transparent
		 *
		 *	@param		string		$bytes				The bytes of a picture
		 *
		 *	@return 	bool
		 */
		private static function _inked( string $bytes ): bool {

			$image = @imagecreatefromstring( $bytes );

			if( $image === false )
				return false;

			if( imageistruecolor( $image ) === false && @imagepalettetotruecolor( $image ) === false )
				return false;

			$small = imagescale( $image, min( imagesx( $image ), 160 ), min( imagesy( $image ), 80 ), IMG_BILINEAR_FIXED );

			if( $small === false )
				return false;

			for( $y = 0; $y < imagesy( $small ); $y++ )
				for( $x = 0; $x < imagesx( $small ); $x++ )
					if( ( ( imagecolorat( $small, $x, $y ) >> 24 ) & 0x7F ) < 127 )
						return true;

			return false;
		}
	}
}
