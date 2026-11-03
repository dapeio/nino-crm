<?php
declare(strict_types=1);

/**
 *	Nino
 *	events-smoke.php	Contract test for the Events feature (Modules\Events): the manifest and the
 *										activation through \Nino\Features with the element type and the words
 *										its unit brings, the time of a date (what a moment is, what is
 *										upcoming and what is past, against a clock the test sets), the
 *										stack [events] with its scope, its order, its limit and its grid and
 *										what a visitor, a member of the group and a member of another group
 *										see of it - with the Members feature beside it and without -, the
 *										chip [event-when] in both languages, the link [event-ics], the
 *										feed and the file of one date (the format of RFC 5545: lines, folding,
 *										escaping, UTC), the tokens of the members' feed links, [event-feed],
 *										the looks for the Design feature, and deactivation.
 *
 *										The clock is the key Events::CLOCK of $appData, a number of seconds, and
 *										the timezone of the site is php's, which this test sets to Europe/Berlin
 *										(UTC+1 in the winter the clock stands in), so that the conversion to UTC
 *										is seen to happen. The class is asked about Members with class_exists():
 *										one part of the test runs the feature in a process where the class is not
 *										there at all (events-alone.php beside this file).
 *
 *										Travels with the feature and runs against the checkout three levels up,
 *										or the one NINO_ROOT names (see tests/harness.php there).
 *
 *	Usage: php features/Events/tests/events-smoke.php
 *	       NINO_ROOT=../nino php features/Events/tests/events-smoke.php
 */

$root = getenv( 'NINO_ROOT' ) ?: dirname( __DIR__, 3 );
defined( 'NINO_FEATURES_DIR' ) === true || define( 'NINO_FEATURES_DIR', dirname( __DIR__, 2 ) );
require $root. '/tests/harness.php';

date_default_timezone_set( 'Europe/Berlin' );

$appData = ninoSandbox( 'events' );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// The project directory and its fill, empty the way a site at the domain root has it, and the facts of the website the
// links and the feed are built on: its address, and the name of the company that a calendar is called by
$appData['/nino/dir'] = '';
if( is_file( $root. '/_admin/install/library/base/text/global.php' ) === true )
	\Nino\Html::addFills( $appData, (array) include $root. '/_admin/install/library/base/text/global.php', '*' );
\Nino\Html::addFills( $appData, [ '[[/nino/dir]]' => '', '[[/project/website/general/url]]' => 'example.org/club', '[[/project/company/contact/email]]' => 'owner@example.org', '[[/project/company/general/name]]' => 'The Club' ], '*' );

// What the kernel boots with, as far as the feature touches it: the components, the csrf guard the sign-in of a member
// posts through and the [template] shortcode the pages of Members use
$appData['/nino/modules'] = [ '\\Nino\\Modules\\Components', '\\Nino\\Modules\\Csrf', '\\Nino\\Modules\\Template' ];
\Nino\AppData::writeContentData( $appData, [ '/nino/modules', '/nino/locales/available', '/nino/locales/native' ] );
\Nino\Csrf::init( $appData );

// A transport takes the mail so nothing is sent: the link a member signs in with is read out of it
$mails = [];
\Nino\Callbacks::registerCallback( $appData, \Nino\Mail::TRANSPORT, static function( array &$appData, array &$mail ) use ( &$mails ): void {
	$mails[] = $mail;
	$mail['sent'] = true;
} );

/**
 *	The clock of the site, set to a wall-clock time of the site's timezone
 *
 *	@param		array 		&$appData
 *	@param		string		$local				eg. '2026-12-10 12:00'
 *
 *	@return 	void
 */
function eventsClock( array &$appData, string $local ): void {

	$appData[\Nino\Modules\Events::CLOCK] = ( new DateTimeImmutable( $local, new DateTimeZone( date_default_timezone_get() ) ) )->getTimestamp();
}

/**
 *	A request of the site, answered by the routes and the callbacks that are registered: the request built, the global
 *	callbacks of the response run - the csrf guard and the gate of Members among them - and then the route's own.
 *	Nothing is rendered
 *
 *	@param		array 		&$appData
 *	@param		string		$uri
 *	@param		string		$method
 *	@param		array 		$post
 *
 *	@return 	array										The request, response and all
 */
function eventsServe( array &$appData, string $uri, string $method = 'GET', array $post = [] ): array {

	$_POST = $post + ( $method === 'POST' ? [ '_csrf' => \Nino\Csrf::getToken( $appData ) ] : [] );
	$request = ninoVisit( $appData, $uri, $method );
	\Nino\Http::response( $appData, $request );
	$_POST = [];

	return $request;
}

/**
 *	What a response says in the places a test reads it
 *
 *	@param		array 		$request
 *
 *	@return 	array										[ status, Content-Type, Cache-Control, Content-Disposition, body ]
 */
function eventsAnswer( array $request ): array {

	$response = $request['/nino/http/response'];

	return [ $response['statusCode'], (string) ( $response['header']['Content-Type'] ?? '' ), (string) ( $response['header']['Cache-Control'] ?? '' ), (string) ( $response['header']['Content-Disposition'] ?? '' ), $response['body'] ?? '' ];
}

/**
 *	The elements of a piece of html by xpath
 *
 *	@param		string		$html
 *	@param		string		$query
 *
 *	@return 	array										The DOMElements
 */
function eventsFind( string $html, string $query ): array {

	$document = new DOMDocument();
	libxml_use_internal_errors( true );
	$document->loadHTML( '<?xml encoding="UTF-8"><body>'. $html. '</body>' );
	libxml_clear_errors();

	return iterator_to_array( ( new DOMXPath( $document ) )->query( $query ) ?: [] );
}

/**
 *	The names of the dates a rendered stack lists, in the order it lists them: the file each cell links, [event-ics .slug]
 *
 *	@param		string		$html
 *
 *	@return 	array										Slugs
 */
function eventsSlugs( string $html ): array {

	return array_map( static fn( DOMElement $link ): string => (string) preg_replace( '~^/\.events/(.*)\.ics$~', '$1', $link->getAttribute( 'href' ) ), eventsFind( $html, '//a[contains(concat(" ", normalize-space(@class), " "), " events-ics ")]' ) );
}

/**
 *	A page of html+ rendered the way the site renders it
 *
 *	@param		array 		&$appData
 *	@param		string		$html
 *
 *	@return 	string
 */
function eventsRender( array &$appData, string $html ): string {

	return \Nino\Html::renderHtml( $appData, $html );
}

/**
 *	The stack with a cell that links the date's file, the way the Builder would put [event-ics .slug] into it
 *
 *	@param		array 		&$appData
 *	@param		string		$attributes		eg. 'scope="past" limit="2"'
 *
 *	@return 	array										Slugs, in the order of the stack
 */
function eventsList( array &$appData, string $attributes = '' ): array {

	return eventsSlugs( eventsRender( $appData, '[events '. $attributes. '][event-ics .slug][/events]' ) );
}

/**
 *	A calendar file with its folded lines joined again
 *
 *	@param		string		$ics
 *
 *	@return 	array										The content lines
 */
function eventsLines( string $ics ): array {

	return explode( "\r\n", rtrim( str_replace( "\r\n ", '', $ics ), "\r\n" ) );
}

/**
 *	The lines of one VEVENT of a calendar file, by the slug in its UID
 *
 *	@param		string		$ics
 *	@param		string		$slug
 *
 *	@return 	array										The unfolded lines of that event; [] for none
 */
function eventsEvent( string $ics, string $slug ): array {

	$lines = eventsLines( $ics );
	$found = [];
	$inside = [];

	foreach( $lines as $line ) {
		if( $line === 'BEGIN:VEVENT' )
			$inside = [ $line ];
		else if( $inside !== [] )
			$inside[] = $line;

		if( $line === 'END:VEVENT' ) {
			if( in_array( 'UID:'. $slug. '@example.org', $inside, true ) === true )
				$found = $inside;
			$inside = [];
		}
	}

	return $found;
}

/**
 *	The slugs of the events a calendar file carries, in the order they stand
 *
 *	@param		string		$ics
 *
 *	@return 	array
 */
function eventsUids( string $ics ): array {

	return array_map( static fn( string $line ): string => substr( $line, 4, (int) strpos( $line, '@' ) - 4 ), array_values( array_filter( eventsLines( $ics ), static fn( string $line ): bool => str_starts_with( $line, 'UID:' ) ) ) );
}

/**
 *	A visitor who has never been here: no session, nobody signed in
 *
 *	@return 	void
 */
function eventsVisitor(): void {

	$_SESSION = [];
}

/**
 *	Sign an account in the way a member does: a link is mailed, the page it opens asks, the button of that page signs in
 *
 *	@param		array 		&$appData
 *	@param		string		$email
 *
 *	@return 	bool											Whether the member is signed in afterwards
 */
function eventsSignIn( array &$appData, string $email ): bool {

	global $mails;

	eventsVisitor();
	\Nino\Filesystem::putFileContent( $appData, '/data/members-tries.php', [] );
	\Nino\Filesystem::putFileContent( $appData, '/data/ratelimit.php', [] );

	$account = array_column( \Nino\Modules\Members::accounts( $appData ), null, 'email' )[$email] ?? null;
	$count = count( $mails );

	if( $account === null || \Nino\Modules\Members::link( $appData, $account, 'login' ) !== true || preg_match( '~href="https://example\.org/club/\.members/login\?t=([A-Za-z0-9_-]+)"~', (string) ( $mails[$count]['body'] ?? '' ), $found ) !== 1 )
		return false;

	eventsServe( $appData, '/.members/login?t='. $found[1] );
	eventsServe( $appData, '/.members/confirm', 'POST', [ 'token' => $found[1] ] );

	return ( \Nino\Modules\Members::current( $appData )['id'] ?? '' ) === $account['id'];
}

/**
 *	The data file of the feature as it is on disk, read fresh
 *
 *	@param		array 		&$appData
 *
 *	@return 	array
 */
function eventsData( array &$appData ): array {

	$state = \Nino\Filesystem::getFileContent( $appData, '/data/events.php', [] );

	return is_array( $state ) === true ? $state : [];
}

// A day the whole test stands on: a Thursday in the winter, noon in Berlin (11:00 UTC)
eventsClock( $appData, '2026-12-10 12:00' );

// --- The feature - manifest and activation ---------------------------------------

echo "The feature - manifest and activation\n";

$dir = dirname( __DIR__ );
$manifest = \Nino\Features::manifest( $dir );

check( 'the manifest validates, key "events", without a warning', is_array( $manifest ) && $manifest['key'] === 'events' && ninoWarnings() === [] );
check( 'class, version and category are what the plan says: content, 1.0.0, no maturity, no extension needed', is_array( $manifest ) && $manifest['module'] === '\\Nino\\Modules\\Events' && $manifest['version'] === '1.0.0'
	&& $manifest['category'] === 'content' && $manifest['maturity'] === '' && $manifest['php'] === [ 'ext' => [] ] );
check( 'it names itself in both interface languages', is_array( $manifest ) && \Nino\Features::localized( $manifest['name'], 'de_DE' ) === 'Termine' && \Nino\Features::localized( $manifest['name'], 'en_US' ) === 'Events'
	&& \Nino\Features::localized( $manifest['description'], 'de_DE' ) !== \Nino\Features::localized( $manifest['description'], 'en_US' ) );
check( 'its floor is 1.9, the kernel that has .slug in a cell, and a constraint of the same major version', is_array( $manifest ) && \Nino\Features::satisfies( $manifest['nino'], '1.8.9' ) === false
	&& \Nino\Features::satisfies( $manifest['nino'], '1.9.0' ) === true && \Nino\Features::satisfies( $manifest['nino'], '1.99.0' ) === true && \Nino\Features::satisfies( $manifest['nino'], '2.0.0' ) === false );
check( 'it requires nothing - Members is optional - and owns the file of the feed tokens, and one stylesheet and no script', is_array( $manifest ) && $manifest['requires'] === [] && $manifest['data'] === [ '/data/events.php' ]
	&& $manifest['assets'] === [ '/features/Events/assets/events.css' ] && glob( $dir. '/assets/*.js' ) === [] );
check( 'the settings are the four of the plan and the timezone, each with its type, its default and its bounds', is_array( $manifest ) && array_keys( $manifest['settings'] ) === [ 'ahead', 'past', 'feed', 'name', 'timezone' ]
	&& $manifest['settings']['ahead']['type'] === 'int' && $manifest['settings']['ahead']['min'] === 1 && $manifest['settings']['ahead']['max'] === 730 && $manifest['settings']['ahead']['unit'] === 'days' && $manifest['settings']['ahead']['default'] === 365
	&& $manifest['settings']['past']['type'] === 'int' && $manifest['settings']['past']['min'] === 0 && $manifest['settings']['past']['max'] === 3650 && $manifest['settings']['past']['unit'] === 'days' && $manifest['settings']['past']['default'] === 0
	&& $manifest['settings']['feed']['type'] === 'bool' && $manifest['settings']['feed']['default'] === true
	&& $manifest['settings']['name']['type'] === 'string' && $manifest['settings']['name']['maxlength'] === 80 && $manifest['settings']['name']['default'] === '' );
check( 'the manual answers all six sections in both languages, and says that the feature stores nothing about a visitor', is_array( $manifest ) && array_keys( $manifest['manual'] ) === \Nino\Features::MANUAL_SECTIONS
	&& array_filter( $manifest['manual'], static fn( array $section ): bool => array_filter( $section, static fn( mixed $line ): bool => is_array( $line ) === false || ( $line['en_US'] ?? '' ) === '' || ( $line['de_DE'] ?? '' ) === '' ) !== [] ) === []
	&& str_contains( json_encode( $manifest['manual']['install'] ), 'visitor' ) === true && str_contains( json_encode( $manifest['manual']['markup'] ), 'about a visitor' ) === true );
check( 'it brings no panel of its own, no section of the privacy policy and no template: the unit is the element type and the words', is_array( $manifest ) && $manifest['manual']['panel'] !== [] && is_file( $dir. '/Admin/Admin.php' ) === false
	&& is_file( $dir. '/install/elements/privacy.php' ) === false && ( include $dir. '/install/manifest.php' ) === [ 'elementTypes' => [ 'events.php' ] ] && glob( $dir. '/install/templates/*' ) === [] );

