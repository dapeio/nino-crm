<?php
declare(strict_types=1);
/**
 *	Crm\Crm		The project module of the CRM. Listed in /nino/modules by the owner (one line in private/config.php);
 *						init() registers behaviour and writes nothing: the panels Kunden and Belege (adminPanels()), the
 *						components [crm-client] and [crm-documents], the callbacks that keep the portal's lookup true when a member signs in or
 *						out and the ones that let a document follow its request in Sign, the style sheet of the portal's list, and the route
 *						/.crm/pdf/<id> that answers the PDF of a finalised document; and the surface at /crm, one page of the project with its
 *						endpoint and assets (Surface::init()). The database is opened on first use, the portal pages come with the install unit
 *						the panel applies.
 */
namespace Crm;

class Crm {

	// The one permission of the CRM: the surface at /crm, and the panels that remain in the workbench
	public const string MANAGE_PERM = '/_admin/crm/manage';

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
			'attributes' => [],
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

		// The list of the portal is drawn by a style sheet of the module, in the bundle of the site
		\Nino\Html::addAsset( $appData, '/.cache/style.css', Portal::STYLE );

		\Nino\Callbacks::registerCallback( $appData, '/members/login', [ Portal::class, 'callbackSession' ] );
		\Nino\Callbacks::registerCallback( $appData, '/members/logout', [ Portal::class, 'callbackSession' ] );

		// A document follows the request for its signature: signed or declined by the member in Sign
		\Nino\Callbacks::registerCallback( $appData, '/sign/signed', [ Documents::class, 'callbackSigned' ] );
		\Nino\Callbacks::registerCallback( $appData, '/sign/declined', [ Documents::class, 'callbackDeclined' ] );

		// The PDF of a document is answered to the workbench session that may manage the documents and, once it is released, to the member of its client (see Render::callbackDownload())
		$appData['/nino/http/routes']['GET://.crm/pdf/*'] = [ 'uri' => '/.crm/pdf/*' ];
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://.crm/pdf/*', [ \Crm\Documents\Render::class, 'callbackDownload' ] );

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

		return [ \Crm\Clients\Admin::class, \Crm\Documents\Admin::class ];
	}
}
