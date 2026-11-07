<?php
declare(strict_types=1);
/**
 *	Crm\Surface		The surface of the CRM: one page of the project at /crm with the workbench's login, its endpoint and its assets. The
 *								page is a standalone document of its own (not a frame of the site, not the workbench's shell); the screens are
 *								drawn by its script, which reads what it needs from one JSON block of the page. Nothing of it enters the site's
 *								bundle.
 *
 *								GET  /crm									the page. No session (a member of the portal is nobody here): the surface's own login, 200, which
 *																					signs in at the kernel and comes back to /crm. A session without the permission
 *																					\Crm\Crm::MANAGE_PERM: the page that says so, 403. Never cached, never framed, its policy its
 *																					own: no inline script
 *								POST /crm/api							{ "action": "crm-documents/list", "data": { ... } } in, the answer of the method of the API class (or of the panel CRM) out,
 *																					status, body, code, params and field exactly as the workbench gives them. The kernel checks the
 *																					token (the route says csrf, the page sends it in X-CSRF-Token); a refused token is the 403 with
 *																					the code csrf, no session the 401 with the code session, an action the table does not hold the
 *																					404 with the code action; the four actions of the Elements panel (elements/list, get, save, delete) are for the types positionen, projekte and events alone, any
 *																					other type is the 404 with the code crm_positionen_type; the two of the Text panel (text/keys, text/savebatch) are for the keys below /project/company/
 *																					alone, a batch with another key is the 404 with the code crm_texts_key, and the seven of the Files panel (files/list, folder/add, folder/save,
 *																					upload, save, remove, delete) and the three of the Review panel (review/comments, review/answer, review/done; the permission of the panel besides the CRM's, the feature on or the 409 with the code review_off) are for the folders of the clients alone, any other is the 404 with the code crm_files_group; crm-uploads/list reads the files of the upload folders of the clients for the screen Uploads. The upload of a picture (pdf/layout/image) comes as a multipart post with the action and the
 *																					data as fields and the file, which the method reads from $_FILES; no other action takes a file
 *								GET  /crm/assets/<hash>-<name>		a file of ASSETS whose content hash is the one in the name, for a year
 *								GET  /crm/preview/<id>		the PDF of a draft as its template makes it now, inline, nothing stored
 *								GET  /crm/uploads.zip?ids=<id>,<id>	the files of the upload folders of the clients as one ZIP, as an attachment: for a session with the CRM's permission and the Files panel's,
 *																					with ZipArchive (see Surface\Uploads); the ZIP lies in /data for the length of the request
 *								GET  /crm/export/belege.csv?jahr=<year>, /crm/export/belege-<year>.zip, /crm/export/belege.zip?ids=<id>,<id>
 *																					the documents of a year as a CSV for the accountant, their PDFs as a ZIP, and the PDFs of a choice as a ZIP: attachments for a session with the CRM's permission
 *																					(see Surface\Export); the ZIP lies in /data for the length of the request
 *								GET  /crm/pdf-preview/template/<key>, /crm/pdf-preview/layout
 *																					the PDF of a saved template with the feature's sample data, and of the layout and design with the sample page, as the
 *																					panel of the feature makes them: inline, nothing stored, for a session with the CRM's permission and the Pdf panel's
 *
 *								The methods an action reaches read their input as the workbench posts it, from $_POST['data']: the endpoint puts the
 *								JSON it was given there, for the length of the request. They keep their own guardPerm(), and the activity log gets the
 *								line the class's log() phrases, as it does for the workbench.
 */
namespace Crm;

class Surface {

	public const string ROUTE = '/crm';

	// The files the module serves, by name: the path below app/Crm. A request names one of these and never a path
	private const array ASSETS = [
		'crm.css'  => [ 'Surface/assets/crm.css', 'text/css; charset=utf-8' ],
		'crm.js'   => [ 'Surface/assets/crm.js', 'text/javascript; charset=utf-8' ],
		'money.js' => [ 'Documents/Money/assets/money.js', 'text/javascript; charset=utf-8' ],
		'login.js' => [ 'Surface/assets/login.js', 'text/javascript; charset=utf-8' ],
	];