check( 'activation succeeds', \Nino\Features::activate( $appData, 'events' ) === true );
check( '...records the version and lists the class', ( \Nino\Features::get( $appData, 'events' )['installed'] ?? '' ) === '1.0.0' && in_array( '\\Nino\\Modules\\Events', (array) ( \Nino\Filesystem::getFileContent( $appData, '/config.php', [] )['/nino/modules'] ?? [] ), true ) );

$model = \Nino\Elements::getElementModel( $appData, '/events' );

check( '...and the element type /events lands, with the seven fields of the plan', \Nino\Filesystem::fileExists( $appData, '/elements/events.php' ) === true && array_keys( $model ) === [ 'title', 'start', 'end', 'place', 'text', 'link', 'group' ] );
check( '...a start that is a date and a time and is required, an end that is the same and is not, and a title, a place and a text per language', ( $model['start']['type'] ?? '' ) === 'datetime' && ( $model['start']['required'] ?? false ) === true
	&& ( $model['end']['type'] ?? '' ) === 'datetime' && ( $model['end']['required'] ?? false ) === false && ( $model['title']['required'] ?? false ) === true
	&& ( $model['title']['locale'] ?? false ) === true && ( $model['place']['locale'] ?? false ) === true && ( $model['text']['locale'] ?? false ) === true
	&& ( $model['start']['locale'] ?? false ) === false && ( $model['end']['locale'] ?? false ) === false && ( $model['group']['locale'] ?? false ) === false && ( $model['link']['locale'] ?? false ) === false );
check( '...the text has paragraphs and lists, the link and the group are plain strings', ( $model['text']['html'] ?? false ) === true && ( $model['text']['blocks'] ?? false ) === true && ( $model['link']['type'] ?? '' ) === 'string' && ( $model['group']['type'] ?? '' ) === 'string' );

foreach( [ 'en_US' => 'Today', 'de_DE' => 'Heute' ] as $locale => $word )
	check( '...and the words of the unit are in the Text panel in '. $locale, ( \Nino\Filesystem::getFileContent( $appData, '/text/'. $locale. '.php', [] )['[[/feature/events/when/today]]'] ?? '' ) === $word );

$words = array_keys( (array) include $dir. '/install/text/en_US.php' );
check( '...the same keys in both languages, each of the grammar (/feature/events/<part>/<name>)', $words === array_keys( (array) include $dir. '/install/text/de_DE.php' ) && count( $words ) === 10
	&& array_filter( $words, static fn( string $key ): bool => preg_match( '~^\[\[/feature/events/[a-z]+(?:-[a-z]+)*/[a-z]+(?:-[a-z]+)*\]\]$~', $key ) !== 1 ) === [] );

// The unit is applied add-only: a type the project changed stays, a word it wrote stays
\Nino\Filesystem::putFileContent( $appData, '/text/en_US.php', [ '[[/feature/events/when/today]]' => 'Tonight' ] + (array) \Nino\Filesystem::getFileContent( $appData, '/text/en_US.php', [] ) );
$typeFile = \Nino\Filesystem::path( $appData, '/elements/events.php' );
file_put_contents( $typeFile, (string) file_get_contents( $typeFile ). "\n// changed by the project\n" );
check( 'activating again leaves the type and the words of the project as they are', \Nino\Features::activate( $appData, 'events' ) === true && str_contains( (string) file_get_contents( $typeFile ), '// changed by the project' ) === true
	&& ( \Nino\Filesystem::getFileContent( $appData, '/text/en_US.php', [] )['[[/feature/events/when/today]]'] ?? '' ) === 'Tonight' );
\Nino\Filesystem::putFileContent( $appData, '/text/en_US.php', [ '[[/feature/events/when/today]]' => 'Today' ] + (array) \Nino\Filesystem::getFileContent( $appData, '/text/en_US.php', [] ) );

\Nino\Html::init( $appData );
\Nino\Modules::callModules( $appData, 'init' );
ninoWarnings();

$components = \Nino\Modules\Components::components( $appData );
$stacks = \Nino\Modules\Components::stacks( $appData );

check( 'the three components and the stack are registered from the manifest, as the feature\'s own', array_keys( array_filter( $components, static fn( array $schema ): bool => ( $schema['by'] ?? '' ) === 'events' ) ) === [ 'event-when', 'event-ics', 'event-feed' ] && isset( $stacks['events'] ) );
check( '...event-when: a text source, the end and the format, a <time> with its class, a Lucide calendar', ( $components['event-when']['source'] ?? '' ) === 'text' && array_keys( $components['event-when']['attributes'] ) === [ 'end', 'format' ]
	&& $components['event-when']['attributes']['format']['options'] === [ 'datetime', 'date', 'time' ] && $components['event-when']['attributes']['format']['default'] === 'datetime' && $components['event-when']['attributes']['end']['default'] === ''
	&& $components['event-when']['preview'] === 'text' && $components['event-when']['draws'] === 'time .events-when' && $components['event-when']['in'] === [ 'section', 'card' ] && count( $components['event-when']['icon'] ) === 4 );
check( '...event-ics: a text source, no attribute of its own, a button, an <a> with its class, a Lucide calendar-plus', ( $components['event-ics']['source'] ?? '' ) === 'text' && $components['event-ics']['attributes'] === []
	&& $components['event-ics']['preview'] === 'button' && $components['event-ics']['draws'] === 'a .events-ics' && $components['event-ics']['in'] === [ 'section', 'card' ] && count( $components['event-ics']['icon'] ) === 6 );
check( '...event-feed: no source, a button, an <a> with its class, a Lucide rss', ( $components['event-feed']['source'] ?? '' ) === 'none' && $components['event-feed']['attributes'] === []
	&& $components['event-feed']['preview'] === 'button' && $components['event-feed']['draws'] === 'a .events-feed' && $components['event-feed']['in'] === [ 'section', 'card' ] && count( $components['event-feed']['icon'] ) === 3 );
check( '...the stack: a grid, a scope of three words that starts at upcoming, an order of two that starts at asc, the cells as a preview', ( $stacks['events']['grid'] ?? false ) === true && array_keys( $stacks['events']['attributes'] ) === [ 'scope', 'order' ]
	&& $stacks['events']['attributes']['scope']['options'] === [ 'upcoming', 'past', 'all' ] && $stacks['events']['attributes']['scope']['default'] === 'upcoming'
	&& $stacks['events']['attributes']['order']['options'] === [ 'asc', 'desc' ] && $stacks['events']['attributes']['order']['default'] === 'asc' && $stacks['events']['preview'] === 'cells' );
check( '...and nothing else of the feature is a shortcode', array_keys( array_filter( \Nino\Modules\Components::registered( $appData ), static fn( array $schema ): bool => ( $schema['by'] ?? '' ) === 'events' ) ) === [ 'event-when', 'event-ics', 'event-feed' ] );

$paths = static fn( string $component ): array => array_map( static fn( array $part ): string => (string) ( $part[1]['d'] ?? ( $part[0] === 'rect' ? 'rect' : 'circle' ) ), $components[$component]['icon'] );
check( 'the icons are the paths of Lucide 0.577.0, the release THIRD-PARTY-NOTICES.md names: calendar, calendar-plus and rss', $paths( 'event-when' ) === [ 'M8 2v4', 'M16 2v4', 'rect', 'M3 10h18' ]
	&& $paths( 'event-ics' ) === [ 'M8 2v4', 'M16 2v4', 'M21 12.598V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8.5', 'M3 10h18', 'M16 19h6', 'M19 16v6' ] && $paths( 'event-feed' ) === [ 'M4 11a9 9 0 0 1 9 9', 'M4 4a16 16 0 0 1 16 16', 'circle' ] );
