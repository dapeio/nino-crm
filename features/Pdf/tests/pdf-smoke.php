<?php
declare(strict_types=1);

// The Pdf feature's contract test. Runs against the checkout three levels up,
// or the one NINO_ROOT names
$root = getenv( 'NINO_ROOT' ) ?: dirname( __DIR__, 3 );
defined( 'NINO_FEATURES_DIR' ) === true || define( 'NINO_FEATURES_DIR', dirname( __DIR__, 2 ) );
require $root. '/tests/harness.php';

use Nino\Modules\Pdf as Pdf;
use Nino\Modules\Pdf\Layout\Allow;

$appData = ninoSandbox( 'pdf' );
$appData['/nino/modules'] = [ '\\Nino\\Modules\\Components' ];
\Nino\AppData::writeContentData( $appData, [ '/nino/modules', '/nino/locales/available', '/nino/locales/native' ] );
$dir = ninoSandboxDir( $appData );

// --- what the tests draw on --------------------------------------------------

$post = static function( string $action, array $data = [] ) use ( &$appData ): array {
	return ninoCall( $appData, [ \Nino\Admin\Admin::class, 'handlePost' ], $data, $action );
};

$picture = static function( string $kind, int $width = 40, int $height = 20, bool $alpha = false, bool $progressive = false ) use ( $dir ): string {

	$image = imagecreatetruecolor( $width, $height );
	imagealphablending( $image, false );
	imagesavealpha( $image, true );
	imagefill( $image, 0, 0, (int) imagecolorallocatealpha( $image, 30, 90, 200, $alpha === true ? 64 : 0 ) );
	imageinterlace( $image, $progressive );
	ob_start();
	$kind === 'png' ? imagepng( $image ) : imagejpeg( $image, null, 80 );

	return (string) ob_get_clean();
};

// A request as the panel gets an upload: the file in $_FILES, the data posted
$upload = static function( string $kind, string $bytes ) use ( $post, $dir ): array {

	$file = $dir. '/upload.bin';
	file_put_contents( $file, $bytes );
	$_FILES['file'] = [ 'name' => 'x', 'type' => '', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => strlen( $bytes ) ];
	$answer = $post( 'pdf/layout/image', [ 'kind' => $kind ] );
	unset( $_FILES['file'] );

	return $answer;
};

$pages = static fn( string $pdf ): int => preg_match_all( '#/Type /Page\b#', $pdf );
$object = static fn( string $pdf, int $id ): string => preg_match( '/\n'. $id. " 0 obj\n(.*?)\nendobj\n/s", $pdf, $found ) === 1 ? $found[1] : '';
$pageObjects = static function( string $pdf ): array {
	preg_match_all( '/\n(\d+) 0 obj\n<< \/Type \/Page \/Parent[^\n]*\n/', $pdf, $found );
	return array_map( 'intval', $found[1] );
};
// The content stream of a page, inflated: the operators a layout wrote
$content = static function( string $pdf, int $page ) use ( $object, $pageObjects ): string {

	$dictionary = $object( $pdf, $pageObjects( $pdf )[$page] );
	preg_match( '#/Contents (\d+) 0 R#', $dictionary, $contents );
	$stream = $object( $pdf, (int) $contents[1] );
	$start = strpos( $stream, "stream\n" );

	return (string) gzuncompress( substr( $stream, $start + 7, (int) strrpos( $stream, "\nendstream" ) - $start - 7 ) );
};
$words = static function( string $pdf, string $options = '-layout' ) use ( $dir ): string {
	file_put_contents( $dir. '/out.pdf', $pdf );
	return (string) shell_exec( 'pdftotext '. $options. ' '. escapeshellarg( $dir. '/out.pdf' ). ' - 2>/dev/null' );
};
$pdftotext = trim( (string) @shell_exec( 'command -v pdftotext 2>/dev/null' ) ) !== '';
$sentence = static function( string $code, string $locale ) use ( &$appData ): string {
	return (string) ( \Nino\Admin\Admin::textFills( $appData, '/features/Pdf/text', $locale )['[[/_admin/error/'. $code. ']]'] ?? '' );
};

// --- the feature, its manifest, its activation --------------------------------

$manifest = \Nino\Features::manifest( dirname( __DIR__ ) );
check( 'the manifest validates without a warning', is_array( $manifest ) && ninoWarnings() === [] );
check( '...it names the data it owns, the extensions it needs, the kernel it is written for and a setting of tags that is a list of slugs', $manifest['data'] === [ '/data/pdf' ] && $manifest['php'] === [ 'ext' => [ 'gd', 'dom', 'iconv', 'zlib' ] ] && $manifest['nino'] === '^1.8'
	&& $manifest['requires'] === [] && $manifest['components'] === [] && $manifest['assets'] === [] && $manifest['settings']['tags']['type'] === 'lines' && count( $manifest['settings'] ) === 1
	&& count( array_filter( $manifest['settings']['tags']['default'], static fn( string $tag ): bool => preg_match( '/^[a-z][a-z0-9-]{1,39}$/D', $tag ) === 1 ) ) === 5 );
check( '...and the six sections of its manual, in both languages', array_keys( $manifest['manual'] ) === [ 'shortcodes', 'markup', 'routes', 'panel', 'callbacks', 'install' ] && \Nino\Features::manualSections( $manifest['manual'], 'de_DE' ) !== null
	&& count( array_filter( $manifest['manual']['panel'], static fn( array $line ): bool => isset( $line['en_US'], $line['de_DE'] ) ) ) === 5 );

if( is_file( dirname( __DIR__, 3 ). '/bin/catalogue.php' ) === true ) {
	$listed = json_decode( (string) shell_exec( escapeshellarg( PHP_BINARY ). ' '. escapeshellarg( dirname( __DIR__, 3 ). '/bin/catalogue.php' ). ' '. escapeshellarg( $root ). ' 2>/dev/null' ), true );
	check( 'bin/catalogue.php lists the feature with its version, its category and the data it owns', is_array( $listed ) === true
		&& count( array_filter( $listed['features'] ?? [], static fn( array $feature ): bool => $feature['key'] === 'pdf' && $feature['version'] === '1.1.0' && $feature['category'] === 'content' ) ) === 1 );
}
else
	echo "note - bin/catalogue.php is not beside this feature: the catalogue's listing of it was NOT checked\n";

check( 'activation lists the class and records 1.1.0', \Nino\Features::activate( $appData, 'pdf' ) === true
	&& in_array( '\\Nino\\Modules\\Pdf', $appData['/nino/modules'], true ) === true && \Nino\Features::get( $appData, 'pdf' )['installed'] === '1.1.0' );
check( '...and copies nothing: the feature has no unit', glob( \Nino\Filesystem::path( $appData, '/templates' ). '/*' ) === [] || is_dir( \Nino\Filesystem::path( $appData, '/templates' ) ) === false );

\Nino\Html::init( $appData );
\Nino\Modules::callModules( $appData, 'init' );
ninoUser( $appData, 'dev@example.com' );
$panels = \Nino\Admin\Admin::panels( $appData );
check( 'the panel is in the registry: its own screen from a template, two tabs of its own after it - text blocks, templates - and the Settings tab the kernel adds', isset( $panels['pdf'] ) === true
	&& array_keys( $panels['pdf']['tabs'] ) === [ 'pdf-blocks', 'pdf-templates', 'pdf-settings' ] && $panels['pdf']['template'] !== '' && $panels['pdf']['group'] === 'features'
	&& $panels['pdf']['tabs']['pdf-blocks']['perm'] === $panels['pdf']['perm'] && $panels['pdf']['tabs']['pdf-templates']['perm'] === $panels['pdf']['perm'] && $panels['pdf']['perm'] === \Nino\Modules\Pdf\Admin::MANAGE_PERM );
check( '...and a feature without the setting tags, fresh from its manifest, lists the five tags of the manifest', Pdf::tags( $appData ) === [ 'offer', 'invoice', 'contract', 'privacy', 'information' ] );

\Nino\Features::saveSettings( $appData, 'pdf', [ 'tags' => "offer\nBad Tag\ninvoice\nx\nthis-tag-is-longer-than-forty-characters-by-far" ] );
check( 'tags() lists the lines of the setting that are slugs of 2 to 40 characters: the kernel holds a list of lines to their length alone', Pdf::tags( $appData ) === [ 'offer', 'invoice' ] );
\Nino\Features::saveSettings( $appData, 'pdf', [ 'tags' => "offer\ninvoice\ncontract\nprivacy\ninformation" ] );
check( '...and the setting as the Settings tab saves it is the list the panel offers', Pdf::tags( $appData ) === [ 'offer', 'invoice', 'contract', 'privacy', 'information' ] );

// --- the tile, the log, the permission -----------------------------------------

check( 'the Dashboard tile counts the templates', \Nino\Modules\Pdf\Admin::summary( $appData )['value'] === 0 );

\Nino\Auth::insertUser( $appData, 'viewer@example.com', 'correct horse battery staple', [] );
\Nino\Auth::loginUser( $appData, 'viewer@example.com', 'correct horse battery staple' );
$denied = [];
foreach( array_keys( \Nino\Modules\Pdf\Admin::actions() ) as $action )
	if( $post( $action )[0] !== 403 )
		$denied[] = $action;
check( 'every panel action is 403 for an account without the permission'. ( $denied === [] ? '' : ' - '. implode( ', ', $denied ) ), $denied === [] && count( \Nino\Modules\Pdf\Admin::actions() ) === 13 );
\Nino\Auth::loginUser( $appData, 'dev@example.com', 'correct horse battery staple' );

// --- the layout -----------------------------------------------------------------

check( 'layout() answers the defaults before anything is saved, and layout/get the same with no pictures', Pdf::layout( $appData ) === Pdf::DEFAULTS
	&& $post( 'pdf/layout/get' )[1]['layout'] === Pdf::DEFAULTS && $post( 'pdf/layout/get' )[1]['images'] === [ 'logo' => '', 'signature' => '' ] );

$layout = [ 'format' => 'letter', 'margins' => [ 15, 16, 17, 18 ], 'font' => 'times', 'size' => 11, 'header' => '<p align="right">Head [[page]]</p>', 'footer' => '<p>Page [[page]] / [[pages]]</p>' ];
[ $status, $body ] = $post( 'pdf/layout/save', $layout );
check( 'layout/save persists what it is given, answers the layout, and a fresh read has it', $status === 200 && $body['layout'] === $layout + [ 'logo' => '', 'signature' => '' ]
	&& Pdf::layout( $appData ) === $layout + [ 'logo' => '', 'signature' => '' ] && is_file( \Nino\Filesystem::path( $appData, Pdf::LAYOUT ) ) === true );

[ $status, $body ] = $post( 'pdf/layout/save', [ 'header' => '<p align="right" onclick="x()">Head [[page]]</p><script>alert(1)</script>', 'footer' => '<div class="f" style="color:red"><p>Page [[page]] / [[pages]]</p></div><style>p{}</style>' ] + $layout );
check( 'layout/save holds the header and the footer to the list of what a document may hold: the stored texts - and the answer - have no script, no style, no attribute the layout does not read', $status === 200
	&& Pdf::layout( $appData )['header'] === '<p align="right">Head [[page]]</p>' && Pdf::layout( $appData )['footer'] === '<div><p>Page [[page]] / [[pages]]</p></div>'
	&& $body['layout']['header'] === Pdf::layout( $appData )['header'] && $body['layout']['footer'] === Pdf::layout( $appData )['footer'] );
$post( 'pdf/layout/save', $layout );
[ $status, $body ] = $post( 'pdf/layout/save', [ 'header' => str_repeat( '&', 3000 ) ] + $layout );
check( '...a header of 3000 characters that is 15000 once its ampersands are written is refused as too long, as the layout would not read it back: nothing is written', $status === 400 && $body['code'] === 'pdf_band_long' && $body['field'] === 'header'
	&& Pdf::layout( $appData ) === $layout + [ 'logo' => '', 'signature' => '' ] );