	// The pages of the workbench the surface links: name => the hash behind /_admin/#. The element type of the items is edited in the screen Positionen and, as the second place, in the Elements panel, the
	// templates in the panel of Pdf, the accounts of the portal in Members, the permission of the CRM in Users
	private const array PANELS = [ 'items' => 'elements/positionen', 'projects' => 'elements/projekte', 'files' => 'files', 'pdf' => 'pdf', 'members' => 'members', 'users' => 'users' ];

	// The policy of the page: its own files and nothing inline; data: for an image of the markup's own (the pictures of the layout)
	private const string POLICY = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

	// The hash of a file, for the request: the file name carries it
	private static array $_hashes = [];

	private static array $_texts = [];

	/**
	 *	Register the routes and what answers them
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	void
	 */
	public static function init( array &$appData ): void {

		$appData['/nino/http/routes']['GET://crm'] = [ 'uri' => '/crm' ];
		$appData['/nino/http/routes']['POST://crm/api'] = [ 'uri' => '/crm/api', 'csrf' => true ];
		$appData['/nino/http/routes']['GET://crm/assets/*'] = [ 'uri' => '/crm/assets/*' ];
		$appData['/nino/http/routes']['GET://crm/preview/*'] = [ 'uri' => '/crm/preview/*' ];
		$appData['/nino/http/routes']['GET://crm/pdf-preview/*'] = [ 'uri' => '/crm/pdf-preview/*' ];
		$appData['/nino/http/routes']['GET://crm/uploads.zip'] = [ 'uri' => '/crm/uploads.zip' ];
		$appData['/nino/http/routes']['GET://crm/export/*'] = [ 'uri' => '/crm/export/*' ];

		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://crm', [ self::class, 'callbackPage' ] );
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://crm/api', [ self::class, 'callbackApi' ] );
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://crm/assets/*', [ self::class, 'callbackAsset' ] );
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://crm/preview/*', [ self::class, 'callbackPreview' ] );
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://crm/pdf-preview/*', [ self::class, 'callbackPdfPreview' ] );
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://crm/uploads.zip', [ Surface\Uploads::class, 'callbackZip' ] );
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://crm/export/*', [ Surface\Export::class, 'callback' ] );
	}

	/**
	 *	The actions the endpoint answers: the API of the clients and of the documents, the two actions of the panel CRM and - where the feature Pdf is
	 *	there - the actions of its panel and the context of the screens PDF-Vorlagen, the four actions of the Elements panel that edit the Positionen
	 *	(for that type alone, see Surface\Elements) and the two of the Text panel that edit the texts of the company (for the keys below /project/company/
	 *	alone, see Surface\Texts), by their names
	 *
	 *	@return 	array											action => [ class, method ]
	 */
	public static function actions(): array {

		return array_merge( \Crm\Clients\Admin::actions(), \Crm\Documents\Admin::actions(), \Crm\Crm\Admin::actions(), \Crm\Projekte\Admin::actions(), \Crm\Termine\Admin::actions(), Surface\Pdf::actions(), Surface\Elements::actions(), Surface\Texts::actions(), Surface\Files::actions(), Surface\Review::actions(), Surface\Uploads::actions(), Surface\Activity::actions() );
	}

