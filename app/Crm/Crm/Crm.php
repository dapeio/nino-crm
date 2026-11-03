<?php
declare(strict_types=1);
/**
 *	Crm\Crm		The project module of the CRM. Listed in /nino/modules by the owner (one line in private/config.php);
 *						init() registers behaviour and writes nothing: the panels Kunden, Belege and Positionen (adminPanels()), the
 *						component [crm-client], the callbacks that keep the portal's lookup true when a member signs in or out, and the route /.crm/pdf/<id> that answers the PDF of a finalised document. The database
 *						is opened on first use, the portal pages come with the install unit the panel applies.
 */
namespace Crm;

class Crm {

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

		\Nino\Callbacks::registerCallback( $appData, '/members/login', [ Portal::class, 'callbackSession' ] );
		\Nino\Callbacks::registerCallback( $appData, '/members/logout', [ Portal::class, 'callbackSession' ] );

		// The PDF of a document is answered to the workbench session that may manage the documents (see Render::callbackDownload())
		$appData['/nino/http/routes']['GET://.crm/pdf/*'] = [ 'uri' => '/.crm/pdf/*' ];
		\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://.crm/pdf/*', [ \Crm\Documents\Render::class, 'callbackDownload' ] );
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

		return [ \Crm\Clients\Admin::class, \Crm\Documents\Admin::class, \Crm\Items\Admin::class ];
	}
}
