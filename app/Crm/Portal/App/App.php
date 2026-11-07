<?php
declare(strict_types=1);
/**
 *	Crm\Portal\App		The portal as an app on the home screen of a phone: the web app manifest (GET /portal/manifest.webmanifest), the icon (GET /portal/icon.svg, and GET /portal/icon.png of 180
 *										pixels for the apple-touch-icon where GD is there), the lines the head of a portal page gets (the manifest, the colour of the bar of the browser, the apple-touch-icon and the
 *										title of the app) and the card that tells a client how to put the portal on the home screen.
 *
 *										The manifest and the icon name nobody but the website: its name, the colours of its theme and the initials of its name. A visitor may read them - a browser asks for a
 *										manifest without a session, so Members is told to leave the three routes open ('/members/open') - and nothing in them is a client's. The name is text: it is put into the
 *										JSON and into the SVG escaped, its brackets as [ or &#91; (the response is rendered once more, and a fill or a shortcode of the name would be read), and a null byte
 *										is left out. The colours are the ones the theme the project is painted by declares (--nino-brand-safe, --nino-default, --nino-on-default of assets/theme.css), as numbers -
 *										the browser needs them before a style sheet is read; where the file has none the kernel's own are used.
 *
 *										The kernel has no hook for the <head> of a page - the module reaches it with a style sheet and a script of the bundles and nothing else - and the head of the site names a manifest
 *										and an icon of its own. So the lines of the portal are put into the finished page: the component [crm-portal-head] says that a page has the bar of the portal
 *										(note()), and '/nino/http/output', the one point that holds the bytes about to be sent, takes the manifest and the apple-touch-icon the head names out and puts the portal's in
 *										before </head> (callbackOutput()). A page without the bar - the door, the Datenschutz, the Impressum - is not touched.
 *
 *										The PNG is made with GD's own font, enlarged (no font file is needed on the server), written once to data/crm-icon/ under a name that holds what it is made of, and answered as a file;
 *										where GD is not there the route is not made and the head does not name it. The icon of the logo of the project is added to the manifest where the slot /logo holds a square raster image.
 */
namespace Crm\Portal;

use Crm\Portal;

class App {

	// The routes: the manifest, the icon as SVG and the icon as PNG
	public const string MANIFEST = '/portal/manifest.webmanifest';
	public const string ICON = '/portal/icon.svg';
	public const string ICON_PNG = '/portal/icon.png';

	// The page the app opens, and the part of the site it covers
	public const string START = '/portal/projekte';
	public const string SCOPE = '/portal';

	// Where the PNG is kept, and the longest short name
	private const string DIRECTORY = '/data/crm-icon';
	private const int SHORT = 12;

	// The key of what the head component says: this page has the bar of the portal
	private const string BAR = './crm/portal/bar';
	private const string GD = './crm/portal/gd';

	// What the theme has not said: the colours the kernel's own theme.css starts from
	private const array DEFAULTS = [ 'brand' => '#147bb7', 'ground' => '#ffffff', 'ink' => '#0b0e10' ];

	/**
	 *	Whether the PNG can be drawn: GD is there with the functions that make it. The app data may say otherwise ('./crm/portal/gd', true or false: a test of a server without GD)
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	bool
	 */
	public static function gd( array &$appData ): bool {

		return is_bool( $appData[self::GD] ?? null ) === true ? $appData[self::GD] : ( function_exists( 'imagecreatetruecolor' ) === true && function_exists( 'imagepng' ) === true && function_exists( 'imagestring' ) === true );
	}