	/**
	 *	The words of the surface: its own file, the sentences of the codes the API answers a refusal with (those of the panels of the clients and of the
	 *	documents, of the Elements panel and of the Pdf panel where the feature is there), as error.<code>, and the sentences of the
	 *	status of the module and of the setup, which the panel CRM of the workbench has as well, as status.<key> and setup.<key>
	 *
	 *	@return 	array											key => text
	 */
	public static function texts(): array {

		if( self::$_texts === [] ) {

			self::$_texts = (array) require __DIR__. '/text/de_DE.php';

			$files = [];

			foreach( [ 'Clients', 'Documents', 'Crm' ] as $panel )
				$files[] = dirname( __DIR__ ). '/'. $panel. '/Admin/text/de_DE.php';

			// The sentences of the codes the panel of the feature Pdf answers a refusal with
			if( Surface\Pdf::textFile() !== '' )
				$files[] = Surface\Pdf::textFile();

			// ...and those of the Elements panel, whose actions edit the Positionen
			if( Surface\Elements::textFile() !== '' )
				$files[] = Surface\Elements::textFile();

			// ...and those of the Text panel, whose actions edit the texts of the company
			if( Surface\Texts::textFile() !== '' )
				$files[] = Surface\Texts::textFile();

			// ...and those of the Files panel, whose actions keep the files of a project
			if( Surface\Files::textFile() !== '' )
				$files[] = Surface\Files::textFile();

			// ...and those of the Review panel, whose actions read and settle the comments on a file of a project
			if( Surface\Review::textFile() !== '' )
				$files[] = Surface\Review::textFile();

			foreach( $files as $file )
				foreach( (array) require $file as $key => $text )
					if( preg_match( '~^\[\[/_admin/(?:(error)/([a-z][a-z0-9_]*)|crm/(status|setup)/([a-z][a-z0-9-]*)|text/error/(save|load))\]\]$~D', (string) $key, $found ) === 1 )
						self::$_texts[( ( $found[5] ?? '' ) !== '' ? 'text.error-'. $found[5] : ( $found[1] !== '' ? 'error.'. $found[2] : $found[3]. '.'. $found[4] ) )] = (string) $text;
		}

		return self::$_texts;
	}

	/**
	 *	The name a file of the page is linked by: the first twelve characters of the hash of its content, then the name
	 *
	 *	@param		string		$name					A key of ASSETS
	 *
	 *	@return 	string										'' for a name the module does not serve
	 */
	public static function assetName( string $name ): string {

		if( isset( self::ASSETS[$name] ) === false )
			return '';

		if( isset( self::$_hashes[$name] ) === false ) {
			$hash = hash_file( 'sha256', self::file( $name ) );
			self::$_hashes[$name] = substr( $hash === false ? '' : $hash, 0, 12 ). '-'. $name;
		}

		return self::$_hashes[$name];
	}