foreach( [
	'a margin of 60'			=> [ [ 'margins' => [ 60, 20, 20, 20 ] ], 'pdf_margin', 'margins', [ 5, 50 ] ],
	'a margin that is no number'	=> [ [ 'margins' => [ 'x', 20, 20, 20 ] ], 'pdf_margin', 'margins', [ 5, 50 ] ],
	'three margins'				=> [ [ 'margins' => [ 20, 20, 20 ] ], 'pdf_margin', 'margins', [ 5, 50 ] ],
	'a size of 15'				=> [ [ 'size' => 15 ], 'pdf_size', 'size', [ 8, 14 ] ],
	'a paper that is none'		=> [ [ 'format' => 'a3' ], 'pdf_format', 'format', [] ],
	'a font that is none'		=> [ [ 'font' => 'comic' ], 'pdf_font', 'font', [] ],
	'a header of 4001 characters'	=> [ [ 'header' => str_repeat( 'x', 4001 ) ], 'pdf_band_long', 'header', [ 4000 ] ],
] as $label => [ $change, $code, $field, $params ] ) {
	[ $status, $body ] = $post( 'pdf/layout/save', $change + $layout );
	check( "layout/save refuses $label with 400, the code, the field and the params", $status === 400 && $body['code'] === $code && $body['field'] === $field && ( $body['params'] ?? [] ) === $params
		&& Pdf::layout( $appData ) === $layout + [ 'logo' => '', 'signature' => '' ] );
}

$logo = $picture( 'png' );
[ $status, $body ] = $upload( 'logo', $logo );
check( 'layout/image with a PNG stores a file the layout names, and answers it as a picture', $status === 200 && $body['layout']['logo'] === 'logo.png'
	&& is_file( \Nino\Filesystem::path( $appData, Pdf::IMAGES. '/logo.png' ) ) === true && str_starts_with( $body['images']['logo'], 'data:image/png;base64,' ) === true && Pdf::picture( $appData, 'logo' ) === $logo );
[ $status, $body ] = $upload( 'logo', $picture( 'jpeg' ) );
check( '...a second upload replaces it: the layout names the new file and one file is left', $status === 200 && $body['layout']['logo'] === 'logo.jpg'
	&& array_map( 'basename', glob( \Nino\Filesystem::path( $appData, Pdf::IMAGES ). '/logo.*' ) ) === [ 'logo.jpg' ] );
check( '...and the layout form saved after it keeps the picture', $post( 'pdf/layout/save', $layout )[1]['layout']['logo'] === 'logo.jpg' );

[ $status, $body ] = $upload( 'logo', "this is no picture\n" );
check( '...a text file is 400 with the code of the kernel, and the layout stays as it was', $status === 400 && $body['code'] === 'image_type' && Pdf::layout( $appData )['logo'] === 'logo.jpg' );
[ $status, $body ] = $upload( 'logo', $picture( 'png', 4001, 2 ) );
check( '...a picture wider than 4000 pixels is 400 with its own code and the limit', $status === 400 && $body['code'] === 'pdf_image_pixels' && $body['params'] === [ 4000 ] );
[ $status, $body ] = $upload( 'badge', $logo );
check( '...a picture that is neither logo nor signature is 400', $status === 400 && $body['code'] === 'pdf_image_kind' );
$_FILES['file'] = [ 'name' => 'x', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'type' => '' ];
[ $status, $body ] = $post( 'pdf/layout/image', [ 'kind' => 'logo' ] );
unset( $_FILES['file'] );
check( '...and no file at all is 400 with the kernel\'s code', $status === 400 && $body['code'] === 'upload_missing' );

$noise = imagecreatetruecolor( 700, 700 );
for( $y = 0; $y < 700; $y++ )
	for( $x = 0; $x < 700; $x++ )
		imagesetpixel( $noise, $x, $y, crc32( $x. ':'. $y ) & 0xFFFFFF );
ob_start();
imagepng( $noise );
$big = (string) ob_get_clean();
$upload( 'logo', $big );
$answered = $post( 'pdf/layout/get' )[1];
check( 'a picture of more than a megabyte is kept and named by the layout but not sent along with it: the screen shows its name', strlen( $big ) > 1048576 && $answered['layout']['logo'] === 'logo.png' && $answered['images']['logo'] === '' );
$upload( 'logo', $picture( 'jpeg' ) );

$signature = $picture( 'png', 60, 20, true );
$upload( 'signature', $signature );
check( 'the signature is a picture of its own, beside the logo', Pdf::layout( $appData )['signature'] === 'signature.png' && Pdf::layout( $appData )['logo'] === 'logo.jpg' );
[ $status, $body ] = $post( 'pdf/layout/image/delete', [ 'kind' => 'logo' ] );
check( 'layout/image/delete takes the logo away - from the layout and from the disk - and leaves the signature', $status === 200 && $body['layout']['logo'] === '' && $body['layout']['signature'] === 'signature.png'
	&& glob( \Nino\Filesystem::path( $appData, Pdf::IMAGES ). '/logo.*' ) === [] );

// A picture is put in place once the layout names it: a layout that cannot be written leaves the pictures as they were
$images = static fn(): array => array_map( 'basename', glob( \Nino\Filesystem::path( $appData, Pdf::IMAGES ). '/*' ) ?: [] );
$upload( 'logo', $logo );
$layoutFile = \Nino\Filesystem::path( $appData, Pdf::LAYOUT );
$layoutKept = (string) file_get_contents( $layoutFile );
unlink( $layoutFile );
mkdir( $layoutFile );
touch( $layoutFile. '/x' );
ninoWarnings();
$replacement = $picture( 'png', 80, 40 );
$replaced = $upload( 'logo', $replacement );
$added = $upload( 'logo', $picture( 'jpeg' ) );
ninoWarnings();
unlink( $layoutFile. '/x' );
rmdir( $layoutFile );
file_put_contents( $layoutFile, $layoutKept );
check( 'a layout that cannot be written refuses a picture with 500: the picture of the same name is not replaced, a picture of another name is not added, and nothing of either upload is left in the directory', $replaced[0] === 500 && $added[0] === 500
	&& file_get_contents( \Nino\Filesystem::path( $appData, Pdf::IMAGES. '/logo.png' ) ) === $logo && $images() === [ 'logo.png', 'signature.png' ] && Pdf::layout( $appData )['logo'] === 'logo.png' );
$replaced = $upload( 'logo', $replacement );
check( '...and the same upload once the layout can be written puts the new picture in the place of the old, and leaves nothing else', $replaced[0] === 200 && $replaced[1]['layout']['logo'] === 'logo.png' && Pdf::picture( $appData, 'logo' ) === $replacement && $images() === [ 'logo.png', 'signature.png' ] );
$post( 'pdf/layout/image/delete', [ 'kind' => 'logo' ] );

// --- the text blocks --------------------------------------------------------------

[ $status, $body ] = $post( 'pdf/blocks/save', [ 'key' => 'intro', 'isNew' => true, 'title' => 'Intro', 'html' => '<p>Hello [[name]] <strong>bold</strong></p><script>alert(1)</script>' ] );
check( 'blocks/save sanitizes: the script tag is gone after a fresh read, the paragraph and the strong stay', $status === 200 && str_contains( Pdf::blocks( $appData )['intro']['html'], '<script' ) === false
	&& str_contains( Pdf::blocks( $appData )['intro']['html'], '<p>Hello [[name]] <strong>bold</strong></p>' ) === true && $body['blocks'][0]['key'] === 'intro' );
check( '...and blocks/list answers the library', $post( 'pdf/blocks/list' )[1]['blocks'] === [ [ 'key' => 'intro', 'title' => 'Intro', 'html' => Pdf::blocks( $appData )['intro']['html'] ] ] );

foreach( [
	'a key taken'					=> [ [ 'key' => 'intro', 'isNew' => true, 'title' => 'Again', 'html' => '' ], 400, 'pdf_key_taken', 'key' ],
	'a key that is no slug'		=> [ [ 'key' => 'No Slug', 'isNew' => true, 'title' => 'x', 'html' => '' ], 400, 'pdf_key', 'key' ],
	'a title that is empty'		=> [ [ 'key' => 'empty', 'isNew' => true, 'title' => ' ', 'html' => '' ], 400, 'pdf_title', 'title' ],
	'a text of 20001 characters'	=> [ [ 'key' => 'long', 'isNew' => true, 'title' => 'x', 'html' => str_repeat( 'x', 20001 ) ], 400, 'pdf_html_long', 'html' ],
	'a block that is not there'	=> [ [ 'key' => 'gone', 'isNew' => false, 'title' => 'x', 'html' => '' ], 404, 'pdf_block_missing', '' ],
] as $label => [ $data, $expected, $code, $field ] ) {
	[ $status, $body ] = $post( 'pdf/blocks/save', $data );
	check( "blocks/save refuses $label with $expected, the code and the field", $status === $expected && $body['code'] === $code && ( $body['field'] ?? '' ) === $field && array_keys( Pdf::blocks( $appData ) ) === [ 'intro' ] );
}

[ $status, $body ] = $post( 'pdf/blocks/save', [ 'key' => 'intro', 'isNew' => false, 'title' => 'Intro two', 'html' => '<p>Changed</p>' ] );
check( 'blocks/save replaces a block that is there, and keeps the order of the rest', $status === 200 && Pdf::blocks( $appData )['intro'] === [ 'title' => 'Intro two', 'html' => '<p>Changed</p>' ] );
$post( 'pdf/blocks/save', [ 'key' => 'intro', 'isNew' => false, 'title' => 'Intro', 'html' => '<p>Hello [[name]] <strong>bold</strong></p>' ] );
$post( 'pdf/blocks/save', [ 'key' => 'spare', 'isNew' => true, 'title' => 'Spare', 'html' => '<p>Spare</p>' ] );

// --- the HTML a document may hold -------------------------------------------------------

// What the layout understands is kept, in the shape it reads it; the rest is unwrapped or goes
$rich = '<h1 class="big" onclick="x()">Title</h1><table border="1" class="t"><thead><tr><th width="30%" id="a">Item</th><th>Price</th></tr></thead><tbody><tr><td style="color:red;text-align:right" onclick="y()">1</td><td align="Center">2</td></tr></tbody></table>'
	. '<hr class="r"><p><img src="pdf:logo" width="20" alt="x"><img src="https://example.com/a.png"><a href="javascript:alert(1)" target="_blank">link</a> <span class="s">span</span><u>under</u></p><style>p{color:red}</style><script>alert(1)</script>';
$kept = '<h1>Title</h1><table border="1"><thead><tr><th width="30%">Item</th><th>Price</th></tr></thead><tbody><tr><td style="text-align:right">1</td><td align="center">2</td></tr></tbody></table><hr><p><img src="pdf:logo" width="20"><a>link</a> <span>span</span>under</p>';
[ $status, $body ] = $post( 'pdf/blocks/save', [ 'key' => 'rich', 'isNew' => true, 'title' => 'Rich', 'html' => $rich ] );
$stored = Pdf::blocks( $appData )['rich']['html'] ?? '';
check( 'a text block keeps what the layout draws, after the save and in a fresh read: a heading, a table with its border and the width of a th, a rule, the logo with its width, a link as its text underlined by the engine, a span', $status === 200
	&& str_contains( $stored, '<h1>Title</h1>' ) === true && str_contains( $stored, '<table border="1">' ) === true && str_contains( $stored, '<th width="30%">Item</th>' ) === true && str_contains( $stored, '<hr>' ) === true
	&& str_contains( $stored, '<img src="pdf:logo" width="20">' ) === true && str_contains( $stored, '<a>link</a>' ) === true && str_contains( $stored, '<span>span</span>' ) === true );
check( '...a style is reduced to its text-align, an align is written in lower case, and no other attribute stays: onclick, class, id, alt, target and the colour are gone', str_contains( $stored, '<td style="text-align:right">' ) === true && str_contains( $stored, '<td align="center">' ) === true
	&& preg_match( '/onclick|class=|id=|alt=|target=|color/', $stored ) === 0 );
check( '...an img that is not the logo goes, a link is its text with no address, a tag that is not listed is unwrapped to its text, and a script and a style go with what is in them', str_contains( $stored, 'example.com' ) === false && str_contains( $stored, 'javascript' ) === false
	&& str_contains( $stored, '<u>' ) === false && str_contains( $stored, 'under' ) === true && preg_match( '/<script|<style|alert|color:red/', $stored ) === 0 );
check( '...all of it is the text the answer carries, it is written once and for all - a second pass over it changes nothing - and it is what the list in the library holds', $stored === $kept && array_column( $body['blocks'], 'html', 'key' )['rich'] === $kept && Allow::html( $stored ) === $stored
	&& array_column( $post( 'pdf/blocks/list' )[1]['blocks'], 'html', 'key' )['rich'] === $kept );
