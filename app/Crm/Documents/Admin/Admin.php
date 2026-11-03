<?php
declare(strict_types=1);
/**
 *	Crm\Documents\Admin		The panel Belege of the CRM module: the documents (list with filters, the editor of a draft with its
 *												lines, finalising, the steps of a finalised document, the offer that becomes an invoice and the
 *												invoice that is taken back by a credit note; the contract, the privacy and the information document
 *												without lines; the release of a document to the portal with its mail and the request for a signature
 *												through Sign) and the settings of the documents. The panel
 *												validates what it is posted and answers the failures a person can cause with codes; the rules of
 *												the data are Crm\Documents', the storage Crm\Db's. A database failure is answered 500 with the
 *												code crm_documents_database.
 */
namespace Crm\Documents;

use Crm\Documents;
use Crm\Settings;

class Admin {

	public const string MANAGE_PERM = '/_admin/crm-documents/manage';

	// What the model refuses with, as the status and the code a person is answered. A kind that stands for a field names it
	private const array REFUSALS = [
		'unknown'  => [ 404, 'crm_documents_unknown', 'id' ],
		'fixed'    => [ 409, 'crm_documents_fixed', '' ],
		'state'    => [ 409, 'crm_documents_state', '' ],
		'category' => [ 400, 'crm_documents_category', 'category' ],
		'client'   => [ 400, 'crm_documents_client', 'client_id' ],
		'inactive' => [ 409, 'crm_documents_inactive', 'client_id' ],
		'period'   => [ 400, 'crm_documents_period', 'period_from' ],
		'reverse'  => [ 409, 'crm_documents_reverse', 'reverse_charge' ],
		'lines'    => [ 400, 'crm_documents_lines', 'lines' ],
		'total'    => [ 400, 'crm_documents_total', 'lines' ],
		'pdf'      => [ 409, 'crm_documents_pdf', '' ],
		'template' => [ 409, 'crm_documents_template', 'template_key' ],
		'empty'    => [ 409, 'crm_documents_empty', 'lines' ],
		'number'   => [ 409, 'crm_documents_number', '' ],
		'render'   => [ 500, 'crm_documents_render', '' ],
		'write'    => [ 500, 'crm_documents_write', '' ],
		'offer'    => [ 400, 'crm_documents_offer', 'parent_id' ],
		'address'  => [ 409, 'crm_documents_address', '' ],
		'limit'    => [ 429, 'crm_documents_limit', '' ],
		'pending'  => [ 409, 'crm_documents_pending', '' ],
		'days'     => [ 400, 'crm_documents_days', 'days' ],
		'field'    => [ 409, 'crm_documents_field', 'template_key' ],
		'account'  => [ 400, 'crm_documents_account', 'account' ],
		'sign'     => [ 409, 'crm_documents_sign', '' ],
	];

	// What Sign refuses a request with, as the status and the code a person is answered
	private const array SIGN_REFUSALS = [
		'template' => [ 409, 'crm_documents_sign_template' ],
		'input'    => [ 409, 'crm_documents_sign_input' ],
		'render'   => [ 500, 'crm_documents_sign_render' ],
		'write'    => [ 500, 'crm_documents_sign_write' ],
	];

	// The fields of a draft a post may carry
	private const array FIELDS = [ 'date', 'due_date', 'valid_until', 'period_from', 'period_to', 'template_key', 'inputs', 'reverse_charge', 'lines', 'title' ];

	// What an action made or touched, by action: written by the action, read by log() in the same request
	private static array $_made = [];

	public static function perm(): string {
		return self::MANAGE_PERM;
	}

	public static function actions(): array {
		return [
			'crm-documents/list'          => [ self::class, 'apiList' ],
			'crm-documents/get'           => [ self::class, 'apiGet' ],
			'crm-documents/save'          => [ self::class, 'apiSave' ],
			'crm-documents/remove'        => [ self::class, 'apiRemove' ],
			'crm-documents/finalise'      => [ self::class, 'apiFinalise' ],
			'crm-documents/state'         => [ self::class, 'apiState' ],
			'crm-documents/from-offer'    => [ self::class, 'apiFromOffer' ],
			'crm-documents/credit'        => [ self::class, 'apiCredit' ],
			'crm-documents/release'       => [ self::class, 'apiRelease' ],
			'crm-documents/unrelease'     => [ self::class, 'apiUnrelease' ],
			'crm-documents/sign'          => [ self::class, 'apiSign' ],
			'crm-documents/settings-get'  => [ self::class, 'apiSettingsGet' ],
			'crm-documents/settings-save' => [ self::class, 'apiSettingsSave' ],
			'crm-documents/templates'     => [ self::class, 'apiTemplates' ],
			'crm-documents/status'        => [ self::class, 'apiStatus' ],
		];
	}