$manual = json_encode( $manifest['manual'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
check( 'the manual says what the plan\'s rounds added: a day is over when its day ends, another loop shows every date, there is no restore callback, the feed reads 0 as a year, the zone is the setting\'s', is_array( $manifest )
	&& str_contains( $manual, 'a day-long date (a midnight start, no end) is over when its day ends' ) === true && str_contains( $manual, 'ganztägiger Termin' ) === true && str_contains( $manual, 'ist vorbei, wenn sein Tag endet, einer mit Uhrzeit und ohne Ende, wenn er beginnt' ) === true
	&& str_contains( $manual, 'Another loop over /events' ) === true && str_contains( $manual, 'Eine andere Schleife über /events' ) === true
	&& str_contains( $manual, 'No callback of /nino/admin/restore' ) === true && str_contains( $manual, 'Kein Callback von /nino/admin/restore' ) === true
	&& str_contains( $manual, 'the feed reads 0 as 365 days' ) === true && str_contains( json_encode( $manifest['settings']['past'], JSON_UNESCAPED_UNICODE ), 'so that it does not grow for ever' ) === true && str_contains( json_encode( $manifest['settings']['past'], JSON_UNESCAPED_UNICODE ), 'damit er nicht für immer wächst' ) === true && str_contains( $manual, 'der Feed liest 0 als 365 Tage' ) === true
	&& str_contains( $manual, 'read in the zone of the setting timezone' ) === true && str_contains( $manual, 'Zone der Einstellung Zeitzone' ) === true );
check( 'the German manual calls the type what the Elements panel does, Events', str_contains( (string) $manifest['manual']['panel']['Elements: Events']['de_DE'], 'Typs Events' ) === true && str_contains( $manual, 'Typs Termine' ) === false && ( include $dir. '/install/events.php' )['title'] === 'Events' );

// --- The moments - what a moment is, and when a date is upcoming ----------------

echo "\nThe moments of a date\n";

$moment = \Nino\Modules\Events::moment( $appData, '2026-12-24T18:30' );
check( 'an editor\'s moment is read in the timezone of the site: 2026-12-24T18:30 is 17:30 UTC', $moment instanceof DateTimeImmutable && $moment->format( 'Y-m-d H:i:s' ) === '2026-12-24 18:30:00' && $moment->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'H:i' ) === '17:30' );
check( '...with seconds, with a space for the T, and a day alone is its midnight', \Nino\Modules\Events::moment( $appData, '2026-12-24T18:30:15' )?->format( 'H:i:s' ) === '18:30:15' && \Nino\Modules\Events::moment( $appData, '2026-12-24 18:30' )?->format( 'H:i' ) === '18:30'
	&& \Nino\Modules\Events::moment( $appData, '2026-12-24' )?->format( 'Y-m-d H:i:s' ) === '2026-12-24 00:00:00' );
check( '...a day that does not exist, a time that does not, an empty value and what is no moment at all are none', \Nino\Modules\Events::moment( $appData, '2027-02-30T10:00' ) === null && \Nino\Modules\Events::moment( $appData, '2026-12-24T24:00' ) === null && \Nino\Modules\Events::moment( $appData, '2026-12-24T10:60' ) === null
	&& \Nino\Modules\Events::moment( $appData, '' ) === null && \Nino\Modules\Events::moment( $appData, 'tomorrow' ) === null && \Nino\Modules\Events::moment( $appData, '24.12.2026' ) === null && \Nino\Modules\Events::moment( $appData, null ) === null && \Nino\Modules\Events::moment( $appData, 20261224 ) === null && \Nino\Modules\Events::moment( $appData, '2026-12-24T18:30junk' ) === null );

$now = \Nino\Modules\Events::now( $appData );
check( 'the clock is the key a test sets, in the timezone of the site: noon in Berlin', $now->format( 'Y-m-d H:i T' ) === '2026-12-10 12:00 CET' && $now->getTimezone()->getName() === 'Europe/Berlin' );

$at = static fn( string $start, ?string $end = null ): array => \Nino\Modules\Events::record( $appData, [ '.uri' => '/events/x', 'start' => $start, 'end' => $end ?? '' ] ) ?? [];
$in = static fn( string $scope, array $record, int $ahead = 365, int $past = 0 ): bool => \Nino\Modules\Events::selects( $record, $scope, $now, $ahead, $past );

check( 'a date that begins in the future is upcoming and not past', $in( 'upcoming', $at( '2026-12-11T10:00' ) ) === true && $in( 'past', $at( '2026-12-11T10:00' ) ) === false );
check( '...one that has begun and has not ended is upcoming, and not past', $in( 'upcoming', $at( '2026-12-10T09:00', '2026-12-10T18:00' ) ) === true && $in( 'past', $at( '2026-12-10T09:00', '2026-12-10T18:00' ) ) === false );
check( '...one that has ended is past, and not upcoming', $in( 'past', $at( '2026-12-10T08:00', '2026-12-10T11:00' ) ) === true && $in( 'upcoming', $at( '2026-12-10T08:00', '2026-12-10T11:00' ) ) === false );
check( '...one with no end is over when it begins: at noon sharp it is upcoming, a minute earlier it is past', $in( 'upcoming', $at( '2026-12-10T12:00' ) ) === true && $in( 'past', $at( '2026-12-10T12:00' ) ) === false
	&& $in( 'upcoming', $at( '2026-12-10T11:59' ) ) === false && $in( 'past', $at( '2026-12-10T11:59' ) ) === true );
check( '...an end that is not after the start, or is none, is no end', $at( '2026-12-10T10:00', '2026-12-10T10:00' )['end'] === null && $at( '2026-12-10T10:00', '2026-12-09T10:00' )['end'] === null && $at( '2026-12-10T10:00', 'later' )['end'] === null );
check( '...an element whose start is no moment is no date', \Nino\Modules\Events::record( $appData, [ '.uri' => '/events/x', 'start' => 'soon' ] ) === null && \Nino\Modules\Events::record( $appData, [ '.uri' => '/events/x' ] ) === null );
check( 'the days ahead end the upcoming dates: a date 365 days away is in, the day after is out, and 730 days take it in', $in( 'upcoming', $at( '2027-12-10T12:00' ) ) === true && $in( 'upcoming', $at( '2027-12-10T12:01' ) ) === false && $in( 'upcoming', $at( '2027-12-10T12:01' ), 730 ) === true );
check( '...the days back end the past ones, counted from the end of a date, and 0 is all of them', $in( 'past', $at( '2026-11-01T10:00', '2026-11-01T12:00' ), 365, 40 ) === true && $in( 'past', $at( '2026-11-01T10:00', '2026-11-01T12:00' ), 365, 30 ) === false
	&& $in( 'past', $at( '2020-01-01T10:00' ), 365, 0 ) === true );
check( '...all is the window of both', $in( 'all', $at( '2020-01-01T10:00' ) ) === true && $in( 'all', $at( '2020-01-01T10:00' ), 365, 30 ) === false && $in( 'all', $at( '2030-01-01T10:00' ) ) === false && $in( 'all', $at( '2026-12-24T10:00' ) ) === true );
check( 'a date at midnight with no end is a day; with an end, or at another time, it is not', $at( '2026-12-20T00:00' )['allDay'] === true && $at( '2026-12-20' )['allDay'] === true && $at( '2026-12-20T00:00', '2026-12-20T23:00' )['allDay'] === false && $at( '2026-12-20T00:01' )['allDay'] === false );
check( 'a day is over when its day ends: a day that is today is upcoming and not past, yesterday\'s is over, and a date with a time and no end is over when it begins, one with an end when that comes',
	$in( 'upcoming', $at( '2026-12-10T00:00' ) ) === true && $in( 'past', $at( '2026-12-10T00:00' ) ) === false && $at( '2026-12-10T00:00' )['over']->format( 'Y-m-d H:i' ) === '2026-12-11 00:00'
	&& $in( 'past', $at( '2026-12-09T00:00' ) ) === true && $in( 'upcoming', $at( '2026-12-09T00:00' ) ) === false && $in( 'upcoming', $at( '2026-12-11T00:00' ) ) === true
	&& $at( '2026-12-10T08:00' )['over']->format( 'H:i' ) === '08:00' && $at( '2026-12-10T00:00', '2026-12-10T08:00' )['over']->format( 'H:i' ) === '08:00' );
check( 'a group is a slug as Members makes it - Board is board - and one that makes none belongs to nobody, never to everyone',
	\Nino\Modules\Events::record( $appData, [ '.uri' => '/events/x', 'start' => '2026-12-20', 'group' => ' Board ' ] )['group'] === 'board' && \Nino\Modules\Events::record( $appData, [ '.uri' => '/events/x', 'start' => '2026-12-20', 'group' => '' ] )['group'] === ''
	&& \Nino\Modules\Events::record( $appData, [ '.uri' => '/events/x', 'start' => '2026-12-20', 'group' => '***' ] )['group'] === '!' && \Nino\Modules\Events::record( $appData, [ '.uri' => '/events/x', 'start' => '2026-12-20' ] )['group'] === '' );


// --- The dates - seeded around the clock ------------------------------------------

echo "\nThe dates of the tests\n";

$long = str_repeat( 'ä', 60 ). ' und lang genug, dass die Zeile gefaltet werden muss';

/*	Every date of the tests, around noon of the 10th of December 2026: its start, its end, the group, the place and the
	title in German. 'far' lies more than a year ahead, 'broken' has no start that is one	*/
$seed = [
	'past-old'			=> [ '2026-11-01T10:00', '2026-11-01T12:00', '', 'Halle', 'Alt' ],
	'past-recent'		=> [ '2026-12-09T09:00', '', '', 'Halle', 'Gestern' ],
	'ended-today'		=> [ '2026-12-10T08:00', '2026-12-10T11:00', '', '', 'Frühstück' ],
	'just-over'			=> [ '2026-12-10T11:59', '', '', '', 'Gerade vorbei' ],
	'running'				=> [ '2026-12-10T09:00', '2026-12-10T18:00', '', 'Halle', 'Läuft' ],
	'at-now'				=> [ '2026-12-10T12:00', '', '', '', 'Jetzt' ],
	'soon'					=> [ '2026-12-11T10:00', '', '', 'Halle', 'Bald' ],
	'board-meeting'	=> [ '2026-12-15T19:00', '', 'Board', '', 'Vorstand' ],
	'staff-party'		=> [ '2026-12-16T19:00', '', 'staff', '', 'Personal' ],
	'strange'				=> [ '2026-12-17T19:00', '', '***', '', 'Seltsam' ],
	'all-day'				=> [ '2026-12-20T00:00', '', '', '', 'Ganzer Tag' ],
	'later'					=> [ '2026-12-24T18:00', '2026-12-24T20:00', '', 'Halle 1, Hof', 'Feier, groß; mit \\ Gästen' ],
	'two-day'				=> [ '2026-12-27T10:00', '2026-12-28T12:00', '', '', $long ],
	'far'						=> [ '2028-01-01T10:00', '', '', '', 'Fern' ],
	'broken'				=> [ 'not a date', '', '', '', 'Kaputt' ],
];

foreach( $seed as $slug => [ $start, $end, $group, $place, $title ] )
	\Nino\Elements::insertElement( $appData, '/events/'. $slug, [ 'title' => $title, 'start' => $start, 'end' => $end, 'group' => $group, 'place' => $place ], 'de_DE' );

\Nino\Elements::updateElement( $appData, '/events/later', [ 'text' => '<p>Erste Zeile</p><p>Zweite, Zeile; ok &amp; <strong>gut</strong></p><ul><li>Punkt</li></ul>', 'link' => 'https://example.org/feier?a=1&b=2' ], 'de_DE' );
\Nino\Elements::updateElement( $appData, '/events/soon', [ 'title' => 'Soon', 'place' => 'The hall' ], 'en_US' );
\Nino\Elements::updateElement( $appData, '/events/running', [ 'link' => '/programm' ], 'de_DE' );

check( 'fifteen dates are seeded, with the moments global and the words in German, and the English ones beside', count( (array) \Nino\Elements::queryElements( $appData, '/events', [], 'de_DE', [] ) ) === 15
	&& ( \Nino\Elements::getElement( $appData, '/events/soon', 'en_US' )['start'] ?? '' ) === '2026-12-11T10:00' && ( \Nino\Elements::getElement( $appData, '/events/soon', 'en_US' )['title'] ?? '' ) === 'Soon' );

// --- The stack ----------------------------------------------------------------------

echo "\nThe stack [events] - for a visitor\n";

$appData['./nino/locales/current'] = 'de_DE';
eventsVisitor();

$upcoming = [ 'running', 'at-now', 'soon', 'all-day', 'later', 'two-day' ];

check( 'upcoming is the default: what is not over, soonest first - a date that has begun and not ended among them, one at this very minute too, and no date of a group, none beyond a year ahead, none that has no start', eventsList( $appData ) === $upcoming && eventsList( $appData, 'scope="upcoming"' ) === $upcoming );
check( '...past is what is over and starts with the one nearest to now', eventsList( $appData, 'scope="past"' ) === [ 'just-over', 'ended-today', 'past-recent', 'past-old' ] );
check( '...all is both, by their start', eventsList( $appData, 'scope="all"' ) === [ 'past-old', 'past-recent', 'ended-today', 'running', 'just-over', 'at-now', 'soon', 'all-day', 'later', 'two-day' ] );
check( '...order="desc" turns it around: the last one that is coming first, and the oldest of the past ones first - all is by start, asc the earliest', eventsList( $appData, 'order="desc"' ) === array_reverse( $upcoming )
	&& eventsList( $appData, 'scope="past" order="desc"' ) === [ 'past-old', 'past-recent', 'ended-today', 'just-over' ] && eventsList( $appData, 'scope="all" order="desc"' ) === array_reverse( eventsList( $appData, 'scope="all"' ) ) && eventsList( $appData, 'order="asc"' ) === $upcoming );
check( '...limit keeps the first of them and 0 all, offset skips', eventsList( $appData, 'limit="2"' ) === [ 'running', 'at-now' ] && eventsList( $appData, 'scope="past" limit="1"' ) === [ 'just-over' ] && eventsList( $appData, 'limit="0"' ) === $upcoming
	&& eventsList( $appData, 'offset="4"' ) === [ 'later', 'two-day' ] );
check( '...limit counts what the viewer sees: the dates of a group take no place from a visitor', eventsList( $appData, 'scope="all" limit="9"' ) === array_slice( eventsList( $appData, 'scope="all"' ), 0, 9 ) && count( eventsList( $appData, 'scope="all" limit="10"' ) ) === 10 );
check( '...a scope or an order that is none is the default', eventsList( $appData, 'scope="never"' ) === $upcoming && eventsList( $appData, 'order="sideways"' ) === $upcoming );
check( '...the sort and the callback of the call are not read: the order is the start', eventsList( $appData, 'sort="-title"' ) === $upcoming && eventsList( $appData, 'callback="/nino/nothing"' ) === $upcoming );
check( '...a query of the loop narrows by a field', eventsList( $appData, 'query="place=Halle"' ) === [ 'running', 'soon' ] && eventsList( $appData, 'scope="past" query="place=Halle"' ) === [ 'past-recent', 'past-old' ] );

$grid = eventsRender( $appData, '[events cols="100 50 33" gap="3" id="next" class="my-class"][event-ics .slug][/events]' );
check( '...the grid is the kernel\'s: a row with the gap, the id and the class of the call, and a cell with the widths of the three viewports for every date', count( eventsFind( $grid, '//div[@id="next" and contains(@class, "nino-grid-row") and contains(@class, "nino-stack") and contains(@class, "nino-stack-gap-3") and contains(@class, "my-class")]' ) ) === 1
	&& count( eventsFind( $grid, '//div[@id="next"]/div[@class="nino-grid-s-100 nino-grid-m-50 nino-grid-l-33"]' ) ) === 6 );
check( '...and the defaults of the kernel without them: two cells to a row from the tablet on, a gap of 2, no id', count( eventsFind( eventsRender( $appData, '[events][event-ics .slug][/events]' ), '//div[contains(@class, "nino-stack-gap-2")]/div[@class="nino-grid-s-100 nino-grid-m-50 nino-grid-l-33"]' ) ) === 6
	&& str_contains( eventsRender( $appData, '[events][event-ics .slug][/events]' ), ' id=' ) === false );
check( '...a class with a quote in it stays in its attribute, it cannot open an attribute of its own', str_contains( eventsRender( $appData, '[events class="a&quot;b"][event-ics .slug][/events]' ), 'a&amp;quot;b' ) === true && count( eventsFind( eventsRender( $appData, '[events class="x&quot; onclick=&quot;y"][event-ics .slug][/events]' ), '//*[@onclick]' ) ) === 0 );

check( 'where there is nothing to list the stack says so in one line, in the words of the Text panel and of the scope', trim( strip_tags( eventsRender( $appData, '[events query="place=Nowhere"][event-ics .slug][/events]' ) ) ) === 'Es stehen keine Termine an.'
	&& trim( strip_tags( eventsRender( $appData, '[events scope="past" query="place=Nowhere"][event-ics .slug][/events]' ) ) ) === 'Es hat noch nichts stattgefunden.'
	&& trim( strip_tags( eventsRender( $appData, '[events scope="all" query="place=Nowhere"][event-ics .slug][/events]' ) ) ) === 'Es gibt keine Termine.'
	&& count( eventsFind( eventsRender( $appData, '[events query="place=Nowhere"][event-ics .slug][/events]' ), '//p[@class="events-empty"]' ) ) === 1 );
$appData['./nino/locales/current'] = 'en_US';
check( '...in English the English ones', trim( strip_tags( eventsRender( $appData, '[events query="place=Nowhere"][event-ics .slug][/events]' ) ) ) === 'There is nothing coming up.' );
$appData['./nino/locales/current'] = 'de_DE';

check( 'the stack leaves nothing of itself behind: the context of a cell is gone after it, and the hook does nothing outside a stack',
	\Nino\Modules\Components::element( $appData ) === null && ( $appData['./events/filter'] ?? null ) === null
	&& \Nino\Callbacks::doCallbacks( $appData, '/events/stack', $untouched ) === null );
$list = [ [ '.uri' => '/events/b', 'start' => '2026-12-12T10:00' ], [ '.uri' => '/events/a', 'start' => '2026-12-11T10:00', 'group' => 'x' ] ];
$copy = $list;
check( '...and the list it is handed is the list it hands back', \Nino\Callbacks::doCallbacks( $appData, '/events/stack', $list ) === $copy && $list === $copy );

// --- The chip ---------------------------------------------------------------------------

echo "\n[event-when] - the date in the language of the page\n";

/**
 *	The chip a date is drawn as in the cell of the stack that lists it: [event-when start end="end"]
 *
 *	@param		array 		&$appData
 *	@param		string		$start				The start the date is found by
 *	@param		string		$call					The attributes of the call
 *
 *	@return 	DOMElement|null
 */
function eventsChip( array &$appData, string $start, string $call = 'start end="end"' ): ?DOMElement {

	$found = eventsFind( eventsRender( $appData, '[events scope="all" query="start='. $start. '"][event-when '. $call. '][/events]' ), '//time' );

	return $found[0] ?? null;
}

$has = static fn( ?DOMElement $chip, string $class ): bool => $chip !== null && in_array( $class, preg_split( '/\s+/', $chip->getAttribute( 'class' ) ) ?: [], true );

$running = eventsChip( $appData, '2026-12-10T09:00' );
check( 'a date that is on today is a <time> with the class of the chip and today\'s, the start as its datetime exactly, and the day in words', $running !== null && $has( $running, 'events-when' ) === true && $has( $running, 'events-when--today' ) === true && $has( $running, 'events-when--past' ) === false
	&& $running->getAttribute( 'datetime' ) === '2026-12-10T09:00' && str_starts_with( $running->textContent, 'Heute' ) === true );
check( '...an end on the same day makes a range of times: 09:00-18:00', $running !== null && str_contains( $running->textContent, '09:00-18:00' ) === true );
$over = eventsChip( $appData, '2026-12-10T08:00' );
check( '...one that is over and was today is both: events-when--today and events-when--past', $has( $over, 'events-when--today' ) === true && $has( $over, 'events-when--past' ) === true && $over?->getAttribute( 'datetime' ) === '2026-12-10T08:00' );
$old = eventsChip( $appData, '2026-11-01T10:00' );
check( '...one that is over is events-when--past alone, with the day written out - it has the day number, the month and the year', $has( $old, 'events-when--past' ) === true && $has( $old, 'events-when--today' ) === false && preg_match( '/0?1\D+11\D+2026|1\. Nov/u', (string) $old?->textContent ) === 1 );
$soon = eventsChip( $appData, '2026-12-11T10:00' );
check( '...tomorrow is a word, and neither class is on a date that is yet to come', $soon !== null && str_starts_with( $soon->textContent, 'Morgen, 10:00' ) === true && $has( $soon, 'events-when--today' ) === false && $has( $soon, 'events-when--past' ) === false );
$later = eventsChip( $appData, '2026-12-24T18:00' );
check( '...a later day has its day number and the time; the range 18:00-20:00; the datetime is the start alone', $later !== null && str_contains( $later->textContent, '24' ) === true && str_contains( $later->textContent, '18:00-20:00' ) === true && $later->getAttribute( 'datetime' ) === '2026-12-24T18:00' );
$two = eventsChip( $appData, '2026-12-27T10:00' );
check( '...an end on another day writes both days with the word between them, bis, and the times with them', $two !== null && str_contains( $two->textContent, '27' ) === true && str_contains( $two->textContent, '28' ) === true && str_contains( $two->textContent, ' bis ' ) === true
	&& str_contains( $two->textContent, '10:00' ) === true && str_contains( $two->textContent, '12:00' ) === true && $two->getAttribute( 'datetime' ) === '2026-12-27T10:00' );
$day = eventsChip( $appData, '2026-12-20T00:00' );
check( '...a date at midnight with no end is a day: the day alone, no time, its datetime a date', $day !== null && $day->getAttribute( 'datetime' ) === '2026-12-20' && str_contains( $day->textContent, ':' ) === false && str_contains( $day->textContent, '20' ) === true );
\Nino\Elements::insertElement( $appData, '/events/today-all-day', [ 'title' => 'Heute ganztägig', 'start' => '2026-12-10T00:00' ], 'de_DE' );
$todayAllDay = eventsChip( $appData, '2026-12-10T00:00' );
check( '...a day that is today is upcoming in the stack, and its chip is events-when--today and not events-when--past - the day is not over at midnight', $todayAllDay !== null && $has( $todayAllDay, 'events-when--today' ) === true && $has( $todayAllDay, 'events-when--past' ) === false
	&& str_starts_with( $todayAllDay->textContent, 'Heute' ) === true && $todayAllDay->getAttribute( 'datetime' ) === '2026-12-10' && in_array( 'today-all-day', eventsList( $appData ), true ) === true && in_array( 'today-all-day', eventsList( $appData, 'scope="past"' ), true ) === false );
\Nino\Elements::insertElement( $appData, '/events/yesterday-all-day', [ 'title' => 'Gestern ganztägig', 'start' => '2026-12-09T00:00' ], 'de_DE' );
\Nino\Elements::insertElement( $appData, '/events/tomorrow-all-day', [ 'title' => 'Morgen ganztägig', 'start' => '2026-12-11T00:00' ], 'de_DE' );
$yesterdayAllDay = eventsChip( $appData, '2026-12-09T00:00' );
$tomorrowAllDay = eventsChip( $appData, '2026-12-11T00:00' );
check( '...yesterday\'s day is over and not today, tomorrow\'s is neither', $has( $yesterdayAllDay, 'events-when--past' ) === true && $has( $yesterdayAllDay, 'events-when--today' ) === false && $has( $tomorrowAllDay, 'events-when--past' ) === false && $has( $tomorrowAllDay, 'events-when--today' ) === false );
foreach( [ 'today-all-day', 'yesterday-all-day', 'tomorrow-all-day' ] as $slug )
	\Nino\Elements::deleteElement( $appData, '/events/'. $slug, 'de_DE' );
check( '...an end that names no field, or a field without a moment in it, leaves the start alone', eventsChip( $appData, '2026-12-24T18:00', 'start end="nowhere"' )?->textContent !== null && str_contains( (string) eventsChip( $appData, '2026-12-24T18:00', 'start end="nowhere"' )?->textContent, '-20:00' ) === false
	&& str_contains( (string) eventsChip( $appData, '2026-12-24T18:00', 'start end="place"' )?->textContent, '-' ) === false && str_contains( (string) eventsChip( $appData, '2026-12-24T18:00', 'start' )?->textContent, '20:00' ) === false );
check( '...and an end the markup writes cannot break out of it - a field name that is none is read as none', eventsChip( $appData, '2026-12-24T18:00', 'start end="a b&quot;"' ) !== null );

\Nino\Elements::insertElement( $appData, '/events/backwards', [ 'title' => 'Zurück', 'start' => '2026-12-30T10:00', 'end' => '2026-12-29T10:00' ], 'de_DE' );
$backwards = eventsChip( $appData, '2026-12-30T10:00' );
check( '...an end before the start is no end: the start alone, no range and no second day', $backwards !== null && str_contains( $backwards->textContent, ' bis ' ) === false && str_contains( $backwards->textContent, '29' ) === false && str_contains( $backwards->textContent, '30' ) === true && str_ends_with( $backwards->textContent, '10:00' ) === true );
\Nino\Elements::deleteElement( $appData, '/events/backwards', 'de_DE' );

$date = eventsChip( $appData, '2026-12-27T10:00', 'start end="end" format="date"' );
check( 'format="date" is the day alone, the two of a range with bis: the datetime is the day', $date !== null && $date->getAttribute( 'datetime' ) === '2026-12-27' && str_contains( $date->textContent, ':' ) === false && str_contains( $date->textContent, ' bis ' ) === true && str_contains( $date->textContent, '28' ) === true );
check( '...a range inside one day is that day, once', str_contains( (string) eventsChip( $appData, '2026-12-24T18:00', 'start end="end" format="date"' )?->textContent, ' bis ' ) === false && eventsChip( $appData, '2026-12-24T18:00', 'start end="end" format="date"' )?->getAttribute( 'datetime' ) === '2026-12-24' );
$time = eventsChip( $appData, '2026-12-24T18:00', 'start end="end" format="time"' );
check( 'format="time" is the time alone: 18:00-20:00 with the start as its datetime', $time !== null && $time->textContent === '18:00-20:00' && $time->getAttribute( 'datetime' ) === '18:00' && eventsChip( $appData, '2026-12-24T18:00', 'start format="time"' )?->textContent === '18:00' );
check( '...a format that is none is datetime', eventsChip( $appData, '2026-12-24T18:00', 'start format="week"' )?->getAttribute( 'datetime' ) === '2026-12-24T18:00' );

$fixed = eventsRender( $appData, '[event-when text="2026-12-24T18:30" format="datetime"][event-when text="2026-12-24T18:30" format="date" class="own"][event-when text="2026-12-24T18:30" format="time"][event-when text="nonsense"][event-when text="2027-02-30T10:00"][event-when text=""]' );
$chips = eventsFind( $fixed, '//time' );
check( 'outside a stack the text="" is the moment: three chips, the datetime of each exactly as its format says, and nothing for what is no moment', count( $chips ) === 3 && $chips[0]->getAttribute( 'datetime' ) === '2026-12-24T18:30' && $chips[1]->getAttribute( 'datetime' ) === '2026-12-24'
	&& $chips[2]->getAttribute( 'datetime' ) === '18:30' && $chips[2]->textContent === '18:30' && in_array( 'own', explode( ' ', $chips[1]->getAttribute( 'class' ) ), true ) === true );
check( '...a key of the Text panel can be the moment', ( static function() use ( &$appData ): bool {
	\Nino\Html::addFills( $appData, [ '[[/project/website/event/start]]' => '2026-12-31T22:00' ], '*' );
	return eventsFind( eventsRender( $appData, '[event-when /project/website/event/start]' ), '//time' )[0]?->getAttribute( 'datetime' ) === '2026-12-31T22:00';
} )() );

$appData['./nino/locales/current'] = 'en_US';
$englishRunning = eventsChip( $appData, '2026-12-10T09:00' );
$englishLater = eventsChip( $appData, '2026-12-24T18:00' );
$englishTwo = eventsChip( $appData, '2026-12-27T10:00' );
check( 'in English the words are English, the datetime attribute is the same, and the numbers are the locale\'s: Today, 24 and 6:00-8:00 PM or 18:00', $englishRunning !== null && str_starts_with( $englishRunning->textContent, 'Today' ) === true && $englishRunning->getAttribute( 'datetime' ) === '2026-12-10T09:00'
	&& $englishLater !== null && str_contains( $englishLater->textContent, '24' ) === true && $englishLater->getAttribute( 'datetime' ) === '2026-12-24T18:00' && preg_match( '/(6:00|18:00).*(8:00|20:00)/u', $englishLater->textContent ) === 1
	&& eventsChip( $appData, '2026-12-11T10:00' ) !== null && str_starts_with( (string) eventsChip( $appData, '2026-12-11T10:00' )?->textContent, 'Tomorrow' ) === true );
check( '...a range over days says until', $englishTwo !== null && str_contains( $englishTwo->textContent, ' until ' ) === true && str_contains( $englishTwo->textContent, '27' ) === true && str_contains( $englishTwo->textContent, '28' ) === true );
$appData['./nino/locales/current'] = 'de_DE';

check( 'the fixed patterns, for the site where the intl extension is not: German 24.12.2026 and 18:00, en_US 12/24/2026 and 6:00 PM, other English 24/12/2026, any other language the ISO day',
	\Nino\Modules\Events::fixed( 'de_DE', new DateTimeImmutable( '2026-12-24 18:00' ), true ) === '24.12.2026' && \Nino\Modules\Events::fixed( 'de_DE', new DateTimeImmutable( '2026-12-24 18:00' ), false ) === '18:00'
	&& \Nino\Modules\Events::fixed( 'de_AT', new DateTimeImmutable( '2026-12-24 18:00' ), true ) === '24.12.2026'
	&& \Nino\Modules\Events::fixed( 'en_US', new DateTimeImmutable( '2026-12-24 18:00' ), true ) === '12/24/2026' && \Nino\Modules\Events::fixed( 'en_US', new DateTimeImmutable( '2026-12-24 18:00' ), false ) === '6:00 PM'
	&& \Nino\Modules\Events::fixed( 'en_GB', new DateTimeImmutable( '2026-12-24 18:00' ), true ) === '24/12/2026' && \Nino\Modules\Events::fixed( 'en_GB', new DateTimeImmutable( '2026-12-24 18:00' ), false ) === '18:00'
	&& \Nino\Modules\Events::fixed( 'fr_FR', new DateTimeImmutable( '2026-12-24 18:00' ), true ) === '2026-12-24' && \Nino\Modules\Events::fixed( 'fr_FR', new DateTimeImmutable( '2026-12-24 18:00' ), false ) === '18:00' );
$appData['./nino/locales/current'] = 'en_US';
$englishDay = \Nino\Modules\Events::pattern( $appData, new DateTimeImmutable( '2026-12-24 18:00' ), true );
$englishTime = \Nino\Modules\Events::pattern( $appData, new DateTimeImmutable( '2026-12-24 18:00' ), false );
$appData['./nino/locales/current'] = 'de_DE';
check( '...and the extension is used where it is loaded: the locale\'s own medium date, short time and the names of its months - Dec 24, 2026 in en_US - and the fixed pattern where it is not',
	extension_loaded( 'intl' ) === true ? ( str_contains( $englishDay, 'Dec' ) === true && str_contains( $englishDay, '24' ) === true && preg_match( '/^6:00\s?PM$/u', $englishTime ) === 1 ) : ( $englishDay === '12/24/2026' && $englishTime === '6:00 PM' ) );
check( '...a time of the German page is the German one: 18:00', \Nino\Modules\Events::pattern( $appData, new DateTimeImmutable( '2026-12-24 18:00' ), false ) === '18:00' && \Nino\Modules\Events::pattern( $appData, new DateTimeImmutable( '2026-12-24 18:00' ), true ) === '24.12.2026' );

// --- The link to one date -----------------------------------------------------------------

echo "\n[event-ics] - the link to the file of one date\n";

$links = eventsFind( eventsRender( $appData, '[event-ics text="soon"][event-ics text="soon" class="own"]' ), '//a' );
check( 'a link to /.events/<slug>.ics, an <a> with its class and the word of the unit, the class of the call added', count( $links ) === 2 && $links[0]->getAttribute( 'href' ) === '/.events/soon.ics' && $links[0]->getAttribute( 'class' ) === 'events-ics'
	&& $links[0]->textContent === 'Zum Kalender hinzufügen' && $links[1]->getAttribute( 'class' ) === 'events-ics own' && $links[0]->getAttribute( 'type' ) === 'text/calendar' );
check( '...in a cell the source is .slug or .uri, and the link carries the directory of the site', eventsList( $appData, 'limit="2"' ) === [ 'running', 'at-now' ]
	&& eventsSlugs( eventsRender( $appData, '[events limit="2"][event-ics .uri][/events]' ) ) === [ 'running', 'at-now' ] );
$appData['/nino/dir'] = '/shop';
check( '...under a directory the href starts with it', eventsFind( eventsRender( $appData, '[event-ics text="soon"]' ), '//a' )[0]?->getAttribute( 'href' ) === '/shop/.events/soon.ics' );
$appData['/nino/dir'] = '';
check( '...nothing for a date that is not there, a name that is no slug and a date whose start is no moment', eventsRender( $appData, '[event-ics text="nowhere"]' ) === '' && eventsRender( $appData, '[event-ics text="Soon"]' ) === '' && eventsRender( $appData, '[event-ics text="so on"]' ) === ''
	&& eventsRender( $appData, '[event-ics text="soon&quot; onclick=&quot;x"]' ) === '' && eventsRender( $appData, '[event-ics text="../soon"]' ) === '' && eventsRender( $appData, '[event-ics text="/other/soon"]' ) === '' && eventsRender( $appData, '[event-ics text="/events/soon/"]' ) === '' && eventsRender( $appData, '[event-ics text="broken"]' ) === '' && eventsRender( $appData, '[event-ics text=""]' ) === '' );
check( '...and nothing for a date of a group that this visitor is not in', eventsRender( $appData, '[event-ics text="board-meeting"]' ) === '' && eventsRender( $appData, '[event-ics text="strange"]' ) === '' );


// --- Without the Members feature - the dates of a group are for nobody ---------------------------

echo "\nWithout Members: the class is there to be found, the feature is not on\n";

check( 'Members is not listed among the modules that run, so the feature does not ask it', class_exists( '\\Nino\\Modules\\Members' ) === true && \Nino\Modules\Events::members( $appData ) === false && \Nino\Modules\Events::viewer( $appData ) === ''
	&& \Nino\Modules\Events::visible( $appData, '' ) === true && \Nino\Modules\Events::visible( $appData, 'board' ) === false );
check( '...a date of a group is in no stack, for anybody, in any scope', eventsList( $appData ) === $upcoming && in_array( 'board-meeting', eventsList( $appData, 'scope="all"' ), true ) === false && in_array( 'board-meeting', eventsList( $appData, 'limit="0" scope="all" order="desc"' ), true ) === false );
check( '...nor a link to it, nor the file of it', eventsRender( $appData, '[event-ics text="board-meeting"]' ) === '' && eventsAnswer( eventsServe( $appData, '/.events/board-meeting.ics' ) )[0] === 404 );
$publicFeed = eventsAnswer( eventsServe( $appData, '/.events.ics' ) );
check( '...nor in the feed, and a token is not looked up: the public feed', $publicFeed[0] === 200 && in_array( 'board-meeting', eventsUids( $publicFeed[4] ), true ) === false
	&& eventsAnswer( eventsServe( $appData, '/.events.ics?k='. str_repeat( 'a', 64 ) ) )[4] === $publicFeed[4] );
check( '...and [event-feed] is the link to the public feed, whoever looks', count( eventsFind( eventsRender( $appData, '[event-feed]' ), '//a[@class="events-feed" and @href="https://example.org/club/.events.ics"]' ) ) === 1 );

// --- With it - the group, the visitor and the member --------------------------------------------

echo "\nWith Members: what a visitor, a member of the group and a member of another group see\n";

check( 'Members is activated beside the feature', \Nino\Features::activate( $appData, 'members' ) === true && in_array( '\\Nino\\Modules\\Members', (array) $appData['/nino/modules'], true ) === true );
\Nino\Modules\Members::init( $appData );
$ada = \Nino\Modules\Members::add( $appData, 'ada@example.org', 'Ada', 'board' );
$bob = \Nino\Modules\Members::add( $appData, 'bob@example.org', 'Bob', 'staff' );
$eve = \Nino\Modules\Members::add( $appData, 'eve@example.org', 'Eve', '' );
check( 'three members: Ada of the board, Bob of the staff and Eve of no group', is_array( $ada ) && is_array( $bob ) && is_array( $eve ) && $ada['group'] === 'board' && $bob['group'] === 'staff' && $eve['group'] === ''
	&& \Nino\Modules\Events::members( $appData ) === true );

eventsVisitor();
check( 'a visitor sees what he saw before', eventsList( $appData ) === $upcoming && \Nino\Modules\Events::viewer( $appData ) === '' );

check( 'Ada signs in', eventsSignIn( $appData, 'ada@example.org' ) === true && \Nino\Modules\Events::viewer( $appData ) === 'board' );
check( '...and sees the dates of the board - Board typed with a capital is the group board - in their place by start, and not those of the staff or the date of a group that makes no slug',
	eventsList( $appData ) === [ 'running', 'at-now', 'soon', 'board-meeting', 'all-day', 'later', 'two-day' ] && eventsList( $appData, 'scope="all" limit="9"' ) === [ 'past-old', 'past-recent', 'ended-today', 'running', 'just-over', 'at-now', 'soon', 'board-meeting', 'all-day' ] );
check( '...limit counts what she sees: the third date is the one of her board', eventsList( $appData, 'limit="4"' ) === [ 'running', 'at-now', 'soon', 'board-meeting' ] && eventsList( $appData, 'order="desc" limit="2"' ) === [ 'two-day', 'later' ] );
check( '...the link to the file of the date of her group is hers, and the one of the staff\'s is nobody\'s of hers', count( eventsFind( eventsRender( $appData, '[event-ics text="board-meeting"]' ), '//a[@href="/.events/board-meeting.ics"]' ) ) === 1
	&& eventsRender( $appData, '[event-ics text="staff-party"]' ) === '' && eventsRender( $appData, '[event-ics text="strange"]' ) === '' );

check( 'Bob signs in and sees the staff\'s dates, and not the board\'s', eventsSignIn( $appData, 'bob@example.org' ) === true && eventsList( $appData ) === [ 'running', 'at-now', 'soon', 'staff-party', 'all-day', 'later', 'two-day' ]
	&& eventsRender( $appData, '[event-ics text="board-meeting"]' ) === '' && eventsRender( $appData, '[event-ics text="staff-party"]' ) !== '' );
check( 'Eve, who is in no group, sees what a visitor sees', eventsSignIn( $appData, 'eve@example.org' ) === true && eventsList( $appData ) === $upcoming && \Nino\Modules\Events::viewer( $appData ) === '' );
eventsVisitor();
check( 'and a visitor again sees the public ones', eventsList( $appData ) === $upcoming );

// --- The feed ----------------------------------------------------------------------------------

echo "\nGET /.events.ics - the feed\n";

$feed = eventsAnswer( eventsServe( $appData, '/.events.ics' ) );
$ics = $feed[4];
$lines = eventsLines( $ics );

check( 'the feed answers text/calendar in UTF-8, inline as events.ics, for a shared cache for an hour', $feed[0] === 200 && $feed[1] === 'text/calendar; charset=utf-8' && $feed[3] === 'inline; filename="events.ics"' && $feed[2] === 'public, max-age=3600' );
check( '...a VCALENDAR 2.0 whose PRODID is the name of the feature and has no version in it, with the name of the company for a name', array_slice( $lines, 0, 5 ) === [ 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Nino//Events//EN', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:The Club' ]
	&& end( $lines ) === 'END:VCALENDAR' && preg_match( '/\d/', $lines[2] ) === 0 );
check( '...every line ends in CRLF and none in a bare LF, and the file ends in one', str_ends_with( $ics, "\r\nEND:VCALENDAR\r\n" ) === true && preg_match( '/(?<!\r)\n/', $ics ) === 0 );
check( '...one VEVENT for each public date within the window - the dates of a group, the one beyond a year and the one without a start are not in it - by start', substr_count( $ics, "BEGIN:VEVENT\r\n" ) === 10 && substr_count( $ics, "END:VEVENT\r\n" ) === 10
	&& eventsUids( $ics ) === [ 'past-old', 'past-recent', 'ended-today', 'running', 'just-over', 'at-now', 'soon', 'all-day', 'later', 'two-day' ] );
check( '...the UID is the slug at the host of the website', array_filter( $lines, static fn( string $line ): bool => str_starts_with( $line, 'UID:' ) && str_ends_with( $line, '@example.org' ) === false ) === [] );

$running = eventsEvent( $ics, 'running' );
check( 'a date with an end is a moment and a moment in UTC - the site is on Berlin time, so 09:00 is 08:00', in_array( 'DTSTART:20261210T080000Z', $running, true ) && in_array( 'DTEND:20261210T170000Z', $running, true ) );
check( '...the stamp is the start, the title, the place and the address of the site are there', in_array( 'DTSTAMP:20261210T080000Z', $running, true ) && in_array( 'SUMMARY:Läuft', $running, true ) && in_array( 'LOCATION:Halle', $running, true ) && in_array( 'URL:https://example.org/club/programm', $running, true ) );
check( '...a date without a place or a text has no line for them', array_filter( eventsEvent( $ics, 'at-now' ), static fn( string $line ): bool => preg_match( '/^(LOCATION|DESCRIPTION|URL|DTEND)/', $line ) === 1 ) === [] );

$allDay = eventsEvent( $ics, 'all-day' );
check( 'a date at midnight with no end is a day: DATE values, the day after as the end, no moment', in_array( 'DTSTART;VALUE=DATE:20261220', $allDay, true ) && in_array( 'DTEND;VALUE=DATE:20261221', $allDay, true ) && array_filter( $allDay, static fn( string $line ): bool => str_starts_with( $line, 'DTSTART:' ) || str_starts_with( $line, 'DTEND:' ) ) === [] );

$later = eventsEvent( $ics, 'later' );
check( 'a title, a place and a text are escaped as RFC 5545 asks: the backslash, the semicolon and the comma with a backslash, a line break as \\n', in_array( 'SUMMARY:Feier\\, groß\; mit \\\\ Gästen', $later, true ) && in_array( 'LOCATION:Halle 1\\, Hof', $later, true )
	&& in_array( 'DESCRIPTION:Erste Zeile\\nZweite\\, Zeile\; ok & gut\\n- Punkt', $later, true ) );
check( '...the link is the address of the date, and no quote or entity is made of it', in_array( 'URL:https://example.org/feier?a=1&b=2', $later, true ) && in_array( 'DTSTART:20261224T170000Z', $later, true ) && in_array( 'DTEND:20261224T190000Z', $later, true ) );

$physical = explode( "\r\n", rtrim( $ics, "\r\n" ) );
$folded = array_filter( $physical, static fn( string $line ): bool => str_starts_with( $line, ' ' ) );
check( 'a line of more than 75 octets is folded: none is longer than 75 octets, the next begins with a space, no character is cut in the middle of its bytes', $folded !== [] && max( array_map( 'strlen', $physical ) ) <= 75 && array_filter( $physical, static fn( string $line ): bool => mb_check_encoding( $line, 'UTF-8' ) === false ) === []
	&& in_array( 'SUMMARY:'. str_replace( [ '\\', ';', ',' ], [ '\\\\', '\;', '\\,' ], $long ), eventsEvent( $ics, 'two-day' ), true ) );

\Nino\Elements::insertElement( $appData, '/events/summer', [ 'title' => 'Sommer', 'start' => '2027-07-01T14:00' ], 'de_DE' );
check( 'the conversion follows the timezone of the day: in the summer Berlin is two hours ahead of UTC', in_array( 'DTSTART:20270701T120000Z', eventsEvent( eventsAnswer( eventsServe( $appData, '/.events.ics' ) )[4], 'summer' ), true ) );
\Nino\Elements::deleteElement( $appData, '/events/summer', 'de_DE' );

// The zone the dates are read in is the setting's: a process that runs in UTC, the site in Berlin
date_default_timezone_set( 'UTC' );
foreach( [ 'tz-winter' => '2026-12-30T14:00', 'tz-summer' => '2027-07-01T14:00' ] as $slug => $start )
	\Nino\Elements::insertElement( $appData, '/events/'. $slug, [ 'title' => 'Zone', 'start' => $start ], 'de_DE' );
$zoned = static function( string $slug ) use ( &$appData ): array {
	return eventsEvent( eventsAnswer( eventsServe( $appData, '/.events/'. $slug. '.ics' ) )[4], $slug );
};
check( 'the setting timezone is "as the server is set" until it is changed, which is the zone php runs in: 14:00 in a process on UTC is 14:00Z, in winter and in summer', \Nino\Features::setting( $appData, 'events', 'timezone' ) === 'server' && \Nino\Modules\Events::zone( $appData )->getName() === 'UTC'
	&& in_array( 'DTSTART:20261230T140000Z', $zoned( 'tz-winter' ), true ) && in_array( 'DTSTART:20270701T140000Z', $zoned( 'tz-summer' ), true ) );
check( 'with the setting Europe/Berlin on a process on UTC 14:00 is 13:00Z in the winter and 12:00Z in the summer, and the clock and the chip are the zone\'s', \Nino\Features::saveSettings( $appData, 'events', [ 'timezone' => 'Europe/Berlin' ] ) === []
	&& in_array( 'DTSTART:20261230T130000Z', $zoned( 'tz-winter' ), true ) && in_array( 'DTSTART:20270701T120000Z', $zoned( 'tz-summer' ), true ) && \Nino\Modules\Events::zone( $appData )->getName() === 'Europe/Berlin'
	&& \Nino\Modules\Events::now( $appData )->format( 'H:i T' ) === '12:00 CET' && str_contains( (string) eventsChip( $appData, '2026-12-30T14:00' )?->textContent, '14:00' ) === true );
$refused = \Nino\Features::saveSettings( $appData, 'events', [ 'timezone' => 'Mars/Base' ] );
check( 'a name that is no option of the setting is refused by the settings validation, and changes nothing', isset( $refused['timezone'] ) === true && \Nino\Features::setting( $appData, 'events', 'timezone' ) === 'Europe/Berlin' );
check( '...the options are "as the server is set" and every zone php knows', array_keys( $manifest['settings']['timezone']['options'] ) === array_merge( [ 'server' ], DateTimeZone::listIdentifiers() ) && $manifest['settings']['timezone']['required'] === true && $manifest['settings']['timezone']['default'] === 'server' && $manifest['settings']['timezone']['type'] === 'select' );
\Nino\Features::saveSettings( $appData, 'events', [ 'timezone' => 'server' ] );
date_default_timezone_set( 'Europe/Berlin' );
foreach( [ 'tz-winter', 'tz-summer' ] as $slug )
	\Nino\Elements::deleteElement( $appData, '/events/'. $slug, 'de_DE' );

// The feed reaches back a year where the setting past says 0; the stack reaches back as far as there are dates
foreach( [ 'old-feed' => '2025-11-05T10:00', 'mid-feed' => '2026-02-13T10:00' ] as $slug => $start )
	\Nino\Elements::insertElement( $appData, '/events/'. $slug, [ 'title' => 'Alt', 'start' => $start ], 'de_DE' );
$reach = eventsUids( eventsAnswer( eventsServe( $appData, '/.events.ics' ) )[4] );
check( 'the feed reads 0 days back as a year: a date 300 days ago is in it and one 400 days ago is not - and the stack, where 0 is all of them, lists both', in_array( 'mid-feed', $reach, true ) === true && in_array( 'old-feed', $reach, true ) === false
	&& array_slice( eventsList( $appData, 'scope="past"' ), -2 ) === [ 'mid-feed', 'old-feed' ] );
\Nino\Features::saveSettings( $appData, 'events', [ 'past' => '500' ] );
check( '...and a setting of its own is the feed\'s too: 500 days takes in the older one', in_array( 'old-feed', eventsUids( eventsAnswer( eventsServe( $appData, '/.events.ics' ) )[4] ), true ) === true );
\Nino\Features::saveSettings( $appData, 'events', [ 'past' => '0' ] );
foreach( [ 'old-feed', 'mid-feed' ] as $slug )
	\Nino\Elements::deleteElement( $appData, '/events/'. $slug, 'de_DE' );

// What an editor types can be nothing a calendar may carry: an address that runs a script, one that leaves for another site without a scheme, a title that tries to start a line of its own
foreach( [ 'js-link' => 'javascript:alert(1)', 'rel-link' => '//evil.example/x', 'data-link' => 'data:text/html,x', 'mail-link' => 'mailto:a@example.org', 'good-link' => 'HTTPS://example.org/ok' ] as $slug => $address )
	\Nino\Elements::insertElement( $appData, '/events/'. $slug, [ 'title' => "Line\r\nATTENDEE:evil\nEND:VEVENT", 'start' => '2026-12-30T10:00', 'link' => $address, 'place' => "A\rB" ], 'de_DE' );
$linked = [];
foreach( [ 'js-link', 'rel-link', 'data-link', 'mail-link', 'good-link' ] as $slug )
	$linked[$slug] = eventsEvent( eventsAnswer( eventsServe( $appData, '/.events/'. $slug. '.ics' ) )[4], $slug );
check( 'an address is a link in the file only if it is http or https - a script, a data address, a mail address and one without a scheme are none - and a site-relative one has the address of the site',
	array_filter( array_slice( $linked, 0, 4 ), static fn( array $lines ): bool => array_filter( $lines, static fn( string $line ): bool => str_starts_with( $line, 'URL:' ) ) !== [] ) === [] && in_array( 'URL:HTTPS://example.org/ok', $linked['good-link'], true ) );
check( '...a title with a line break in it is one line of the file, as are the place and the rest: nothing an editor types starts a line of its own', in_array( 'SUMMARY:Line\\nATTENDEE\\:evil\\nEND\\:VEVENT', $linked['js-link'], true ) === false
	&& in_array( 'SUMMARY:Line\\nATTENDEE:evil\\nEND:VEVENT', $linked['js-link'], true ) === true && in_array( 'LOCATION:A\\nB', $linked['js-link'], true ) === true && array_filter( $linked['js-link'], static fn( string $line ): bool => str_starts_with( $line, 'ATTENDEE' ) ) === [] && count( $linked['js-link'] ) === 7 );
foreach( array_keys( $linked ) as $slug )
	\Nino\Elements::deleteElement( $appData, '/events/'. $slug, 'de_DE' );

$appData['./nino/locales/current'] = 'en_US';
$english = eventsAnswer( eventsServe( $appData, '/.events.ics' ) )[4];
check( 'the words are the page\'s language\'s, and what that language has none of is the native one\'s', in_array( 'SUMMARY:Soon', eventsEvent( $english, 'soon' ), true ) && in_array( 'LOCATION:The hall', eventsEvent( $english, 'soon' ), true ) && in_array( 'SUMMARY:Frühstück', eventsEvent( $english, 'ended-today' ), true ) );
$appData['./nino/locales/current'] = 'de_DE';

// The settings: the window and the name of the calendar and the switch of the feed
\Nino\Elements::insertElement( $appData, '/events/long-run', [ 'title' => 'Lang', 'start' => '2026-10-01T10:00', 'end' => '2026-12-05T18:00' ], 'de_DE' );
$saved = \Nino\Features::saveSettings( $appData, 'events', [ 'ahead' => '730', 'past' => '30', 'name' => 'Club, Kalender; 1' ] );
$windowed = eventsAnswer( eventsServe( $appData, '/.events.ics' ) )[4];
check( 'the settings are the window of the feed: 730 days ahead take in the date more than a year away, 30 days back leave out the old one but not a long one that ended within them - and the name is the calendar\'s, escaped', $saved === [] && eventsUids( $windowed ) === [ 'long-run', 'past-recent', 'ended-today', 'running', 'just-over', 'at-now', 'soon', 'all-day', 'later', 'two-day', 'far' ]
	&& in_array( 'X-WR-CALNAME:Club\\, Kalender\; 1', eventsLines( $windowed ), true ) );
check( '...the window of the stack\'s upcoming dates is the same one, and the past ones go back as far', eventsList( $appData ) === [ 'running', 'at-now', 'soon', 'all-day', 'later', 'two-day', 'far' ] && eventsList( $appData, 'scope="past"' ) === [ 'just-over', 'ended-today', 'past-recent', 'long-run' ] );
\Nino\Elements::deleteElement( $appData, '/events/long-run', 'de_DE' );
check( '...a value out of its bounds is refused and changes nothing', is_array( \Nino\Features::saveSettings( $appData, 'events', [ 'ahead' => '0' ] ) ) && count( \Nino\Features::saveSettings( $appData, 'events', [ 'ahead' => '731' ] ) ) === 1 && count( \Nino\Features::saveSettings( $appData, 'events', [ 'past' => '3651' ] ) ) === 1
	&& \Nino\Features::setting( $appData, 'events', 'ahead' ) === 730 );
\Nino\Features::saveSettings( $appData, 'events', [ 'ahead' => '365', 'past' => '0', 'name' => '' ] );

check( 'the window is the days of the setting before the stack looks: with the defaults back the feed is as it was', eventsAnswer( eventsServe( $appData, '/.events.ics' ) )[4] === $ics && eventsList( $appData ) === $upcoming );

check( 'with the feed switched off /.events.ics is a 404 and [event-feed] shows nothing, and the file of a single date is still there', \Nino\Features::saveSettings( $appData, 'events', [ 'feed' => '0' ] ) === []
	&& eventsAnswer( eventsServe( $appData, '/.events.ics' ) )[0] === 404 && eventsAnswer( eventsServe( $appData, '/.events.ics' ) )[4] === 'Not Found' && eventsRender( $appData, '[event-feed]' ) === ''
	&& eventsAnswer( eventsServe( $appData, '/.events/soon.ics' ) )[0] === 200 && eventsAnswer( eventsServe( $appData, '/.events.ics?k='. str_repeat( 'a', 64 ) ) )[0] === 404 );
\Nino\Features::saveSettings( $appData, 'events', [ 'feed' => '1' ] );
check( '...and on again', eventsAnswer( eventsServe( $appData, '/.events.ics' ) )[0] === 200 );

// --- The single date -------------------------------------------------------------------------------

echo "\nGET /.events/<slug>.ics - one date\n";

$single = eventsAnswer( eventsServe( $appData, '/.events/soon.ics' ) );
check( 'a public date is a calendar file with its one VEVENT, inline under its own name, for a shared cache', $single[0] === 200 && $single[1] === 'text/calendar; charset=utf-8' && $single[3] === 'inline; filename="soon.ics"' && $single[2] === 'public, max-age=3600'
	&& substr_count( $single[4], 'BEGIN:VEVENT' ) === 1 && eventsUids( $single[4] ) === [ 'soon' ] && in_array( 'SUMMARY:Bald', eventsEvent( $single[4], 'soon' ), true ) && str_starts_with( $single[4], "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" ) === true );

$nothing = eventsAnswer( eventsServe( $appData, '/.events/nowhere.ics' ) );
check( 'a date that is not there is a plain 404 that no cache keeps', $nothing[0] === 404 && $nothing[4] === 'Not Found' && $nothing[1] === 'text/plain; charset=utf-8' && $nothing[2] === 'no-store' );

$refused = [];
foreach( [ '/.events/Soon.ics', '/.events/so_on.ics', '/.events/soon.txt', '/.events/soon', '/.events/soon.ics/more', '/.events/.ics', '/.events/soon.ICS', '/.events/broken.ics', '/.events/so%20on.ics', '/.events/..%2Fsoon.ics' ] as $uri )
	if( eventsAnswer( eventsServe( $appData, $uri ) ) !== $nothing )
		$refused[] = $uri;
check( 'a name that is no slug, another extension, more path and a date whose start is no moment are that very 404', $refused === [] );

$board = eventsServe( $appData, '/.events/board-meeting.ics' );
check( 'a date of a group is the same 404 as a date that is not there - for a visitor', eventsAnswer( $board ) === $nothing );
check( '...for a member of another group, and for a date of a group that makes no slug, whoever asks', eventsSignIn( $appData, 'bob@example.org' ) === true && eventsAnswer( eventsServe( $appData, '/.events/board-meeting.ics' ) ) === $nothing
	&& eventsSignIn( $appData, 'ada@example.org' ) === true && eventsAnswer( eventsServe( $appData, '/.events/strange.ics' ) ) === $nothing && eventsAnswer( eventsServe( $appData, '/.events/staff-party.ics' ) ) === $nothing );
$mine = eventsAnswer( eventsServe( $appData, '/.events/board-meeting.ics' ) );
check( '...a member of the group gets the file, and a cache keeps it from nobody else', $mine[0] === 200 && $mine[1] === 'text/calendar; charset=utf-8' && $mine[2] === 'private, no-store' && $mine[3] === 'inline; filename="board-meeting.ics"' && eventsUids( $mine[4] ) === [ 'board-meeting' ]
	&& in_array( 'SUMMARY:Vorstand', eventsEvent( $mine[4], 'board-meeting' ), true ) );
check( '...and a public date is the same file for her as for everyone', eventsAnswer( eventsServe( $appData, '/.events/soon.ics' ) ) === $single );

// --- The feed of a member ---------------------------------------------------------------------------

echo "\n[event-feed] and the token of a member\n";

eventsVisitor();
$link = eventsFind( eventsRender( $appData, '[event-feed]' ), '//a' )[0] ?? null;
check( 'a visitor gets the link to the public feed, with the word of the unit, the class and a type', $link !== null && $link->getAttribute( 'href' ) === 'https://example.org/club/.events.ics' && $link->getAttribute( 'class' ) === 'events-feed' && $link->textContent === 'Kalender abonnieren'
	&& $link->getAttribute( 'type' ) === 'text/calendar' && $link->getAttribute( 'rel' ) === 'nofollow' && eventsData( $appData ) === [] );
check( '...and the class of the call is added to it', in_array( 'own', explode( ' ', eventsFind( eventsRender( $appData, '[event-feed class="own"]' ), '//a' )[0]?->getAttribute( 'class' ) ?? '' ), true ) === true );

eventsSignIn( $appData, 'ada@example.org' );
$member = eventsFind( eventsRender( $appData, '[event-feed]' ), '//a' )[0] ?? null;
preg_match( '~^https://example\.org/club/\.events\.ics\?k=([a-f0-9]{64})$~', (string) $member?->getAttribute( 'href' ), $found );
$adaToken = $found[1] ?? '';
check( 'a member who is signed in gets the link with a token of 64 hex characters, the word for their calendar', $adaToken !== '' && $member?->textContent === 'Deinen Kalender abonnieren' && $member?->getAttribute( 'class' ) === 'events-feed' );
$again = eventsFind( eventsRender( $appData, '[event-feed]' ), '//a' )[0] ?? null;
check( '...the same token at every render after the first, so that a calendar app can be subscribed to it', $again?->getAttribute( 'href' ) === $member?->getAttribute( 'href' ) && count( eventsData( $appData )['tokens'] ?? [] ) === 1 );

$data = eventsData( $appData );
check( 'the file keeps the sha256 of the token with the id of the account, and a secret of 32 random bytes - and not the token', ( $data['format'] ?? 0 ) === 1 && preg_match( '/^[a-f0-9]{64}$/D', (string) ( $data['secret'] ?? '' ) ) === 1
	&& array_keys( $data['tokens'] ?? [] ) === [ hash( 'sha256', $adaToken ) ] && ( $data['tokens'][ hash( 'sha256', $adaToken ) ]['account'] ?? '' ) === $ada['id']
	&& str_contains( (string) file_get_contents( \Nino\Filesystem::path( $appData, '/data/events.php' ) ), $adaToken ) === false );

check( '...and the stamp of a token is the clock\'s, in UTC: noon in Berlin is 11:00Z', ( $data['tokens'][ hash( 'sha256', $adaToken ) ]['created'] ?? '' ) === '2026-12-10T11:00:00Z' );

eventsSignIn( $appData, 'bob@example.org' );
preg_match( '~\?k=([a-f0-9]{64})$~', (string) ( eventsFind( eventsRender( $appData, '[event-feed]' ), '//a' )[0]?->getAttribute( 'href' ) ?? '' ), $found );
$bobToken = $found[1] ?? '';
eventsSignIn( $appData, 'eve@example.org' );
preg_match( '~\?k=([a-f0-9]{64})$~', (string) ( eventsFind( eventsRender( $appData, '[event-feed]' ), '//a' )[0]?->getAttribute( 'href' ) ?? '' ), $found );
$eveToken = $found[1] ?? '';
check( 'each member has a token of their own, and the secret does not change', $bobToken !== '' && $eveToken !== '' && count( array_unique( [ $adaToken, $bobToken, $eveToken ] ) ) === 3 && count( eventsData( $appData )['tokens'] ?? [] ) === 3 && ( eventsData( $appData )['secret'] ?? '' ) === $data['secret'] );
check( '...and Tokens says who a token is for, and nobody for what is none', \Nino\Modules\Events\Tokens::account( $appData, $adaToken ) === $ada['id'] && \Nino\Modules\Events\Tokens::account( $appData, $bobToken ) === $bob['id']
	&& \Nino\Modules\Events\Tokens::account( $appData, str_repeat( '0', 64 ) ) === null && \Nino\Modules\Events\Tokens::account( $appData, 'short' ) === null && \Nino\Modules\Events\Tokens::account( $appData, strtoupper( $adaToken ) ) === null && \Nino\Modules\Events\Tokens::account( $appData, '' ) === null );

// Deleting the file ends every link at once: the secret is made anew, from random bytes, and the old tokens open nothing
$secret = $data['secret'];
\Nino\Filesystem::putFileContent( $appData, '/data/events.php', [] );
$newAda = \Nino\Modules\Events\Tokens::forAccount( $appData, $ada['id'] );
check( 'the file deleted, the next token is made from a new secret: another token for the same account, and the old links open nothing', preg_match( '/^[a-f0-9]{64}$/D', $newAda ) === 1 && $newAda !== $adaToken && (string) ( eventsData( $appData )['secret'] ?? '' ) !== $secret
	&& preg_match( '/^[a-f0-9]{64}$/D', (string) ( eventsData( $appData )['secret'] ?? '' ) ) === 1 && \Nino\Modules\Events\Tokens::account( $appData, $adaToken ) === null && \Nino\Modules\Events\Tokens::account( $appData, $newAda ) === $ada['id']
	&& (string) ( eventsData( $appData )['secret'] ?? '' ) !== str_repeat( '0', 64 ) );
$adaToken = $newAda;
$bobToken = \Nino\Modules\Events\Tokens::forAccount( $appData, $bob['id'] );
$eveToken = \Nino\Modules\Events\Tokens::forAccount( $appData, $eve['id'] );

eventsVisitor();
$adaFeed = eventsAnswer( eventsServe( $appData, '/.events.ics?k='. $adaToken ) );
$publicUids = [ 'past-old', 'past-recent', 'ended-today', 'running', 'just-over', 'at-now', 'soon', 'all-day', 'later', 'two-day' ];
check( 'the feed with a token has no session to go by - a calendar app has none - and is the public dates and those of the group of the account: Ada\'s are the board\'s, by start, and not for a shared cache',
	$adaFeed[0] === 200 && eventsUids( $adaFeed[4] ) === [ 'past-old', 'past-recent', 'ended-today', 'running', 'just-over', 'at-now', 'soon', 'board-meeting', 'all-day', 'later', 'two-day' ] && $adaFeed[2] === 'private, max-age=3600' && $adaFeed[1] === 'text/calendar; charset=utf-8' && $adaFeed[3] === 'inline; filename="events.ics"' );
check( '...Bob\'s are the staff\'s, Eve\'s - who has no group - the public ones', eventsUids( eventsAnswer( eventsServe( $appData, '/.events.ics?k='. $bobToken ) )[4] ) === [ 'past-old', 'past-recent', 'ended-today', 'running', 'just-over', 'at-now', 'soon', 'staff-party', 'all-day', 'later', 'two-day' ]
	&& eventsUids( eventsAnswer( eventsServe( $appData, '/.events.ics?k='. $eveToken ) )[4] ) === $publicUids );
check( '...a date of a group that makes no slug is in nobody\'s feed', in_array( 'strange', eventsUids( $adaFeed[4] ), true ) === false && in_array( 'strange', eventsUids( eventsAnswer( eventsServe( $appData, '/.events.ics?k='. $eveToken ) )[4] ), true ) === false );

$publicBody = eventsAnswer( eventsServe( $appData, '/.events.ics' ) );
$bad = [];
ninoWarnings();
foreach( [ '?k='. str_repeat( 'a', 64 ), '?k=abc', '?k=', '?k[]='. $adaToken, '?k='. strtoupper( $adaToken ), '?k='. $adaToken. 'x', '?k=%20'. $adaToken, '?other='. $adaToken ] as $query )
	if( eventsAnswer( eventsServe( $appData, '/.events.ics'. $query ) ) !== $publicBody )
		$bad[] = $query;
check( 'a token that is none, that nobody holds or that is not written as it was made is the public feed - no word says that it was wrong, and not a warning of php', $bad === [] && $publicBody[2] === 'public, max-age=3600' && ninoWarnings() === [] );

\Nino\Modules\Members::change( $appData, $bob['id'], [ 'active' => false ] );
check( '...the token of an account that is switched off is the public feed', eventsAnswer( eventsServe( $appData, '/.events.ics?k='. $bobToken ) ) === $publicBody );
\Nino\Modules\Members::change( $appData, $bob['id'], [ 'active' => true ] );
\Nino\Modules\Members::change( $appData, $ada['id'], [ 'group' => 'staff' ] );
check( '...and the group is the account\'s at the moment the feed is read: Ada moved to the staff, her link brings theirs', in_array( 'staff-party', eventsUids( eventsAnswer( eventsServe( $appData, '/.events.ics?k='. $adaToken ) )[4] ), true ) === true && in_array( 'board-meeting', eventsUids( eventsAnswer( eventsServe( $appData, '/.events.ics?k='. $adaToken ) )[4] ), true ) === false );
\Nino\Modules\Members::change( $appData, $ada['id'], [ 'group' => 'board' ] );

$modules = $appData['/nino/modules'];
$appData['/nino/modules'] = array_values( array_diff( $modules, [ '\\Nino\\Modules\\Members' ] ) );
check( 'with the Members feature switched off the token is not looked up, the dates of the board are shown to nobody and the member\'s link is the public one',
	eventsAnswer( eventsServe( $appData, '/.events.ics?k='. $adaToken ) ) === $publicBody && eventsList( $appData, 'scope="all"' ) === array_values( array_diff( eventsList( $appData, 'scope="all"' ), [ 'board-meeting' ] ) ) && in_array( 'board-meeting', eventsList( $appData, 'scope="all"' ), true ) === false
	&& eventsAnswer( eventsServe( $appData, '/.events/board-meeting.ics' ) )[0] === 404 && str_contains( eventsRender( $appData, '[event-feed]' ), '?k=' ) === false );
$appData['/nino/modules'] = $modules;

check( 'an account that is deleted has the public feed, and no link: its token stays in the file and opens nothing', \Nino\Modules\Members::remove( $appData, $ada['id'] ) === 'ok' && eventsAnswer( eventsServe( $appData, '/.events.ics?k='. $adaToken ) ) === $publicBody
	&& eventsList( $appData ) === $upcoming );

$appData['/nino/dir'] = '/shop';
\Nino\Html::addFills( $appData, [ '[[/project/website/general/url]]' => '' ], '*' );
check( 'a site without an address has no absolute link: the feed link is the path, under the directory of the project - and a date\'s UID is its slug alone', eventsFind( eventsRender( $appData, '[event-feed]' ), '//a' )[0]?->getAttribute( 'href' ) === '/shop/.events.ics'
	&& in_array( 'UID:soon', eventsLines( eventsAnswer( eventsServe( $appData, '/.events.ics' ) )[4] ), true ) );
$appData['/nino/dir'] = '';
\Nino\Html::addFills( $appData, [ '[[/project/website/general/url]]' => 'example.org/club' ], '*' );


// --- Without the Members feature at all ------------------------------------------------------------------

echo "\nA project where the class of Members does not exist\n";

/*	One process of its own, with a features directory that holds this feature and nothing else: class_exists() then answers false
	for real, which no test in a process that has all the features can make it do	*/
$alone = ninoSandboxDir( $appData ). '/alone';
mkdir( $alone, 0755, true );
\Nino\Features::copyTree( $dir, $alone. '/Events' );
$output = [];
exec( 'NINO_ROOT='. escapeshellarg( $root ). ' NINO_EVENTS_FEATURES='. escapeshellarg( $alone ). ' '. escapeshellarg( PHP_BINARY ). ' '. escapeshellarg( __DIR__. '/events-alone.php' ). ' 2>&1', $output );
$seen = json_decode( (string) end( $output ), true );

check( 'the process runs the feature in a project that has no Members', is_array( $seen ) && $seen['activated'] === true && $seen['class'] === false );
check( '...the feature does not ask for what is not there: nobody is in a group, and a date of a group is in no stack, no feed and no file, and has no link', is_array( $seen ) && $seen['members'] === false && $seen['viewer'] === '' && $seen['slugs'] === [ 'open-house' ]
	&& $seen['uids'] === [ 'open-house' ] && $seen['single'] === 404 && $seen['public'] === 200 );
check( '...and the link to the feed is the public one, and no file of tokens is made', is_array( $seen ) && $seen['link'] === 'https://example.org/club/.events.ics' && $seen['tokens'] === false );

// --- The calendar file itself ----------------------------------------------------------------------------------------

echo "\nEvents\\Ics - the format\n";

$ics = '\\Nino\\Modules\\Events\\Ics';

check( 'a TEXT value escapes the backslash first, then the semicolon, the comma and every kind of line break as \\n', $ics::text( "a\\b;c,d\ne\r\nf\rg" ) === 'a\\\\b\;c\\,d\\ne\\nf\\ng' && $ics::text( 'plain' ) === 'plain' );
check( '...a control character is left out, a byte that is no UTF-8 is replaced', $ics::text( "a\x00b\x07c\x7Fd" ) === 'abcd' && mb_check_encoding( $ics::text( "a\xFFb" ), 'UTF-8' ) === true && str_starts_with( $ics::text( "a\xFFb" ), 'a' ) === true );
check( 'a line of 75 octets is not folded, a line of 76 is, the next line begins with one space which counts', $ics::fold( str_repeat( 'a', 75 ) ) === str_repeat( 'a', 75 ) && $ics::fold( str_repeat( 'a', 76 ) ) === str_repeat( 'a', 75 ). "\r\n a"
	&& $ics::fold( str_repeat( 'a', 149 ) ) === str_repeat( 'a', 75 ). "\r\n ". str_repeat( 'a', 74 ) && $ics::fold( str_repeat( 'a', 150 ) ) === str_repeat( 'a', 75 ). "\r\n ". str_repeat( 'a', 74 ). "\r\n a" );
$cut = [ str_repeat( 'ä', 60 ), str_repeat( 'a', 74 ). 'ä'. str_repeat( 'b', 10 ), str_repeat( '😀', 40 ), 'SUMMARY:'. str_repeat( '日本語', 30 ) ];
$cutProblems = [];
foreach( $cut as $line ) {
	$parts = explode( "\r\n", $ics::fold( $line ) );
	if( max( array_map( 'strlen', $parts ) ) > 75 || array_filter( $parts, static fn( string $part ): bool => mb_check_encoding( $part, 'UTF-8' ) === false ) !== [] || str_replace( "\r\n ", '', $ics::fold( $line ) ) !== $line || array_filter( array_slice( $parts, 1 ), static fn( string $part ): bool => $part[0] !== ' ' ) !== [] )
		$cutProblems[] = mb_substr( $line, 0, 12 );
}
check( '...a character of two, three or four bytes is never cut, and the lines joined are the line', $cutProblems === [] );

$utc = new DateTimeZone( 'UTC' );
$berlin = new DateTimeZone( 'Europe/Berlin' );
$event = [ 'uid' => 'x@example.org', 'stamp' => new DateTimeImmutable( '2026-07-01 14:00', $berlin ), 'start' => new DateTimeImmutable( '2026-07-01 14:00', $berlin ), 'end' => new DateTimeImmutable( '2026-07-01 16:30', $berlin ), 'allDay' => false,
	'summary' => 'S, 1', 'location' => 'L; 2', 'description' => "D\n3", 'url' => "https://example.org/a b\r\n" ];

check( 'the UID is a TEXT value too', $ics::event( [ 'uid' => 'a,b;c\\d@example.org' ] + $event )[1] === 'UID:a\\,b\\;c\\\\d@example.org' );
check( 'an event is its lines: the moments in UTC with the Z, the text escaped, the address as it is without a space or a line break in it', $ics::event( $event ) === [ 'BEGIN:VEVENT', 'UID:x@example.org', 'DTSTAMP:20260701T120000Z', 'DTSTART:20260701T120000Z', 'DTEND:20260701T143000Z', 'SUMMARY:S\\, 1', 'LOCATION:L\; 2', 'DESCRIPTION:D\\n3', 'URL:https://example.org/ab', 'END:VEVENT' ] );
check( '...without an end there is no DTEND, and a value that is empty has no line', $ics::event( [ 'end' => null, 'summary' => '', 'location' => '', 'description' => '', 'url' => '' ] + $event ) === [ 'BEGIN:VEVENT', 'UID:x@example.org', 'DTSTAMP:20260701T120000Z', 'DTSTART:20260701T120000Z', 'END:VEVENT' ] );
check( '...a day is DATE values in the zone of the day - the end is the day after, the 31st of December is the 1st of January - and not converted', $ics::event( [ 'allDay' => true, 'start' => new DateTimeImmutable( '2026-12-31 00:00', $berlin ), 'end' => null ] + $event )[3] === 'DTSTART;VALUE=DATE:20261231'
	&& $ics::event( [ 'allDay' => true, 'start' => new DateTimeImmutable( '2026-12-31 00:00', $berlin ), 'end' => null ] + $event )[4] === 'DTEND;VALUE=DATE:20270101' );
check( 'a calendar without a name has no X-WR-CALNAME, and with events is each one between its BEGIN and END, every line with its CRLF', $ics::calendar( '', [] ) === "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Nino//Events//EN\r\nCALSCALE:GREGORIAN\r\nEND:VCALENDAR\r\n"
	&& str_contains( $ics::calendar( 'N', [ $event, $event ] ), "X-WR-CALNAME:N\r\nBEGIN:VEVENT\r\n" ) && substr_count( $ics::calendar( 'N', [ $event, $event ] ), "BEGIN:VEVENT\r\n" ) === 2 && str_ends_with( $ics::calendar( 'N', [ $event ] ), "END:VEVENT\r\nEND:VCALENDAR\r\n" ) );
check( '...a long line in it is folded', str_contains( $ics::calendar( str_repeat( 'n', 100 ), [] ), "X-WR-CALNAME:". str_repeat( 'n', 62 ). "\r\n ". str_repeat( 'n', 38 ). "\r\n" ) );

// --- The source of the feature -------------------------------------------------------------------------------------------

echo "\nThe source of the feature\n";

$sources = [ $dir. '/Events.php', $dir. '/Ics/Ics.php', $dir. '/Tokens/Tokens.php' ];
$offences = [];

foreach( $sources as $source )
	foreach( token_get_all( (string) file_get_contents( $source ) ) as $token )
		if( is_array( $token ) === true && in_array( $token[0], [ T_ECHO, T_EXIT, T_PRINT, T_INLINE_HTML ], true ) === true )
			$offences[] = basename( $source ). ': '. token_name( $token[0] );

$code = implode( "\n", array_map( static fn( string $source ): string => (string) file_get_contents( $source ), $sources ) );

check( 'the classes write no output: no echo, print, exit or inline html - and call no header() or http_response_code()', count( $sources ) === 3 && $offences === [] && preg_match( '/\b(?:header|http_response_code|readfile|fpassthru)\s*\(/', $code ) === 0 );
check( '...no htmlspecialchars() without the flags the rules ask for, no network, no eval', preg_match_all( '/htmlspecialchars\(/', $code ) === preg_match_all( '/htmlspecialchars\([^;]*ENT_QUOTES \| ENT_SUBSTITUTE, \'UTF-8\'/', $code ) && preg_match( '/\b(?:curl_|fsockopen|file_get_contents\(\s*[\'"]https?|eval\()/', $code ) === 0 );
check( '...nothing of the feature names a path of the machine it was written on, and it brings no script', preg_match( '~/home/|/Users/|C:\\\\~', $code. (string) file_get_contents( $dir. '/feature.php' ) ) === 0 && glob( $dir. '/{assets,templates,design,install}/*.js', GLOB_BRACE ) === [] );

// --- The looks for the Design feature ----------------------------------------------------------------------------------------

echo "\ndesign/ - the look, the block of the preview and the stylesheet\n";

$design = $dir. '/design';
$sheet = static function( string $css ): array {

	preg_match( '#^\s*/\*(.*?)\*/#s', $css, $comment );
	$tags = [];

	foreach( (array) preg_split( '/\R/', $comment[1] ?? '' ) as $line )
		if( preg_match( '/^\s*\*?\s*(@[a-z]+(?:-de)?)\s+(\S.*?)\s*$/', (string) $line, $found ) === 1 )
			$tags[ $found[1] ][] = $found[2];

	$rules = [];
	preg_match_all( '/([^{}]+)\{([^{}]*)\}/', (string) preg_replace( '#/\*.*?\*/#s', '', $css ), $found, PREG_SET_ORDER );

	foreach( $found as $rule )
		$rules[ trim( (string) preg_replace( '/\s+/', ' ', $rule[1] ) ) ][] = $rule[2];

	return [ 'tags' => $tags, 'rules' => $rules ];
};
$declarations = static function( array $bodies ): array {

	$declared = [];

	foreach( $bodies as $body ) {
		preg_match_all( '/(--[a-z0-9-]+)\s*:\s*([^;]+);/', $body, $found, PREG_SET_ORDER );

		foreach( $found as $declaration )
			$declared[$declaration[1]] = trim( (string) preg_replace( '/\s+/', ' ', $declaration[2] ) );
	}

	return $declared;
};

$regular = $sheet( (string) file_get_contents( $design. '/regular.css' ) );
$base = $sheet( (string) file_get_contents( $dir. '/assets/events.css' ) );
$ladders = $declarations( $regular['rules'][':where(:root)'] ?? [] );
$defaults = $declarations( $base['rules'][':where(:root)'] ?? [] );

check( 'the feature brings one look, regular - where a feature starts from - with a name and a description in both languages', array_map( 'basename', glob( $design. '/*.css' ) ?: [] ) === [ 'regular.css' ] && ( $regular['tags']['@name'] ?? [] ) === [ 'Regular' ] && ( $regular['tags']['@name-de'] ?? [] ) === [ 'Regulär' ]
	&& count( $regular['tags']['@description'] ?? [] ) === 1 && count( $regular['tags']['@description-de'] ?? [] ) === 1 );
check( '...three knobs: the ground of the chip (a ground of the palette, alt), the space inside it and its corners (scales, on the tab of the surfaces), each with its German label',
	$regular['tags']['@knob'] === [ 'ground	ground	colours	Ground of the chip	= alt', 'space	scale	surfaces	Space inside the chip', 'corners	scale	surfaces	Corners of the chip' ] || array_map( static fn( string $line ): string => (string) preg_replace( '/\s+/', ' ', $line ), $regular['tags']['@knob'] ) === [ 'ground ground colours Ground of the chip = alt', 'space scale surfaces Space inside the chip', 'corners scale surfaces Corners of the chip' ]
	&& count( $regular['tags']['@knob-de'] ?? [] ) === 3 );
$missing = [];
foreach( [ 'space', 'corners' ] as $knob )
	foreach( [ 'least', 'less', 'default', 'more', 'most' ] as $step )
		if( isset( $ladders[ '--events-'. $knob. '--'. $step ] ) === false )
			$missing[] = $knob. ' '. $step;
if( $missing !== [] )
	echo '  note - the ladders miss '. implode( ', ', $missing ). "\n";
check( '...a ladder of five for each scale, --events-<knob>--least to --most, in :where(:root)', $missing === [] && count( $ladders ) === 10 );
check( '...the middle of each ladder is the default events.css has, so that the chip under the look is the chip without it', ( $ladders['--events-space--default'] ?? 'a' ) === ( $defaults['--events-space'] ?? 'b' ) && ( $ladders['--events-corners--default'] ?? 'a' ) === ( $defaults['--events-corners'] ?? 'b' ) );
check( '...and the look writes no rule: the layout is events.css\'s, the look sets the ladders', array_keys( $regular['rules'] ) === [ ':where(:root)' ] );

$css = (string) file_get_contents( $dir. '/assets/events.css' );
$selectors = array_keys( $base['rules'] );
check( 'events.css declares the properties the look moves in :where(:root) - the ground with its parts, the space and the corners - and never in a bare :root', array_keys( $defaults ) === [ '--events-ground', '--events-ground-on', '--events-ground-link', '--events-ground-border', '--events-ground-focus', '--events-space', '--events-corners' ]
	&& isset( $base['rules'][':root'] ) === false );
check( '...it styles the chip and the two links and nothing else, takes every colour from a token, and has no !important', array_diff( $selectors, [ ':where(:root)', '.events-when', '.events-when--today', '.events-when--past', '.events-ics, .events-feed', '.events-ics:hover, .events-feed:hover', '.events-ics:focus-visible, .events-feed:focus-visible' ] ) === []
	&& preg_match( '/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(/', (string) preg_replace( '#/\*.*?\*/#s', '', $css ) ) === 0 && str_contains( $css, '!important' ) === false );
check( '...a cell of a stack is a grid that stretches an inline box to its width: the chip and the two links start at the left', str_contains( implode( ' ', $base['rules']['.events-when'] ?? [] ), 'justify-self: start' ) === true && str_contains( implode( ' ', $base['rules']['.events-ics, .events-feed'] ?? [] ), 'justify-self: start' ) === true );
check( '...each default is a token of Nino.css, which exists there', array_filter( $defaults, static fn( string $value ): bool => preg_match( '/^var\(--[a-z0-9-]+\)$/', $value ) !== 1 ) === []
	&& array_filter( array_map( static fn( string $value ): string => substr( $value, 4, -1 ), $defaults ), static fn( string $token ): bool => str_contains( (string) file_get_contents( $root. '/_nino/Nino.css' ), "\t". $token. ':' ) === false ) === [] );

$block = (string) file_get_contents( $design. '/preview.tpl' );
preg_match_all( '/\bid="([^"]+)"/', $block, $ids );
check( 'the block of the preview is one grid row of three cells, each a chip, a title and the link to the file written out - with ids that start with the key of the feature, and no shortcode and no section',
	substr_count( $block, 'nino-grid-row' ) === 1 && preg_match_all( '/<div class="nino-grid-100 nino-grid-m-33[^"]*">/', $block ) === 3 && substr_count( $block, '<time class="events-when' ) === 3 && substr_count( $block, 'class="events-ics"' ) === 3
	&& count( eventsFind( $block, '//div[contains(@class, "nino-grid-m-33")]//time[contains(@class, "events-when")]/../following-sibling::p/following-sibling::p/a[@class="events-ics"]' ) ) === 3
	&& $ids[1] !== [] && array_filter( $ids[1], static fn( string $id ): bool => str_starts_with( $id, 'events-' ) === false ) === [] && count( array_unique( $ids[1] ) ) === count( $ids[1] )
	&& preg_match( '/\[[a-z][a-z0-9-]*[\s\]]/', (string) preg_replace( '/\[\[[^\]]*\]\]/', '', $block ) ) === 0 && str_contains( $block, '<section' ) === false );

$designSetup = '\\Nino\\Modules\\Design\\Setup';
$designPreview = '\\Nino\\Modules\\Design\\Preview';
$designCompiler = '\\Nino\\Modules\\Design\\Compiler';

if( class_exists( $designSetup ) === false || class_exists( $designCompiler ) === false )
	echo "  note - there is no Design feature beside this one: the look is not run through it here, bin/check.sh runs it with it in the checkout\n";
else {
	$library = \Nino\Features::dir(). '/Design/library';
	$modulesKept = (array) $appData['/nino/modules'];
	$localeKept = $appData['./nino/locales/current'];

	/** Which of the two features are on: their classes are listed in /nino/modules, and what the kernel and the Design feature have read of the features is read again */
	$featuresOn = static function( array $names ) use ( &$appData, $modulesKept ): void {
		$appData['/nino/modules'] = array_merge( array_values( array_diff( $modulesKept, [ '\\Nino\\Modules\\Events', '\\Nino\\Modules\\Design' ] ) ), array_map( static fn( string $name ): string => '\\Nino\\Modules\\'. $name, $names ) );
		unset( $appData['./nino/features/all'], $appData['./nino/features/listed'], $appData['./design/features'] );
	};

	$compile = static function( array $raw, array &$notes = [] ) use ( &$appData, $designSetup, $designCompiler, $library ): string {
		$setup = $designSetup::normalize( $appData, $raw, $library, $notes );

		return $designCompiler::compile( $appData, $setup, $library, $notes );
	};

	ninoWarnings();
	$featuresOn( [ 'Events', 'Design' ] );
	$appData['./nino/locales/current'] = 'en_US';

	$notes = [];
	$scan = $designSetup::features( $appData, $notes );
	$feature = $scan['events'] ?? [];
	$catalogue = $feature['catalogue'] ?? [];

	check( 'with the Design feature on, the scan lists this feature by its one look, regular the one it has before anything is chosen, the anchor of its block - and has nothing to say of any file', isset( $scan['events'] ) === true && realpath( (string) $feature['dir'] ) === realpath( $dir )
		&& array_keys( $catalogue ) === [ 'regular' ] && $feature['default'] === 'regular' && $feature['anchor'] === 'feature-events' && $notes === [] );
	check( '...it publishes the three knobs with the kind and the tab of its head, the two scales each by a ladder of its own', array_map( static fn( array $knob ): array => [ $knob['kind'], $knob['tab'] ], $catalogue['regular']['knobs'] ?? [] ) === [ 'ground' => [ 'ground', 'colours' ], 'space' => [ 'scale', 'surfaces' ], 'corners' => [ 'scale', 'surfaces' ] ]
		&& ( $catalogue['regular']['knobs']['space']['ladder'] ?? [] ) === [ 'space' ] && ( $catalogue['regular']['knobs']['corners']['ladder'] ?? [] ) === [ 'corners' ] && ( $catalogue['regular']['knobs']['ground']['default'] ?? '' ) === 'alt' );

	$blockTold = [];
	$blockInside = $designSetup::previewOf( (string) $feature['dir'], 'events', $blockTold );
	$blocks = $designPreview::blocks( $appData );
	check( 'the block stands the check Design holds it to, and the preview makes the section of it, with the anchor the scan names', $blockInside === trim( $block ) && $blockTold === [] && isset( $blocks['events'] ) === true
		&& preg_match( '~^<section id="feature-events" class="nino-section" aria-label="[^"]+">\s*(.*?)\s*</section>$~s', $blocks['events'] ?? '', $section ) === 1 && str_contains( $section[1] ?? '', 'id="events-when-today"' ) === true );

	$featuresOn( [ 'Events', 'Design' ] );
	$withNone = $compile( [] );
	$featuresOn( [ 'Design' ] );
	$withOff = $compile( [] );
	$featuresOn( [ 'Events', 'Design' ] );
	check( 'with no look chosen the feature compiles nothing: the sheet of a project that has it on is the sheet of one that has it off, byte for byte', $withNone === $withOff && str_contains( $withNone, 'feature events' ) === false );

	$sheetNotes = [];
	$compiled = $compile( [ 'features' => [ 'events' => [ 'set' => 'regular' ] ] ], $sheetNotes );
	check( 'with the look chosen the sheet carries its section, its stylesheet as the file has it, the selection of the middle of each ladder and the ground the palette solves for alt', $sheetNotes === [] && preg_match( '~^/\* ==== \d+\. feature events: regular \(space default, corners default\) ==== \*/$~m', $compiled ) === 1
		&& str_contains( $compiled, rtrim( (string) file_get_contents( $design. '/regular.css' ), "\n" ) ) === true && str_contains( $compiled, "\n\t--events-space: var(". sprintf( $designSetup::KNOB_TOKEN, 'events', 'space', 'default' ). ");\n" ) === true
		&& str_contains( $compiled, "\n\t--events-corners: var(". sprintf( $designSetup::KNOB_TOKEN, 'events', 'corners', 'default' ). ");\n" ) === true && str_contains( $compiled, "\n\t--events-ground: var(--nino-alt);\n" ) === true );

	$bundled = $appData;
	$bundled['/nino/html/assets'] = [ '/.cache/style.css' => array_merge( (array) ( \Nino\AppData::DEFAULTS['/nino/html/assets']['/.cache/style.css'] ?? [] ), [ $designCompiler::TARGET, '/assets/style.css' ] ) ];
	\Nino\Features::registerAssets( $bundled );
	$bundle = $bundled['/nino/html/assets']['/.cache/style.css'];
	check( 'in a booted project the bundle lists events.css after the theme.css the Design feature writes its sheet to - the order the :where() of its defaults is for', in_array( '/features/Events/assets/events.css', $bundle, true ) === true
		&& array_search( '/features/Events/assets/events.css', $bundle, true ) > array_search( $designCompiler::TARGET, $bundle, true ) );

	$appData['/nino/modules'] = $modulesKept;
	$appData['./nino/locales/current'] = $localeKept;
	unset( $appData['./nino/features/all'], $appData['./nino/features/listed'], $appData['./design/features'] );
	ninoWarnings();
}

// --- Deactivation ----------------------------------------------------------------------------------------------------------------

echo "\nDeactivation\n";

$typeBefore = (string) file_get_contents( $typeFile );
$dataBefore = eventsData( $appData );
check( 'deactivation succeeds, nothing requires the feature', \Nino\Features::deactivate( $appData, 'events' ) === true );
check( '...the class is no longer listed, the routes were never written to config.php, and what the project has - the type, the dates, the words, the tokens - stays',
	in_array( '\\Nino\\Modules\\Events', (array) ( \Nino\Filesystem::getFileContent( $appData, '/config.php', [] )['/nino/modules'] ?? [] ), true ) === false
	&& array_filter( array_keys( (array) ( \Nino\Filesystem::getFileContent( $appData, '/config.php', [] )['/nino/http/routes'] ?? [] ) ), static fn( string $route ): bool => str_contains( $route, '.events' ) ) === []
	&& (string) file_get_contents( $typeFile ) === $typeBefore && count( (array) \Nino\Elements::queryElements( $appData, '/events', [], 'de_DE', [] ) ) === 15 && eventsData( $appData ) === $dataBefore
	&& ( \Nino\Filesystem::getFileContent( $appData, '/text/de_DE.php', [] )['[[/feature/events/when/today]]'] ?? '' ) === 'Heute' );
check( '...and the feature can be activated again, at the version it was', \Nino\Features::activate( $appData, 'events' ) === true && ( \Nino\Features::get( $appData, 'events' )['installed'] ?? '' ) === '1.0.0' );

ninoDone( $appData );
