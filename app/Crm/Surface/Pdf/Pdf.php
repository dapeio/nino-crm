<?php
declare(strict_types=1);
/**
 *	Crm\Surface\Pdf		What the screens PDF-Vorlagen of the surface take from the Pdf feature and from the CRM: the actions of the feature's panel
 *										(pdf/templates/..., pdf/blocks/..., pdf/layout/..., pdf/preview) go through the endpoint as the panel has them, under the
 *										permission of the panel, and the sentences of their codes are the panel's own. Two things only the CRM knows are added:
 *
 *										crm-pdf/context			for each template how many documents name it and the day it was written, for each block of
 *																				the library the names of the templates that use it, and the keys of the site's texts a template may place
 *																				([[/project/company/...]])
 *										GET /crm/pdf-preview/...	the sample PDFs of a template and of the layout (Surface::callbackPdfPreview())
 *										pdf/templates/delete		the panel's, but a template that a document names is not deleted: 409
 *																				crm_pdf_template_used (the feature does not know the documents)
 *
 *										The feature is used as it is; where it is not there, the surface has no screens for it.
 */
namespace Crm\Surface;

use Crm\Db;

class Pdf {

	// The permission of the feature's panel: the one the actions of pdf/* ask for
	public const string PERM = '/_admin/pdf/manage';

	// The actions that take a file with them, as a multipart post: the picture of the layout, and nothing else
	public const array FILE_ACTIONS = [ 'pdf/layout/image' ];

	// The prefixes of the site's texts a template may place: the company of the site (the words of the wizard's company step)
	private const string SITE_KEY = '~^/project/company/[a-z0-9_-]+/[a-z0-9_-]+$~D';

	/**
	 *	Whether the feature's panel is there to be asked
	 *
	 *	@return 	bool
	 */
	public static function available(): bool {

		return class_exists( '\\Nino\\Modules\\Pdf\\Admin' );
	}

	/**
	 *	The actions of the screens: the panel's, with the delete of a template as the surface has it, and the context
	 *
	 *	@return 	array											action => [ class, method ]
	 */
	public static function actions(): array {

		if( self::available() === false )
			return [];

		$actions = [];

		foreach( \Nino\Modules\Pdf\Admin::actions() as $action => $target )
			$actions[$action] = $action === 'pdf/templates/delete' ? [ self::class, 'apiTemplateDelete' ] : $target;

		return $actions + [ 'crm-pdf/context' => [ self::class, 'apiContext' ] ];
	}

	/**
	 *	Whether an action is one of these screens' (and so needs the feature to be on)
	 *
	 *	@param		string		$action
	 *
	 *	@return 	bool
	 */
	public static function owns( string $action ): bool {

		return str_starts_with( $action, 'pdf/' ) === true || str_starts_with( $action, 'crm-pdf/' ) === true;
	}

	/**
	 *	Whether the account holds the permission of the Pdf panel (whether the feature is there is available()'s)
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	bool
	 */
	public static function allowed( array &$appData ): bool {

		return \Nino\Auth::checkPermission( $appData, self::PERM ) === true;
	}

	/**
	 *	The file with the sentences of the feature's panel
	 *
	 *	@return 	string										'' where the feature is not there
	 */
	public static function textFile(): string {

		if( self::available() === false )
			return '';

		$file = (string) ( new \ReflectionClass( '\\Nino\\Modules\\Pdf\\Admin' ) )->getFileName();
		$texts = dirname( $file, 2 ). '/text/de_DE.php';

		return is_file( $texts ) === true ? $texts : '';
	}

	/**
	 *	The line of the activity log: the panel's own for its actions, none for a read of the context
	 *
	 *	@param		string		$action
	 *	@param		array 		$data
	 *
	 *	@return 	string
	 */
	public static function log( string $action, array $data ): string {

		return self::available() === true && $action !== 'crm-pdf/context' ? \Nino\Modules\Pdf\Admin::log( $action, $data ) : '';
	}