	public static function nav(): array {
		return [ 'crm-documents', '/_admin/crm-documents/nav', 40, 'content' ];
	}

	public static function icon(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6"/><path d="M9 17h6"/></svg>';
	}

	// The panel draws into the one region of templates/panel.tpl
	public static function template(): string {
		return \Nino\Admin\Panels::relative( __DIR__. '/templates/panel' );
	}

	public static function assets(): array {
		return [ \Nino\Admin\Panels::relative( dirname( __DIR__ ). '/Money/assets/money.js' ), \Nino\Admin\Panels::relative( __DIR__. '/assets/admin.js' ), \Nino\Admin\Panels::relative( __DIR__. '/assets/admin.css' ) ];
	}

	public static function text(): string {
		return \Nino\Admin\Panels::relative( __DIR__. '/text' );
	}

	/**
	 *	The Dashboard's tile: the open invoices, how many and what they come to. A database that cannot be opened leaves the
	 *	tile out rather than the Dashboard: the panel itself says the failure when it is opened
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array|null
	 */
	public static function summary( array &$appData ): ?array {

		try {
			$open = Documents::summary( $appData );

			return [ 'value' => $open['count']. ' · '. Money::format( $open['gross'], Settings::get( $appData, 'currency' ) ), 'label' => '/_admin/crm-documents/label/open' ];
		}
		catch( \PDOException $e ) {
			return null;
		}
	}

	/**
	 *	The activity-log line of a completed action: the number or the id of the document, never a client or an amount
	 *
	 *	@param		string		$action
	 *	@param		array 		$data
	 *
	 *	@return 	string
	 */
	public static function log( string $action, array $data ): string {

		$of = self::$_made[$action] ?? '';

		return match( $action ) {
			'crm-documents/save'          => ( isset( $data['id'] ) === true && $data['id'] !== '' ? 'Changed document ' : 'Added draft ' ). $of,
			'crm-documents/remove'        => 'Deleted draft '. $of,
			'crm-documents/finalise'      => 'Finalised document '. $of,
			'crm-documents/state'         => 'Moved document '. $of,
			'crm-documents/from-offer'    => 'Made an invoice draft from offer '. $of,
			'crm-documents/credit'        => 'Made a credit note draft from invoice '. $of,
			'crm-documents/release'       => 'Released document '. $of,
			'crm-documents/unrelease'     => 'Took back document '. $of,
			'crm-documents/sign'          => 'Asked for a signature on document '. $of,
			'crm-documents/settings-save' => 'Saved the settings of the documents',
			default                       => '',
		};
	}