	/**
	 *	GET /crm: the page for a session that holds the permission
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackPage( array &$appData, array &$request ): void {

		$user = \Nino\Auth::getCurrentUser( $appData );
		$dir = rtrim( \Nino\Filesystem::getDir( $appData ), '/' );
		$links = [
			'css' => $dir. '/crm/assets/'. self::assetName( 'crm.css' ),
			'site' => $dir. '/',
			'workbench' => $dir. '/_admin/',
		];
		// The text fill hands the name over as markup (a quote is &quot;) and fill() escapes what it puts in: the name is read as the text it is, as the portal reads it
		$brand = trim( html_entity_decode( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/general/name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$brands = [ '[[brand]]' => $brand !== '' ? $brand : self::texts()['brand.tag'], '[[tag]]' => $brand !== '' ? self::texts()['brand.tag'] : '' ];

		$request['/nino/http/response']['header'] = self::headers( $request['/nino/http/response']['header'], 'text/html; charset=utf-8' );

		// Nobody signed in (a member of the portal is nobody here): the surface's own login, which returns to /crm
		if( $user === false ) {

			$request['/nino/http/response']['statusCode'] = 200;
			$data = [ 'base' => $dir, 'csrf' => \Nino\Csrf::getToken( $appData ), 'texts' => self::texts() ];
			$values = $brands + [ '[[css]]' => $links['css'], '[[script]]' => $dir. '/crm/assets/'. self::assetName( 'login.js' ), '[[site]]' => $links['site'] ];
			$request['/nino/http/response']['body'] = str_replace( '[[data]]', self::json( $data ), self::fill( self::template( 'login' ), self::texts(), $values ) );
			return;
		}

		$allowed = \Nino\Auth::checkPermission( $appData, \Crm\Crm::MANAGE_PERM );

		$request['/nino/http/response']['statusCode'] = $allowed === true ? 200 : 403;

		if( $allowed === false ) {
			$request['/nino/http/response']['body'] = self::fill( self::template( '403' ), self::texts(), [ '[[css]]' => $links['css'], '[[workbench]]' => $links['workbench'] ] );
			return;
		}

		$data = [
			'base'  => $dir,
			'csrf'  => \Nino\Csrf::getToken( $appData ),
			'user'  => [ 'name' => (string) $user['mail'] ],
			'perm'  => \Crm\Crm::MANAGE_PERM,
			'perms' => [ 'crm' => true, 'pdf' => Surface\Pdf::allowed( $appData ), 'elements' => Surface\Elements::allowed( $appData ), 'text' => Surface\Texts::allowed( $appData ), 'files' => Surface\Files::allowed( $appData ), 'review' => Surface\Review::allowed( $appData ), 'members' => \Crm\Crm::featureOn( $appData, 'members' ) === true && \Crm\Crm::mayLink( $appData, 'members' ), 'sign' => \Crm\Crm::featureOn( $appData, 'sign' ) === true && \Crm\Crm::mayLink( $appData, 'sign' ) ],
			'pdf_available' => Surface\Pdf::available(),
			'texts' => self::texts(),
			'links' => $links + array_map( static fn( string $panel ): string => $dir. '/_admin/#'. $panel, self::PANELS ),
		];

		$values = $brands + [
			'[[css]]'       => $links['css'],
			'[[script]]'    => $dir. '/crm/assets/'. self::assetName( 'crm.js' ),
			'[[money]]'     => $dir. '/crm/assets/'. self::assetName( 'money.js' ),
			'[[site]]'      => $links['site'],
			'[[workbench]]' => $links['workbench'],
			'[[user]]'      => (string) $user['mail'],
		];

		$request['/nino/http/response']['body'] = str_replace( '[[data]]', self::json( $data ), self::fill( self::template( 'page' ), self::texts(), $values ) );
	}

	/**
	 *	The JSON of the data block: nothing in it ends the script element
	 *
	 *	@param		array 		$data
	 *
	 *	@return 	string
	 */
	private static function json( array $data ): string {
		return json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
	}

