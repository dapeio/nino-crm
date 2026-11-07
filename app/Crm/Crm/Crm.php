<?php
declare(strict_types=1);
/**
 *	Crm\Crm		The project module of the CRM. Listed in /nino/modules by the setup wizard, which finds its install unit (install/);
 *						init() registers behaviour and writes nothing: the panel CRM (adminPanels()), the
 *						components [crm-portal-head], [crm-portal-door], [crm-client], [crm-documents], [crm-portal-nav], [crm-portal-start], [crm-portal-dates], [crm-projects], [crm-uploads], [crm-client-data] and [crm-privacy], the callbacks that keep the portal's lookup true when a member signs in or
 *						out, the one that takes the portal's pages out of the menus of a visitor, the gate of the client (every page of the portal sends a member whose client has not taken note of the Datenschutz or given the data of the invoice to that step, and a file is withheld from such a member) and the ones that let a document follow its request in Sign, the style sheet and the script of the portal, the route
 *						/.crm/pdf/<id> that answers the PDF of a finalised document, /.crm/xml/<id> that answers its electronic invoice and the routes POST /.crm/daten of the page Meine Daten, POST /.crm/anmeldeadresse and POST /.crm/anmeldeadresse/code of the block Anmeldeadresse on that page, POST /.crm/geraete/abmelden of its button that signs the account out of every device, GET /portal/manifest.webmanifest and the icons /portal/icon.svg and /portal/icon.png of the portal as an app on the home screen (Portal\App), POST /.crm/datenschutz of the page Datenschutz, POST /.crm/auftrag of the order under an offer and POST /.crm/abnahme of the approval under the files of a project and POST /.crm/einwilligung/widerruf of the revocation of a consent, GET /.crm/player that answers the player of a file of a project to the script of the portal, and the callback that mails the owner when a member has changed the address the account signs in with; and the surface at /crm, one page of the project with its
 *						endpoint and assets (Surface::init()). The database is opened on first use, the portal pages come with the install unit
 *						the wizard applies, and the screen Einrichtung of the surface completes the product (see Admin::apiSetup()).
 */
namespace Crm;

class Crm {

	// The one permission of the CRM: the surface at /crm, the panel CRM of the workbench and the API behind both
	public const string MANAGE_PERM = '/_admin/crm/manage';

	// The permissions of the features whose links the owner may have shown instead of mailed: a link without the mail signs in or signs as the account,
	// so it needs the permission of the feature that makes it - the CRM's alone is not enough
	public const string LINK_PERM_MEMBERS = '/_admin/members/manage';
	public const string LINK_PERM_SIGN = '/_admin/sign/manage';

	// The body of the route GET:// once the site's front is the portal's door (written by the Einrichtung, answered by Portal::callbackDoor())
	public const string DOOR = '[template /templates/page-door]';

