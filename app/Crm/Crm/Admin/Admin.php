<?php
declare(strict_types=1);
/**
 *	Crm\Crm\Admin		The one panel of the CRM in the workbench: a link to the surface at /crm, the status of the module - what it
 *									misses - and the button that applies the install unit, the portal pages. The work with clients and documents is
 *									the surface's; the panels Kunden and Belege are gone, their classes stay as the API the surface's endpoint
 *									reaches. The panel validates nothing it is posted: both actions take no input.
 */
namespace Crm\Crm;

class Admin {

	// The features the portal draws its pages with, in the order the status names them
	private const array FEATURES = [ 'members', 'files', 'review', 'sign', 'pdf' ];

	public static function perm(): string {
		return \Crm\Crm::MANAGE_PERM;
	}

	public static function actions(): array {
		return [
			'crm/status' => [ self::class, 'apiStatus' ],
			'crm/setup'  => [ self::class, 'apiSetup' ],
		];
	}

	public static function manual(): array {
		return [ 'intro' ];
	}

	public static function nav(): array {
		return [ 'crm', '/_admin/crm/nav', 30, 'content' ];
	}

	public static function icon(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
	}

	// The panel draws into the one region of templates/panel.tpl
	public static function template(): string {
		return \Nino\Admin\Panels::relative( __DIR__. '/templates/panel' );
	}

	public static function assets(): array {
		return [ \Nino\Admin\Panels::relative( __DIR__. '/assets/admin.js' ), \Nino\Admin\Panels::relative( __DIR__. '/assets/admin.css' ) ];
	}

	public static function text(): string {
		return \Nino\Admin\Panels::relative( __DIR__. '/text' );
	}

	/**
	 *	The activity-log line of a completed action
	 *
	 *	@param		string		$action
	 *	@param		array 		$data
	 *
	 *	@return 	string
	 */
	public static function log( string $action, array $data ): string {

		return $action === 'crm/setup' ? 'Applied the portal unit' : '';
	}

