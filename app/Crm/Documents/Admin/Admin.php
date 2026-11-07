<?php
declare(strict_types=1);
/**
 *	Crm\Documents\Admin		The API of the documents behind the surface's endpoint (Surface::actions()): the documents (list with filters,
 *												the draft with its lines, finalising, the steps of a finalised document, the offer that becomes an
 *												invoice and the invoice that is taken back by a credit note; the contract, the privacy and the
 *												information document without lines; the release of a document to the portal with its mail and the
 *												request for a signature through Sign), the settings of the documents with what stands beside them
 *												(the next numbers, the templates of Pdf, the portal) and the items of the catalogue. It is no panel of
 *												the workbench any more. It validates what it is posted and answers the failures a person can cause with
 *												codes (their sentences are in text/); the rules of the data are Crm\Documents', the storage Crm\Db's.
 *												A database failure is answered 500 with the code crm_documents_database.
 */
namespace Crm\Documents;

use Crm\Documents;
use Crm\Settings;

class Admin {

	public const string MANAGE_PERM = \Crm\Crm::MANAGE_PERM;

	// What the model refuses with, as the status and the code a person is answered. A kind that stands for a field names it
	private const array REFUSALS = [
		'unknown'  => [ 404, 'crm_documents_unknown', 'id' ],
		'fixed'    => [ 409, 'crm_documents_fixed', '' ],
		'state'    => [ 409, 'crm_documents_state', '' ],
		'category' => [ 400, 'crm_documents_category', 'category' ],
		'client'   => [ 400, 'crm_documents_client', 'client_id' ],
		'inactive' => [ 409, 'crm_documents_inactive', 'client_id' ],
		'unready'  => [ 409, 'crm_documents_unready', 'client_id' ],
		'period'   => [ 400, 'crm_documents_period', 'period_from' ],
		'reverse'  => [ 409, 'crm_documents_reverse', 'reverse_charge' ],
		'lines'    => [ 400, 'crm_documents_lines', 'lines' ],
		'total'    => [ 400, 'crm_documents_total', 'lines' ],
		'pdf'      => [ 409, 'crm_documents_pdf', '' ],
		'template' => [ 409, 'crm_documents_template', 'template_key' ],
		'empty'    => [ 409, 'crm_documents_empty', 'lines' ],
		'gross'    => [ 409, 'crm_documents_gross', 'lines' ],
		'credit_parent' => [ 409, 'crm_documents_credit_parent', '' ],
		'credit_lines'  => [ 400, 'crm_documents_credit_lines', 'lines' ],
		'credit_over'   => [ 409, 'crm_documents_credit_over', 'lines' ],
		'daterange' => [ 400, 'crm_documents_date_range', 'date' ],
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
		'released' => [ 409, 'crm_documents_released', '' ],
		'download' => [ 409, 'crm_documents_download', '' ],
		'note'     => [ 400, 'crm_documents_note', 'note' ],
		'note_mail' => [ 409, 'crm_documents_note_mail', 'note' ],
		'mail'     => [ 409, 'crm_documents_sign_mail', '' ],
		'link_perm' => [ 403, 'crm_documents_link_perm', '' ],
		'project'  => [ 400, 'crm_project', 'project' ],
		'project_client' => [ 409, 'crm_project_client', 'project' ],
	];

	// What Sign refuses a request with, as the status and the code a person is answered
	// A setting that is one of the invoice particulars, or the message to the clients, has a sentence of its own for a wrong value
	private const array PARTICULARS = [
		'tax_id' => 'crm_documents_particular_tax_id', 'bank_name' => 'crm_documents_particular_bank_name', 'iban' => 'crm_documents_particular_iban', 'bic' => 'crm_documents_particular_bic', 'paypal' => 'crm_documents_particular_paypal',
		'seller_name' => 'crm_documents_particular_seller_name', 'seller_street' => 'crm_documents_particular_seller_street', 'seller_zip' => 'crm_documents_particular_seller_zip', 'seller_city' => 'crm_documents_particular_seller_city',
		'seller_country' => 'crm_documents_particular_seller_country', 'seller_contact' => 'crm_documents_particular_seller_contact', 'seller_phone' => 'crm_documents_particular_seller_phone', 'seller_email' => 'crm_documents_particular_seller_email',
		'vat_id' => 'crm_documents_particular_vat_id', 'vat_exempt_text' => 'crm_documents_particular_vat_exempt_text',
		'portal_message' => 'crm_documents_portal_message',
		'consent_social' => 'crm_documents_consent_social', 'consent_archive' => 'crm_documents_consent_archive',
	];