	/**
	 *	@param		array 		&$appData			(reference) Array with current app data
	 *
	 *	@return 	void
	 */
	public static function init( array &$appData ): void {

		\Nino\Modules\Components::addComponent( $appData, 'crm-portal-head', [ Portal::class, 'componentCrmPortalHead' ], [
			'label'      => [ 'en_US' => 'Head of a portal page', 'de_DE' => 'Kopf einer Portalseite' ],
			'source'     => 'none',
			'loop'       => false,
			'attributes' => [
				'calendar' => [
					'type'    => 'bool',
					'default' => false,
					'label'   => [ 'en_US' => 'Calendar subscription', 'de_DE' => 'Kalender-Abo' ],
					'hint'    => [
						'en_US' => 'The subscription of the calendar of the Events feature among the actions of the head (the page Termine).',
						'de_DE' => 'Das Abo des Kalenders des Features Termine unter den Aktionen des Kopfes (die Seite Termine).',
					],
				],
				'nav' => [
					'type'    => 'bool',
					'default' => true,
					'label'   => [ 'en_US' => 'Navigation', 'de_DE' => 'Navigation' ],
					'hint'    => [
						'en_US' => 'The bar of the application and the navigation of the portal before the head. Off for a template that sets [crm-portal-nav] itself.',
						'de_DE' => 'Die Leiste der Anwendung und die Navigation des Portals vor dem Kopf. Aus für eine Vorlage, die [crm-portal-nav] selbst setzt.',
					],
				],
			],
			'preview'    => 'block',
			'draws'      => 'div .crm-head',
			'in'         => [ 'section', 'card' ],
			'group'      => 'global',
		] );

		\Nino\Modules\Components::addComponent( $appData, 'crm-portal-door', [ Portal::class, 'componentCrmPortalDoor' ], [
			'label'      => [ 'en_US' => 'Door of the portal', 'de_DE' => 'Tor des Portals' ],
			'source'     => 'none',
			'loop'       => false,
			'attributes' => [],
			'preview'    => 'block',
			'draws'      => 'div .crm-door',
			'in'         => [ 'section', 'card' ],
			'group'      => 'global',
		] );

		\Nino\Modules\Components::addComponent( $appData, 'crm-portal-dates', [ Portal\Dates::class, 'componentCrmPortalDates' ], [
			'label'      => [ 'en_US' => 'Dates of the member', 'de_DE' => 'Termine des Mitglieds' ],
			'source'     => 'none',
			'loop'       => false,
			'attributes' => [],
			'preview'    => 'block',
			'draws'      => 'div .crm-dates',
			'in'         => [ 'section', 'card' ],
			'group'      => 'global',
		] );

		\Nino\Modules\Components::addComponent( $appData, 'crm-client', [ Portal::class, 'componentCrmClient' ], [
			'label'      => [ 'en_US' => 'Client of the member', 'de_DE' => 'Kunde des Mitglieds' ],
			'source'     => 'none',
			'loop'       => false,
			'attributes' => [],
			'preview'    => 'block',
			'draws'      => 'div .crm-client',
			'group'      => 'global',
		] );

		\Nino\Modules\Components::addComponent( $appData, 'crm-documents', [ Portal::class, 'componentCrmDocuments' ], [
			'label'      => [ 'en_US' => 'Documents of the member', 'de_DE' => 'Dokumente des Mitglieds' ],
			'source'     => 'none',
			'loop'       => false,
			'attributes' => [
				'project' => [
					'type'    => 'string',
					'default' => '',
					'label'   => [ 'en_US' => 'Project', 'de_DE' => 'Projekt' ],
					'hint'    => [
						'en_US' => 'The id of a project of the client: only the documents of that project, and no column for the project. Empty: all of them.',
						'de_DE' => 'Die Kennung eines Projekts des Kunden: nur die Belege dieses Projekts und keine Spalte für das Projekt. Leer: alle.',
					],
				],
			],
			'preview'    => 'block',
			'draws'      => 'div .crm-documents',
			'in'         => [ 'section', 'card' ],
			'icon'       => [
				[ 'path', [ 'd' => 'M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z' ] ],
				[ 'path', [ 'd' => 'M14 2v5a1 1 0 0 0 1 1h5' ] ],
				[ 'path', [ 'd' => 'M10 9H8' ] ],
				[ 'path', [ 'd' => 'M16 13H8' ] ],
				[ 'path', [ 'd' => 'M16 17H8' ] ],
			],
			'group'      => 'global',
		] );

		\Nino\Modules\Components::addComponent( $appData, 'crm-portal-nav', [ Portal::class, 'componentCrmPortalNav' ], [
			'label'      => [ 'en_US' => 'Navigation of the portal', 'de_DE' => 'Navigation des Portals' ],
			'source'     => 'none',
			'loop'       => false,
			'attributes' => [],
			'preview'    => 'block',
			'draws'      => 'nav .crm-nav',
			'in'         => [ 'section', 'card' ],
			'group'      => 'global',
		] );

		\Nino\Modules\Components::addComponent( $appData, 'crm-projects', [ Portal\Projects::class, 'componentCrmProjects' ], [
			'label'      => [ 'en_US' => 'Projects of the member', 'de_DE' => 'Projekte des Mitglieds' ],
			'source'     => 'none',
			'loop'       => false,
			'attributes' => [
				'open'  => [
					'type'    => 'bool',
					'default' => false,
					'label'   => [ 'en_US' => 'Only open projects', 'de_DE' => 'Nur laufende Projekte' ],
					'hint'    => [
						'en_US' => 'Leave out the projects that are done. The list is shown whatever the address says.',
						'de_DE' => 'Lässt die abgeschlossenen Projekte weg. Die Liste steht da, was auch immer die Adresse sagt.',
					],
				],
				'short' => [
					'type'    => 'bool',
					'default' => false,
					'label'   => [ 'en_US' => 'Short list', 'de_DE' => 'Kurze Liste' ],
					'hint'    => [
						'en_US' => 'At most five projects. The list is shown whatever the address says.',
						'de_DE' => 'Höchstens fünf Projekte. Die Liste steht da, was auch immer die Adresse sagt.',
					],
				],
			],
			'preview'    => 'block',
			'draws'      => 'div .crm-projects',
			'in'         => [ 'section', 'card' ],
			'group'      => 'global',
		] );

		\Nino\Modules\Components::addComponent( $appData, 'crm-uploads', [ Portal\Files::class, 'componentCrmUploads' ], [
			'label'      => [ 'en_US' => 'Uploads of the member', 'de_DE' => 'Uploads des Mitglieds' ],
			'source'     => 'none',
			'loop'       => false,
			'attributes' => [],
			'preview'    => 'block',
			'draws'      => 'div .crm-uploads',
			'in'         => [ 'section', 'card' ],
			'group'      => 'global',
		] );

		\Nino\Modules\Components::addComponent( $appData, 'crm-client-data', [ Portal\Data::class, 'componentCrmClientData' ], [
			'label'      => [ 'en_US' => 'Data of the client', 'de_DE' => 'Daten des Kunden' ],
			'source'     => 'none',
			'loop'       => false,
			'attributes' => [],
			'preview'    => 'block',
			'draws'      => 'div .crm-data',
			'in'         => [ 'section', 'card' ],
			'group'      => 'global',
		] );

		\Nino\Modules\Components::addComponent( $appData, 'crm-privacy', [ Portal\Privacy::class, 'componentCrmPrivacy' ], [
			'label'      => [ 'en_US' => 'Privacy policy of the client', 'de_DE' => 'Datenschutz des Kunden' ],
			'source'     => 'none',
			'loop'       => false,
			'attributes' => [],
			'preview'    => 'block',
			'draws'      => 'div .crm-privacy',
			'in'         => [ 'section', 'card' ],
			'group'      => 'global',
		] );

		\Nino\Modules\Components::addComponent( $appData, 'crm-portal-start', [ Portal\Start::class, 'componentCrmPortalStart' ], [
			'label'      => [ 'en_US' => 'What is pending for the client', 'de_DE' => 'Was für den Kunden ansteht' ],
			'source'     => 'none',
			'loop'       => false,
			'attributes' => [],
			'preview'    => 'block',
			'draws'      => 'div .crm-start',
			'in'         => [ 'section', 'card' ],
			'group'      => 'global',
		] );

		// The portal is drawn by a style sheet of the module and wired by a script of it, both in the bundles of the site
		\Nino\Html::addAsset( $appData, '/.cache/style.css', Portal::STYLE );
		\Nino\Html::addAsset( $appData, '/.cache/script.js', Portal::SCRIPT );

		\Nino\Callbacks::registerCallback( $appData, '/members/login', [ Portal::class, 'callbackSession' ] );
		\Nino\Callbacks::registerCallback( $appData, '/members/logout', [ Portal::class, 'callbackSession' ] );

		// A client that signs in without a page it asked for lands where something waits for it: the offer to order, else the project that was last active (see Portal::callbackStart())
		\Nino\Callbacks::registerCallback( $appData, '/members/start', [ Portal::class, 'callbackStart' ] );

		// A visitor sees no page of the portal in a menu of the site (the kernel fires the callback once it has built the lines of a menu)
		\Nino\Callbacks::registerCallback( $appData, '/nino/navigation/lines', [ Portal::class, 'callbackNavigationLines' ] );

		// The start page of the site is the door of the portal: a member who is signed in goes on to /portal
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://', [ Portal::class, 'callbackDoor' ] );

		// The gate of the client: a member whose client has not taken note of the Datenschutz or given the data of the invoice is sent to that step from every page of
		// the portal, and is given no file (see Portal::callbackPending(), Portal::callbackFileAllowed()); nor is a file of a project to be downloaded that the owner has not
		// switched on, or a file of a project while an offer of it waits for the order
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response', [ Portal::class, 'callbackPending' ] );
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response', [ Portal::class, 'callbackPrivacyWords' ], 2 );
		\Nino\Callbacks::registerCallback( $appData, '/files/allowed', [ Portal::class, 'callbackFileAllowed' ] );

		// The mail that tells the owner of a comment leads to the card of the project the file belongs to, and names the client and the project
		\Nino\Callbacks::registerCallback( $appData, '/review/owner-mail', [ \Crm\Surface\Review::class, 'callbackOwnerMail' ] );

		// A document follows the request for its signature: signed or declined by the member in Sign
		\Nino\Callbacks::registerCallback( $appData, '/sign/signed', [ Documents::class, 'callbackSigned' ] );
		\Nino\Callbacks::registerCallback( $appData, '/sign/declined', [ Documents::class, 'callbackDeclined' ] );

		// The portal as an app on the home screen: its manifest, its icon and the lines of the head (see Portal\App)
		Portal\App::init( $appData );

		// The PDF of a document is answered to the workbench session that may manage the documents and, once it is released, to the member of its client (see Render::callbackDownload())
		$appData['/nino/http/routes']['GET://.crm/pdf/*'] = [ 'uri' => '/.crm/pdf/*' ];
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://.crm/pdf/*', [ \Crm\Documents\Render::class, 'callbackDownload' ] );

		// The electronic invoice of a document is answered by the same rules: the workbench session that may manage the documents, the member of the client once it is released
		$appData['/nino/http/routes']['GET://.crm/xml/*'] = [ 'uri' => '/.crm/xml/*' ];
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://.crm/xml/*', [ \Crm\Documents\XRechnung::class, 'callbackDownload' ] );

		// The form of the page Meine Daten posts here: a member of a client changes the data of the client (see Portal\Data::callbackSave())
		$appData['/nino/http/routes']['POST://.crm/daten'] = [ 'uri' => Portal\Data::ROUTE ];
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://.crm/daten', [ Portal\Data::class, 'callbackSave' ] );

		// The block Anmeldeadresse of that page: a member of a client asks for another sign-in address and gives back the code that went to it (see Portal\Data::callbackLogin(),
		// callbackCode()); the owner is mailed when the member has changed it (callbackChanged(), told by Members)
		$appData['/nino/http/routes']['POST://.crm/anmeldeadresse'] = [ 'uri' => Portal\Data::ROUTE_LOGIN ];
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://.crm/anmeldeadresse', [ Portal\Data::class, 'callbackLogin' ] );
		$appData['/nino/http/routes']['POST://.crm/anmeldeadresse/code'] = [ 'uri' => Portal\Data::ROUTE_CODE ];
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://.crm/anmeldeadresse/code', [ Portal\Data::class, 'callbackCode' ] );
		$appData['/nino/http/routes']['POST://.crm/geraete/abmelden'] = [ 'uri' => Portal\Data::ROUTE_DEVICES ];
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://.crm/geraete/abmelden', [ Portal\Data::class, 'callbackDevices' ] );
		\Nino\Callbacks::registerCallback( $appData, '/members/changed', [ Portal\Data::class, 'callbackChanged' ] );

		// The Datenschutz and the order: the forms of the pages Datenschutz and of the offers post here, a member of a client agrees or orders (see Portal\Privacy::callbackAgree(), Portal\Order::callbackOrder())
		$appData['/nino/http/routes']['POST://.crm/datenschutz'] = [ 'uri' => Portal\Privacy::ROUTE ];
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://.crm/datenschutz', [ Portal\Privacy::class, 'callbackAgree' ] );
		$appData['/nino/http/routes']['POST://.crm/auftrag'] = [ 'uri' => Portal\Order::ROUTE ];
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://.crm/auftrag', [ Portal\Order::class, 'callbackOrder' ] );

		// The approval of a project: the form under its files posts here, a member of a client accepts the project as its files stand (see Portal\Approval::callbackApprove())
		$appData['/nino/http/routes']['POST://.crm/abnahme'] = [ 'uri' => Portal\Approval::ROUTE ];
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://.crm/abnahme', [ Portal\Approval::class, 'callbackApprove' ] );

		// The card of a message of the owner posts here: a member of a client puts the message away for itself (see Portal\Message::callbackDismiss())
		$appData['/nino/http/routes']['POST://.crm/nachricht/weg'] = [ 'uri' => Portal\Message::ROUTE ];
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://.crm/nachricht/weg', [ Portal\Message::class, 'callbackDismiss' ] );
		// The consents of a client: the button of the block Einwilligungen of the page Meine Daten posts here, a member of a client takes a consent back (see Portal\Consent::callbackRevoke())
		$appData['/nino/http/routes']['POST://.crm/einwilligung/widerruf'] = [ 'uri' => Portal\Consent::ROUTE ];
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://.crm/einwilligung/widerruf', [ Portal\Consent::class, 'callbackRevoke' ] );

		// The player of a file of a project, as the page of the project puts it beside the list: the script of the portal asks for it to open a file or another version without leaving the page (see Portal\Projects::callbackPlayer())
		$appData['/nino/http/routes']['GET://.crm/player'] = [ 'uri' => Portal\Projects::ROUTE ];
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://.crm/player', [ Portal\Projects::class, 'callbackPlayer' ] );

		// The projects: a project that a document names is not deleted, and its client is a client that is there and stays - the kernel's vetoes, so that
		// the Elements panel of the workbench refuses as the endpoint of the surface does
		\Nino\Callbacks::registerCallback( $appData, '/nino/elements/delete'. Projekte::TYPE, [ Projekte::class, 'callbackDelete' ] );
		\Nino\Callbacks::registerCallback( $appData, '/nino/elements'. Projekte::TYPE. '/insert', [ Projekte::class, 'callbackWrite' ] );
		\Nino\Callbacks::registerCallback( $appData, '/nino/elements'. Projekte::TYPE. '/update', [ Projekte::class, 'callbackWrite' ] );

		// ...and a project that is deleted takes the row of its state in the database with it
		\Nino\Callbacks::registerCallback( $appData, '/nino/elements/committed', [ Projekte::class, 'callbackCommitted' ] );

		// The surface: one page of the project, its endpoint, its assets and the preview of a draft
		\Crm\Surface::init( $appData );
	}

	/**
	 *	Whether a feature is installed, active and without problems - the one answer the panel and the portal ask
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$key					The feature's key
	 *
	 *	@return 	bool
	 */
	public static function featureOn( array &$appData, string $key ): bool {

		$feature = \Nino\Features::get( $appData, $key );

		return $feature !== null && $feature['active'] === true && $feature['problems'] === [];
	}

	/**
	 *	Whether the account may have a link shown instead of mailed: it holds the permission of the feature that makes the link (members or sign)
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$feature			members or sign
	 *
	 *	@return 	bool
	 */
	public static function mayLink( array &$appData, string $feature ): bool {

		return \Nino\Auth::checkPermission( $appData, $feature === 'sign' ? self::LINK_PERM_SIGN : self::LINK_PERM_MEMBERS ) === true;
	}

	/**
	 *	@param		array 		&$appData			(reference) Array with current app data
	 *
	 *	@return 	array										Panel classes
	 */
	public static function adminPanels( array &$appData ): array {

		return [ \Crm\Crm\Admin::class ];
	}
}