	/**
	 *	Apply the install unit of the module - the portal pages: routes, templates and words, each only where the
	 *	project has none, and /portal among the protected paths of Members, and as its start page, where the project has none of its own.
	 *	Answers what was added and what was there
	 */
	public static function apiSetup( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, \Crm\Crm::MANAGE_PERM ) === false )
			return;

		if( \Crm\Crm::featureOn( $appData, 'members' ) === false ) {
			\Nino\Http::fail( $request, 409, 'the Members feature is not active', 'crm_setup_members' );
			return;
		}

		$unit = self::unitDir();
		$manifest = \Nino\Features::readUnitManifest( $unit ) ?? [];
		$native = (string) $appData['/nino/locales/native'];

		$routes = \Nino\AppData::stored( $appData, '/nino/http/routes', \Nino\AppData::DEFAULTS['/nino/http/routes'] );
		$routes = is_array( $routes ) ? $routes : \Nino\AppData::DEFAULTS['/nino/http/routes'];
		$before = $routes;
		$blacklist = [];
		$config = [];

		$templates = array_values( (array) ( $manifest['templates'] ?? [] ) );
		$hadTemplates = array_values( array_filter( $templates, static fn( string $file ): bool => \Nino\Filesystem::fileExists( $appData, '/templates/'. $file ) ) );
		$types = array_values( (array) ( $manifest['elementTypes'] ?? [] ) );
		$hadTypes = array_values( array_filter( $types, static fn( string $file ): bool => \Nino\Filesystem::fileExists( $appData, '/elements/'. $file ) ) );
		$textsBefore = count( (array) \Nino\Filesystem::getFileContent( $appData, '/text/'. $native. '.php', [] ) );

		$applied = \Nino\Features::applyUnit( $appData, $unit, \Nino\Locales::getAvailableLocales( $appData ), $routes, $blacklist, $config, false );

		if( $applied !== true ) {
			\Nino\Http::fail( $request, 500, 'the portal unit could not be applied: '. $applied );
			return;
		}

		// The Elements panel's own words for the type are the workbench's: the Text panel does not offer them
		if( $blacklist !== [] && \Nino\Filesystem::mutate( $appData, '/text/blacklist.php', static fn( array $list ): array => array_values( array_unique( array_merge( $list, $blacklist ) ) ) ) === false ) {
			\Nino\Http::fail( $request, 500, 'the blacklist of the text keys could not be written' );
			return;
		}

		$addedRoutes = [];

		foreach( $routes as $key => $route )
			if( ( $before[$key] ?? null ) !== $route )
				$addedRoutes[$key] = $route;

		// The persisted routes get the new ones and nothing else; the live ones keep what this request booted with
		if( $addedRoutes !== [] ) {

			$live = (array) ( $appData['/nino/http/routes'] ?? [] );

			$written = \Nino\Filesystem::mutate( $appData, '/config.php', static function( array $content ) use ( $addedRoutes ): array {

				$stored = is_array( $content['/nino/http/routes'] ?? null ) ? $content['/nino/http/routes'] : \Nino\AppData::DEFAULTS['/nino/http/routes'];

				foreach( $addedRoutes as $key => $route )
					if( isset( $stored[$key] ) === false )
						$stored[$key] = $route;

				$content['/nino/http/routes'] = $stored;

				return $content;
			} );

			if( $written === false ) {
				\Nino\Http::fail( $request, 500, 'the routes could not be written' );
				return;
			}

			$appData['/nino/http/routes'] = $live + $routes;
		}

		// A unit's config cannot write the setting of a feature, so the paths and the start page are set here - each only where
		// the project has nothing yet: no protected path, and the start page the site's root (the setting's own default)
		$posted = [];

		if( array_filter( (array) \Nino\Features::setting( $appData, 'members', 'paths', [] ), 'is_string' ) === [] )
			$posted['paths'] = [ '/portal' ];

		if( in_array( \Nino\Features::setting( $appData, 'members', 'start', '/' ), [ '', '/' ], true ) === true )
			$posted['start'] = '/portal';

		if( $posted !== [] && \Nino\Features::saveSettings( $appData, 'members', $posted ) !== [] ) {
			\Nino\Http::fail( $request, 500, 'the settings of Members could not be written' );
			return;
		}

		$paths = isset( $posted['paths'] );
		$start = isset( $posted['start'] );

		\Nino\Http::ok( $request, [
			'added' => [
				'routes'    => array_keys( $addedRoutes ),
				'templates' => array_values( array_diff( $templates, $hadTemplates ) ),
				'types'     => array_values( array_diff( $types, $hadTypes ) ),
				'texts'     => count( (array) \Nino\Filesystem::getFileContent( $appData, '/text/'. $native. '.php', [] ) ) - $textsBefore,
				'paths'     => $paths,
				'start'     => $start,
			],
			'existed' => [
				'routes'    => array_values( array_diff( array_keys( (array) ( $manifest['routes'] ?? [] ) ), array_keys( $addedRoutes ) ) ),
				'templates' => $hadTemplates,
				'types'     => $hadTypes,
				'paths'     => $paths === false,
				'start'     => $start === false,
			],
		] );
	}

	/**
	 *	What the module misses: each item has a key and a state
	 *	  module   ok, off (the class is not in /nino/modules)
	 *	  data     ok, unwritable (the data directory)
	 *	  members, files, review, sign, pdf     ok, off (installed, not active), missing (not installed), problem
	 *	  portal   ok (routes, templates, the element types and the protected path are there), partial, none - and what it lacks: lacks lists routes, templates, types and paths
	 */
	public static function apiStatus( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, \Crm\Crm::MANAGE_PERM ) === false )
			return;

		$items = [];

		$modules = array_map( static fn( mixed $class ): string => ltrim( (string) $class, '\\' ), (array) ( $appData['/nino/modules'] ?? [] ) );
		$items[] = [ 'key' => 'module', 'state' => in_array( \Crm\Crm::class, $modules, true ) === true ? 'ok' : 'off' ];

		// A read answers a read: the directory is looked at and not made - one that is not there yet is made by the first
		// connection, so what has to be writable is the directory above it
		$data = \Nino\Filesystem::path( $appData, '/data' );
		$items[] = [ 'key' => 'data', 'state' => is_writable( is_dir( $data ) === true ? $data : dirname( $data ) ) === true ? 'ok' : 'unwritable' ];

		foreach( self::FEATURES as $key ) {

			$feature = \Nino\Features::get( $appData, $key );

			$items[] = [ 'key' => $key, 'state' => match( true ) {
				$feature === null                 => 'missing',
				$feature['problems'] !== []       => 'problem',
				$feature['active'] === false      => 'off',
				default                           => 'ok',
			} ];
		}

		$manifest = \Nino\Features::readUnitManifest( self::unitDir() ) ?? [];
		$routes = \Nino\AppData::stored( $appData, '/nino/http/routes', [] );
		$routes = is_array( $routes ) ? $routes : [];
		$wanted = array_keys( (array) ( $manifest['routes'] ?? [] ) );
		$kinds = [
			'routes'    => [ count( array_filter( $wanted, static fn( string $key ): bool => isset( $routes[$key] ) === true ) ), count( $wanted ) ],
			'templates' => [ count( array_filter( (array) ( $manifest['templates'] ?? [] ), static fn( string $file ): bool => \Nino\Filesystem::fileExists( $appData, '/templates/'. $file ) ) ), count( (array) ( $manifest['templates'] ?? [] ) ) ],
			'types'     => [ count( array_filter( (array) ( $manifest['elementTypes'] ?? [] ), static fn( string $file ): bool => \Nino\Filesystem::fileExists( $appData, '/elements/'. $file ) ) ), count( (array) ( $manifest['elementTypes'] ?? [] ) ) ],
			'paths'     => [ in_array( '/portal', (array) \Nino\Features::setting( $appData, 'members', 'paths', [] ), true ) === true ? 1 : 0, 1 ],
		];
		$present = array_sum( array_column( $kinds, 0 ) );
		$total = array_sum( array_column( $kinds, 1 ) );
		$lacks = array_keys( array_filter( $kinds, static fn( array $count ): bool => $count[0] < $count[1] ) );

		$items[] = [ 'key' => 'portal', 'state' => $present === $total ? 'ok' : ( $present === 0 ? 'none' : 'partial' ), 'lacks' => $lacks ];

		\Nino\Http::ok( $request, [ 'items' => $items ] );
	}

	/**
	 *	The directory of the install unit of the module
	 *
	 *	@return 	string
	 */
	public static function unitDir(): string {

		return dirname( __DIR__, 2 ). '/unit';
	}

}