	/**
	 *	POST /crm/api: one action of the table, answered as the workbench answers it
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackApi( array &$appData, array &$request ): void {

		$request['/nino/http/response']['header']['Cache-Control'] = 'no-store';

		// The methods of the API classes are written against the workbench's shell, which only its own entry point loads
		if( self::workbench( $appData ) === false ) {
			\Nino\Http::fail( $request, 500, 'the workbench is not there', 'workbench' );
			return;
		}

		// The token was refused before this callback ran: say it in the way the page reacts to
		if( self::status( $request ) !== 200 ) {
			self::blocked( $request );
			return;
		}

		$user = \Nino\Auth::getCurrentUser( $appData );

		if( $user === false ) {
			\Nino\Http::fail( $request, 401, 'not logged in', 'session' );
			return;
		}

		if( \Nino\Auth::checkPermission( $appData, \Crm\Crm::MANAGE_PERM ) === false ) {
			\Nino\Http::fail( $request, 403, 'not allowed' );
			return;
		}

		// A picture larger than the server takes: the token came in the header, so the kernel let it pass, and PHP dropped the whole form - no field, no
		// action. It is told what it is, not 404 action
		$type = strtolower( (string) ( $request['/nino/http/request']['header']['Content-Type'] ?? '' ) );

		if( $_POST === [] && str_starts_with( $type, 'multipart/form-data' ) === true && self::oversized( $request ) === true ) {
			self::tooLarge( $request );
			return;
		}

		$body = json_decode( (string) ( $request['/nino/http/request']['body'] ?? '' ), true, 16 );

		// A picture comes as a multipart post, whose fields PHP has read already: the action and the data are the fields, the file is in $_FILES. Only the
		// actions that take a file are read that way; the body of a post of any other kind is the JSON of the others
		if( is_array( $body ) === false && in_array( $_POST['action'] ?? null, array_merge( Surface\Pdf::FILE_ACTIONS, Surface\Files::FILE_ACTIONS ), true ) === true )
			$body = [ 'action' => $_POST['action'], 'data' => json_decode( is_string( $_POST['data'] ?? null ) === true ? $_POST['data'] : '', true, 16 ) ];

		$action = is_array( $body ) === true && is_string( $body['action'] ?? null ) === true ? $body['action'] : '';
		$data = is_array( $body ) === true && is_array( $body['data'] ?? null ) === true ? $body['data'] : [];
		$actions = self::actions();

		if( isset( $actions[$action] ) === false ) {
			\Nino\Http::fail( $request, 404, 'unknown action', 'action' );
			return;
		}

		// The screens PDF-Vorlagen need the feature to be on; the panel's methods do not ask
		if( Surface\Pdf::owns( $action ) === true && \Crm\Crm::featureOn( $appData, 'pdf' ) === false ) {
			\Nino\Http::fail( $request, 409, 'the feature Pdf is not active', 'pdf_off' );
			return;
		}

		// The tab Dateien of a project needs the feature to be on, too
		if( Surface\Files::owns( $action ) === true && \Crm\Crm::featureOn( $appData, 'files' ) === false ) {
			\Nino\Http::fail( $request, 409, 'the feature Files is not active', 'files_off' );
			return;
		}

		// ...and so do the comments on them: the Review panel's two actions
		if( Surface\Review::owns( $action ) === true && \Crm\Crm::featureOn( $appData, 'review' ) === false ) {
			\Nino\Http::fail( $request, 409, 'the feature Review is not active', 'review_off' );
			return;
		}

		// What the methods read: the posted action and data, as the workbench posts them
		$_POST['action'] = $action;
		$_POST['data'] = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );

		self::run( $appData, $request, $actions[$action], $action, (string) $user['mail'] );
	}

	/**
	 *	Run the method of an action and, where it went through, write the line of the activity log: the class phrases it and leaves a read out
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *	@param		array 		$target				[ class, method ]
	 *	@param		string		$action
	 *	@param		string		$mail					The account that asked
	 *
	 *	@return 	void
	 */
	private static function run( array &$appData, array &$request, array $target, string $action, string $mail ): void {

		[ $class, $method ] = $target;
		$class::{$method}( $appData, $request );

		if( self::status( $request ) !== 200 || method_exists( $class, 'log' ) === false )
			return;

		$message = (string) $class::log( $action, \Nino\Admin\Admin::postData() );

		if( $message !== '' )
			\Nino\Admin\Admin::record( $appData, $mail, $message );
	}