	/**
	 *	The routes, the callbacks and the opening of the three paths for Members: called by Crm::init()
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	void
	 */
	public static function init( array &$appData ): void {

		$appData['/nino/http/routes']['GET://portal/manifest.webmanifest'] = [ 'uri' => self::MANIFEST, 'header' => [ 'Content-Type' => 'application/manifest+json; charset=utf-8', 'Cache-Control' => 'public, max-age=3600' ] ];
		$appData['/nino/http/routes']['GET://portal/icon.svg'] = [ 'uri' => self::ICON, 'header' => [ 'Content-Type' => 'image/svg+xml; charset=utf-8', 'Cache-Control' => 'public, max-age=86400' ] ];
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://portal/manifest.webmanifest', [ self::class, 'callbackManifest' ] );
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://portal/icon.svg', [ self::class, 'callbackIcon' ] );

		if( self::gd( $appData ) === true ) {
			$appData['/nino/http/routes']['GET://portal/icon.png'] = [ 'uri' => self::ICON_PNG, 'header' => [ 'Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=86400' ] ];
			\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://portal/icon.png', [ self::class, 'callbackIconPng' ] );
		}

		\Nino\Callbacks::registerCallback( $appData, '/members/open', [ self::class, 'callbackOpen' ] );
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/output', [ self::class, 'callbackOutput' ] );
	}

	/**
	 *	'/members/open': the three routes are no business of the gate - a browser fetches them without a session
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$list				The uris Members is told are open
	 *
	 *	@return 	void
	 */
	public static function callbackOpen( array &$appData, array &$list ): void {

		array_push( $list, self::MANIFEST, self::ICON, self::ICON_PNG );
	}

	/**
	 *	The name of the app: the name of the website as text, a null byte left out; the word of the portal where the website has none
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	string										Not escaped
	 */
	public static function name( array &$appData ): string {

		$name = Portal::siteName( $appData );

		if( $name !== '' )
			return $name;

		Portal::words( $appData );

		return str_replace( "\0", '', trim( (string) \Nino\Html::resolveTextfill( $appData, '/project/crm-portal/app/name' ) ) );
	}

	/**
	 *	The name a home screen has room for: the whole name where it has at most 12 characters, else its first 12 characters cut at the end of a word (a word that is longer than that is cut where it stands)
	 *
	 *	@param		string		$name
	 *
	 *	@return 	string
	 */
	public static function shortName( string $name ): string {

		$name = trim( $name );

		if( mb_strlen( $name, 'UTF-8' ) <= self::SHORT )
			return $name;

		$head = mb_substr( $name, 0, self::SHORT, 'UTF-8' );

		// The cut falls at the end of a word where the next character is a blank; else the last blank before it is where the word begins that does not fit
		if( preg_match( '/\s/u', mb_substr( $name, self::SHORT, 1, 'UTF-8' ) ) === 1 )
			return rtrim( $head );

		$space = mb_strrpos( $head, ' ', 0, 'UTF-8' );

		return $space === false || $space === 0 ? $head : rtrim( mb_substr( $head, 0, $space, 'UTF-8' ) );
	}

	/**
	 *	The colours of the app as numbers: brand (the surface the initials stand on, white on it reads), page (the ground of a portal page, light), bar (the ground of the bar, light) and dark (the ground
	 *	of the dark scheme). Read from the first 64 KB of assets/theme.css - the tokens it declares are at the top of the file, the light ones first; the ground of the dark scheme is the one in the block of @media (prefers-color-scheme: dark)
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											brand, page, light and dark - each '#rrggbb', dark null where the theme has no block for the dark scheme
	 */
	public static function colours( array &$appData ): array {

		$file = \Nino\Filesystem::path( $appData, '/assets/theme.css' );
		$css = $file !== '' && is_file( $file ) === true ? (string) file_get_contents( $file, false, null, 0, 65536 ) : '';
		$find = static function( string $token, int $nth, string $default ) use ( $css ): string {

			return preg_match_all( '/--'. $token. ':\s*(#[0-9a-fA-F]{6})\b/', $css, $found ) > $nth ? strtolower( $found[1][$nth] ) : $default;
		};
		$ground = $find( 'nino-default', 0, self::DEFAULTS['ground'] );

		return [
			'brand' => $find( 'nino-brand-safe', 0, self::DEFAULTS['brand'] ),
			'page'  => self::mix( $ground, $find( 'nino-on-default', 0, self::DEFAULTS['ink'] ), .04 ),
			'light' => $ground,
			'dark'  => preg_match( '/@media\s*\(prefers-color-scheme:\s*dark\)\s*\{[^{}]*\{[^{}]*?--nino-default:\s*(#[0-9a-fA-F]{6})\b/', $css, $dark ) === 1 ? strtolower( $dark[1] ) : null,
		];
	}

