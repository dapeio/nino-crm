<?php
declare(strict_types=1);
/**
 *	Crm\Crm		The project module of the CRM. Listed in /nino/modules by the setup wizard, which finds its install unit (install/);
 *						init() registers behaviour and writes nothing: the panel CRM (adminPanels()), the
 *						components [crm-client], [crm-documents], [crm-portal-nav], [crm-projects], [crm-uploads] and [crm-client-data], the callbacks that keep the portal's lookup true when a member signs in or
 *						out and the ones that let a document follow its request in Sign, the style sheet of the portal, the route
 *						/.crm/pdf/<id> that answers the PDF of a finalised document and the route POST /.crm/daten of the page Meine Daten; and the surface at /crm, one page of the project with its
 *						endpoint and assets (Surface::init()). The database is opened on first use, the portal pages come with the install unit
 *						the wizard applies, and the screen Einrichtung of the surface completes the product (see Admin::apiSetup()).
 */
namespace Crm;

class Crm {

	// The one permission of the CRM: the surface at /crm, the panel CRM of the workbench and the API behind both
	public const string MANAGE_PERM = '/_admin/crm/manage';

	// The body of the route GET:// once the site's front is the portal's door (written by the Einrichtung, answered by Portal::callbackDoor())
	public const string DOOR = '[template /templates/page-door]';

	/**
	 *	@param		array 		&$appData			(reference) Array with current app data
	 *
	 *	@return 	void
	 */
	public static function init( array &$appData ): void {

		\Nino\Modules\Components::addComponent( $appData, 'crm-client', [ Portal::class, 'componentCrmClient' ], [
			'label'      => [ 'en_US' => 'Client of the member', 'de_DE' => 'Kunde des Mitglieds' ],
			'source'     => 'none',
			'loop'       => false,
			'attributes' => [],
			'preview'    => 'text',
			'draws'      => 'span .crm-client',
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
			'draws'      => 'form .crm-data',
			'in'         => [ 'section', 'card' ],
			'group'      => 'global',
		] );

		// The list of the portal is drawn by a style sheet of the module, in the bundle of the site
		\Nino\Html::addAsset( $appData, '/.cache/style.css', Portal::STYLE );

		\Nino\Callbacks::registerCallback( $appData, '/members/login', [ Portal::class, 'callbackSession' ] );
		\Nino\Callbacks::registerCallback( $appData, '/members/logout', [ Portal::class, 'callbackSession' ] );

		// The start page of the site is the door of the portal: a member who is signed in goes on to /portal
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://', [ Portal::class, 'callbackDoor' ] );

		// A document follows the request for its signature: signed or declined by the member in Sign
		\Nino\Callbacks::registerCallback( $appData, '/sign/signed', [ Documents::class, 'callbackSigned' ] );
		\Nino\Callbacks::registerCallback( $appData, '/sign/declined', [ Documents::class, 'callbackDeclined' ] );

		// The PDF of a document is answered to the workbench session that may manage the documents and, once it is released, to the member of its client (see Render::callbackDownload())
		$appData['/nino/http/routes']['GET://.crm/pdf/*'] = [ 'uri' => '/.crm/pdf/*' ];
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://.crm/pdf/*', [ \Crm\Documents\Render::class, 'callbackDownload' ] );

		// The form of the page Meine Daten posts here: a member of a client changes the data of the client (see Portal\Data::callbackSave())
		$appData['/nino/http/routes']['POST://.crm/daten'] = [ 'uri' => Portal\Data::ROUTE ];
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://.crm/daten', [ Portal\Data::class, 'callbackSave' ] );

		// The projects: a project that a document names is not deleted, and its client is a client that is there and stays - the kernel's vetoes, so that
		// the Elements panel of the workbench refuses as the endpoint of the surface does
		\Nino\Callbacks::registerCallback( $appData, '/nino/elements/delete'. Projekte::TYPE, [ Projekte::class, 'callbackDelete' ] );
		\Nino\Callbacks::registerCallback( $appData, '/nino/elements'. Projekte::TYPE. '/insert', [ Projekte::class, 'callbackWrite' ] );
		\Nino\Callbacks::registerCallback( $appData, '/nino/elements'. Projekte::TYPE. '/update', [ Projekte::class, 'callbackWrite' ] );

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
	 *	@param		array 		&$appData			(reference) Array with current app data
	 *
	 *	@return 	array										Panel classes
	 */
	public static function adminPanels( array &$appData ): array {

		return [ \Crm\Crm\Admin::class ];
	}
}