	/**
	 *	GET /crm/assets/<hash>-<name>
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackAsset( array &$appData, array &$request ): void {

		$uri = (string) ( $request['/nino/http/request']['uri'] ?? '' );
		$wanted = str_starts_with( $uri, '/crm/assets/' ) === true ? substr( $uri, strlen( '/crm/assets/' ) ) : '';
		$name = preg_match( '/^[0-9a-f]{12}-([a-z0-9.]+)$/D', $wanted, $found ) === 1 ? $found[1] : '';

		// The name must be one of the module's and the hash the one of the file now: a stale link is a 404, not an old file under a new name
		if( $name === '' || self::assetName( $name ) !== $wanted ) {
			self::plain( $request, 404, 'Not Found' );
			return;
		}

		$request['/nino/http/response']['file'] = self::file( $name );
		$request['/nino/http/response']['header']['Content-Type'] = self::ASSETS[$name][1];
		$request['/nino/http/response']['header']['Cache-Control'] = 'public, max-age=31536000';
	}

	/**
	 *	GET /crm/preview/<id>: the PDF of a draft, or the sentence that says why there is none
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackPreview( array &$appData, array &$request ): void {

		if( \Nino\Auth::getCurrentUser( $appData ) === false ) {
			self::plain( $request, 401, self::texts()['error.session'] );
			return;
		}

		if( \Nino\Auth::checkPermission( $appData, \Crm\Crm::MANAGE_PERM ) === false ) {
			self::plain( $request, 403, self::texts()['preview.forbidden'] );
			return;
		}

		$uri = (string) ( $request['/nino/http/request']['uri'] ?? '' );
		$id = str_starts_with( $uri, '/crm/preview/' ) === true ? substr( $uri, strlen( '/crm/preview/' ) ) : '';

		if( preg_match( '/^[1-9][0-9]{0,9}$/D', $id ) !== 1 ) {
			self::plain( $request, 404, self::texts()['error.crm_documents_unknown'] );
			return;
		}

		try {
			$made = Documents::preview( $appData, (int) $id );
		}
		catch( \PDOException $e ) {
			self::plain( $request, 500, self::texts()['error.crm_documents_database'] );
			return;
		}

		if( is_string( $made ) === true ) {

			[ $kind ] = explode( ':', $made, 2 );
			$status = match( $kind ) { 'unknown' => 404, 'render' => 500, default => 409 };
			$texts = self::texts();
			self::plain( $request, $status, $texts['preview.'. $kind] ?? $texts['error.crm_documents_'. $kind] ?? $texts['error.request'] );
			return;
		}

		$request['/nino/http/response']['body'] = $made['bytes'];
		$request['/nino/http/response']['header']['Content-Type'] = 'application/pdf';
		$request['/nino/http/response']['header']['Content-Disposition'] = 'inline; filename="'. preg_replace( '/[^A-Za-z0-9._-]/', '-', $made['name'] ). '.pdf"';
		$request['/nino/http/response']['header']['Cache-Control'] = 'private, no-store';
	}

	/**
	 *	GET /crm/pdf-preview/template/<key> and /crm/pdf-preview/layout: the PDF the Pdf panel's previews render, served with its own headers
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackPdfPreview( array &$appData, array &$request ): void {

		if( \Nino\Auth::getCurrentUser( $appData ) === false ) {
			self::plain( $request, 401, self::texts()['error.session'] );
			return;
		}

		if( \Nino\Auth::checkPermission( $appData, \Crm\Crm::MANAGE_PERM ) === false || Surface\Pdf::allowed( $appData ) === false ) {
			self::plain( $request, 403, self::texts()['pdf.forbidden'] );
			return;
		}

		// The feature is not there, or not on: the sentence for that, not the one for a missing permission
		if( Surface\Pdf::available() === false || \Crm\Crm::featureOn( $appData, 'pdf' ) === false ) {
			self::plain( $request, 409, self::texts()['error.pdf_off'] );
			return;
		}

		$uri = (string) ( $request['/nino/http/request']['uri'] ?? '' );
		$kind = str_starts_with( $uri, '/crm/pdf-preview/' ) === true ? substr( $uri, strlen( '/crm/pdf-preview/' ) ) : '';
		$made = Surface\Pdf::sample( $appData, $kind );

		if( $made === null ) {
			self::plain( $request, 404, self::texts()['error.pdf_template_missing'] );
			return;
		}

		$request['/nino/http/response']['body'] = $made['bytes'];
		$request['/nino/http/response']['header']['Content-Type'] = 'application/pdf';
		$request['/nino/http/response']['header']['Content-Disposition'] = 'inline; filename="'. $made['file']. '"';
		$request['/nino/http/response']['header']['Cache-Control'] = 'private, no-store';
		$request['/nino/http/response']['header']['X-Content-Type-Options'] = 'nosniff';
	}

	/**
	 *	Make sure the shell of the workbench is loaded: \Nino\Admin\Admin (guardPerm(), postData(), record()), Panels and Recovery are classes of
	 *	_admin/Admin.php, which declares them and runs nothing. The public entry point does not load it; _admin/index.php does
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	bool										Whether the classes are there
	 */
	public static function workbench( array &$appData ): bool {

		if( class_exists( '\\Nino\\Admin\\Admin', false ) === true )
			return true;

		$file = \Nino\Filesystem::path( $appData, '/_admin/Admin.php' );

		if( is_file( $file ) === false )
			return false;

		require_once $file;

		return true;
	}