	/**
	 *	The sample PDF of a preview: what the panel's apiPreview() renders for a saved template and apiLayoutPreview() for the layout, by the same
	 *	call of the feature
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$kind					template/<key> or layout
	 *
	 *	@return 	array|null								bytes and file; null for a kind or a key that is none
	 */
	public static function sample( array &$appData, string $kind ): ?array {

		// The sample looks like a document: the invoice particulars of the settings are in it, as the documents hand them
		$data = [ 'tokens' => self::particulars( $appData ) ];

		if( $kind === 'layout' )
			return [ 'bytes' => \Nino\Modules\Pdf::compose( $appData, [ 'name' => 'Design', 'tag' => '', 'blocks' => [] ], $data, [ 'title' => 'Design' ], true ), 'file' => 'design.pdf' ];

		if( preg_match( '~^template/([a-z][a-z0-9-]{1,39})$~D', $kind, $found ) !== 1 )
			return null;

		$template = \Nino\Modules\Pdf::template( $appData, $found[1] );

		return $template === null ? null : [ 'bytes' => \Nino\Modules\Pdf::compose( $appData, $template, $data, [ 'title' => $template['name'] ], true ), 'file' => 'preview.pdf' ];
	}

	/**
	 *	The invoice particulars as the tokens a document hands (see Render::data())
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											/site/tax, /site/bank, /site/iban, /site/bic
	 */
	public static function particulars( array &$appData ): array {

		$all = \Crm\Settings::all( $appData );

		return [ '/site/tax' => $all['tax_id'], '/site/bank' => $all['bank_name'], '/site/iban' => $all['iban'], '/site/bic' => $all['bic'] ];
	}

	/**
	 *	How many documents name each template, by its key
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											key => count, for the keys that a document names
	 */
	public static function used( array &$appData ): array {

		$used = [];

		foreach( Db::rows( $appData, 'SELECT template_key, COUNT(*) AS n FROM documents WHERE template_key <> :none GROUP BY template_key', [ 'none' => '' ] ) as $row )
			$used[(string) $row['template_key']] = (int) $row['n'];

		return $used;
	}

	/**
	 *	What the screens show beside the feature's own lists
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function apiContext( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request ): void {

			$changed = [];
			$blocks = [];

			foreach( \Nino\Modules\Pdf::keys( $appData ) as $key ) {

				$time = @filemtime( \Nino\Filesystem::path( $appData, \Nino\Modules\Pdf::TEMPLATES. '/'. $key. '.php' ) );
				$changed[$key] = $time === false ? '' : gmdate( 'Y-m-d', $time );

				$template = \Nino\Modules\Pdf::template( $appData, $key );

				foreach( $template['blocks'] ?? [] as $block )
					if( isset( $block['block'] ) === true && in_array( $template['name'], $blocks[$block['block']] ?? [], true ) === false )
						$blocks[$block['block']][] = $template['name'];
			}

			$site = [];

			foreach( array_keys( \Nino\Html::getFills( $appData ) ) as $fill )
				if( preg_match( '~^\[\[(/[^\]]+)\]\]$~D', (string) $fill, $found ) === 1 && preg_match( self::SITE_KEY, $found[1] ) === 1 )
					$site[] = $found[1];

			sort( $site );

			\Nino\Http::ok( $request, [ 'used' => self::used( $appData ), 'changed' => $changed, 'blocks' => $blocks, 'site' => $site ] );
		} );
	}

	/**
	 *	Delete a template, unless a document names it
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function apiTemplateDelete( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::PERM ) === false )
			return;

		$key = \Nino\Admin\Admin::postString( \Nino\Admin\Admin::postData(), 'key' );
		$count = null;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, $key, &$count ): void {
			$count = self::used( $appData )[$key] ?? 0;
		} );

		// The database failed, and the answer says so
		if( $count === null )
			return;

		if( $count > 0 ) {
			\Nino\Http::fail( $request, 409, 'documents name this template', 'crm_pdf_template_used', [ $count ] );
			return;
		}

		\Nino\Modules\Pdf\Admin::apiTemplatesDelete( $appData, $request );
	}
}
