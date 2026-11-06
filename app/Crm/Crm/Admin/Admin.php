<?php
declare(strict_types=1);
/**
 *	Crm\Crm\Admin		The one panel of the CRM in the workbench: a link to the surface at /crm, the status of the module - what it
 *									misses - and the button that makes the product whole (the features, the portal pages, the PDF templates, the start page). The work with clients and documents is
 *									the surface's; the panels Kunden and Belege are gone, their classes stay as the API the surface's endpoint
 *									reaches. The panel validates nothing it is posted: both actions take no input. The two actions are the
 *									surface's screen Einrichtung as well: crm/status is what it checks, crm/setup is its one button.
 */
namespace Crm\Crm;

class Admin {

	// The features the product is made of, in the order the status names them, and their labels, in the language of the workbench session, are named in the sentence of a refusal
	private const array FEATURES = [ 'members', 'files', 'review', 'sign', 'pdf', 'events' ];

	// The route of the site's start page, and the directory of the templates of the Pdf feature (Pdf::TEMPLATES; the feature may be off or absent)
	private const array LABELS = [
		'members' => [ 'de_DE' => 'Mitglieder', 'en_US' => 'Members' ], 'files' => [ 'de_DE' => 'Dateien', 'en_US' => 'Files' ], 'review' => [ 'de_DE' => 'Review', 'en_US' => 'Review' ],
		'sign' => [ 'de_DE' => 'Unterschrift', 'en_US' => 'Sign' ], 'pdf' => [ 'de_DE' => 'PDF', 'en_US' => 'PDF' ], 'events' => [ 'de_DE' => 'Termine', 'en_US' => 'Events' ],
	];

	private const string HOME = 'GET://';

	// The body of the start page the wizard proposes: the one page the setup may replace; a start page of any other body is the owner's
	private const string STARTER = '[template /templates/page-home]';
	private const string PDF_TEMPLATES = '/data/pdf/templates';

	// The cap of mails an hour and address the kernel keeps (a key of config.php, a field of the Config panel; the kernel's own default of 5 is for the forms of a public site)
	// and the one a portal is set to where config.php has none. The kernel counts the calls of one caller, not the recipients; a portal has few callers that
	// trigger mail often - the owner and the members - so the 5 of a public site's forms is too tight for it
	private const string CAP_KEY = '/nino/mail/cap';
	public const int CAP = 100;

	// The setting of Review that mails the owner when a customer comments: the owner of a portal wants to know, so the setup switches it on where the setting has no value of its own
	private const string NOTIFY_SETTING = 'notifyOwner';

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