	/**
	 *	A token the kernel refused: the 403 the page reloads on, and for a request larger than the server takes the 413 that says so
	 *
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	private static function blocked( array &$request ): void {

		if( \Nino\Form::blocked( $request ) === true && self::oversized( $request ) === true ) {
			self::tooLarge( $request );
			return;
		}

		\Nino\Http::fail( $request, 403, 'the session token is no longer valid - reload the page', 'csrf' );
	}

	/**
	 *	Whether the request says it is larger than the server takes (post_max_size): then PHP has dropped its body and its fields
	 *
	 *	@param		array 		$request
	 *
	 *	@return 	bool
	 */
	private static function oversized( array $request ): bool {

		$length = $request['/nino/http/request']['header']['Content-Length'] ?? '';
		$post = \Nino\Images::limits()['post'];

		return $post > 0 && is_string( $length ) === true && ctype_digit( $length ) === true && (int) $length > $post;
	}

	/**
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	private static function tooLarge( array &$request ): void {

		\Nino\Http::fail( $request, 413, 'the request is larger than the server accepts (post_max_size)', 'post_too_large', [ \Nino\Admin\Admin::megabytes( \Nino\Images::limits()['post'] ) ] );
	}

	/**
	 *	@param		array 		$request
	 *
	 *	@return 	int											The status the response has now
	 */
	private static function status( array $request ): int {

		return (int) ( $request['/nino/http/response']['statusCode'] ?? 0 );
	}

	/**
	 *	The headers of a page of the surface: the response's own, with its policy, never cached, never framed
	 *
	 *	@param		array 		$header				The response's
	 *	@param		string		$type					The Content-Type
	 *
	 *	@return 	array
	 */
	private static function headers( array $header, string $type ): array {

		return [
			'Content-Type'            => $type,
			'Cache-Control'           => 'private, no-store',
			'X-Frame-Options'         => 'DENY',
			'Content-Security-Policy' => self::POLICY,
			'Referrer-Policy'         => 'same-origin',
		] + $header;
	}

	/**
	 *	A plain-text answer that is no file
	 *
	 *	@param		array 		&$request
	 *	@param		int				$status
	 *	@param		string		$body
	 *
	 *	@return 	void
	 */
	public static function plain( array &$request, int $status, string $body ): void {

		$request['/nino/http/response']['statusCode'] = $status;
		$request['/nino/http/response']['body'] = $body;
		$request['/nino/http/response']['header']['Content-Type'] = 'text/plain; charset=utf-8';
		$request['/nino/http/response']['header']['Cache-Control'] = 'no-store';
	}

	/**
	 *	The absolute path of a served file
	 *
	 *	@param		string		$name					A key of ASSETS
	 *
	 *	@return 	string
	 */
	private static function file( string $name ): string {

		return dirname( __DIR__ ). '/'. self::ASSETS[$name][0];
	}

	/**
	 *	The markup of a template of templates/
	 *
	 *	@param		string		$name					Lower case letters and digits
	 *
	 *	@return 	string
	 */
	private static function template( string $name ): string {

		if( preg_match( '/^[a-z0-9]+$/D', $name ) !== 1 )
			throw new \InvalidArgumentException( 'There is no surface template "'. $name. '"' );

		$markup = file_get_contents( __DIR__. '/templates/'. $name. '.tpl' );

		if( $markup === false )
			throw new \RuntimeException( 'The surface template "'. $name. '" cannot be read' );

		return $markup;
	}

	/**
	 *	A template with its words and its values in: [[t.<key>]] is the word, escaped; the other tokens are the values, escaped as well
	 *
	 *	@param		string		$markup
	 *	@param		array 		$texts
	 *	@param		array 		$values				[[token]] => value
	 *
	 *	@return 	string
	 */
	private static function fill( string $markup, array $texts, array $values ): string {

		$markup = (string) preg_replace_callback( '/\[\[t\.([a-z0-9._-]+)\]\]/', static fn( array $found ): string => \Nino\Modules\Components::escape( (string) ( $texts[$found[1]] ?? '' ) ), $markup );

		return str_replace( array_keys( $values ), array_map( static fn( string $value ): string => \Nino\Modules\Components::escape( $value ), array_values( $values ) ), $markup );
	}
}