	/**
	 *	Two colours mixed: $share of the second in the first
	 *
	 *	@param		string		$base					'#rrggbb'
	 *	@param		string		$other				'#rrggbb'
	 *	@param		float			$share				0 to 1
	 *
	 *	@return 	string										'#rrggbb'
	 */
	private static function mix( string $base, string $other, float $share ): string {

		$out = '#';

		foreach( [ 1, 3, 5 ] as $at )
			$out .= sprintf( '%02x', (int) round( hexdec( substr( $base, $at, 2 ) ) * ( 1 - $share ) + hexdec( substr( $other, $at, 2 ) ) * $share ) );

		return $out;
	}

	/**
	 *	A text as the string of a JSON document: the encoding of PHP with the characters that end a tag or an attribute as escapes, a null byte left out and every bracket - of a fill, of a
	 *	shortcode - as [ and ], since the response is rendered once more before it goes out. Only the strings of the document are touched, never its own brackets
	 *
	 *	@param		array 		$document
	 *
	 *	@return 	string
	 */
	public static function json( array $document ): string {

		$json = json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR );

		return (string) preg_replace_callback( '/"(?:[^"\\\\]|\\\\.)*"/s', static fn( array $string ): string => str_replace( [ '[', ']' ], [ '\\u005b', '\\u005d' ], $string[0] ), (string) $json );
	}

	/**
	 *	The logo of the project as an icon of the manifest: the file of the slot /logo where it is a raster image - png, jpeg or webp - that is square. A wide logo is no icon: a phone would
	 *	draw it squeezed
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array|null								src, sizes and type; null where there is none
	 */
	private static function logo( array &$appData ): ?array {

		$slot = \Nino\Images::getSlot( $appData, '/logo' );
		$filename = is_array( $slot ) === true && is_string( $slot['filename'] ?? null ) === true ? $slot['filename'] : '';
		$types = [ 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp' ];
		$type = $types[strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) )] ?? '';

		if( $filename === '' || $type === '' )
			return null;

		$bytes = \Nino\Images::read( $appData, $filename );
		$size = $bytes === false ? false : @getimagesizefromstring( $bytes );

		if( $size === false || $size[0] !== $size[1] || $size[0] < 48 )
			return null;

		return [ 'src' => \Nino\Images::getUrl( $appData, $filename ), 'sizes' => $size[0]. 'x'. $size[1], 'type' => $type ];
	}

	/**
	 *	'/nino/http/response/GET://portal/manifest.webmanifest': the web app manifest. The name of the website and its short form, the page the app opens and the part of the site it covers (with the
	 *	directory the project is served from), standalone, the colours, the language of the site and the icons: the SVG, the PNG where GD is there and the logo where it is a square raster image
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackManifest( array &$appData, array &$request ): void {

		$dir = rtrim( \Nino\Filesystem::getDir( $appData ), '/' );
		$name = self::name( $appData );
		$colours = self::colours( $appData );
		$icons = [ [ 'src' => $dir. self::ICON, 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any maskable' ] ];

		if( self::gd( $appData ) === true )
			$icons[] = [ 'src' => $dir. self::ICON_PNG, 'sizes' => '180x180', 'type' => 'image/png', 'purpose' => 'any' ];

		$logo = self::logo( $appData );

		if( $logo !== null )
			$icons[] = [ 'src' => $logo['src'], 'sizes' => $logo['sizes'], 'type' => $logo['type'], 'purpose' => 'any' ];

		$request['/nino/http/response']['statusCode'] = 200;
		$request['/nino/http/response']['header']['Content-Type'] = 'application/manifest+json; charset=utf-8';
		$request['/nino/http/response']['header']['Cache-Control'] = 'public, max-age=3600';
		$request['/nino/http/response']['body'] = self::json( [
			'name'             => $name,
			'short_name'       => self::shortName( $name ),
			'start_url'        => $dir. self::START,
			'scope'            => $dir. self::SCOPE,
			'display'          => 'standalone',
			'background_color' => $colours['page'],
			'theme_color'      => $colours['brand'],
			'lang'             => str_replace( '_', '-', \Nino\Locales::getCurrentLocale( $appData ) ),
			'icons'            => $icons,
		] );
	}

	/**
	 *	'/nino/http/response/GET://portal/icon.svg': 512 by 512 pixels, the brand's surface and the initials of the name - the first letter of the first and of the last word, as the chip of the bar has
	 *	them - in white. A square with no corners cut: where a phone masks the icon (maskable) it rounds it itself, and the initials stand well inside the part a mask leaves
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackIcon( array &$appData, array &$request ): void {

		$initials = self::initials( $appData );
		$size = mb_strlen( $initials, 'UTF-8' ) > 1 ? 224 : 288;

		// The initials are letters and digits; they are escaped all the same, and the brackets of a fill or a shortcode cannot be among them
		$text = str_replace( [ '[', ']' ], [ '&#91;', '&#93;' ], htmlspecialchars( $initials, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8' ) );

		$request['/nino/http/response']['statusCode'] = 200;
		$request['/nino/http/response']['header']['Content-Type'] = 'image/svg+xml; charset=utf-8';
		$request['/nino/http/response']['header']['Cache-Control'] = 'public, max-age=86400';
		$request['/nino/http/response']['body'] = '<?xml version="1.0" encoding="UTF-8"?>'. "\n". '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512">'
			. '<rect width="512" height="512" fill="'. self::colours( $appData )['brand']. '"/>'
			. '<text x="256" y="256" fill="#ffffff" font-family="system-ui, -apple-system, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif" font-size="'. $size. '" font-weight="700" text-anchor="middle" dominant-baseline="central">'. $text. '</text></svg>';
	}

	/**
	 *	The initials of the name of the app, as Portal::initials() has them for the chip of the bar
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	string
	 */
	public static function initials( array &$appData ): string {

		return Portal::initials( self::name( $appData ) );
	}

	/**
	 *	'/nino/http/response/GET://portal/icon.png': 180 by 180 pixels, the same motif as the SVG. The file is written once under a name that holds the initials and the colour, and answered as a
	 *	file; one that was made for another name is taken away. A file that could not be written is a 404
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackIconPng( array &$appData, array &$request ): void {

		$file = self::png( $appData );

		if( $file === null ) {
			$request['/nino/http/response']['statusCode'] = 404;
			$request['/nino/http/response']['body'] = 'Not Found';
			$request['/nino/http/response']['header']['Content-Type'] = 'text/plain; charset=utf-8';
			$request['/nino/http/response']['header']['Cache-Control'] = 'no-store';

			return;
		}

		$request['/nino/http/response']['statusCode'] = 200;
		$request['/nino/http/response']['file'] = $file;
		$request['/nino/http/response']['header']['Content-Type'] = 'image/png';
		$request['/nino/http/response']['header']['Cache-Control'] = 'public, max-age=86400';
	}

	/**
	 *	The file of the PNG, drawn when it is not there: the brand's surface and the initials in white, drawn with GD's own font (ISO-8859-1: a letter it has no sign for is left out) on a small canvas and
	 *	enlarged with smoothing, so that no font file of the server is needed
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	string|null								The absolute path; null where GD is not there or the file could not be written
	 */
	private static function png( array &$appData ): ?string {

		if( self::gd( $appData ) === false || function_exists( 'imagecreatetruecolor' ) === false )
			return null;

		$initials = self::initials( $appData );
		$brand = self::colours( $appData )['brand'];
		$name = substr( hash( 'sha256', $initials. '|'. $brand ), 0, 16 ). '.png';
		$file = \Nino\Filesystem::path( $appData, self::DIRECTORY. '/'. $name );

		if( is_file( $file ) === true )
			return $file;

		$bytes = self::draw( $initials, $brand );

		if( $bytes === null )
			return null;

		\Nino\Filesystem::forceDir( $appData, self::DIRECTORY );

		$temporary = $file. '.'. bin2hex( random_bytes( 6 ) ). '.tmp';

		if( @file_put_contents( $temporary, $bytes ) !== strlen( $bytes ) || @rename( $temporary, $file ) === false ) {
			@unlink( $temporary );
			trigger_error( 'Crm: the icon of the portal could not be written', E_USER_WARNING );

			return null;
		}

		// An icon of another name or colour is not wanted any more
		foreach( glob( dirname( $file ). '/*.png' ) ?: [] as $other )
			if( $other !== $file )
				@unlink( $other );

		return $file;
	}

	/**
	 *	The PNG, drawn
	 *
	 *	@param		string		$initials
	 *	@param		string		$brand				'#rrggbb'
	 *
	 *	@return 	string|null								The bytes; null where GD does not make them
	 */
	private static function draw( string $initials, string $brand ): ?string {

		$size = 180;
		$text = (string) @iconv( 'UTF-8', 'ISO-8859-1//IGNORE', $initials );
		$text = $text === '' ? '?' : $text;
		$font = 5;
		$cell = [ imagefontwidth( $font ) * strlen( $text ), imagefontheight( $font ) ];
		$mask = imagecreatetruecolor( $cell[0], $cell[1] );
		$canvas = imagecreatetruecolor( $size, $size );

		if( $mask === false || $canvas === false )
			return null;

		imagefill( $mask, 0, 0, (int) imagecolorallocate( $mask, 0, 0, 0 ) );
		imagestring( $mask, $font, 0, 0, $text, (int) imagecolorallocate( $mask, 255, 255, 255 ) );

		// The height of the letters is 40% of the icon, which is inside the part a mask leaves
		$height = (int) round( $size * ( strlen( $text ) > 1 ? .36 : .46 ) );
		$width = (int) round( $cell[0] * $height / $cell[1] );
		$large = imagescale( $mask, $width, $height, IMG_BILINEAR_FIXED );
		$colour = [ (int) hexdec( substr( $brand, 1, 2 ) ), (int) hexdec( substr( $brand, 3, 2 ) ), (int) hexdec( substr( $brand, 5, 2 ) ) ];

		imagefill( $canvas, 0, 0, (int) imagecolorallocate( $canvas, $colour[0], $colour[1], $colour[2] ) );

		if( $large !== false ) {

			$left = (int) round( ( $size - $width ) / 2 );
			$top = (int) round( ( $size - $height ) / 2 );

			// The brightness of the enlarged mask is how much of the letter a pixel holds: white over the brand's surface
			for( $y = 0; $y < $height; $y++ )
				for( $x = 0; $x < $width; $x++ ) {

					$share = ( imagecolorat( $large, $x, $y ) & 0xff ) / 255;

					if( $share > 0 )
						imagesetpixel( $canvas, $left + $x, $top + $y, ( (int) round( $colour[0] + ( 255 - $colour[0] ) * $share ) << 16 ) | ( (int) round( $colour[1] + ( 255 - $colour[1] ) * $share ) << 8 ) | (int) round( $colour[2] + ( 255 - $colour[2] ) * $share ) );
				}
		}

		ob_start();
		imagepng( $canvas );

		return (string) ob_get_clean();
	}

	/**
	 *	The lines the head of a portal page gets: the manifest, the colour of the bar of the browser (light and dark), the apple-touch-icon where GD is there, and the two lines that make iOS an app of it
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	string										Markup, tab-indented to stand in the head
	 */
	public static function headTags( array &$appData ): string {

		$dir = htmlspecialchars( rtrim( \Nino\Filesystem::getDir( $appData ), '/' ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
		$colours = self::colours( $appData );
		$title = htmlspecialchars( self::shortName( self::name( $appData ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );

		return "\t\t\t".'<link rel="manifest" href="'. $dir. self::MANIFEST. '">'. "\n"
			. ( $colours['dark'] === null
				? "\t\t\t".'<meta name="theme-color" content="'. $colours['light']. '">'. "\n"
				: "\t\t\t".'<meta name="theme-color" content="'. $colours['light']. '" media="(prefers-color-scheme: light)">'. "\n". "\t\t\t".'<meta name="theme-color" content="'. $colours['dark']. '" media="(prefers-color-scheme: dark)">'. "\n" )
			. ( self::gd( $appData ) === true ? "\t\t\t".'<link rel="apple-touch-icon" href="'. $dir. self::ICON_PNG. '">'. "\n" : '' )
			. "\t\t\t".'<meta name="apple-mobile-web-app-capable" content="yes">'. "\n"
			. "\t\t\t".'<meta name="apple-mobile-web-app-title" content="'. $title. '">'. "\n";
	}

	/**
	 *	The card that tells a client how to put the portal on its home screen, under the message of the owner: on the start page of the portal and on the list of the projects - not on the page of a project - and
	 *	where no step of the gate is pending. It is drawn hidden: portal.js shows it where the page is no app yet (not standalone), the window is narrower than 1024 pixels and the device has not put it away
	 *	(localStorage), so that nothing is shown without the script, and nothing is stored on the server. The sentences are the words of the portal, one for each system; the button Installieren is shown
	 *	by the script where the browser offers the install itself
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client				As Clients::get() has it
	 *	@param		array|null	$project			The project the page is of, null for a page that is no project's
	 *
	 *	@return 	string										Markup; '' for any other page
	 */
	public static function note( array &$appData, array $client, ?array $project ): string {

		$request = \Nino\Http::getRequest( $appData );
		$path = rtrim( $request === false ? '' : (string) ( $request['/nino/http/request']['uri'] ?? '' ), '/' );

		if( $project !== null || in_array( $path, [ '/portal', '/portal/projekte' ], true ) === false || Portal::pending( $appData, $client ) !== '' )
			return '';

		return Portal::template( 'home' );
	}

	/**
	 *	The head component says that the page it stands on has the bar of the portal
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	void
	 */
	public static function markBar( array &$appData ): void {

		$appData[self::BAR] = true;
	}

	/**
	 *	'/nino/http/output': the finished page of a request that drew the bar of the portal gets the lines of the app in its head - the manifest and the apple-touch-icon of the head of the
	 *	site are taken out first (a browser would take the first manifest it finds), the portal's come before </head>. Nothing else of the page is touched, and nothing of a response that is no page
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackOutput( array &$appData, array &$request ): void {

		$body = $request['/nino/http/response']['body'] ?? null;

		if( ( $appData[self::BAR] ?? false ) !== true || is_string( $body ) === false || \Nino\Http::answersHtml( (array) ( $request['/nino/http/response'] ?? [] ) ) === false )
			return;

		unset( $appData[self::BAR] );

		$end = stripos( $body, '</head>' );

		if( $end === false )
			return;

		$head = substr( $body, 0, $end );

		// The head of the site names the manifest of the site and, with it, an icon of its own for iOS: the portal's are those of the pages that have the bar
		$head = (string) preg_replace( '~^[ \t]*<link\b[^>\n]*\brel=["\']manifest["\'][^>\n]*>[ \t]*\r?\n~mi', '', $head );

		if( self::gd( $appData ) === true )
			$head = (string) preg_replace( '~^[ \t]*<link\b[^>\n]*\brel=["\']apple-touch-icon["\'][^>\n]*>[ \t]*\r?\n~mi', '', $head );

		$request['/nino/http/response']['body'] = rtrim( $head, " \t" ). self::headTags( $appData ). "\t\t". substr( $body, $end );
	}
}