		return $action === 'crm/setup' ? 'Set the CRM up' : '';
	}

	/**
	 *	Make the product whole - the one button of the screen Einrichtung. Idempotent and add-only, in this order, and a step that fails
	 *	stops the ones after it (what was done stays, a second press goes on from there):
	 *	  1. the six features are switched on (Features::activate(), each answer checked; the one that fails names itself),
	 *	  2. Members' settings: /portal among the protected paths and as the start page, each where the project has none of its own,
	 *	  3. the install unit: the portal pages (routes, templates, words), the element type, the blacklist - what the project has stays,
	 *	  4. the PDF: the templates, the text blocks and the header and footer of the layout, through Pdf::install() - what the owner has stays,
	 *	  5. the start page: the route GET:// becomes the door of the portal where there is none or the wizard's page /home; the door that is there and
	 *	     a start page of the owner's stay,
	 *	  6. the cap of mails: '/nino/mail/cap' of config.php is set to CAP where config.php carries no value of its own, the way the Config panel writes a key
	 *	     (the key in the request's data, then AppData::writeContentData()); a value that is there - the owner's own, 0 for no cap among them - stays,
	 *	  7. the notification of the owner: the setting notifyOwner of Review is switched on (Features::saveSettings()) where the setting has no value of its own; a value that
	 *	     is there - off among them - stays.
	 *	Answers what was added and what existed
	 */
	public static function apiSetup( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, \Crm\Crm::MANAGE_PERM ) === false )
			return;

		$added = [ 'features' => [], 'routes' => [], 'templates' => [], 'types' => [], 'texts' => 0, 'paths' => false, 'start' => false, 'pdf' => [ 'blocks' => [], 'templates' => [], 'layout' => [] ], 'home' => false, 'cap' => false, 'notify' => false ];
		$existed = [ 'features' => [], 'routes' => [], 'templates' => [], 'types' => [], 'paths' => false, 'start' => false, 'pdf' => [ 'blocks' => [], 'templates' => [], 'layout' => [] ], 'home' => false, 'cap' => false, 'notify' => false ];

		// 1. The features. One is on when it is active at its version and nothing is wrong with it; what is on before the first step is "there
		// already", even where a feature that was switched on before it - Sign takes PDF with it - did the work
		$on = static function( string $key ) use ( &$appData ): bool {

			$have = \Nino\Features::get( $appData, $key );

			return $have !== null && $have['active'] === true && $have['problems'] === [] && $have['installed'] === $have['version'];
		};
		$was = array_filter( self::FEATURES, static fn( string $key ): bool => $on( $key ) === true );

		foreach( self::FEATURES as $key ) {

			if( in_array( $key, $was, true ) === true ) {
				$existed['features'][] = $key;
				continue;
			}

			$done = \Nino\Features::get( $appData, $key ) === null ? 'it is not installed' : ( $on( $key ) === true ? true : \Nino\Features::activate( $appData, $key ) );

			if( $done !== true ) {
				\Nino\Http::fail( $request, 409, 'the feature "'. $key. '" could not be switched on: '. $done, 'crm_setup_feature', [ self::label( $appData, $key ) ] );
				return;
			}

			$added['features'][] = $key;
		}

		$unit = self::unitDir();
		$manifest = \Nino\Features::readUnitManifest( $unit ) ?? [];
		$native = (string) $appData['/nino/locales/native'];

		// 2. The settings of Members - the unit's config cannot write the setting of a feature, so the paths and the start page are set here,
		// each only where the project has nothing yet: no protected path, and the start page the site's root (the setting's own default)
		$posted = [];

		if( array_filter( (array) \Nino\Features::setting( $appData, 'members', 'paths', [] ), 'is_string' ) === [] )
			$posted['paths'] = [ '/portal' ];

		if( in_array( \Nino\Features::setting( $appData, 'members', 'start', '/' ), [ '', '/' ], true ) === true )
			$posted['start'] = '/portal';

		if( $posted !== [] && \Nino\Features::saveSettings( $appData, 'members', $posted ) !== [] ) {
			\Nino\Http::fail( $request, 500, 'the settings of Members could not be written' );
			return;
		}

		$added['paths'] = isset( $posted['paths'] );
		$added['start'] = isset( $posted['start'] );
		$existed['paths'] = $added['paths'] === false;
		$existed['start'] = $added['start'] === false;

		// 3. The install unit
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

		$added['routes'] = array_keys( $addedRoutes );
		$added['templates'] = array_values( array_diff( $templates, $hadTemplates ) );
		$added['types'] = array_values( array_diff( $types, $hadTypes ) );
		$added['texts'] = count( (array) \Nino\Filesystem::getFileContent( $appData, '/text/'. $native. '.php', [] ) ) - $textsBefore;
		$existed['routes'] = array_values( array_diff( array_keys( (array) ( $manifest['routes'] ?? [] ) ), array_keys( $addedRoutes ) ) );
		$existed['templates'] = $hadTemplates;
		$existed['types'] = $hadTypes;

		// 4. The PDF
		$seed = self::seed();
		$pdf = \Nino\Modules\Pdf::install( $appData, $seed['templates'], $seed['blocks'], $seed['layout'], false );
		$refused = [];

		foreach( $pdf['refused'] as $kind => $codes )
			foreach( $codes as $key => $code )
				$refused[] = $kind. ' '. $key. ' ('. $code. ')';

		if( $refused !== [] ) {
			\Nino\Http::fail( $request, 500, 'the PDF could not be seeded: '. implode( ', ', $refused ), 'crm_setup_pdf', [ implode( ', ', $refused ) ] );
			return;
		}

		$added['pdf'] = $pdf['added'];
		$existed['pdf'] = $pdf['existing'];

		// 5. The start page: the door of the portal. Written where there is none and where the wizard's own page is (/home, which keeps its template
		// file and no route); a start page the owner made is kept, and a door that is there stays
		$stored = \Nino\AppData::stored( $appData, '/nino/http/routes', \Nino\AppData::DEFAULTS['/nino/http/routes'] );
		$stored = is_array( $stored ) ? $stored : [];
		$home = self::homeState( $stored );

		if( $home === 'ok' )
			$existed['home'] = 'door';
		else if( $home === 'own' )
			$existed['home'] = 'own';
		else {

			$written = \Nino\Filesystem::mutate( $appData, '/config.php', static function( array $content ): array {

				$content['/nino/http/routes'] = array_merge( is_array( $content['/nino/http/routes'] ?? null ) ? $content['/nino/http/routes'] : \Nino\AppData::DEFAULTS['/nino/http/routes'], [ self::HOME => [ 'uri' => '/', 'body' => \Crm\Crm::DOOR ] ] );

				return $content;
			} );

			if( $written === false ) {
				\Nino\Http::fail( $request, 500, 'the start page could not be written' );
				return;
			}

			$appData['/nino/http/routes'][self::HOME] = [ 'uri' => '/', 'body' => \Crm\Crm::DOOR ];
			$added['home'] = isset( $stored[self::HOME] ) === true ? 'starter' : 'new';
		}

		// 6. The cap of mails: the owner's value stays, whatever it is; the kernel's default (the key is not in config.php) is set to what a portal needs
		$cap = \Nino\AppData::stored( $appData, self::CAP_KEY, null );

		if( $cap === null ) {

			$appData[self::CAP_KEY] = self::CAP;

			if( \Nino\AppData::writeContentData( $appData, [ self::CAP_KEY ] ) === false ) {
				\Nino\Http::fail( $request, 500, 'the cap of mails could not be written' );
				return;
			}

			$added['cap'] = self::CAP;
		}
		else
			$existed['cap'] = (int) $cap;

		// 7. The notification of the owner: Review mails the contact address when a customer comments, where the setting has no value yet - a value the owner set, off among them, stays
		if( self::notifySet( $appData ) === false ) {

			if( \Nino\Features::saveSettings( $appData, 'review', [ self::NOTIFY_SETTING => true ] ) !== [] ) {
				\Nino\Http::fail( $request, 500, 'the setting of Review could not be written' );
				return;
			}

			$added['notify'] = true;
		}
		else
			$existed['notify'] = \Nino\Features::setting( $appData, 'review', self::NOTIFY_SETTING, false ) === true;

		// A run that went through is written down: the screen of the first run is for a product that was never set up
		try {
			\Crm\Settings::markSetupDone( $appData );
		}
		catch( \PDOException $e ) {
			\Nino\Http::fail( $request, 500, 'the setup could not be recorded' );
			return;
		}

		\Nino\Http::ok( $request, [ 'added' => $added, 'existed' => $existed ] );
	}

	/**
	 *	What the module misses: each item has a key and a state
	 *	  module   ok, off (the class is not in /nino/modules)
	 *	  data     ok, unwritable (the data directory)
	 *	  members, files, review, sign, pdf, events     ok, off (installed, not active), missing (not installed), problem
	 *	  portal   ok (routes, templates, words, the element types, the protected path and the start page are there), partial, none - and what it lacks: lacks lists routes, templates, texts, type-<name> (one for each element type that is missing), paths and start
	 *	  pdf-templates   ok (every template of the seed is there), partial, none - and lacks lists the keys that are not
	 *	  home     ok (the start page of the site is the door of the portal), own (a page the owner made: left as it is, and no lack), other (none, or the wizard's own)
	 *	  mail-cap   ok (config.php carries a cap of its own, the owner's or the one the setup wrote), default (it carries none: the kernel's default stands) - and value, the cap that is in force
	 *	  notify   ok (the setting notifyOwner of Review has a value of its own, the owner's or the one the setup wrote), default (it has none) - and value, whether Review mails the owner
	 *	The answer is whole when every item is ok or - the start page - own; it is first_run while it is not whole and no run of the setup went through (the day of
	 *	the last one is the CRM's setting setup_done): a template deleted afterwards makes it not whole, and not a first run - the screen Einrichtung is shown while it is not
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
		$native = (string) $appData['/nino/locales/native'];
		$fragment = self::unitDir(). '/text/'. $native. '.php';
		$words = is_file( $fragment ) === true ? array_keys( (array) include $fragment ) : [];
		$had = \Nino\Filesystem::getFileContent( $appData, '/text/'. $native. '.php', [] );
		$had = is_array( $had ) === true ? $had : [];
		$kinds = [
			'routes'    => [ count( array_filter( $wanted, static fn( string $key ): bool => isset( $routes[$key] ) === true ) ), count( $wanted ) ],
			'templates' => [ count( array_filter( (array) ( $manifest['templates'] ?? [] ), static fn( string $file ): bool => \Nino\Filesystem::fileExists( $appData, '/templates/'. $file ) ) ), count( (array) ( $manifest['templates'] ?? [] ) ) ],
			'texts'     => [ count( array_filter( $words, static fn( mixed $key ): bool => array_key_exists( $key, $had ) === true ) ), count( $words ) ],
			'paths'     => [ in_array( '/portal', (array) \Nino\Features::setting( $appData, 'members', 'paths', [] ), true ) === true ? 1 : 0, 1 ],
			'start'     => [ in_array( \Nino\Features::setting( $appData, 'members', 'start', '/' ), [ '', '/' ], true ) === true ? 0 : 1, 1 ],
		];

		// Each element type is a kind of its own, so that the status names the one that is missing and not the one that is there
		foreach( (array) ( $manifest['elementTypes'] ?? [] ) as $file )
			$kinds['type-'. basename( (string) $file, '.php' )] = [ \Nino\Filesystem::fileExists( $appData, '/elements/'. $file ) === true ? 1 : 0, 1 ];

		$present = array_sum( array_column( $kinds, 0 ) );
		$total = array_sum( array_column( $kinds, 1 ) );
		$lacks = array_keys( array_filter( $kinds, static fn( array $count ): bool => $count[0] < $count[1] ) );

		$items[] = [ 'key' => 'portal', 'state' => $present === $total ? 'ok' : ( $present === 0 ? 'none' : 'partial' ), 'lacks' => $lacks ];

		$seeded = self::seed()['templates'];
		$keys = array_keys( $seeded );
		$missing = array_values( array_filter( $keys, static fn( string $key ): bool => \Nino\Filesystem::fileExists( $appData, self::PDF_TEMPLATES. '/'. $key. '.php' ) === false ) );
		$absent = array_map( static fn( string $key ): string => (string) $seeded[$key]['name'], $missing );

		$items[] = [ 'key' => 'pdf-templates', 'state' => $missing === [] ? 'ok' : ( count( $missing ) === count( $keys ) ? 'none' : 'partial' ), 'lacks' => $absent ];
		$items[] = [ 'key' => 'home', 'state' => self::homeState( $routes ) ];

		$cap = \Nino\AppData::stored( $appData, self::CAP_KEY, null );
		$items[] = [ 'key' => 'mail-cap', 'state' => $cap === null ? 'default' : 'ok', 'value' => (int) ( $cap ?? \Nino\AppData::DEFAULTS[self::CAP_KEY] ) ];

		$items[] = [ 'key' => 'notify', 'state' => self::notifySet( $appData ) === true ? 'ok' : 'default', 'value' => \Nino\Features::setting( $appData, 'review', self::NOTIFY_SETTING, false ) === true ];

		$whole = array_filter( $items, static fn( array $item ): bool => in_array( $item['state'], [ 'ok', 'own' ], true ) === false ) === [];

		\Nino\Http::ok( $request, [ 'items' => $items, 'whole' => $whole, 'first_run' => $whole === false && \Crm\Settings::setupDone( $appData ) === '' ] );
	}

	/**
	 *	Whether the setting notifyOwner of Review has a value of its own: one that is stored, whatever it is (off among them)
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	bool
	 */
	private static function notifySet( array &$appData ): bool {

		$settings = $appData[\Nino\Features::STATE_KEY]['review']['settings'] ?? null;

		return is_array( $settings ) === true && array_key_exists( self::NOTIFY_SETTING, $settings ) === true;
	}

	/**
	 *	The name of a feature as the Features panel shows it, in the interface language of the session; for a feature that is not installed the module's own name for it
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$key
	 *
	 *	@return 	string
	 */
	private static function label( array &$appData, string $key ): string {

		$locale = \Nino\Admin\Admin::sessionLocale( $appData );
		$feature = \Nino\Features::get( $appData, $key );
		$label = $feature === null ? '' : \Nino\Features::localized( $feature['name'] ?? '', $locale );

		// A feature that is not installed has no manifest to ask: the module's own names for its six
		return $label !== '' ? $label : ( self::LABELS[$key][$locale] ?? self::LABELS[$key]['en_US'] ?? $key );
	}

	/**
	 *	What the start page of the site is, from the persisted routes
	 *
	 *	@param		array 		$routes
	 *
	 *	@return 	string										ok (the door), own (a body of the owner's), other (no start page, or the one of the wizard)
	 */
	private static function homeState( array $routes ): string {

		$body = is_array( $routes[self::HOME] ?? null ) === true ? (string) ( $routes[self::HOME]['body'] ?? '' ) : null;

		return match( true ) {
			$body === \Crm\Crm::DOOR => 'ok',
			$body === null || $body === self::STARTER => 'other',
			default => 'own',
		};
	}

	/**
	 *	What the Einrichtung seeds into the Pdf feature: templates, blocks and layout (install/pdf.php)
	 *
	 *	@return 	array											templates, blocks, layout
	 */
	public static function seed(): array {

		return (array) include self::unitDir(). '/pdf.php';
	}

	/**
	 *	The directory of the install unit of the module
	 *
	 *	@return 	string
	 */
	public static function unitDir(): string {

		return dirname( __DIR__ ). '/install';
	}

}