	/**
	 *	The documents by the filters, the clients and years to filter by, the settings the screen draws with and the active items a line is filled from
	 */
	public static function apiList( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		$data = \Nino\Admin\Admin::postData();
		$filter = [];

		foreach( [ 'category', 'state' ] as $field )
			$filter[$field] = \Nino\Admin\Admin::postString( $data, $field );

		foreach( [ 'year' => 'year', 'client' => 'client_id' ] as $posted => $key ) {

			$value = \Nino\Admin\Admin::postString( $data, $posted );

			if( $value !== '' && preg_match( '/^[0-9]{1,10}$/D', $value ) !== 1 ) {
				\Nino\Http::fail( $request, 400, 'the filter is not valid', 'crm_documents_format', [], $posted );
				return;
			}

			if( $value !== '' )
				$filter[$key] = (int) $value;
		}

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request, $filter ): void {

			\Nino\Http::ok( $request, [
				'documents' => Documents::list( $appData, $filter ),
				'years'     => Documents::years( $appData ),
				'clients'   => self::_clients( $appData ),
				'settings'  => self::_settings( $appData ),
				'items'     => \Crm\Items::list( $appData, true ),
				'offers'    => self::_offers( $appData ),
			] );
		} );
	}

	/**
	 *	One document with what its editor needs: the templates of its tag with their inputs
	 */
	public static function apiGet( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request ): void {

			$document = self::_document( $appData, $request, \Nino\Admin\Admin::postData() );

			if( $document !== null )
				\Nino\Http::ok( $request, self::_view( $appData, $document ) );
		} );
	}

	/**
	 *	Add a draft (no id: category and client_id) or change one (id). A finalised document takes its notes and nothing else
	 */
	public static function apiSave( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		$data = \Nino\Admin\Admin::postData();
		$input = [];

		foreach( array_merge( self::FIELDS, [ 'notes' ] ) as $field )
			if( array_key_exists( $field, $data ) === true )
				$input[$field] = $data[$field];

		$client = \Nino\Admin\Admin::postString( $data, 'client_id' );

		if( preg_match( '/^[1-9][0-9]{0,9}$/D', $client ) === 1 )
			$input['client_id'] = (int) $client;

		$offer = \Nino\Admin\Admin::postString( $data, 'parent_id' );

		if( preg_match( '/^[1-9][0-9]{0,9}$/D', $offer ) === 1 )
			$input['parent_id'] = (int) $offer;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request, $data, $input ): void {

			if( \Nino\Admin\Admin::postString( $data, 'id' ) === '' ) {
				$saved = Documents::add( $appData, \Nino\Admin\Admin::postString( $data, 'category' ), $input['client_id'] ?? 0, array_diff_key( $input, [ 'client_id' => 0 ] ) );
			}
			else {
				$existing = self::_document( $appData, $request, $data );

				if( $existing === null )
					return;

				// The notes are what a finalised document still takes; anything else of it is fixed
				if( $existing['state'] !== 'draft' )
					$saved = array_diff_key( $input, [ 'notes' => 0 ] ) === [] && array_key_exists( 'notes', $input ) === true && is_string( $input['notes'] ) === true
						? \Crm\Documents::setNotes( $appData, $existing['id'], $input['notes'] )
						: 'fixed';
				else
					$saved = Documents::change( $appData, $existing['id'], $input );
			}

			if( is_string( $saved ) === true ) {
				self::_refused( $request, $saved );
				return;
			}

			self::$_made['crm-documents/save'] = $saved['number'] !== '' ? $saved['number'] : (string) $saved['id'];

			\Nino\Http::ok( $request, [ 'document' => self::_shown( $appData, $saved ) ] );
		} );
	}

	/**
	 *	Delete a draft
	 */
	public static function apiRemove( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request ): void {

			$document = self::_document( $appData, $request, \Nino\Admin\Admin::postData() );

			if( $document === null )
				return;

			$removed = Documents::remove( $appData, $document['id'] );

			if( is_string( $removed ) === true ) {
				self::_refused( $request, $removed );
				return;
			}

			self::$_made['crm-documents/remove'] = (string) $document['id'];

			\Nino\Http::ok( $request, [ 'removed' => true ] );
		} );
	}

	/**
	 *	Finalise a draft; answers the view of the finalised document, as get does
	 */
	public static function apiFinalise( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request ): void {

			$document = self::_document( $appData, $request, \Nino\Admin\Admin::postData() );

			if( $document === null )
				return;

			$final = Documents::finalise( $appData, $document['id'] );

			if( is_string( $final ) === true ) {
				self::_refused( $request, $final );
				return;
			}

			self::$_made['crm-documents/finalise'] = Render::name( $final );

			// The screen draws the page of the document from this: the accounts of the client and the days of Sign come with it
			\Nino\Http::ok( $request, self::_view( $appData, $final ) );
		} );
	}

	/**
	 *	Move a finalised document a step: state is sent, paid, accepted or declined; date is the day of it
	 */
	public static function apiState( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request ): void {

			$data = \Nino\Admin\Admin::postData();
			$document = self::_document( $appData, $request, $data );

			if( $document === null )
				return;

			$date = \Nino\Admin\Admin::postString( $data, 'date' );
			$moved = Documents::setState( $appData, $document['id'], \Nino\Admin\Admin::postString( $data, 'state' ), $date === '' ? null : $date );

			if( is_string( $moved ) === true ) {
				self::_refused( $request, $moved );
				return;
			}

			self::$_made['crm-documents/state'] = Render::name( $moved ). ' to '. $moved['state'];

			\Nino\Http::ok( $request, [ 'document' => self::_shown( $appData, $moved ) ] );
		} );
	}

	/**
	 *	An invoice draft from an offer
	 */
	public static function apiFromOffer( array &$appData, array &$request ): void {
		self::_derived( $appData, $request, 'crm-documents/from-offer', [ Documents::class, 'fromOffer' ] );
	}

	/**
	 *	A credit note draft from an invoice
	 */
	public static function apiCredit( array &$appData, array &$request ): void {
		self::_derived( $appData, $request, 'crm-documents/credit', [ Documents::class, 'creditOf' ] );
	}

	/**
	 *	Release a finalised document to the portal; mail true also mails the accounts of its client (and sends an offer, an invoice or a
	 *	credit note that was open). Answers the document and how many mails were delivered
	 */
	public static function apiRelease( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request ): void {

			$data = \Nino\Admin\Admin::postData();
			$document = self::_document( $appData, $request, $data );

			if( $document === null )
				return;

			$mail = ( $data['mail'] ?? false ) === true;
			$released = Documents::release( $appData, $document['id'], $mail );

			if( is_string( $released ) === true ) {
				self::_refused( $request, $released );
				return;
			}

			self::$_made['crm-documents/release'] = Render::name( $document );

			\Nino\Http::ok( $request, [ 'document' => self::_shown( $appData, $released['document'] ), 'mailed' => $released['mailed'], 'mail' => $mail ] );
		} );
	}

	/**
	 *	Take a document back from the portal
	 */
	public static function apiUnrelease( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request ): void {

			$document = self::_document( $appData, $request, \Nino\Admin\Admin::postData() );

			if( $document === null )
				return;

			$taken = Documents::unrelease( $appData, $document['id'] );

			if( is_string( $taken ) === true ) {
				self::_refused( $request, $taken );
				return;
			}

			self::$_made['crm-documents/unrelease'] = Render::name( $document );

			\Nino\Http::ok( $request, [ 'document' => self::_shown( $appData, $taken ) ] );
		} );
	}

	/**
	 *	Ask an account of the client of a contract or a privacy document for a signature through Sign: account is the id of the account,
	 *	days how long the link is good for (1 to 90; none is the setting of Sign)
	 */
	public static function apiSign( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		$data = \Nino\Admin\Admin::postData();
		$account = \Nino\Admin\Admin::postString( $data, 'account' );
		$days = \Nino\Admin\Admin::postString( $data, 'days' );

		if( preg_match( '/^[a-f0-9]{12}$/D', $account ) !== 1 ) {
			\Nino\Http::fail( $request, 400, 'the account is none', 'crm_documents_account', [], 'account' );
			return;
		}

		if( $days !== '' && preg_match( '/^[0-9]{1,3}$/D', $days ) !== 1 ) {
			\Nino\Http::fail( $request, 400, 'the days are none', 'crm_documents_days', [], 'days' );
			return;
		}

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request, $data, $account, $days ): void {

			$document = self::_document( $appData, $request, $data );

			if( $document === null )
				return;

			$asked = Documents::requestSignature( $appData, $document['id'], $account, $days === '' ? null : (int) $days );

			if( is_string( $asked ) === true ) {
				self::_refused( $request, $asked );
				return;
			}

			self::$_made['crm-documents/sign'] = Render::name( $document );

			\Nino\Http::ok( $request, [ 'document' => self::_shown( $appData, $asked['document'] ), 'mailed' => $asked['mailed'] ] );
		} );
	}

	/**
	 *	The settings of the documents
	 */
	public static function apiSettingsGet( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request ): void {
			\Nino\Http::ok( $request, [ 'settings' => Settings::all( $appData ) ] );
		} );
	}

	/**
	 *	Save settings: any of the keys; every value is checked before one is written
	 */
	public static function apiSettingsSave( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		$data = \Nino\Admin\Admin::postData();
		$clean = [];

		foreach( array_keys( Settings::DEFAULTS ) as $key ) {

			if( array_key_exists( $key, $data ) === false )
				continue;

			$value = Settings::clean( $key, $data[$key] );

			if( is_array( $value ) === true ) {
				self::_refused( $request, $value[0] );
				return;
			}

			$clean[$key] = $value;
		}

		// The rates written in percent are the screen's form of vat_rates, and say what they mean when they are refused
		if( array_key_exists( 'vat_rates_percent', $data ) === true ) {

			$value = Settings::clean( 'vat_rates_percent', $data['vat_rates_percent'] );

			if( is_array( $value ) === true ) {
				self::_refused( $request, $value[0] );
				return;
			}

			$clean['vat_rates'] = $value;
		}

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request, $clean ): void {

			foreach( $clean as $key => $value )
				Settings::set( $appData, $key, $value );

			\Nino\Http::ok( $request, [ 'settings' => Settings::all( $appData ) ] );
		} );
	}

	/**
	 *	The templates of Pdf for a category, each with its inputs
	 */
	public static function apiTemplates( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		$category = \Nino\Admin\Admin::postString( \Nino\Admin\Admin::postData(), 'category' );

		if( in_array( $category, Documents::CATEGORIES, true ) === false ) {
			\Nino\Http::fail( $request, 400, 'the category is none', 'crm_documents_category', [], 'category' );
			return;
		}

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request, $category ): void {
			\Nino\Http::ok( $request, [ 'templates' => self::_templates( $appData, $category ), 'offers' => self::_offers( $appData ) ] );
		} );
	}

	/**
	 *	What the module misses: each item has a key and a state
	 *	  module           ok, off (the class is not in /nino/modules)
	 *	  data             ok, unwritable (the data directory)
	 *	  pdf              ok, off (installed, not active), missing (not installed), problem
	 *	  templates-offer, templates-invoice     ok, none (Pdf has no template of the tag) - only while Pdf is active
	 *	  pdf-dir          ok, unwritable (the directory the PDF files are kept in, or the one above it where it is not made yet)
	 */
	public static function apiStatus( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		$modules = array_map( static fn( mixed $class ): string => ltrim( (string) $class, '\\' ), (array) ( $appData['/nino/modules'] ?? [] ) );
		$items = [ [ 'key' => 'module', 'state' => in_array( \Crm\Crm::class, $modules, true ) === true ? 'ok' : 'off' ] ];

		// A read answers a read: the directories are looked at and not made
		$data = \Nino\Filesystem::path( $appData, '/data' );
		$items[] = [ 'key' => 'data', 'state' => is_writable( is_dir( $data ) === true ? $data : dirname( $data ) ) === true ? 'ok' : 'unwritable' ];

		$feature = \Nino\Features::get( $appData, 'pdf' );
		$items[] = [ 'key' => 'pdf', 'state' => match( true ) {
			$feature === null            => 'missing',
			$feature['problems'] !== []  => 'problem',
			$feature['active'] === false => 'off',
			default                      => 'ok',
		} ];

		$on = \Crm\Crm::featureOn( $appData, 'pdf' );

		// Without Pdf there are no templates to miss: the line about Pdf says it
		if( $on === true )
			foreach( [ 'offer', 'invoice' ] as $tag )
				$items[] = [ 'key' => 'templates-'. $tag, 'state' => \Nino\Modules\Pdf::templates( $appData, $tag ) !== [] ? 'ok' : 'none' ];

		$dir = \Nino\Filesystem::path( $appData, Render::DIR );

		// The nearest directory there is must be writable, and nothing that is no directory may lie in the way
		while( $dir !== '' && is_dir( $dir ) === false && file_exists( $dir ) === false && dirname( $dir ) !== $dir )
			$dir = dirname( $dir );

		$items[] = [ 'key' => 'pdf-dir', 'state' => is_dir( $dir ) === true && is_writable( $dir ) === true ? 'ok' : 'unwritable' ];

		\Nino\Http::ok( $request, [ 'items' => $items ] );
	}

	/**
	 *	The step from a finalised document to a draft of another: the offer's invoice, the invoice's credit note
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *	@param		string		$action
	 *	@param		callable	$derive
	 *
	 *	@return 	void
	 */
	private static function _derived( array &$appData, array &$request, string $action, callable $derive ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request, $action, $derive ): void {

			$source = self::_document( $appData, $request, \Nino\Admin\Admin::postData() );

			if( $source === null )
				return;

			$draft = $derive( $appData, $source['id'] );

			if( is_string( $draft ) === true ) {
				self::_refused( $request, $draft );
				return;
			}

			self::$_made[$action] = $source['number'];

			\Nino\Http::ok( $request, [ 'document' => self::_shown( $appData, $draft ) ] );
		} );
	}

	/**
	 *	The document a request names by id, or the 400/404 that says why not
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *	@param		array 		$data
	 *
	 *	@return 	array|null
	 */
	private static function _document( array &$appData, array &$request, array $data ): ?array {

		$id = \Crm\Admin\Api::id( $request, $data, 'crm_documents_id' );

		if( $id === null )
			return null;

		$document = Documents::get( $appData, $id );

		if( $document === null )
			\Nino\Http::fail( $request, 404, 'there is no such document', 'crm_documents_unknown', [], 'id' );

		return $document;
	}

	/**
	 *	A document as the screen has it: the frozen client is the client, and the address of its PDF is there once it has one; a document
	 *	that asked for a signature has the addresses of the PDFs of the request, which Sign answers to the workbench
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document
	 *
	 *	@return 	array
	 */
	private static function _shown( array &$appData, array $document ): array {

		unset( $document['client_frozen'] );
		$dir = \Nino\Filesystem::getDir( $appData );
		$document['pdf_url'] = $document['pdf_path'] === '' ? '' : $dir. '/.crm/pdf/'. $document['id'];
		$document['sign_shown_url'] = $document['sign_id'] === '' ? '' : $dir. '/.sign/pdf?r='. $document['sign_id']. '&kind=shown';
		$document['sign_signed_url'] = $document['sign_state'] === 'signed' ? $dir. '/.sign/pdf?r='. $document['sign_id']. '&kind=signed' : '';

		return $document;
	}

	/**
	 *	A document with the templates of its tag, the settings the editor draws with, the offers a contract can hang on and - for a
	 *	document that can ask for a signature - the accounts of its client
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document
	 *
	 *	@return 	array
	 */
	private static function _view( array &$appData, array $document ): array {

		$signable = in_array( $document['category'], Documents::SIGNABLE, true ) === true && $document['state'] !== 'draft';

		return [
			'document'  => self::_shown( $appData, $document ),
			'templates' => self::_templates( $appData, $document['category'] ),
			'clients'   => self::_clients( $appData ),
			'settings'  => self::_settings( $appData ),
			'items'     => \Crm\Items::list( $appData, true ),
			'offers'    => self::_offers( $appData ),
			'accounts'  => $signable === true ? self::_accounts( $appData, $document['client_id'] ) : [],
			'sign_days' => max( 1, min( 90, (int) \Nino\Features::setting( $appData, 'sign', 'days', 14 ) ) ),
		];
	}

	/**
	 *	The offers a contract can hang on: finalised, the newest first
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											id, number, date, client_id and client_title of each
	 */
	private static function _offers( array &$appData ): array {

		$offers = array_filter( Documents::list( $appData, [ 'category' => 'offer' ], false ), static fn( array $offer ): bool => $offer['state'] !== 'draft' );

		return array_values( array_map( static fn( array $offer ): array => [ 'id' => $offer['id'], 'number' => $offer['number'], 'date' => $offer['date'], 'client_id' => $offer['client_id'], 'client_title' => $offer['client_title'] ], $offers ) );
	}

	/**
	 *	The accounts of a client with their state in Members: the ones a signature can be asked of are the active ones
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$clientId
	 *
	 *	@return 	array											id, email, name and active of each; none while Members is off
	 */
	private static function _accounts( array &$appData, int $clientId ): array {

		if( \Crm\Crm::featureOn( $appData, 'members' ) === false )
			return [];

		$accounts = [];

		foreach( \Crm\Clients::accounts( $appData, $clientId ) as $id ) {

			$account = \Nino\Modules\Members::account( $appData, $id );

			if( $account !== null )
				$accounts[] = [ 'id' => $account['id'], 'email' => $account['email'], 'name' => $account['name'], 'active' => $account['active'] ];
		}

		return $accounts;
	}

	/**
	 *	The templates of Pdf for the tag of a category; none while Pdf is not active
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$category
	 *
	 *	@return 	array											key, name and the inputs of each
	 */
	private static function _templates( array &$appData, string $category ): array {

		if( \Crm\Crm::featureOn( $appData, 'pdf' ) === false )
			return [];

		$templates = [];

		foreach( \Nino\Modules\Pdf::templates( $appData, Render::tag( $category ) ) as $key => $template )
			$templates[] = [ 'key' => $key, 'name' => $template['name'], 'inputs' => \Nino\Modules\Pdf::inputs( $appData, $key ) ];

		return $templates;
	}

	/**
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											id, number, title and active of every client, by number
	 */
	private static function _clients( array &$appData ): array {

		return array_map( static fn( array $client ): array => [ 'id' => $client['id'], 'number' => $client['number'], 'title' => $client['company'] !== '' ? $client['company'] : $client['name'], 'active' => $client['active'] ], \Crm\Clients::list( $appData ) );
	}

	/**
	 *	What the screen needs to compute and draw a document
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											The settings, and the rates as a list
	 */
	private static function _settings( array &$appData ): array {

		return Settings::all( $appData ) + [ 'rates' => Settings::rates( $appData ) ];
	}

	/**
	 *	The answer to a refusal of the model
	 *
	 *	@param		array 		&$request
	 *	@param		string		$why					See Crm\Documents and Crm\Settings
	 *
	 *	@return 	void
	 */
	private static function _refused( array &$request, string $why ): void {

		[ $kind, $rest ] = array_pad( explode( ':', $why, 2 ), 2, '' );

		// What Sign refused the request with; a refusal it does not make is the module's own error
		if( $kind === 'sign' && $rest !== '' ) {

			if( isset( self::SIGN_REFUSALS[$rest] ) === false ) {
				\Nino\Http::fail( $request, 500, 'the document module refused with "'. $why. '"' );
				return;
			}

			[ $status, $code ] = self::SIGN_REFUSALS[$rest];
			\Nino\Http::fail( $request, $status, 'Sign refused the request: '. $rest, $code );
			return;
		}

		// A document without lines has a title, and it is the title that is missing, too long or wrong
		if( $kind === 'empty' && $rest === 'title' ) {
			\Nino\Http::fail( $request, 400, 'the title is missing', 'crm_documents_title', [], 'title' );
			return;
		}

		if( isset( self::REFUSALS[$kind] ) === true ) {
			[ $status, $code, $field ] = self::REFUSALS[$kind];
			\Nino\Http::fail( $request, $status, 'the document does not allow it: '. $kind, $code, $kind === 'lines' ? [ \Crm\Documents::MAX_LINES ] : [], $field );
			return;
		}

		match( $kind ) {
			'date'   => \Nino\Http::fail( $request, 400, 'the date is none', 'crm_documents_date', [], $rest ),
			'long'   => \Nino\Http::fail( $request, 400, 'the value is too long', 'crm_documents_long', [ $rest === 'title' ? \Crm\Documents::MAX_TITLE : \Crm\Documents::MAX_NOTES ], $rest ),
			'format' => str_starts_with( $rest, 'number_' ) === true
				? \Nino\Http::fail( $request, 400, 'the number format is none', 'crm_documents_numberformat', [], $rest )
				: \Nino\Http::fail( $request, 400, 'the value has a wrong format', 'crm_documents_format', [], $rest ),
			'exists' => \Nino\Http::fail( $request, 409, 'the PDF file is there already', 'crm_documents_exists', [ basename( $rest, '.pdf' ), ltrim( $rest, '/' ) ] ),
			'inputs' => \Nino\Http::fail( $request, 409, 'a required input is missing', 'crm_documents_inputs', [], 'inputs.'. $rest ),
			'line'   => self::_refusedLine( $request, $rest ),
			'value'  => \Nino\Http::fail( $request, 400, 'the setting has a wrong value', 'crm_documents_setting', [], $rest ),
			default  => \Nino\Http::fail( $request, 500, 'the document module refused with "'. $kind. '"' ),
		};
	}

	/**
	 *	A line that is wrong: line:<index>:<kind>:<field>
	 *
	 *	@param		array 		&$request
	 *	@param		string		$rest					<index>:<kind>:<field>
	 *
	 *	@return 	void
	 */
	private static function _refusedLine( array &$request, string $rest ): void {

		[ $index, $kind, $field ] = array_pad( explode( ':', $rest, 3 ), 3, '' );
		$code = in_array( $kind, [ 'empty', 'long', 'format', 'amount', 'rate' ], true ) === true ? $kind : 'format';

		\Nino\Http::fail( $request, 400, 'a line is wrong', 'crm_documents_line_'. $code, [ (int) $index + 1 ], 'lines.'. (int) $index. ( $field === '' ? '' : '.'. $field ) );
	}
}