check( '...the list is the one decided: 24 tags, and the attributes each may keep', array_keys( Allow::TAGS ) === [ 'h1', 'h2', 'h3', 'p', 'div', 'br', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li', 'a', 'hr', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'img', 'span' ]
	&& Allow::TAGS['th'] === [ 'width', 'align', 'style' ] && Allow::TAGS['img'] === [ 'src', 'width' ] && Allow::TAGS['table'] === [ 'border' ] && Allow::TAGS['a'] === [] );
check( '...an attribute stays on the tags that read it only, in the shape they read it: a width in percent on a cell and in millimetres on the logo, an align on a text and a cell, a border that is a number', Allow::html( '<p width="5%" border="2" align="right">a</p><td width="25%" border="1" align="left">b</td><td width="25">c</td><img src="PDF:LOGO" width="12.5 MM" align="right"><table border="thick"><tr><td>d</td></tr></table>' )
	=== '<p align="right">a</p><td width="25%" align="left">b</td><td>c</td><img src="pdf:logo" width="12.5mm"><table><tr><td>d</td></tr></table>' );
check( '...the last text-align that is valid is the one, as a cascade has it', Allow::html( '<p style="text-align:left;TEXT-ALIGN : Center !important;text-align:banana;x-text-align:right">a</p><p style="color:red">b</p>' ) === '<p style="text-align:center">a</p><p>b</p>' );
check( '...text is escaped as it is written - an ampersand, the characters of a tag, a no-break space - and an o umlaut and the euro sign stay as they are', Allow::html( "<p>a & b &lt;i&gt; \u{A0}ö€</p>" ) === "<p>a &amp; b &lt;i&gt; &nbsp;ö€</p>"
	&& Allow::html( '   ' ) === '' && Allow::html( '<script>x</script>' ) === '' && Allow::html( "<p>bad \xC3\x28 byte</p>" ) === "<p>bad \u{FFFD}( byte</p>" );

[ $status, $body ] = $post( 'pdf/blocks/save', [ 'key' => 'rich', 'isNew' => false, 'title' => 'Rich', 'html' => str_repeat( '&', 7000 ) ] );
check( '...a text that is 35000 characters once it is written, from 7000 that were typed, is refused with the limit, as the answer for 20001 is, and the block stays as it was', $status === 400 && $body['code'] === 'pdf_html_long' && $body['params'] === [ 20000 ] && $body['field'] === 'html' && Pdf::blocks( $appData )['rich']['html'] === $kept );
$post( 'pdf/blocks/delete', [ 'key' => 'rich' ] );

// --- the templates -----------------------------------------------------------------

$blocks = [
	[ 'type' => 'html', 'block' => 'intro' ],
	[ 'type' => 'html', 'html' => '<p>Own text with ö and €</p><script>x</script>' ],
	[ 'type' => 'slot', 'name' => 'table' ],
	[ 'type' => 'input', 'name' => 'note', 'label' => 'A note', 'multiline' => true, 'required' => true ],
	[ 'type' => 'pagebreak' ],
	[ 'type' => 'signature', 'name' => 'seller', 'party' => 'owner', 'label' => 'The seller', 'page' => 'last', 'x' => 20, 'y' => 200.04, 'w' => 60, 'h' => 20, 'line' => true ],
	[ 'type' => 'signature', 'name' => 'buyer', 'party' => 'member', 'label' => 'The buyer', 'page' => '1', 'x' => 110.5, 'y' => 200, 'w' => 60, 'h' => 20, 'line' => false ],
];
[ $status, $body ] = $post( 'pdf/templates/save', [ 'key' => 'offer-one', 'isNew' => true, 'name' => 'Offer one', 'tag' => 'offer', 'blocks' => $blocks ] );
$read = Pdf::template( $appData, 'offer-one' );
check( 'templates/save with every type of block persists, and a fresh read has the shape of the table: one of block or html, numbers with one decimal, a page as a number or last', $status === 200 && $body['key'] === 'offer-one' && $read !== null
	&& array_column( $read['blocks'], 'type' ) === [ 'html', 'html', 'slot', 'input', 'pagebreak', 'signature', 'signature' ]
	&& $read['blocks'][0] === [ 'type' => 'html', 'block' => 'intro' ] && array_keys( $read['blocks'][1] ) === [ 'type', 'html' ] && str_contains( $read['blocks'][1]['html'], '<script' ) === false
	&& $read['blocks'][2] === [ 'type' => 'slot', 'name' => 'table' ] && $read['blocks'][3] === [ 'type' => 'input', 'name' => 'note', 'label' => 'A note', 'multiline' => true, 'required' => true ]
	&& $read['blocks'][5] === [ 'type' => 'signature', 'name' => 'seller', 'party' => 'owner', 'label' => 'The seller', 'page' => 'last', 'x' => 20.0, 'y' => 200.0, 'w' => 60.0, 'h' => 20.0, 'line' => true ]
	&& $read['blocks'][6]['page'] === 1 && $read['blocks'][6]['x'] === 110.5 && $read['blocks'][6]['line'] === false );

$bad = static fn( array $change ): array => $post( 'pdf/templates/save', $change + [ 'key' => 'other', 'isNew' => true, 'name' => 'Other', 'tag' => 'invoice', 'blocks' => [] ] );
$box = static fn( array $change ): array => [ [ 'type' => 'signature', 'name' => 'seller', 'party' => 'owner', 'label' => '', 'page' => 'last', 'x' => 20, 'y' => 200, 'w' => 60, 'h' => 20, 'line' => true ] ];
foreach( [
	'a signature box over the right edge of the page'	=> [ [ 'blocks' => [ [ 'x' => 160 ] + $box( [] )[0] ] ], 400, 'pdf_signature_box' ],
	'a signature box under the foot of the page'			=> [ [ 'blocks' => [ [ 'y' => 290 ] + $box( [] )[0] ] ], 400, 'pdf_signature_box' ],
	'a signature box that is no number'					=> [ [ 'blocks' => [ [ 'w' => 'wide' ] + $box( [] )[0] ] ], 400, 'pdf_signature_box' ],
	'a signature box at a negative x'					=> [ [ 'blocks' => [ [ 'x' => -1 ] + $box( [] )[0] ] ], 400, 'pdf_signature_box' ],
	'a signature box above the page'					=> [ [ 'blocks' => [ [ 'y' => -0.5 ] + $box( [] )[0] ] ], 400, 'pdf_signature_box' ],
	'a signature box of no width'						=> [ [ 'blocks' => [ [ 'w' => 0 ] + $box( [] )[0] ] ], 400, 'pdf_signature_box' ],
	'a signature box of no height'						=> [ [ 'blocks' => [ [ 'h' => 0 ] + $box( [] )[0] ] ], 400, 'pdf_signature_box' ],
	'a signature on page 0'								=> [ [ 'blocks' => [ [ 'page' => 0 ] + $box( [] )[0] ] ], 400, 'pdf_signature_page' ],
	'a signature of neither party'						=> [ [ 'blocks' => [ [ 'party' => 'guest' ] + $box( [] )[0] ] ], 400, 'pdf_signature_party' ],
	'a duplicate key'									=> [ [ 'key' => 'offer-one' ], 400, 'pdf_key_taken' ],
	'a key that is no slug'								=> [ [ 'key' => 'Bad_Key' ], 400, 'pdf_key' ],
	'a tag the project does not have'					=> [ [ 'tag' => 'bogus' ], 400, 'pdf_tag' ],
	'a block of the library that is not there'			=> [ [ 'blocks' => [ [ 'type' => 'html', 'block' => 'nowhere' ] ] ], 400, 'pdf_block_ref' ],
	'an html block with both a reference and a text'	=> [ [ 'blocks' => [ [ 'type' => 'html', 'block' => 'intro', 'html' => '<p>x</p>' ] ] ], 400, 'pdf_block_html' ],
	'a block of a type that is none'					=> [ [ 'blocks' => [ [ 'type' => 'image' ] ] ], 400, 'pdf_block_type' ],
	'a slot with a name that is no slug'				=> [ [ 'blocks' => [ [ 'type' => 'slot', 'name' => 'A B' ] ] ], 400, 'pdf_block_name' ],
	'an input label of 121 characters'					=> [ [ 'blocks' => [ [ 'type' => 'input', 'name' => 'note', 'label' => str_repeat( 'x', 121 ) ] ] ], 400, 'pdf_block_label' ],
	'61 blocks'											=> [ [ 'blocks' => array_fill( 0, 61, [ 'type' => 'pagebreak' ] ) ], 400, 'pdf_blocks_many' ],
	'a name that is empty'								=> [ [ 'name' => '' ], 400, 'pdf_title' ],
	'a template that is not there'						=> [ [ 'key' => 'nowhere', 'isNew' => false ], 404, 'pdf_template_missing' ],
] as $label => [ $change, $expected, $code ] ) {
	[ $status, $body ] = $bad( $change );
	check( "templates/save refuses $label with $expected and the code $code, and writes nothing", $status === $expected && $body['code'] === $code && array_keys( Pdf::templates( $appData ) ) === [ 'offer-one' ] );
}

// Every code the sources answer a person with has its sentence in both languages, with a place for each param it carries
$problems = [];
$codes = [];
foreach( [ 'Store/Store.php', 'Admin/Admin.php' ] as $source )
	preg_match_all( "/'(pdf_[a-z_]+)'/", (string) file_get_contents( dirname( __DIR__ ). '/'. $source ), $found ) === false || $codes = array_merge( $codes, $found[1] );
$codes = array_unique( $codes );
foreach( $codes as $code )
	foreach( [ 'en_US', 'de_DE' ] as $locale )
		if( $sentence( $code, $locale ) === '' )
			$problems[] = $locale. ' '. $code;
check( 'every code the panel answers with has its sentence in English and in German ('. count( $codes ). ' codes)'. ( $problems === [] ? '' : ' - '. implode( ', ', $problems ) ), $problems === [] && count( $codes ) > 20 );
$english = array_keys( \Nino\Admin\Admin::textFills( $appData, '/features/Pdf/text', 'en_US' ) );
$german = array_keys( \Nino\Admin\Admin::textFills( $appData, '/features/Pdf/text', 'de_DE' ) );
sort( $english );
sort( $german );
check( 'the two text files carry the same keys', $english === $german && count( $english ) > 100 );

$before = array_keys( Pdf::templates( $appData ) );
[ $status, $body ] = $post( 'pdf/templates/duplicate', [ 'key' => 'offer-one', 'name' => 'Offer one (copy)' ] );
check( 'templates/duplicate makes a second file under a free key, with the name it is given and the blocks of the original', $status === 200 && $body['key'] === 'offer-one-copy'
	&& array_keys( Pdf::templates( $appData ) ) === [ 'offer-one', 'offer-one-copy' ] && Pdf::templates( $appData )['offer-one-copy']['name'] === 'Offer one (copy)'
	&& Pdf::template( $appData, 'offer-one-copy' )['blocks'] === Pdf::template( $appData, 'offer-one' )['blocks'] && count( glob( \Nino\Filesystem::path( $appData, Pdf::TEMPLATES ). '/*.php' ) ) === 2 );
foreach( [ 'no name' => [], 'an empty name' => [ 'name' => '' ], 'a name of blanks' => [ 'name' => " \t " ] ] as $label => $extra ) {
	[ $status, $body ] = $post( 'pdf/templates/duplicate', [ 'key' => 'offer-one' ] + $extra );
	check( "...$label is 400 with the code of a name, its limit and its field, and writes nothing: the server has no word of its own to name a copy by", $status === 400 && $body['code'] === 'pdf_title' && $body['field'] === 'name' && $body['params'] === [ 120 ]
		&& array_keys( Pdf::templates( $appData ) ) === [ 'offer-one', 'offer-one-copy' ] && count( glob( \Nino\Filesystem::path( $appData, Pdf::TEMPLATES ). '/*.php' ) ) === 2 );
}
$second = $post( 'pdf/templates/duplicate', [ 'key' => 'offer-one', 'name' => '  Second copy ' ] );
check( '...and a second copy takes the next key, with the name it is given, trimmed', $second[1]['key'] === 'offer-one-copy-2' && Pdf::templates( $appData )['offer-one-copy-2']['name'] === 'Second copy' );
check( '...a duplicate of a template that is not there is 404, whatever name it is given or not', $post( 'pdf/templates/duplicate', [ 'key' => 'nowhere' ] )[0] === 404 && $post( 'pdf/templates/duplicate', [ 'key' => 'nowhere', 'name' => 'Copy' ] )[0] === 404 );
$post( 'pdf/templates/delete', [ 'key' => 'offer-one-copy-2' ] );

$post( 'pdf/templates/save', [ 'key' => 'invoice-a', 'isNew' => true, 'name' => 'Invoice A', 'tag' => 'invoice', 'blocks' => [] ] );
$post( 'pdf/templates/save', [ 'key' => 'invoice-b', 'isNew' => true, 'name' => 'apple invoice', 'tag' => 'invoice', 'blocks' => [] ] );
check( 'templates() lists all, sorted by name; templates( "invoice" ) only those of that tag; a tag nobody has lists none', array_keys( Pdf::templates( $appData ) ) === [ 'invoice-b', 'invoice-a', 'offer-one', 'offer-one-copy' ]
	&& array_keys( Pdf::templates( $appData, 'invoice' ) ) === [ 'invoice-b', 'invoice-a' ] && Pdf::templates( $appData, 'invoice' )['invoice-a'] === [ 'name' => 'Invoice A', 'tag' => 'invoice' ] && Pdf::templates( $appData, 'contract' ) === [] );
$listed = $post( 'pdf/templates/list' )[1];
check( 'templates/list answers key, name, tag and the number of blocks of each, with the tags, the library and the paper for the editor', array_column( $listed['templates'], 'blocks', 'key' ) === [ 'invoice-b' => 0, 'invoice-a' => 0, 'offer-one' => 7, 'offer-one-copy' => 7 ]
	&& $listed['tags'] === Pdf::tags( $appData ) && array_column( $listed['library'], 'key' ) === [ 'intro', 'spare' ] && $listed['paper'] === [ 215.9, 279.4 ] );
$got = $post( 'pdf/templates/get', [ 'key' => 'offer-one' ] );
check( 'templates/get answers one template as the file holds it; an unknown one is 404 with its code', $got[0] === 200 && $got[1]['template'] === Pdf::template( $appData, 'offer-one' ) && $got[1]['key'] === 'offer-one'
	&& $post( 'pdf/templates/get', [ 'key' => 'nowhere' ] )[0] === 404 && $post( 'pdf/templates/get', [ 'key' => 'nowhere' ] )[1]['code'] === 'pdf_template_missing' && $post( 'pdf/templates/get', [ 'key' => '../x' ] )[0] === 404 );

[ $status, $body ] = $post( 'pdf/blocks/delete', [ 'key' => 'intro' ] );
check( 'blocks/delete of a block a template references is 409 with the code and the names of the templates, and the block stays', $status === 409 && $body['code'] === 'pdf_block_used'
	&& str_contains( $body['params'][0], 'Offer one' ) === true && isset( Pdf::blocks( $appData )['intro'] ) === true );
check( '...a block no template uses is deleted, an unknown one is 404', $post( 'pdf/blocks/delete', [ 'key' => 'spare' ] )[0] === 200 && isset( Pdf::blocks( $appData )['spare'] ) === false && $post( 'pdf/blocks/delete', [ 'key' => 'spare' ] )[0] === 404 );

// --- fields() and inputs() ----------------------------------------------------------

$fields = Pdf::fields( $appData, 'offer-one' );
check( 'fields() answers the signature blocks in order, every key of the API', array_column( $fields, 'name' ) === [ 'seller', 'buyer' ]
	&& array_keys( $fields[0] ) === [ 'name', 'party', 'label', 'page', 'x', 'y', 'w', 'h', 'line' ] && $fields[0]['page'] === 'last' && $fields[1]['page'] === 1 && $fields[1]['x'] === 110.5 );
check( 'inputs() answers the input blocks in order, every key of the API', Pdf::inputs( $appData, 'offer-one' ) === [ [ 'name' => 'note', 'label' => 'A note', 'multiline' => true, 'required' => true ] ]
	&& Pdf::inputs( $appData, 'invoice-a' ) === [] && Pdf::fields( $appData, 'nowhere' ) === [] && Pdf::inputs( $appData, 'nowhere' ) === [] );

// --- render() -----------------------------------------------------------------------

$data = [ 'tokens' => [ 'name' => 'Ada', 'client' => [ 'city' => 'Köln' ] ], 'slots' => [ 'table' => '<table><thead><tr><th>Item</th><th>Price</th></tr></thead><tbody><tr><td>Cell one</td><td>Cell two</td></tr></tbody></table>' ], 'inputs' => [ 'note' => "first line\nsecond line" ] ];
$pdf = Pdf::render( $appData, 'offer-one', $data );
check( 'render() answers bytes that start with %PDF-1.4 and end with %%EOF, with no trailer id and no date', is_string( $pdf ) === true && str_starts_with( $pdf, '%PDF-1.4' ) === true && str_ends_with( $pdf, '%%EOF' ) === true
	&& str_contains( $pdf, '/ID' ) === false && str_contains( $pdf, '/CreationDate' ) === false && str_contains( $pdf, '/ModDate' ) === false );
check( '...the xref table points at every object, and startxref at the table', (function() use ( $pdf ): bool {
	preg_match( '/startxref\n(\d+)\n%%EOF$/', $pdf, $start );
	preg_match( '/^xref\n0 (\d+)\n/', substr( $pdf, (int) $start[1] ), $count );
	$entries = substr( $pdf, (int) $start[1] + strlen( $count[0] ), 20 * (int) $count[1] );
	$ok = strlen( $entries ) === 20 * (int) $count[1];
	for( $id = 1; $id < (int) $count[1]; $id++ )
		$ok = $ok && (int) substr( $entries, 20 * $id, 10 ) === strpos( $pdf, "\n". $id. " 0 obj\n" ) + 1;
	return $ok;
})() );
check( '...the /Producer is the framework and the feature with no version - nothing after the name - and there is no title without the option', str_contains( $pdf, '/Producer (Nino PDF)' ) === true && str_contains( $pdf, '/Title' ) === false );
$before = (string) Pdf::render( $appData, 'invoice-a' );
// An update of the feature is a manifest that says another version: the registry's reading of it - cached for the request - is made to
$manifestVersion = \Nino\Features::get( $appData, 'pdf' )['version'];
$appData['./nino/features/all']['pdf']['version'] = '9.9.9';
check( '...an update of the feature changes no byte: the same render after the manifest of the feature says another version is the same bytes, so that a document kept with its hash can be made again', \Nino\Features::get( $appData, 'pdf' )['version'] === '9.9.9' && $manifestVersion !== '9.9.9' && $before !== '' && (string) Pdf::render( $appData, 'invoice-a' ) === $before );
unset( $appData['./nino/features/all'] );
check( '...the paper is the layout\'s: US Letter in points', str_contains( $pdf, '/MediaBox [ 0 0 612 792 ]' ) === true );
check( '...two pages: the page break adds one', $pages( $pdf ) === 2 && $pages( (string) Pdf::render( $appData, 'invoice-a' ) ) === 1 );
check( '...two renders of one template with the same arguments are the same bytes, with a picture in it as well', $pdf === Pdf::render( $appData, 'offer-one', $data )
	&& Pdf::render( $appData, 'offer-one', $data, [ 'signatures' => [ 'buyer' => $signature ], 'title' => 'T', 'date' => '2026-01-02' ] ) === Pdf::render( $appData, 'offer-one', $data, [ 'signatures' => [ 'buyer' => $signature ], 'title' => 'T', 'date' => '2026-01-02' ] ) );

$dated = (string) Pdf::render( $appData, 'offer-one', $data, [ 'date' => '2026-01-02', 'title' => 'Offer ö' ] );
check( '/CreationDate and /ModDate are there only with the option, as D:YYYYMMDD000000Z; the title is a text string', str_contains( $dated, '/CreationDate (D:20260102000000Z)' ) === true && str_contains( $dated, '/ModDate (D:20260102000000Z)' ) === true
	&& str_contains( $dated, '/Title <FEFF' ) === true && str_contains( (string) Pdf::render( $appData, 'offer-one', $data, [ 'date' => '2026-13-45' ] ), '/CreationDate' ) === false );

ninoWarnings();
check( 'an unknown template is null, with a warning that names it', Pdf::render( $appData, 'nowhere' ) === null && count( array_filter( ninoWarnings(), static fn( string $line ): bool => str_contains( $line, 'nowhere' ) ) ) === 1 );

// A template tagged with a word the project no longer has is a template all the same
$stale = \Nino\Filesystem::getFileContent( $appData, Pdf::TEMPLATES. '/invoice-a.php', [] );
\Nino\Filesystem::putFileContent( $appData, Pdf::TEMPLATES. '/invoice-a.php', [ 'tag' => 'retired' ] + $stale );
check( 'a tag that no longer exists is no reason: the template renders, and the list says the tag is gone', is_string( Pdf::render( $appData, 'invoice-a' ) ) === true && in_array( 'retired', Pdf::tags( $appData ), true ) === false
	&& array_column( $post( 'pdf/templates/list' )[1]['templates'], 'known', 'key' )['invoice-a'] === false && array_column( $post( 'pdf/templates/list' )[1]['templates'], 'known', 'key' )['offer-one'] === true );
\Nino\Filesystem::putFileContent( $appData, Pdf::TEMPLATES. '/invoice-a.php', $stale );

// The text that fills two pages, and the footer's numbers
$post( 'pdf/blocks/save', [ 'key' => 'long', 'isNew' => true, 'title' => 'Long', 'html' => '<p>'. str_repeat( 'word ', 1500 ). '</p>' ] );
$post( 'pdf/templates/save', [ 'key' => 'long', 'isNew' => true, 'name' => 'Long', 'tag' => 'information', 'blocks' => [ [ 'type' => 'html', 'block' => 'long' ] ] ] );
$long = (string) Pdf::render( $appData, 'long' );
check( 'a text long enough for two pages gives two pages, a short one gives one', $pages( $long ) === 2 && $pages( (string) Pdf::render( $appData, 'invoice-b' ) ) === 1 );
check( '...the footer is laid out twice: page 1 of 2 and page 2 of 2 are written in the content of the pages', str_contains( $content( $long, 0 ), '(Page 1 / 2) Tj' ) === true && str_contains( $content( $long, 1 ), '(Page 2 / 2) Tj' ) === true
	&& str_contains( $content( $long, 0 ), '(Head 1) Tj' ) === true );
$post( 'pdf/templates/save', [ 'key' => 'long-break', 'isNew' => true, 'name' => 'Long break', 'tag' => 'information', 'blocks' => [ [ 'type' => 'html', 'block' => 'long' ], [ 'type' => 'pagebreak' ], [ 'type' => 'html', 'block' => 'intro' ] ] ] );
check( '...and a page break after it adds a third', $pages( (string) Pdf::render( $appData, 'long-break' ) ) === 3 );

// --- the words of a document ----------------------------------------------------------

if( $pdftotext === true ) {

	$text = $words( $pdf );
	check( 'a token value is in the text, the dotted one too; an unknown token is gone', str_contains( $text, 'Hello Ada' ) === true && str_contains( $words( (string) Pdf::render( $appData, 'offer-one', [ 'tokens' => [ 'name' => 'Q' ] ] ) ), 'Hello Q' ) === true );
	check( '...the text of a block of its own, with an o umlaut and the euro sign, is in the text', str_contains( $text, 'Own text with ö and €' ) === true );
	check( '...a slot\'s table cell is, and so is the input\'s text, a line to a line', str_contains( $text, 'Cell one' ) === true && str_contains( $text, 'Price' ) === true && preg_match( '/first line\s+second line/', $text ) === 1 );
	check( '...the footer carries 1 / 2 and 2 / 2', str_contains( $words( $pdf, '-f 1 -l 1' ), 'Page 1 / 2' ) === true && str_contains( $words( $pdf, '-f 2 -l 2' ), 'Page 2 / 2' ) === true );

	$escaped = $words( (string) Pdf::render( $appData, 'offer-one', [ 'tokens' => [ 'name' => '<b>&</b>' ] ] ) );
	check( '<b>&</b> handed in as a token arrives as the characters <b>&</b> and not as bold', str_contains( $escaped, 'Hello <b>&</b> bold' ) === true );
	check( '...a token that is not there is removed from the document, not written out', str_contains( $words( (string) Pdf::render( $appData, 'offer-one', [] ) ), '[[name]]' ) === false && str_contains( $words( (string) Pdf::render( $appData, 'offer-one', [] ) ), 'Hello bold' ) === true );
}
else
	echo "note - pdftotext is not on the path: the pins on the words of a document were NOT run\n";

// --- the pictures in a document ---------------------------------------------------------

$withSignature = (string) Pdf::render( $appData, 'offer-one', $data, [ 'signatures' => [ 'seller' => $signature, 'buyer' => $signature ] ] );
$lastPage = $object( $withSignature, $pageObjects( $withSignature )[1] );
preg_match( '#/XObject << /Im1 (\d+) 0 R >>#', $lastPage, $found );
$xobject = $object( $withSignature, (int) ( $found[1] ?? 0 ) );
check( 'a signature PNG with alpha handed in is an image XObject with an /SMask on the resources of the page named: last', $found !== [] && str_contains( $xobject, '/Subtype /Image' ) === true && preg_match( '#/SMask (\d+) 0 R#', $xobject, $mask ) === 1
	&& str_contains( $object( $withSignature, (int) $mask[1] ), '/ColorSpace /DeviceGray' ) === true && str_contains( $content( $withSignature, 1 ), ' Do Q' ) === true );
check( '...and the page of the other field has its own: page 1 holds the buyer\'s picture, the last page the seller\'s', str_contains( $object( $withSignature, $pageObjects( $withSignature )[0] ), '/XObject <<' ) === true );
check( '...an image used twice is one XObject', substr_count( $withSignature, '/Subtype /Image' ) === 2 );

$noPictures = (string) Pdf::render( $appData, 'offer-one', $data );
$lastNone = $object( $noPictures, $pageObjects( $noPictures )[1] );
check( 'the owner field draws the layout\'s signature without being handed one: its image is on the last page', str_contains( $lastNone, '/XObject <<' ) === true && str_contains( $object( $noPictures, $pageObjects( $noPictures )[0] ), '/XObject' ) === false );
check( '...a member field without an image draws its label only, and a field with a line draws the hairline', str_contains( $content( $noPictures, 0 ), '(The buyer) Tj' ) === true && str_contains( $content( $noPictures, 1 ), '(The seller) Tj' ) === true
	&& str_contains( $content( $noPictures, 1 ), ' l S' ) === true );
$post( 'pdf/layout/image/delete', [ 'kind' => 'signature' ] );
$bare = (string) Pdf::render( $appData, 'offer-one', $data );
check( '...and an owner field with no signature at all draws its label too, with no image anywhere', str_contains( $bare, '/Subtype /Image' ) === false && str_contains( $content( $bare, 1 ), '(The seller) Tj' ) === true );
$file = $dir. '/signature.png';
file_put_contents( $file, $signature );
check( 'a signature handed in as the absolute path of a file is drawn - a picture and its mask; a path that is no file draws nothing', substr_count( (string) Pdf::render( $appData, 'offer-one', $data, [ 'signatures' => [ 'buyer' => $file ] ] ), '/Subtype /Image' ) === 2
	&& substr_count( (string) Pdf::render( $appData, 'offer-one', $data, [ 'signatures' => [ 'buyer' => $dir. '/nothing.png' ] ] ), '/Subtype /Image' ) === 0 );
$upload( 'signature', $signature );

$baseline = $picture( 'jpeg', 50, 30 );
$progressive = $picture( 'jpeg', 50, 30, false, true );
$jpegs = [ 'baseline' => Pdf\Images\Image::fromBytes( $baseline ), 'progressive' => Pdf\Images\Image::fromBytes( $progressive ) ];
check( 'a JPEG passes through behind /DCTDecode, baseline or progressive, untouched', $jpegs['baseline'] !== null && $jpegs['progressive'] !== null && $jpegs['baseline']->data() === $baseline && $jpegs['progressive']->data() === $progressive
	&& str_contains( $jpegs['baseline']->entries( 0 ), '/Filter /DCTDecode' ) === true && str_contains( $jpegs['baseline']->entries( 0 ), '/ColorSpace /DeviceRGB' ) === true && str_ends_with( $progressive, "\xFF\xD9" ) === true
	&& str_contains( $progressive, "\xFF\xC2" ) === true );
$cmyk = "\xFF\xD8\xFF\xC0\x00\x14\x08\x00\x10\x00\x10\x04\x01\x11\x00\x02\x11\x00\x03\x11\x00\x04\x11\x00\xFF\xD9";
check( 'a CMYK JPEG is refused, and so is a file that is no picture and one that is too big', Pdf\Images\Image::fromBytes( $cmyk ) === null && Pdf\Images\Image::fromBytes( 'GIF89a nonsense' ) === null && Pdf\Images\Image::fromBytes( '' ) === null
	&& Pdf\Images\Image::fromBytes( $picture( 'png', 4001, 1 ) ) === null );
$flat = Pdf\Images\Image::fromBytes( $picture( 'png', 30, 10 ) );
$alpha = Pdf\Images\Image::fromBytes( $signature );
check( 'a PNG is decoded to Flate-compressed RGB samples, with a soft mask of the alpha channel only where there is alpha', $flat !== null && $alpha !== null && $flat->alpha() === null && $alpha->alpha() !== null
	&& str_contains( $alpha->entries( 9 ), '/Filter /FlateDecode' ) === true && str_contains( $alpha->entries( 9 ), '/SMask 9 0 R' ) === true && strlen( (string) gzuncompress( $alpha->data() ) ) === 60 * 20 * 3
	&& strlen( (string) gzuncompress( (string) $alpha->alpha() ) ) === 60 * 20 && (string) gzuncompress( (string) $alpha->alpha() ) === str_repeat( chr( 255 - (int) round( 64 * 255 / 127 ) ), 60 * 20 ) );

// A JPEG that its EXIF block says to turn is decoded and turned, not passed through
$exif = static function( string $jpeg, int $orientation ): string {
	$block = "Exif\x00\x00MM\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01". pack( 'n', $orientation ). "\x00\x00\x00\x00\x00\x00";
	return "\xFF\xD8\xFF\xE1". pack( 'n', strlen( $block ) + 2 ). $block. substr( $jpeg, 2 );
};
$turned = Pdf\Images\Image::fromBytes( $exif( $picture( 'jpeg', 40, 20 ), 6 ) );
$upright = Pdf\Images\Image::fromBytes( $exif( $picture( 'jpeg', 40, 20 ), 1 ) );
check( 'a JPEG whose EXIF orientation turns it is decoded and turned upright - the pixels swap their sides; one that is upright passes through', $turned !== null && $turned->width() === 20 && $turned->height() === 40 && str_contains( $turned->entries( 0 ), '/FlateDecode' ) === true
	&& $upright !== null && $upright->width() === 40 && str_contains( $upright->entries( 0 ), '/DCTDecode' ) === true );
$palette = imagecreate( 30, 10 );
$clear = imagecolorallocate( $palette, 255, 255, 255 );
imagecolortransparent( $palette, $clear );
imagefilledrectangle( $palette, 0, 0, 9, 9, (int) imagecolorallocate( $palette, 255, 0, 0 ) );
ob_start();
imagegif( $palette );
$gif = Pdf\Images\Image::fromBytes( (string) ob_get_clean() );
check( 'a GIF with a transparent colour is a picture with a soft mask: the transparent colour is the alpha channel', $gif !== null && $gif->alpha() !== null && strlen( (string) gzuncompress( (string) $gif->alpha() ) ) === 300 && str_contains( (string) gzuncompress( (string) $gif->alpha() ), "\x00" ) === true );

// --- the preview ----------------------------------------------------------------------------

[ $status, $body ] = $post( 'pdf/preview', [ 'name' => 'Draft', 'tag' => 'offer', 'blocks' => $blocks ] );
$previewed = base64_decode( (string) ( $body['pdf'] ?? '' ), true );
check( 'preview answers base64 that decodes to the bytes of a PDF, whatever is saved: an editor that holds a new template renders', $status === 200 && is_string( $previewed ) === true && str_starts_with( $previewed, '%PDF' ) === true && str_ends_with( $previewed, '%%EOF' ) === true );
preg_match( '#<td>(.*?)</td>#', \Nino\Feature::template( $appData, '/features/Pdf/templates', 'preview-slot' ), $slotCell );
preg_match( '#<h1>(.*?)</h1>#', \Nino\Feature::template( $appData, '/features/Pdf/templates', 'preview-sample' ), $sampleHead );
check( '...a slot is drawn as the table of the sample and a token as its name in square brackets, an input as its label, so the page says where the data lands', $pages( $previewed ) === 2
	&& $slotCell !== [] && str_contains( $content( $previewed, 0 ), '('. $slotCell[1]. ')' ) === true && str_contains( $content( $previewed, 0 ), '([A note]) Tj' ) === true && str_contains( $content( $previewed, 0 ), '[name]' ) === true );
check( '...a template with no body shows the layout around a sample text', $pages( (string) base64_decode( $post( 'pdf/preview', [ 'name' => 'Empty', 'tag' => 'offer', 'blocks' => [] ] )[1]['pdf'] ) ) >= 1
	&& str_contains( $content( (string) base64_decode( $post( 'pdf/preview', [ 'name' => 'Empty', 'tag' => 'offer', 'blocks' => [] ] )[1]['pdf'] ), 0 ), '(Page 1 /' ) === true
	&& $sampleHead !== [] && str_contains( $content( (string) base64_decode( $post( 'pdf/preview', [ 'name' => 'Empty', 'tag' => 'offer', 'blocks' => [] ] )[1]['pdf'] ), 0 ), '('. $sampleHead[1]. ') Tj' ) === true );
[ $status, $body ] = $post( 'pdf/preview', [ 'name' => 'Draft', 'tag' => 'offer', 'blocks' => [ [ 'type' => 'signature', 'name' => 'seal', 'party' => 'owner', 'label' => '', 'page' => 'last', 'x' => 200, 'y' => 10, 'w' => 60, 'h' => 20, 'line' => true ] ] ] );
check( '...a preview of a state that a save would refuse is refused the same way', $status === 400 && $body['code'] === 'pdf_signature_box' );

// --- the writer ----------------------------------------------------------------------------------

check( 'the numbers of a PDF are written with three decimals at most, whatever the locale', Pdf\Document\Document::num( 595.2756 ) === '595.276' && Pdf\Document\Document::num( 12.0 ) === '12' && Pdf\Document\Document::num( -0.0001 ) === '0' && Pdf\Document\Document::num( 0.5 ) === '0.5' );
check( '...a literal string escapes the delimiters, the backslash and every byte outside printable ASCII', Pdf\Document\Document::literal( "a(b)\\\xE4\r" ) === '(a\\050b\\051\\134\\344\\015)' );
check( '...a text string is a literal for ASCII and UTF-16BE with its byte order mark for the rest', Pdf\Document\Document::text( 'Plain' ) === '(Plain)' && Pdf\Document\Document::text( 'Ä' ) === '<FEFF00C4>' );
check( 'the widths are the metrics of the AFM files: Helvetica, Times and Courier, 224 numbers for the codes 32 to 255 and one number for Courier', Pdf\Fonts\Metrics::width( 'Helvetica', 'A' ) === 667.0 && Pdf\Fonts\Metrics::width( 'Helvetica-Bold', 'A' ) === 722.0
	&& Pdf\Fonts\Metrics::width( 'Times-Roman', 'A' ) === 722.0 && Pdf\Fonts\Metrics::width( 'Times-Italic', 'i' ) === 278.0 && Pdf\Fonts\Metrics::width( 'Courier-BoldOblique', 'Wi!' ) === 1800.0
	&& Pdf\Fonts\Metrics::width( 'Helvetica', "\xE4" ) === 556.0 && Pdf\Fonts\Metrics::width( 'Helvetica', "\x80" ) === 556.0 && Pdf\Fonts\Metrics::width( 'Helvetica', ' ' ) === 278.0 && Pdf\Fonts\Metrics::width( 'Helvetica', "\x01" ) === 0.0
	&& count( (array) require dirname( __DIR__ ). '/Fonts/Metrics/Helvetica-BoldOblique.php' ) === 224 && ( require dirname( __DIR__ ). '/Fonts/Metrics/Courier-Bold.php' ) === 600 );
check( 'a family and a style name one of twelve base fonts', Pdf\Fonts\Metrics::font( 'times', true, true ) === 'Times-BoldItalic' && Pdf\Fonts\Metrics::font( 'courier', false, true ) === 'Courier-Oblique' && Pdf\Fonts\Metrics::font( 'helvetica', true, false ) === 'Helvetica-Bold'
	&& Pdf\Fonts\Metrics::font( 'nothing', false, false ) === 'Helvetica' );
check( 'text is written in the code page of the fonts: o umlaut, euro sign and typographic quotes are one byte, what the page cannot carry is a question mark', Pdf\Layout\Text::encode( "\u{F6}\u{20AC}\u{2019}" ) === "\xF6\x80\x92" && Pdf\Layout\Text::encode( "\u{65E5}x" ) === '?x' && bin2hex( Pdf\Layout\Text::encode( "a\u{AD}b" ) ) === '6162' );

$type = [ 'f' => 'Helvetica', 'z' => 10.0, 'u' => false ];
$lines = Pdf\Layout\Text::wrap( Pdf\Layout\Text::atoms( "one  two\n three four five six seven", $type ), 70.0, $type );
check( 'text wraps at word boundaries on the measured widths: no line is wider than the room, whitespace collapses, and the words stay whole', count( $lines ) > 2 && array_filter( $lines, static fn( array $line ): bool => $line['w'] > 70.01 ) === []
	&& implode( ' ', array_map( static fn( array $line ): string => implode( '', array_column( $line['a'], 's' ) ), $lines ) ) === 'one two three four five six seven' );
$wide = Pdf\Layout\Text::wrap( Pdf\Layout\Text::atoms( str_repeat( 'W', 40 ), $type ), 50.0, $type );
check( 'a word wider than the line is broken at the width, and not before', count( $wide ) > 3 && array_filter( $wide, static fn( array $line ): bool => $line['w'] > 50.01 ) === [] && implode( '', array_map( static fn( array $line ): string => implode( '', array_column( $line['a'], 's' ) ), $wide ) ) === str_repeat( 'W', 40 )
	&& $wide[0]['w'] > 40.0 );
$glued = Pdf\Layout\Text::wrap( array_merge( Pdf\Layout\Text::atoms( 'x ab', $type ), Pdf\Layout\Text::atoms( 'cd', [ 'f' => 'Helvetica-Bold' ] + $type ) ), 25.0, $type );
check( 'words that touch are not parted: a bold word in a word moves to the next line with the rest of it; and the line is as high as 1.35 times the type', count( $glued ) === 2 && implode( '', array_column( $glued[0]['a'], 's' ) ) === 'x'
	&& implode( '', array_column( $glued[1]['a'], 's' ) ) === 'abcd' && abs( $glued[0]['h'] - 13.5 ) < 0.001 );

$names = [ 'name' => 'Ada <b>', 'client' => [ 'city' => 'Köln', 'tags' => [ 'a' ] ], 'page' => '7', 'number' => 12 ];
check( 'tokens: a plain and a dotted name are filled and escaped as text; an unknown one, an array and a missing key are removed', Pdf\Layout\Tokens::fill( $appData, '[[name]]|[[client.city]]|[[client.tags]]|[[client.none]]|[[nothing]]|[[number]]', $names ) === 'Ada &lt;b&gt;|Köln||||12' );
check( '...in a preview an unknown name stands as itself in square brackets - the opening one as its character reference; page and pages stay for the engine in a header or a footer only', Pdf\Layout\Tokens::fill( $appData, '[[nothing]] [[client.none]]', $names, true ) === '&#91;nothing] &#91;client.none]'
	&& Pdf\Layout\Tokens::fill( $appData, '[[page]] [[pages]]', $names, false, true ) === '[[page]] [[pages]]' && Pdf\Layout\Tokens::fill( $appData, '[[page]] [[pages]]', $names ) === '7 ' );
\Nino\Html::addFills( $appData, [ '[[/project/company/general/name]]' => 'Acme & <Sons>' ] );
check( '...a token with a leading slash is the text of the site for that key, escaped as text; a key without a text is removed', Pdf\Layout\Tokens::fill( $appData, '[[/project/company/general/name]]|[[/project/company/general/none]]', [] ) === 'Acme &amp; &lt;Sons&gt;|' );
check( '...a value that holds a token is not read for tokens again, and its square brackets are character references: what reads the HTML afterwards for page and pages finds no token in it', Pdf\Layout\Tokens::fill( $appData, '[[a]]', [ 'a' => '[[b]]', 'b' => 'x' ] ) === '&#91;&#91;b]]'
	&& Pdf\Layout\Tokens::fill( $appData, '[[a]]', [ 'a' => 'X[[pages]]Y' ], false, true ) === 'X&#91;&#91;pages]]Y' );
\Nino\Html::addFills( $appData, [ '[[/project/company/general/pages]]' => 'See [[pages]]' ] );
check( '...and so are those of a text of the site', Pdf\Layout\Tokens::fill( $appData, '[[/project/company/general/pages]]', [], false, true ) === 'See &#91;&#91;pages]]' );

// --- the tables ----------------------------------------------------------------------------------------

$rows = '';
for( $row = 1; $row <= 50; $row++ )
	$rows .= '<tr><td>Row '. $row. '</td><td>Value</td></tr>';
$post( 'pdf/templates/save', [ 'key' => 'table', 'isNew' => true, 'name' => 'Table', 'tag' => 'information', 'blocks' => [ [ 'type' => 'slot', 'name' => 'rows' ] ] ] );
$tabled = (string) Pdf::render( $appData, 'table', [ 'slots' => [ 'rows' => '<table><thead><tr><th>Head A</th><th>Head B</th></tr></thead><tbody>'. $rows. '</tbody></table>' ] ] );
check( 'a table that does not fit moves its rows to the next page, and the rows of the thead are drawn there again', $pages( $tabled ) === 2 && substr_count( $content( $tabled, 0 ), '(Head A) Tj' ) === 1 && substr_count( $content( $tabled, 1 ), '(Head A) Tj' ) === 1
	&& str_contains( $content( $tabled, 1 ), '(Row 50) Tj' ) === true && str_contains( $content( $tabled, 0 ), '(Row 50) Tj' ) === false );
check( '...a table with border="0" has no hairline under its rows; with the rule under a th row it has one', substr_count( $content( (string) Pdf::render( $appData, 'table', [ 'slots' => [ 'rows' => '<table border="0"><tr><td>a</td></tr><tr><td>b</td></tr></table>' ] ] ), 0 ), ' l S' ) === 0
	&& substr_count( $content( (string) Pdf::render( $appData, 'table', [ 'slots' => [ 'rows' => '<table><tr><td>a</td></tr><tr><td>b</td></tr></table>' ] ] ), 0 ), ' l S' ) === 2 );
ninoWarnings();
$tall = (string) Pdf::render( $appData, 'table', [ 'slots' => [ 'rows' => '<table><tr><td>'. str_repeat( 'tall ', 6000 ). '</td></tr><tr><td>after</td></tr></table>' ] ] );
check( 'a single row taller than a page is drawn clipped on a page of its own, with an E_USER_WARNING; the next row starts another page', $pages( $tall ) === 2 && str_contains( $content( $tall, 0 ), ' re W n' ) === true && str_contains( $content( $tall, 1 ), '(after) Tj' ) === true
	&& count( array_filter( ninoWarnings(), static fn( string $line ): bool => str_contains( $line, 'taller than a page' ) ) ) === 1 );

$grid = (string) Pdf::render( $appData, 'table', [ 'slots' => [ 'rows' => '<table><tr><td width="25%">a</td><td>b</td></tr></table>' ] ] );
preg_match_all( '/BT \/F\d+ [\d.]+ Tf ([\d.]+) [\d.]+ Td \((a|b)\) Tj ET/', $content( $grid, 0 ), $columns );
$column = array_combine( $columns[2], array_map( 'floatval', $columns[1] ) );
check( 'the columns take the width the cells of the first row ask for in percent, and share the rest: the second starts a quarter of the column in, plus the padding', abs( ( $column['b'] - $column['a'] ) - 0.25 * ( 612 - ( 16 + 18 ) * 72 / 25.4 ) ) < 0.02 );

// --- the layout around the text ----------------------------------------------------------------------------

$post( 'pdf/layout/save', [ 'format' => 'a4', 'margins' => [ 20, 20, 20, 20 ], 'font' => 'courier', 'size' => 10, 'header' => '<h3>Head</h3><ul><li>one</li><li>two</li></ul><p><img src="pdf:logo" width="20"></p>', 'footer' => '<hr><p align="center">[[page]]</p>' ] );
$cour = (string) Pdf::render( $appData, 'invoice-b' );
check( 'the layout is applied: A4 in points, the base font of the family, a rule and a centred footer', str_contains( $cour, '/MediaBox [ 0 0 595.276 841.89 ]' ) === true && str_contains( $cour, '/BaseFont /Courier' ) === true && str_contains( $cour, '/BaseFont /Courier-Bold' ) === true );
check( '...an img with src="pdf:logo" is drawn only when the layout has a logo, and any other src is left out', substr_count( $cour, '/Subtype /Image' ) === 0 );
$upload( 'logo', $picture( 'png', 80, 40 ) );
$withLogo = (string) Pdf::render( $appData, 'table', [ 'slots' => [ 'rows' => '<p><img src="https://example.com/x.png" width="20">text</p><p><img src="pdf:logo" width="20"></p>' ] ] );
check( '...with a logo it is: one image XObject, drawn where the header and the text name it; an img of any other src is left out', substr_count( $withLogo, '/Subtype /Image' ) === 1 && substr_count( $content( $withLogo, 0 ), ' Do Q' ) === 2 );
$tallBand = '<p>'. str_repeat( 'band ', 780 ). '</p>';
$post( 'pdf/layout/save', [ 'format' => 'a4', 'margins' => [ 20, 20, 20, 20 ], 'font' => 'helvetica', 'size' => 10, 'header' => $tallBand, 'footer' => '' ] );
$capped = (string) Pdf::render( $appData, 'long' );
preg_match_all( '/BT \/F\d+ [\d.]+ Tf [\d.]+ ([\d.]+) Td \(word word/', $content( $capped, 0 ), $body );
check( 'a header never takes more than 40 mm: the text starts 20 mm of margin, 40 of header and 4 of gap down from the top - the first baseline 9.3 points below that - and the header is clipped to its band', $body[1] !== []
	&& abs( max( array_map( 'floatval', $body[1] ) ) - ( 841.8898 - 64 * 72 / 25.4 - 9.305 ) ) < 0.05 && str_contains( $content( $capped, 0 ), ' re W n' ) === true );
$post( 'pdf/layout/save', [ 'format' => 'a4', 'margins' => [ 20, 20, 20, 20 ], 'font' => 'helvetica', 'size' => 10, 'header' => '', 'footer' => '' ] );

// A value is text: it cannot take the page numbers of a header or a footer, whatever it holds
$post( 'pdf/layout/save', [ 'format' => 'a4', 'margins' => [ 20, 20, 20, 20 ], 'font' => 'helvetica', 'size' => 10, 'header' => '<p>[[c]] / [[page]] of [[pages]]</p>', 'footer' => '<p>[[c]]</p>' ] );
$injected = (string) Pdf::render( $appData, 'invoice-b', [ 'tokens' => [ 'c' => 'X[[pages]]Y [[page]]' ] ] );
check( 'a value that holds the page tokens is text in a header and in a footer, and the numbers are the engine\'s: the characters of the value are drawn, the page and the pages after them', $pages( $injected ) === 1
	&& str_contains( $content( $injected, 0 ), '(X[[pages]]Y [[page]] / 1 of 1) Tj' ) === true && str_contains( $content( $injected, 0 ), '(X[[pages]]Y [[page]]) Tj' ) === true );
if( $pdftotext === true )
	check( '...and pdftotext reads them as they were handed in, X[[pages]]Y, in both', substr_count( $words( $injected ), 'X[[pages]]Y [[page]]' ) === 2 && str_contains( $words( $injected ), 'X1Y' ) === false );

// A header that no save of the panel wrote - a file put there by hand - is held to the list when a document is laid out
\Nino\Filesystem::putFileContent( $appData, Pdf::LAYOUT, [ 'header' => '<p onclick="x()">Band text</p><script>band_hidden()</script><title>band_title</title>', 'footer' => '' ] + Pdf::layout( $appData ) );
$handmade = (string) Pdf::render( $appData, 'invoice-b' );
check( 'a header in a file of the layout is held to the list as a saved one is: its text is drawn, its script and its title are not', str_contains( $content( $handmade, 0 ), '(Band text) Tj' ) === true && str_contains( $content( $handmade, 0 ), 'band_hidden' ) === false && str_contains( $content( $handmade, 0 ), 'band_title' ) === false );
$post( 'pdf/layout/save', [ 'format' => 'a4', 'margins' => [ 20, 20, 20, 20 ], 'font' => 'helvetica', 'size' => 10, 'header' => '', 'footer' => '' ] );

// --- the geometry of the HTML -------------------------------------------------------------------------------

$fontOf = static function( string $pdf, int $page, string $name ) use ( $object, $pageObjects ): string {
	preg_match( '#/Font <<([^>]*)>>#', $object( $pdf, $pageObjects( $pdf )[$page] ), $fonts );
	preg_match( '#/'. $name. ' (\d+) 0 R#', $fonts[1] ?? '', $found );
	return preg_match( '#/BaseFont /([A-Za-z-]+)#', $object( $pdf, (int) ( $found[1] ?? 0 ) ), $base ) === 1 ? $base[1] : '';
};
// [ text => [ x, font, size, y ] ] of the strings a page shows
$shown = static function( string $pdf, int $page ) use ( $content, $fontOf ): array {
	preg_match_all( '#BT /(F\d+) ([\d.]+) Tf ([\d.]+) ([\d.]+) Td \(([^)]*)\) Tj ET#', $content( $pdf, $page ), $found, PREG_SET_ORDER );
	$out = [];
	foreach( $found as $one )
		$out[ $one[5] ] = [ (float) $one[3], $fontOf( $pdf, $page, $one[1] ), (float) $one[2], (float) $one[4] ];
	return $out;
};

$post( 'pdf/layout/save', [ 'format' => 'a4', 'margins' => [ 20, 20, 20, 20 ], 'font' => 'helvetica', 'size' => 10, 'header' => '<p>[[/project/company/general/name]] / [[page]]</p>', 'footer' => '' ] );
$html = '<h1>One</h1><h2>Two</h2><h3>Three</h3><p>Plain</p><ul><li>Item</li></ul><ol><li>First</li></ol><p align="right">Right</p><p style="text-align:center">Centre</p>'
	. '<p><b>Bold</b> <i>Italic</i> <b><i>Both</i></b> <a href="#">Link</a></p><table><tr><th>Head</th></tr><tr><td>Cell</td></tr></table>'
	. '<p>one<br>two<br><br>three</p><p>Seen</p><script>hidden()</script><style>.quiet{}</style><template>tpl_hidden</template><title>title_hidden</title>';
$geometry = (string) Pdf::render( $appData, 'table', [ 'slots' => [ 'rows' => $html ] ] );
$at = $shown( $geometry, 0 );

check( 'a heading is bold and 1.8, 1.4 or 1.15 times the base size; a paragraph is the base size in the font of the layout', $at['One'][2] === 18.0 && $at['Two'][2] === 14.0 && $at['Three'][2] === 11.5 && $at['Plain'][2] === 10.0
	&& $at['One'][1] === 'Helvetica-Bold' && $at['Two'][1] === 'Helvetica-Bold' && $at['Three'][1] === 'Helvetica-Bold' && $at['Plain'][1] === 'Helvetica' );
check( '...a list item is indented 6 mm, with its bullet - or its number - in front of it', abs( $at['Item'][0] - $at['Plain'][0] - 6 * 72 / 25.4 ) < 0.05 && isset( $at['\\225'] ) === true && $at['\\225'][0] < $at['Item'][0] && isset( $at['1.'] ) === true && $at['1.'][0] < $at['First'][0] );
check( '...a paragraph can be set right or centred: its text ends at the right margin or stands in the middle of the column', abs( $at['Right'][0] - ( 595.2756 - 20 * 72 / 25.4 - 23.34 ) ) < 0.05
	&& abs( $at['Centre'][0] - ( 20 * 72 / 25.4 + ( 170 * 72 / 25.4 - 30.01 ) / 2 ) ) < 0.05 );
check( '...bold, italic and both are the fonts of the family; a th is bold, and the rule under its row is a heavier line than the hairlines', $at['Bold'][1] === 'Helvetica-Bold' && $at['Italic'][1] === 'Helvetica-Oblique' && $at['Both'][1] === 'Helvetica-BoldOblique'
	&& $at['Head'][1] === 'Helvetica-Bold' && $at['Cell'][1] === 'Helvetica' && str_contains( $content( $geometry, 0 ), 'q 0.8 w' ) === true && str_contains( $content( $geometry, 0 ), 'q 0.4 w' ) === true );
check( '...a br ends the line and two make an empty one, at 1.35 times the type; a script and a style are no text of the document', abs( $at['one'][3] - $at['two'][3] - 13.5 ) < 0.001 && abs( $at['two'][3] - $at['three'][3] - 27 ) < 0.001
	&& isset( $at['Seen'] ) === true && str_contains( $content( $geometry, 0 ), 'hidden' ) === false && str_contains( $content( $geometry, 0 ), 'quiet' ) === false );
$unlisted = (string) Pdf::render( $appData, 'table', [ 'slots' => [ 'rows' => '<p onclick="alert(1)">Before <font color="red">Unwrapped</font> <u>Under</u> <a href="javascript:alert(1)">Link</a></p><section>Section</section><img src="https://example.com/x.png" width="20"><p>After</p>' ] ] );
check( '...a slot is held to the list of what a document may hold as a saved text is: the text of a tag that is not listed is drawn, the tag and the address of a link and of a picture that is not the logo are not in the file, and there is no picture',
	str_contains( $content( $unlisted, 0 ), '(Before Unwrapped Under ) Tj' ) === true && str_contains( $content( $unlisted, 0 ), '(Link) Tj' ) === true && str_contains( $content( $unlisted, 0 ), '(Section) Tj' ) === true && str_contains( $content( $unlisted, 0 ), '(After) Tj' ) === true
	&& preg_match( '/font|javascript|example\.com|onclick|alert/', $unlisted ) === 0 && str_contains( $unlisted, '/Subtype /Image' ) === false );
if( $pdftotext === true )
	check( '...and pdftotext reads the text of it, not the tags', preg_match( '/Before Unwrapped Under Link\s+Section\s+After/', $words( $unlisted ) ) === 1 && preg_match( '/<font|<section|<u>|<a |javascript|onclick/', $words( $unlisted ) ) === 0 );
check( '...a link is its text underlined, with no annotation; a text key and a token in the header are filled in before the page is laid out', substr_count( $content( (string) Pdf::render( $appData, 'table', [ 'slots' => [ 'rows' => '<p><a href="#">Link</a> and more</p>' ] ] ), 0 ), ' l S' ) === 1
	&& str_contains( $geometry, '/Annots' ) === false && str_contains( $content( $geometry, 0 ), '(Acme & <Sons> / 1) Tj' ) === true );
$post( 'pdf/layout/save', [ 'format' => 'a4', 'margins' => [ 20, 20, 20, 20 ], 'font' => 'helvetica', 'size' => 10, 'header' => '', 'footer' => '' ] );

// --- the paper and the boxes on it ------------------------------------------------------------------------------

// A signature box that lies on the page of A4 may lie off the page of US Letter, and the other way round: the paper is not changed under the templates
$seal = static fn( array $change ): array => [ $change + [ 'type' => 'signature', 'name' => 'seal', 'party' => 'member', 'label' => '', 'page' => 'last', 'x' => 20, 'y' => 270, 'w' => 60, 'h' => 20, 'line' => false ] ];
$onLetter = [ 'format' => 'letter', 'margins' => [ 20, 20, 20, 20 ], 'font' => 'helvetica', 'size' => 12, 'header' => '', 'footer' => '' ];
$post( 'pdf/templates/save', [ 'key' => 'low-b', 'isNew' => true, 'name' => 'Low B', 'tag' => 'offer', 'blocks' => $seal( [] ) ] );
$post( 'pdf/templates/save', [ 'key' => 'low-a', 'isNew' => true, 'name' => 'Low A', 'tag' => 'offer', 'blocks' => $seal( [] ) ] );
\Nino\Filesystem::putFileContent( $appData, Pdf::TEMPLATES. '/ghost.php', [ 'name' => 'Ghost', 'tag' => 'offer', 'blocks' => $seal( [ 'y' => 295 ] ) ] );
[ $status, $body ] = $post( 'pdf/layout/save', [ 'format' => 'a4' ] + $onLetter );
check( 'layout/save with the paper it has is no change of paper, whatever boxes lie off the page: it is saved', $status === 200 && Pdf::layout( $appData )['size'] === 12 && Pdf::layout( $appData )['format'] === 'a4' );
[ $status, $body ] = $post( 'pdf/layout/save', $onLetter );
check( '...but the other paper is 409 with the code, the names of the templates whose boxes would lie off the page - in the order of their keys, a template the panel did not write as well - and the field; the form is written not at all', $status === 409 && $body['code'] === 'pdf_format_boxes'
	&& $body['params'] === [ 'Ghost, Low A, Low B' ] && $body['field'] === 'format' && Pdf::layout( $appData )['format'] === 'a4' && $post( 'pdf/layout/save', [ 'size' => 9 ] + $onLetter )[0] === 409 && Pdf::layout( $appData )['size'] === 12 );
$post( 'pdf/templates/delete', [ 'key' => 'ghost' ] );
foreach( [ 'low-a' => 'Low A', 'low-b' => 'Low B' ] as $key => $name )
	$post( 'pdf/templates/save', [ 'key' => $key, 'isNew' => false, 'name' => $name, 'tag' => 'offer', 'blocks' => $seal( [ 'y' => 200 ] ) ] );
check( '...once every box lies on both papers the paper changes', $post( 'pdf/layout/save', $onLetter )[0] === 200 && Pdf::layout( $appData )['format'] === 'letter' );
check( '...and a template that is saved on the new paper is held to its page: the box that lies on A4 does not lie on Letter', $post( 'pdf/templates/save', [ 'key' => 'low-a', 'isNew' => false, 'name' => 'Low A', 'tag' => 'offer', 'blocks' => $seal( [] ) ] )[1]['code'] === 'pdf_signature_box' );
$post( 'pdf/templates/save', [ 'key' => 'wide', 'isNew' => true, 'name' => 'Wide', 'tag' => 'offer', 'blocks' => $seal( [ 'x' => 155, 'y' => 200 ] ) ] );
[ $status, $body ] = $post( 'pdf/layout/save', [ 'format' => 'a4' ] + $onLetter );
check( '...and the way back is held to the width: a box that lies on Letter and not on A4 refuses A4, naming that template alone', $status === 409 && $body['params'] === [ 'Wide' ] && Pdf::layout( $appData )['format'] === 'letter' );
foreach( [ 'wide', 'low-a', 'low-b' ] as $key )
	$post( 'pdf/templates/delete', [ 'key' => $key ] );
$post( 'pdf/layout/save', [ 'format' => 'a4', 'margins' => [ 20, 20, 20, 20 ], 'font' => 'helvetica', 'size' => 10, 'header' => '', 'footer' => '' ] );

$post( 'pdf/templates/save', [ 'key' => 'stamp', 'isNew' => true, 'name' => 'Stamp', 'tag' => 'offer', 'blocks' => [ [ 'type' => 'signature', 'name' => 'seal', 'party' => 'member', 'label' => '', 'page' => 'last', 'x' => 20, 'y' => 200, 'w' => 60, 'h' => 20, 'line' => false ] ] ] );
$stamped = (string) Pdf::render( $appData, 'stamp', [], [ 'signatures' => [ 'seal' => $picture( 'png', 20, 40 ) ] ] );
preg_match( '#q ([\d.]+) 0 0 ([\d.]+) ([\d.]+) ([\d.]+) cm /Im1 Do Q#', $content( $stamped, 0 ), $cm );
check( 'a signature picture is fitted into its box keeping its ratio, bottom-aligned at the left edge: a tall picture in a wide box is as high as the box and half as wide, and stands on the bottom edge', $pages( $stamped ) === 1 && count( $cm ) === 5
	&& abs( $cm[1] - 10 * 72 / 25.4 ) < 0.05 && abs( $cm[2] - 20 * 72 / 25.4 ) < 0.05 && abs( $cm[3] - 20 * 72 / 25.4 ) < 0.05 && abs( $cm[4] - ( 841.8898 - 220 * 72 / 25.4 ) ) < 0.05 );
$post( 'pdf/templates/delete', [ 'key' => 'stamp' ] );

// --- the appended part ---------------------------------------------------------------------------------------------

// The option append of render(): an HTML part on a page of its own after the last block, which a signature feature uses for the record of a signing
$record = '<h2>Appended record</h2><p>Appended text of the caller</p>';
$base = (string) Pdf::render( $appData, 'invoice-b' );
$appended = (string) Pdf::render( $appData, 'invoice-b', [], [ 'append' => $record ] );
check( 'append lays the part out on a page of its own after the last block: one page more, its text on the last page and not on the first - and a render without the option is the render it was, byte for byte',
	$pages( $base ) === 1 && $pages( $appended ) === 2 && str_contains( $content( $appended, 1 ), '(Appended text of the caller) Tj' ) === true && str_contains( $content( $appended, 0 ), 'Appended' ) === false
	&& $base === Pdf::render( $appData, 'invoice-b' ) && $appended === Pdf::render( $appData, 'invoice-b', [], [ 'append' => $record ] ) );
check( '...nothing is added where there is nothing to add: an empty option, one of blanks, one that is no text and one that holds nothing to draw leave the bytes as they were',
	Pdf::render( $appData, 'invoice-b', [], [ 'append' => '' ] ) === $base && Pdf::render( $appData, 'invoice-b', [], [ 'append' => " \n\t " ] ) === $base
	&& Pdf::render( $appData, 'invoice-b', [], [ 'append' => [ '<p>x</p>' ] ] ) === $base && Pdf::render( $appData, 'invoice-b', [], [ 'append' => '<script>hidden()</script><style>p{}</style>' ] ) === $base );

$held = (string) Pdf::render( $appData, 'invoice-b', [], [ 'append' => '<p onclick="alert(1)">Held <font color="red">text</font></p><script>hidden()</script><img src="https://example.com/x.png" width="20">' ] );
check( '...it is held to the list of what a document may hold, as a slot is: the text of a tag that is not listed is drawn, and an attribute, a script, an address and a picture that is not the logo are not in the file',
	$pages( $held ) === 2 && str_contains( $content( $held, 1 ), '(Held text) Tj' ) === true && preg_match( '/onclick|alert|hidden|example\.com|<font/', $held ) === 0 && str_contains( $held, '/Subtype /Image' ) === false );

$post( 'pdf/layout/save', [ 'format' => 'a4', 'margins' => [ 20, 20, 20, 20 ], 'font' => 'helvetica', 'size' => 10, 'header' => '<p>Head [[page]]</p>', 'footer' => '<p>Page [[page]] / [[pages]]</p>' ] );
$framed = (string) Pdf::render( $appData, 'invoice-b', [], [ 'append' => $record ] );
$longer = (string) Pdf::render( $appData, 'invoice-b', [], [ 'append' => '<p>'. str_repeat( 'word ', 1500 ). '</p>' ] );
check( '...the appended page has the header and the footer of the layout and [[pages]] counts it: page 2 of 2; a part longer than a page goes on to the next, and the last of three says 3 of 3',
	str_contains( $content( $framed, 0 ), '(Page 1 / 2) Tj' ) === true && str_contains( $content( $framed, 1 ), '(Page 2 / 2) Tj' ) === true && str_contains( $content( $framed, 1 ), '(Head 2) Tj' ) === true
	&& $pages( $longer ) === 3 && str_contains( $content( $longer, 2 ), '(Page 3 / 3) Tj' ) === true );
$post( 'pdf/layout/save', [ 'format' => 'a4', 'margins' => [ 20, 20, 20, 20 ], 'font' => 'helvetica', 'size' => 10, 'header' => '', 'footer' => '' ] );

// The fields belong to the pages of the template: the last page is the last before the appended one
$field = static fn( string $name, int|string $page, string $label = '' ): array => [ 'type' => 'signature', 'name' => $name, 'party' => 'member', 'label' => $label, 'page' => $page, 'x' => 20, 'y' => 200, 'w' => 60, 'h' => 20, 'line' => false ];
$post( 'pdf/templates/save', [ 'key' => 'append-seal', 'isNew' => true, 'name' => 'Append seal', 'tag' => 'offer', 'blocks' => [ [ 'type' => 'html', 'block' => 'intro' ], $field( 'seal', 'last' ), $field( 'later', 2 ) ] ] );
$sealed = (string) Pdf::render( $appData, 'append-seal', [], [ 'signatures' => [ 'seal' => $picture( 'png', 20, 40 ), 'later' => $picture( 'png', 20, 40 ) ], 'append' => $record ] );
check( 'a field on the last page stays on the last page of the template and not on the appended one, and a field on a page past the template is not drawn on the appended page: the picture is on the first of the two pages only',
	$pages( $sealed ) === 2 && str_contains( $object( $sealed, $pageObjects( $sealed )[0] ), '/XObject <<' ) === true && str_contains( $object( $sealed, $pageObjects( $sealed )[1] ), '/XObject' ) === false
	&& str_contains( $content( $sealed, 1 ), ' Do Q' ) === false && str_contains( $content( $sealed, 0 ), ' Do Q' ) === true && substr_count( $content( $sealed, 0 ), ' Do Q' ) === 1 );

check( '...a template with text keeps it on its own page and the part comes on the one after: the text of the template is on the first page and not on the second, the part on the second and not on the first',
	str_contains( $content( $sealed, 0 ), 'Hello' ) === true && str_contains( $content( $sealed, 0 ), 'Appended' ) === false && str_contains( $content( $sealed, 1 ), 'Appended' ) === true && str_contains( $content( $sealed, 1 ), 'Hello' ) === false );

$post( 'pdf/templates/save', [ 'key' => 'append-empty', 'isNew' => true, 'name' => 'Append empty', 'tag' => 'offer', 'blocks' => [ $field( 'seal', 'last', 'The seal' ) ] ] );
$emptied = (string) Pdf::render( $appData, 'append-empty', [], [ 'append' => $record ] );
check( '...a template with no text has its page all the same - the part is the second, not the first', $pages( $emptied ) === 2 && str_contains( $content( $emptied, 0 ), '(The seal) Tj' ) === true
	&& str_contains( $content( $emptied, 1 ), '(Appended text of the caller) Tj' ) === true && $pages( (string) Pdf::render( $appData, 'append-empty' ) ) === 1 );

$post( 'pdf/templates/save', [ 'key' => 'append-end', 'isNew' => true, 'name' => 'Append end', 'tag' => 'offer', 'blocks' => [ [ 'type' => 'html', 'block' => 'intro' ], [ 'type' => 'pagebreak' ] ] ] );
check( '...and a template that ends in a page break adds no page of its own before the part: the part is the second page, not the third', $pages( (string) Pdf::render( $appData, 'append-end' ) ) === 1
	&& $pages( (string) Pdf::render( $appData, 'append-end', [], [ 'append' => $record ] ) ) === 2 );
if( $pdftotext === true )
	check( '...and pdftotext reads the part on the last page, after the text of the template', preg_match( '/Appended record\s+Appended text of the caller/', $words( $appended, '-f 2 -l 2' ) ) === 1 && str_contains( $words( $appended, '-f 1 -l 1' ), 'Appended' ) === false );

foreach( [ 'append-seal', 'append-empty', 'append-end' ] as $key )
	$post( 'pdf/templates/delete', [ 'key' => $key ] );

// --- the blob frame -----------------------------------------------------------------------------------------------

$request = [ '/nino/http/request' => [ 'uri' => '/_admin' ], '/nino/http/response' => [ 'header' => [ 'Content-Security-Policy' => "default-src 'self'; img-src * data:" ] ] ];
\Nino\Modules\Pdf::callbackOutput( $appData, $request );
$site = [ '/nino/http/request' => [ 'uri' => '/legal' ], '/nino/http/response' => [ 'header' => [ 'Content-Security-Policy' => "default-src 'self'; img-src * data:" ] ] ];
\Nino\Modules\Pdf::callbackOutput( $appData, $site );
check( 'the workbench may frame a blob: address, by one source added to its frame-src; a page of the site keeps its policy as it is', $request['/nino/http/response']['header']['Content-Security-Policy'] === "default-src 'self'; img-src * data:; frame-src 'self' blob:"
	&& $site['/nino/http/response']['header']['Content-Security-Policy'] === "default-src 'self'; img-src * data:"
	&& in_array( [ \Nino\Modules\Pdf::class, 'callbackOutput' ], array_merge( ...( $appData['./nino/callbacks']['/nino/http/output'] ?? [ [] ] ) ), true ) === true );

// --- the log, deactivation ------------------------------------------------------------------------------------------

$log = static fn( string $action, array $data = [] ): string => \Nino\Modules\Pdf\Admin::log( $action, $data );
$writes = [ 'pdf/layout/save', 'pdf/layout/image', 'pdf/layout/image/delete', 'pdf/blocks/save', 'pdf/blocks/delete', 'pdf/templates/save', 'pdf/templates/delete', 'pdf/templates/duplicate' ];
$reads = [ 'pdf/layout/get', 'pdf/blocks/list', 'pdf/templates/list', 'pdf/templates/get', 'pdf/preview' ];
check( 'the activity log has a line for each action that writes - it names the key of the template or the block, or the kind of the picture, and what was done differs from one action to the next - and none for an action that reads', array_sum( array_map( static fn( string $action ): int => $log( $action, [ 'key' => 'offer-one', 'kind' => 'logo' ] ) !== '' ? 1 : 0, $writes ) ) === count( $writes )
	&& count( array_unique( array_map( static fn( string $action ): string => $log( $action, [ 'key' => 'offer-one', 'kind' => 'logo' ] ), $writes ) ) ) === count( $writes )
	&& str_contains( $log( 'pdf/templates/save', [ 'key' => 'offer-one' ] ), 'offer-one' ) === true && str_contains( $log( 'pdf/templates/delete', [ 'key' => 'offer-one' ] ), 'offer-one' ) === true && str_contains( $log( 'pdf/blocks/delete', [ 'key' => 'intro' ] ), 'intro' ) === true
	&& str_contains( $log( 'pdf/layout/image', [ 'kind' => 'logo' ] ), 'logo' ) === true && str_contains( $log( 'pdf/layout/image/delete', [ 'kind' => 'signature' ] ), 'signature' ) === true
	&& array_filter( array_map( static fn( string $action ): string => $log( $action, [ 'key' => 'offer-one', 'kind' => 'logo' ] ), $reads ) ) === [] );
check( 'the Dashboard tile counts the templates there are now', \Nino\Modules\Pdf\Admin::summary( $appData )['value'] === count( Pdf::keys( $appData ) ) && count( Pdf::keys( $appData ) ) === 7 );

$files = glob( \Nino\Filesystem::path( $appData, Pdf::DIR ). '/*' );
check( 'deactivation takes the panel away and keeps /data/pdf', \Nino\Features::deactivate( $appData, 'pdf' ) === true && isset( \Nino\Admin\Admin::panels( $appData )['pdf'] ) === false
	&& glob( \Nino\Filesystem::path( $appData, Pdf::DIR ). '/*' ) === $files && is_file( \Nino\Filesystem::path( $appData, Pdf::TEMPLATES. '/offer-one.php' ) ) === true && count( $files ) === 4 );

// --- the panel's script, where node is on the path -------------------------------------------------------------------

$node = function_exists( 'shell_exec' ) === true ? trim( (string) @shell_exec( 'command -v node 2>/dev/null' ) ) : '';

if( $node === '' || function_exists( 'exec' ) === false )
	echo "  --  - node is not available here: pdf-js-smoke.js was NOT run\n";
else {
	$output = [];
	$exit = 1;
	exec( 'NINO_ROOT='. escapeshellarg( $root ). ' '. escapeshellarg( $node ). ' '. escapeshellarg( __DIR__. '/pdf-js-smoke.js' ). ' 2>&1', $output, $exit );
	check( 'pdf-js-smoke.js passes - '. ( $output === [] ? 'no output' : end( $output ) ), $exit === 0 );
}

ninoDone( $appData );
