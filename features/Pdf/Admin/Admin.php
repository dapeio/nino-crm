<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Modules\Pdf\Admin		The /_admin panel of the Pdf feature
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules\Pdf {

	use Nino\Modules\Pdf as Feature;

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Pdf\Admin					The "PDF" panel: a pane of three tabs - the Layout, which is the
	 *										panel's own screen, the text blocks and the templates - and,
	 *										last, the Settings tab the registry attaches, where the tags
	 *										of the project are.
	 *
	 *										Layout: the paper, the four margins, the font and the base size,
	 *										the header and the footer as HTML, the design - the feature's own
	 *										rules as CSS, read-only, and the owner's rules over them, with a
	 *										preview of the saved design - the logo and the owner's
	 *										signature as pictures. Text blocks: the library, a title and
	 *										HTML each, which a template uses by reference. Templates: a name,
	 *										a tag and the ordered blocks of a document - html, a slot, an
	 *										input, a signature field at a position, a page break - with a
	 *										preview that renders what the editor holds, saved or not, with
	 *										sample data.
	 *
	 *										The two tabs bring no action of their own: every pdf/* action
	 *										stays on this panel, whichever tab asks for it, and the tabs
	 *										share its permission. The checks and the writes are
	 *										\Nino\Modules\Pdf\Store's; this class guards, reads the request,
	 *										and answers.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Admin {

		public const string MANAGE_PERM = '/_admin/pdf/manage';

		// The key a duplicate was made under, by action: written by the action, read by log() in the same request
		private static array $_made = [];

		public static function perm(): string {
			return self::MANAGE_PERM;
		}

		public static function actions(): array {
			return [
				'pdf/layout/get'					=> [ self::class, 'apiLayoutGet' ],
				'pdf/layout/save'					=> [ self::class, 'apiLayoutSave' ],
				'pdf/layout/image'				=> [ self::class, 'apiLayoutImage' ],
				'pdf/layout/image/delete'	=> [ self::class, 'apiLayoutImageDelete' ],
				'pdf/layout/preview'			=> [ self::class, 'apiLayoutPreview' ],
				'pdf/blocks/list'					=> [ self::class, 'apiBlocksList' ],
				'pdf/blocks/save'					=> [ self::class, 'apiBlocksSave' ],
				'pdf/blocks/delete'				=> [ self::class, 'apiBlocksDelete' ],
				'pdf/templates/list'			=> [ self::class, 'apiTemplatesList' ],
				'pdf/templates/get'				=> [ self::class, 'apiTemplatesGet' ],
				'pdf/templates/save'			=> [ self::class, 'apiTemplatesSave' ],
				'pdf/templates/delete'		=> [ self::class, 'apiTemplatesDelete' ],
				'pdf/templates/duplicate'	=> [ self::class, 'apiTemplatesDuplicate' ],
				'pdf/preview'							=> [ self::class, 'apiPreview' ],
			];
		}

		// No group is named here: a panel a feature brought always lands in the rail's own "features" group
		public static function nav(): array {
			return [ 'pdf', '/_admin/nav/pdf', 60 ];
		}

		// What the strip on top of the pane calls the panel's own screen
		public static function tab(): string {
			return '/_admin/nav/pdf-layout';
		}

		// The two tabs the panel brings; the Settings tab the registry attaches comes after them
		public static function tabs(): array {
			return [ Blocks\Tab::class, Templates\Tab::class ];
		}

		public static function icon(): string {
			return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-file-text-icon lucide-file-text"><path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>';
		}

		// The panel's own screen is a template: the mount the Layout tab is drawn into
		public static function template(): string {
			return \Nino\Admin\Panels::relative( dirname( __DIR__ ). '/templates/panel' );
		}

		public static function assets(): array {
			return [
				\Nino\Admin\Panels::relative( dirname( __DIR__ ). '/assets/admin.js' ),
				\Nino\Admin\Panels::relative( dirname( __DIR__ ). '/assets/admin.css' ),
			];
		}

		public static function text(): string {
			return \Nino\Admin\Panels::relative( dirname( __DIR__ ). '/text' );
		}

		public static function summary( array &$appData ): array {
			return [ 'value' => count( Feature::keys( $appData ) ), 'label' => '/_admin/pdf/label/templates' ];
		}

		public static function log( string $action, array $data ): string {

			$key = \Nino\Admin\Admin::postString( $data, 'key' );
			$kind = \Nino\Admin\Admin::postString( $data, 'kind' );

			return match( $action ) {
				'pdf/layout/save'					=> 'Save the PDF layout',
				'pdf/layout/image'				=> 'Set the PDF '. $kind,
				'pdf/layout/image/delete'	=> 'Remove the PDF '. $kind,
				'pdf/blocks/save'					=> 'Save PDF block "'. $key. '"',
				'pdf/blocks/delete'				=> 'Delete PDF block "'. $key. '"',
				'pdf/templates/save'			=> 'Save PDF template "'. $key. '"',
				'pdf/templates/delete'		=> 'Delete PDF template "'. $key. '"',
				'pdf/templates/duplicate'	=> 'Duplicate PDF template "'. $key. '" as "'. ( self::$_made[$action] ?? '' ). '"',
				default										=> '',
			};
		}

		/**
		 *	The layout, with the pictures the screen shows
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiLayoutGet( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			\Nino\Http::ok( $request, self::_layout( $appData ) );
		}

		/**
		 *	Save the paper, the margins, the font, the size, the header and the footer
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiLayoutSave( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			self::_answer( $request, Store::saveLayout( $appData, \Nino\Admin\Admin::postData() ), static fn(): array => self::_layout( $appData ) );
		}

		/**
		 *	A PDF of the sample text with the layout and the design as they are saved, as base64 in the answer
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiLayoutPreview( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$bytes = Feature::compose( $appData, [ 'name' => 'Design', 'tag' => '', 'blocks' => [] ], [], [ 'title' => 'Design' ], true );

			\Nino\Http::ok( $request, [ 'pdf' => base64_encode( $bytes ), 'file' => 'design.pdf' ] );
		}

		/**
		 *	Take an uploaded picture in as the logo or the owner's signature
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiLayoutImage( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$failure = \Nino\Admin\Admin::uploadError( $_FILES['file'] ?? null );

			if( $failure !== null ) {
				\Nino\Http::fail( $request, $failure['status'], $failure['error'], $failure['code'], $failure['params'], 'file' );
				return;
			}

			$bytes = @file_get_contents( (string) ( $_FILES['file']['tmp_name'] ?? '' ) );

			if( is_string( $bytes ) === false ) {
				\Nino\Http::fail( $request, 400, 'the file could not be read', 'upload_missing', [], 'file' );
				return;
			}

			self::_answer( $request, Store::saveImage( $appData, \Nino\Admin\Admin::postString( \Nino\Admin\Admin::postData(), 'kind' ), $bytes ), static fn(): array => self::_layout( $appData ) );
		}

		/**
		 *	Take the logo or the signature away
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiLayoutImageDelete( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			self::_answer( $request, Store::deleteImage( $appData, \Nino\Admin\Admin::postString( \Nino\Admin\Admin::postData(), 'kind' ) ), static fn(): array => self::_layout( $appData ) );
		}

		public static function apiBlocksList( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			\Nino\Http::ok( $request, self::_blocks( $appData ) );
		}

		public static function apiBlocksSave( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			self::_answer( $request, Store::saveBlock( $appData, \Nino\Admin\Admin::postData() ), static fn(): array => self::_blocks( $appData ) );
		}

		public static function apiBlocksDelete( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			self::_answer( $request, Store::deleteBlock( $appData, \Nino\Admin\Admin::postString( \Nino\Admin\Admin::postData(), 'key' ) ), static fn(): array => self::_blocks( $appData ) );
		}

		public static function apiTemplatesList( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			\Nino\Http::ok( $request, self::_templates( $appData ) );
		}

		/**
		 *	One template for the editor, with what the editor offers: the tags, the library, the paper
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiTemplatesGet( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$key = \Nino\Admin\Admin::postString( \Nino\Admin\Admin::postData(), 'key' );
			$template = Feature::template( $appData, $key );

			if( $template === null )
				\Nino\Http::fail( $request, 404, 'there is no such template', 'pdf_template_missing' );
			else
				\Nino\Http::ok( $request, [ 'key' => $key, 'template' => $template ] + self::_offer( $appData ) );
		}

		public static function apiTemplatesSave( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$saved = Store::saveTemplate( $appData, \Nino\Admin\Admin::postData() );

			self::_answer( $request, $saved, static fn(): array => [ 'key' => $saved['key'] ?? '' ] + self::_templates( $appData ) );
		}

		public static function apiTemplatesDelete( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			self::_answer( $request, Store::deleteTemplate( $appData, \Nino\Admin\Admin::postString( \Nino\Admin\Admin::postData(), 'key' ) ), static fn(): array => self::_templates( $appData ) );
		}

		public static function apiTemplatesDuplicate( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$data = \Nino\Admin\Admin::postData();
			$made = Store::duplicateTemplate( $appData, \Nino\Admin\Admin::postString( $data, 'key' ), \Nino\Admin\Admin::postString( $data, 'name' ) );
			self::$_made['pdf/templates/duplicate'] = $made['key'] ?? '';

			self::_answer( $request, $made, static fn(): array => [ 'key' => $made['key'] ?? '' ] + self::_templates( $appData ) );
		}

		/**
		 *	A PDF of what the editor holds - saved or not - with sample data, as base64 in the answer:
		 *	[[name]] for a token, the label for an input, a table for a slot, the sample text for a template
		 *	with no body. It is written nowhere
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiPreview( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$checked = Store::template( $appData, \Nino\Admin\Admin::postData() );

			if( isset( $checked['fail'] ) === true ) {
				\Nino\Http::fail( $request, ...$checked['fail'] );
				return;
			}

			$bytes = Feature::compose( $appData, $checked['template'], [], [ 'title' => $checked['template']['name'] ], true );

			\Nino\Http::ok( $request, [ 'pdf' => base64_encode( $bytes ), 'file' => 'preview.pdf' ] );
		}

		/**
		 *	The answer of a Store method: its refusal as a failure, else what the screen draws now
		 *
		 *	@param		array 		&$request			(reference) Current server request
		 *	@param		array 		$result				What the Store answered
		 *	@param		callable	$body					Makes the body of a success
		 *
		 *	@return 	void
		 */
		private static function _answer( array &$request, array $result, callable $body ): void {

			if( isset( $result['fail'] ) === true )
				\Nino\Http::fail( $request, ...$result['fail'] );
			else
				\Nino\Http::ok( $request, $body() );
		}

		/**
		 *	The layout and what the screen needs to edit it: the pictures as data addresses (a picture of
		 *	a megabyte or less), the papers, the fonts, the feature's rules as CSS, and the ranges the server holds the numbers to
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array
		 */
		private static function _layout( array &$appData ): array {

			$layout = Feature::layout( $appData );
			$images = [];

			foreach( [ 'logo', 'signature' ] as $kind ) {

				$bytes = Feature::picture( $appData, $kind );
				$images[$kind] = $bytes !== '' && strlen( $bytes ) <= 1048576 ? 'data:image/'. ( str_ends_with( $layout[$kind], '.png' ) === true ? 'png' : 'jpeg' ). ';base64,'. base64_encode( $bytes ) : '';
			}

			return [
				'layout'	=> $layout,
				'sheet'		=> Feature::sheet( $appData ),
				'refusal'	=> self::_refusal( $layout['design'] ),
				'images'	=> $images,
				'papers'	=> array_keys( \Nino\Modules\Pdf\Layout\Engine::PAPER ),
				'fonts'		=> \Nino\Modules\Pdf\Fonts\Metrics::FAMILIES,
				'ranges'	=> [ 'margin' => [ Feature::MARGIN_MIN, Feature::MARGIN_MAX ], 'size' => [ Feature::SIZE_MIN, Feature::SIZE_MAX ], 'band' => Feature::MAX_BAND, 'design' => Feature::MAX_DESIGN ],
			];
		}

		/**
		 *	Why a stored design is not read: the code and the params of the refusal, as a save refuses the same text - the panel says
		 *	them in its own language - and null where the text is a sheet
		 *
		 *	@param		string		$design
		 *
		 *	@return 	array|null
		 */
		private static function _refusal( string $design ): ?array {

			$read = \Nino\Modules\Pdf\Layout\Sheet::check( $design );

			return isset( $read['rules'] ) === true ? null : Store::refusal( $read );
		}

		/**
		 *	The library, as a list for the screen
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array
		 */
		private static function _blocks( array &$appData ): array {

			$blocks = [];

			foreach( Feature::blocks( $appData ) as $key => $block )
				$blocks[] = [ 'key' => $key, 'title' => $block['title'], 'html' => $block['html'] ];

			return [ 'blocks' => $blocks, 'ranges' => [ 'title' => Feature::MAX_TITLE, 'html' => Feature::MAX_HTML ] ];
		}

		/**
		 *	The templates, as a list for the screen: key, name, tag, how many blocks, and whether the tag
		 *	is still one of the project's
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array
		 */
		private static function _templates( array &$appData ): array {

			$tags = Feature::tags( $appData );
			$list = [];

			foreach( Feature::templates( $appData ) as $key => $template )
				$list[] = [ 'key' => $key, 'name' => $template['name'], 'tag' => $template['tag'], 'blocks' => count( Feature::template( $appData, $key )['blocks'] ?? [] ), 'known' => in_array( $template['tag'], $tags, true ) ];

			return [ 'templates' => $list ] + self::_offer( $appData );
		}

		/**
		 *	What the template editor offers: the tags, the blocks of the library, the papers' size in mm
		 *	and the limits of the fields
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array
		 */
		private static function _offer( array &$appData ): array {

			$library = [];

			foreach( Feature::blocks( $appData ) as $key => $block )
				$library[] = [ 'key' => $key, 'title' => $block['title'] ];

			return [
				'tags'		=> Feature::tags( $appData ),
				'library'	=> $library,
				'paper'		=> \Nino\Modules\Pdf\Layout\Engine::PAPER[ Feature::layout( $appData )['format'] ],
				'ranges'	=> [ 'title' => Feature::MAX_TITLE, 'html' => Feature::MAX_HTML, 'label' => Feature::MAX_LABEL, 'blocks' => Feature::MAX_BLOCKS ],
			];
		}
	}
}