	private const array SIGN_REFUSALS = [
		'template' => [ 409, 'crm_documents_sign_template' ],
		'input'    => [ 409, 'crm_documents_sign_input' ],
		'render'   => [ 500, 'crm_documents_sign_render' ],
		'write'    => [ 500, 'crm_documents_sign_write' ],
	];

	// The fields of a draft a post may carry
	// The template Einrichten makes for each kind of document: a new draft starts with it where nothing else says which to take
	private const array STANDARD = [ 'offer' => 'angebot', 'invoice' => 'rechnung', 'credit' => 'stornorechnung', 'contract' => 'vertrag', 'privacy' => 'datenschutz', 'information' => 'information' ];

	private const array FIELDS = [ 'date', 'due_date', 'valid_until', 'period_from', 'period_to', 'template_key', 'inputs', 'reverse_charge', 'lines', 'title', 'project', 'buyer_reference' ];

	// What an action made or touched, by action: written by the action, read by log() in the same request
	private static array $_made = [];

	public static function actions(): array {
		return [
			'crm-documents/list'          => [ self::class, 'apiList' ],
			'crm-documents/get'           => [ self::class, 'apiGet' ],
			'crm-documents/save'          => [ self::class, 'apiSave' ],
			'crm-documents/remove'        => [ self::class, 'apiRemove' ],
			'crm-documents/project'       => [ self::class, 'apiProject' ],
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
			'crm-documents/positionen'    => [ self::class, 'apiPositionen' ],
			'crm-documents/status'        => [ self::class, 'apiStatus' ],
			'crm-documents/overview'      => [ self::class, 'apiOverview' ],
			'crm-documents/figures'       => [ self::class, 'apiFigures' ],
			'crm-documents/copy'          => [ self::class, 'apiCopy' ],
			'crm-documents/remind'        => [ self::class, 'apiRemind' ],
			'crm-documents/sign-remind'   => [ self::class, 'apiSignRemind' ],
			'crm-documents/sign-cancel'   => [ self::class, 'apiSignCancel' ],
		];
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
			'crm-documents/project'       => 'Changed the project of document '. $of,
			'crm-documents/finalise'      => 'Finalised document '. $of,
			'crm-documents/state'         => 'Moved document '. $of,
			'crm-documents/from-offer'    => 'Made an invoice draft from offer '. $of,
			'crm-documents/credit'        => 'Made a credit note draft from invoice '. $of,
			'crm-documents/release'       => 'Released document '. $of,
			'crm-documents/unrelease'     => 'Took back document '. $of,
			'crm-documents/sign'          => 'Asked for a signature on document '. $of,
			'crm-documents/copy'          => 'Copied document '. $of. ' to a draft',
			'crm-documents/remind'        => str_contains( $of, ', without mail' ) === true ? 'Noted a payment reminder for document '. $of : 'Sent a payment reminder for document '. $of,
			'crm-documents/sign-remind'   => str_contains( $of, ', without mail' ) === true ? 'Made a new link of the signature on document '. $of : 'Sent the link of the signature on document '. $of. ' again',
			'crm-documents/sign-cancel'   => 'Took back the signature request on document '. $of,
			'crm-documents/settings-save' => 'Saved the settings of the documents',
			default                       => '',
		};
	}

	/**
	 *	The documents by the filters, the clients and years to filter by, the settings the screen draws with, the active items of the catalogue (the elements of the type Positionen) a line is filled from and what the export of the list can do (export: whether the ZIP can be made, for how many documents, and how many documents of the year of the filter have a PDF file)
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

		$project = \Nino\Admin\Admin::postString( $data, 'project' );

		if( $project !== '' && preg_match( \Crm\Projekte::ID, $project ) !== 1 ) {
			\Nino\Http::fail( $request, 400, 'the filter is not valid', 'crm_documents_format', [], 'project' );
			return;
		}

		if( $project !== '' )
			$filter['project'] = $project;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request, $filter ): void {

			\Nino\Http::ok( $request, [
				'documents' => Documents::list( $appData, $filter ),
				'years'     => Documents::years( $appData ),
				'clients'   => self::_clients( $appData ),
				'settings'  => self::_settings( $appData ),
				'items'     => self::_catalogue( $appData ),
				'offers'    => self::_offers( $appData ),
				'export'    => [ 'zip' => \Crm\Surface\Uploads::zipAvailable(), 'limit' => \Crm\Surface\Export::MAX_FILES, 'files' => isset( $filter['year'] ) === true ? Documents::exportable( $appData, $filter['year'] ) : 0 ],
			] );
		} );
	}

	/**
	 *	What the first screen of the surface shows: the books at a glance - and the count of the active clients, which the rail shows - what needs the owner, the finished projects whose files lie on the server for long (stale, see Projekte\Admin::stale()), the comments of the customers that no one has settled (review, see Surface\Review::open()) and the documents of the latest dates, with the settings the amounts are written by
	 */
	public static function apiOverview( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request ): void {

			\Nino\Http::ok( $request, [
				'summary'  => Documents::summary( $appData ) + [ 'clients' => \Crm\Clients::countActive( $appData ) ],
				'needs'    => Documents::needs( $appData ),
				'stale'    => \Crm\Projekte\Admin::stale( $appData ),
				'review'   => \Crm\Surface\Review::open( $appData ),
				'recent'   => Documents::recent( $appData, 8 ),
				'settings' => self::_settings( $appData ),
			] );
		} );
	}

	/**
	 *	The figures of the block Zahlen on the first screen: the last twelve months, the five clients that were billed most this year and the offers of the year (see Documents::figures()), with the settings the amounts are written by
	 */
	public static function apiFigures( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request ): void {
			\Nino\Http::ok( $request, Documents::figures( $appData ) + [ 'settings' => self::_settings( $appData ) ] );
		} );
	}

	/**
	 *	A draft from a document of any state
	 */
	public static function apiCopy( array &$appData, array &$request ): void {
		self::_derived( $appData, $request, 'crm-documents/copy', [ Documents::class, 'copy' ] );
	}

	/**
	 *	Remind the accounts of the client of an invoice that is open or sent and released to the portal - overdue or not - to pay it: the release mail again, in the words of a reminder - unless notify says false,
	 *	when nothing is sent and the reminder is noted all the same. Answers the document and how many mails were delivered
	 */
	public static function apiRemind( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request ): void {

			$data = \Nino\Admin\Admin::postData();
			$document = self::_document( $appData, $request, $data );

			if( $document === null )
				return;

			$notify = ( $data['notify'] ?? true ) !== false;
			$reminded = Documents::remind( $appData, $document['id'], $notify );

			if( is_string( $reminded ) === true ) {
				self::_refused( $request, $reminded );
				return;
			}

			self::$_made['crm-documents/remind'] = Render::name( $document ). ( $notify === true ? ', by mail' : ', without mail' );

			\Nino\Http::ok( $request, [ 'document' => self::_shown( $appData, $reminded['document'] ), 'mailed' => $reminded['mailed'], 'notify' => $notify ] );
		} );
	}

	/**
	 *	Send the link of a signature request again - or, with notify false, make the new link and send nothing: the answer carries it, once
	 */
	public static function apiSignRemind( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request ): void {

			$data = \Nino\Admin\Admin::postData();
			$source = self::_document( $appData, $request, $data );

			if( $source === null )
				return;

			$notify = ( $data['notify'] ?? true ) !== false;

			// A link that is shown is the link of the account: it takes the permission of Sign, which the CRM's alone is not
			if( $notify === false && \Crm\Crm::mayLink( $appData, 'sign' ) === false ) {
				self::_refused( $request, 'link_perm' );
				return;
			}

			$reminded = Documents::remindSignature( $appData, $source['id'], $notify );

			if( is_string( $reminded ) === true ) {
				self::_refused( $request, $reminded );
				return;
			}

			// The link is the answer's and nothing else's: it is taken off the document before the document is shown, and no line of the log holds it
			$link = (string) ( $reminded['link'] ?? '' );
			unset( $reminded['link'] );

			self::$_made['crm-documents/sign-remind'] = Render::name( $source ). ( $notify === true ? '' : ', without mail' );

			\Nino\Http::ok( $request, [ 'document' => self::_shown( $appData, $reminded ) ] + ( $notify === false ? [ 'link' => $link ] : [] ) );
		} );
	}

	/**
	 *	Take a signature request back
	 */
	public static function apiSignCancel( array &$appData, array &$request ): void {
		self::_derived( $appData, $request, 'crm-documents/sign-cancel', [ Documents::class, 'cancelSignature' ] );
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
	 *	Set the project of a document of any state, a finalised one included: id and project (the id of a project of the document's client, '' for none).
	 *	The relation is no part of the finalised content, the PDF and its hash stay. Answers the document as save does
	 */
	public static function apiProject( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request ): void {

			$data = \Nino\Admin\Admin::postData();
			$document = self::_document( $appData, $request, $data );

			if( $document === null )
				return;

			$project = is_string( $data['project'] ?? null ) === true ? $data['project'] : null;

			if( $project === null ) {
				\Nino\Http::fail( $request, 400, 'the value has a wrong format', 'crm_documents_format', [], 'project' );
				return;
			}

			$moved = Documents::setProject( $appData, $document['id'], $project );

			if( is_string( $moved ) === true ) {
				self::_refused( $request, $moved );
				return;
			}

			self::$_made['crm-documents/project'] = Render::name( $moved ). ' to '. ( $moved['project'] === '' ? 'none' : 'project '. $moved['project'] );

			\Nino\Http::ok( $request, [ 'document' => self::_shown( $appData, $moved ) ] );
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
	 *	credit note that was open). With download true the release of an invoice of a project also switches the download of the project's files on (Documents::release()).
	 *	note is a sentence of the owner to the customer (at most Documents::MAX_NOTE characters, text) that the mail carries and the document keeps; it needs mail true. The activity log names the document, never the sentence.
	 *	Answers the document and how many mails were delivered
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
			$user = \Nino\Auth::getCurrentUser( $appData );

			// The sentence to the customer is text; anything else is no sentence
			if( array_key_exists( 'note', $data ) === true && is_string( $data['note'] ) === false ) {
				self::_refused( $request, 'note' );
				return;
			}

			$released = Documents::release( $appData, $document['id'], $mail, ( $data['download'] ?? false ) === true, $user === false ? '' : (string) $user['mail'], (string) ( $data['note'] ?? '' ) );

			if( is_string( $released ) === true ) {
				self::_refused( $request, $released );
				return;
			}

			self::$_made['crm-documents/release'] = Render::name( $document ). ( $released['download'] === true ? ', with the download of project '. $document['project'] : '' );

			\Nino\Http::ok( $request, [ 'document' => self::_shown( $appData, $released['document'] ), 'mailed' => $released['mailed'], 'mail' => $mail, 'download' => $released['download'] ] );
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
	 *	days how long the link is good for (1 to 90; none is the setting of Sign), notify (a bool, true unless it says false): with false the request is
	 *	made, nobody is mailed and the answer carries the link, once
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

			$notify = ( $data['notify'] ?? true ) !== false;

			if( $notify === false && \Crm\Crm::mayLink( $appData, 'sign' ) === false ) {
				self::_refused( $request, 'link_perm' );
				return;
			}

			$asked = Documents::requestSignature( $appData, $document['id'], $account, $days === '' ? null : (int) $days, $notify );

			if( is_string( $asked ) === true ) {
				self::_refused( $request, $asked );
				return;
			}

			self::$_made['crm-documents/sign'] = Render::name( $document ). ( $notify === true ? '' : ', without mail' );

			\Nino\Http::ok( $request, [ 'document' => self::_shown( $appData, $asked['document'] ), 'mailed' => $asked['mailed'] ] + ( $notify === false ? [ 'link' => (string) $asked['link'] ] : [] ) );
		} );
	}

	/**
	 *	The settings of the documents, and what the screen of the settings shows beside them: the number each sequence gives next, the templates
	 *	of Pdf by tag, whether Pdf is on, the portal as the owner is told of it and the texts of the company (the block Firmendaten reads them here)
	 */
	public static function apiSettingsGet( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request ): void {
			\Nino\Http::ok( $request, [
				'settings'  => Settings::all( $appData ),
				'next'      => self::_next( $appData ),
				'templates' => self::_tags( $appData ),
				'pdf'       => \Crm\Crm::featureOn( $appData, 'pdf' ),
				'portal'    => \Crm\Portal::overview( $appData ),
				'company'   => \Crm\Surface\Texts::company( $appData ),
			] );
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

			// What the module keeps itself (the day the Datenschutz changed) is not posted
			if( array_key_exists( $key, $data ) === false || in_array( $key, Settings::READ_ONLY, true ) === true )
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
			$templates = self::_templates( $appData, $category );
			$standard = self::STANDARD[$category];

			\Nino\Http::ok( $request, [ 'templates' => $templates, 'standard' => in_array( $standard, array_column( $templates, 'key' ), true ) === true ? $standard : '', 'offers' => self::_offers( $appData ), 'items' => self::_catalogue( $appData ) ] );
		} );
	}

	/**
	 *	The items of the catalogue as the Elements panel keeps them, for the screen that looks at them: every item, hidden ones too, in the order of
	 *	their sort, with its words in each language of the site (what the element holds in that language, nothing taken from another), the price
	 *	and the rate. missing says the element type is not there, which the button Portal einrichten brings
	 */
	public static function apiPositionen( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request ): void {

			\Nino\Http::ok( $request, [
				'missing'  => \Crm\Positionen::missing( $appData ),
				'unit_code' => \Crm\Positionen::hasUnitCode( $appData ),
				'locales'  => array_values( \Nino\Locales::getAvailableLocales( $appData ) ),
				'native'   => \Nino\Locales::getNativeLocale( $appData ),
				'items'    => \Crm\Positionen::all( $appData ),
				'settings' => self::_settings( $appData ),
			] );
		} );
	}

	/**
	 *	What the module misses: each item has a key and a state
	 *	  module           ok, off (the class is not in /nino/modules)
	 *	  data             ok, unwritable (the data directory)
	 *	  pdf              ok, off (installed, not active), missing (not installed), problem
	 *	  templates-offer, templates-invoice     ok, none (Pdf has no template of the tag) - only while Pdf is active
	 *	  pdf-dir          ok, unwritable (the directory the PDF files are kept in, or the one above it where it is not made yet)
	 *	  positionen       ok, missing (the element type /positionen, which the button Portal einrichten brings, in the panel CRM and in the settings of the surface)
	 *	  projekte         ok, missing (the element type /projekte, which the same button brings)
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
		$items[] = [ 'key' => 'positionen', 'state' => \Crm\Positionen::missing( $appData ) === true ? 'missing' : 'ok' ];
		$items[] = [ 'key' => 'projekte', 'state' => \Crm\Projekte::missing( $appData ) === true ? 'missing' : 'ok' ];

		\Nino\Http::ok( $request, [ 'items' => $items ] );
	}

	/**
	 *	The step from a document to another or to itself again: the offer's invoice, the invoice's credit note, a copy, a signature request sent again or taken back
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

			self::$_made[$action] = Render::name( $source );

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
		$document['xml_url'] = $document['xml_path'] === '' ? '' : $dir. '/.crm/xml/'. $document['id'];
		$document['erechnung'] = self::_electronic( $appData, $document );
		$document['sign_shown_url'] = $document['sign_id'] === '' ? '' : $dir. '/.sign/pdf?r='. $document['sign_id']. '&kind=shown';
		$document['sign_signed_url'] = $document['sign_state'] === 'signed' ? $dir. '/.sign/pdf?r='. $document['sign_id']. '&kind=signed' : '';

		return $document;
	}

	/**
	 *	What the screens say of the electronic invoice of a document: null for a document that gets none (an offer, a document without lines, a document of a private
	 *	client, one finalised before the client had the flag); otherwise whether the file is there (xml) and what is missing, each with its key and its sentence.
	 *	A draft is read as it stands now; a finalised document says what was missing when it was finalised
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			As get() has it
	 *
	 *	@return 	array|null								xml (bool) and lacks ( [ key, text ] )
	 */
	private static function _electronic( array &$appData, array $document ): ?array {

		if( $document['state'] === 'draft' )
			return XRechnung::applies( $document ) === false ? null : [ 'xml' => false, 'lacks' => XRechnung::sentences( XRechnung::lacks( $appData, $document ) ) ];

		if( $document['xml_path'] !== '' )
			return [ 'xml' => true, 'lacks' => [] ];

		return $document['xml_lacks'] === [] ? null : [ 'xml' => false, 'lacks' => XRechnung::sentences( $document['xml_lacks'] ) ];
	}

	/**
	 *	A document with the templates of its tag, the settings the editor draws with, the offers a contract can hang on and - for a
	 *	document that is not a draft - the accounts of its client: the ones a signature can be asked of and the ones the release mails
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document
	 *
	 *	@return 	array
	 */
	private static function _view( array &$appData, array $document ): array {

		return [
			'document'  => self::_shown( $appData, $document ),
			'templates' => self::_templates( $appData, $document['category'] ),
			'clients'   => self::_clients( $appData ),
			'settings'  => self::_settings( $appData ),
			'items'     => self::_catalogue( $appData ),
			'offers'    => self::_offers( $appData ),
			'projects'  => self::_projects( $appData, $document['client_id'] ),
			'accounts'  => $document['state'] !== 'draft' ? self::_accounts( $appData, $document['client_id'] ) : [],
			'order'     => $document['state'] !== 'draft' ? \Crm\Records::forDocument( $appData, $document['id'] ) : null,
			'decline'   => $document['state'] !== 'draft' ? self::_withReason( \Crm\Records::declineOf( $appData, $document['id'] ) ) : null,
			'payment'   => $document['state'] !== 'draft' ? \Crm\Records::paymentOf( $appData, $document['id'] ) : null,
			'sign_days' => max( 1, min( 90, (int) \Nino\Features::setting( $appData, 'sign', 'days', 14 ) ) ),
		];
	}

	/**
	 *	The record of a decline with the reason the customer wrote, which the JSON that was shown holds
	 *
	 *	@param		array|null	$record				As Records::declineOf() has it
	 *
	 *	@return 	array|null								The record with reason; null for none
	 */
	private static function _withReason( ?array $record ): ?array {

		return $record === null ? null : $record + [ 'reason' => \Crm\Records::reasonOf( $record ) ];
	}

	/**
	 *	The items a line is filled from: the active elements of the type Positionen in the native language, as the editor reads them
	 *	(price_net in cents, vat_rate in hundredths). Where the project is a small business the rate is not asked for and 0 is written
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											id, title, description, unit, unit_code, price_net, vat_rate, active and sort of each
	 */
	private static function _catalogue( array &$appData ): array {

		$small = \Crm\Settings::get( $appData, 'vat_mode' ) === 'small_business';

		return array_map( static fn( array $item ): array => [
			'id'          => $item['id'],
			'title'       => $item['title'],
			'description' => $item['description'],
			'unit'        => $item['unit'],
			'unit_code'   => $item['unit_code'],
			'price_net'   => $item['price'],
			'vat_rate'    => $small === true ? 0 : $item['vat'],
			'active'      => $item['active'],
			'sort'        => $item['sort'],
		], \Crm\Positionen::list( $appData ) );
	}

	/**
	 *	The projects of a client, the newest first, the done ones included (the screen offers the open ones and the one the document names)
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$clientId
	 *
	 *	@return 	array											id, title, done and download (whether the client may download the files) of each
	 */
	private static function _projects( array &$appData, int $clientId ): array {

		$client = \Crm\Clients::get( $appData, $clientId );

		return $client === null ? [] : array_map( static fn( array $project ): array => [ 'id' => $project['id'], 'title' => $project['title'], 'done' => $project['done'], 'download' => $project['download'] ], \Crm\Projekte::ofClient( $appData, $client['number'] ) );
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
	 *	@return 	array											id, number, title, ready, bare (neither company nor name), active, business and buyer_reference of every client, by number
	 */
	private static function _clients( array &$appData ): array {

		return array_map( static fn( array $client ): array => [ 'id' => $client['id'], 'number' => $client['number'], 'title' => $client['title'], 'ready' => $client['ready'], 'bare' => $client['company'] === '' && $client['name'] === '', 'active' => $client['active'], 'business' => $client['business'], 'buyer_reference' => $client['buyer_reference'] ], \Crm\Clients::list( $appData ) );
	}

	/**
	 *	The number each sequence gives next in the year now
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											category => number (offer, invoice, credit)
	 */
	private static function _next( array &$appData ): array {

		$year = (int) date( 'Y' );
		$next = [];

		foreach( Settings::FORMATS as $category => $key )
			$next[$category] = Numbers::upcoming( $appData, $category, $year, Settings::get( $appData, $key ) );

		return $next;
	}

	/**
	 *	The templates of Pdf by the tags a document has - none while Pdf is off
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											tag => names of its templates
	 */
	private static function _tags( array &$appData ): array {

		$tags = [];

		foreach( [ 'offer', 'invoice', 'contract', 'privacy', 'information' ] as $tag )
			$tags[$tag] = array_column( self::_templates( $appData, $tag ), 'name' );

		return $tags;
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
			'long'   => \Nino\Http::fail( $request, 400, 'the value is too long', 'crm_documents_long', [ match( $rest ) { 'title' => \Crm\Documents::MAX_TITLE, 'buyer_reference' => \Crm\Documents::MAX_REFERENCE, default => \Crm\Documents::MAX_NOTES } ], $rest ),
			'format' => str_starts_with( $rest, 'number_' ) === true
				? \Nino\Http::fail( $request, 400, 'the number format is none', 'crm_documents_numberformat', [], $rest )
				: \Nino\Http::fail( $request, 400, 'the value has a wrong format', 'crm_documents_format', [], $rest ),
			'exists' => str_ends_with( $rest, '.xml' ) === true
				? \Nino\Http::fail( $request, 409, 'the file of the electronic invoice is there already', 'crm_documents_exists_xml', [ basename( $rest, '.xml' ), ltrim( $rest, '/' ) ] )
				: \Nino\Http::fail( $request, 409, 'the PDF file is there already', 'crm_documents_exists', [ basename( $rest, '.pdf' ), ltrim( $rest, '/' ) ] ),
			'inputs' => \Nino\Http::fail( $request, 409, 'a required input is missing', 'crm_documents_inputs', [], 'inputs.'. $rest ),
			'line'   => self::_refusedLine( $request, $rest ),
			'value'  => \Nino\Http::fail( $request, 400, 'the setting has a wrong value', self::PARTICULARS[$rest] ?? 'crm_documents_setting', [], $rest ),
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
