<?php
declare(strict_types=1);

/**
 *	Nino
 *	events-alone.php	Not a test of its own - events-smoke.php runs it in a process of its own, with a features
 *										directory that holds the Events feature and no other, which is the one way to have the class of
 *										the Members feature not exist at all. It sets a project up, seeds a public date and a date of a
 *										group, and prints what a visitor can see as one line of json: whether the class is there, what
 *										the feature makes of that, the dates the stack lists, the feed and the file of the date of the
 *										group, and the link of [event-feed].
 *
 *										NINO_ROOT names the checkout, NINO_EVENTS_FEATURES the directory that holds Events.
 */

$root = (string) getenv( 'NINO_ROOT' );
define( 'NINO_FEATURES_DIR', (string) getenv( 'NINO_EVENTS_FEATURES' ) );
require $root. '/tests/harness.php';

date_default_timezone_set( 'Europe/Berlin' );

$appData = ninoSandbox( 'events-alone' );
$appData['/nino/dir'] = '';
$appData['./nino/locales/current'] = 'en_US';
\Nino\Html::addFills( $appData, [ '[[/nino/dir]]' => '', '[[/project/website/general/url]]' => 'example.org/club' ], '*' );
$appData['/nino/modules'] = [ '\\Nino\\Modules\\Components' ];
\Nino\AppData::writeContentData( $appData, [ '/nino/modules', '/nino/locales/available', '/nino/locales/native' ] );

$activated = \Nino\Features::activate( $appData, 'events' );
\Nino\Html::init( $appData );
\Nino\Modules::callModules( $appData, 'init' );

// A project whose config still lists Members, the class of which is gone: the entry says nothing about whether it can be asked
$appData['/nino/modules'][] = '\\Nino\\Modules\\Members';

$appData[\Nino\Modules\Events::CLOCK] = ( new DateTimeImmutable( '2026-12-10 12:00' ) )->getTimestamp();

\Nino\Elements::insertElement( $appData, '/events/open-house', [ 'title' => 'Open house', 'start' => '2026-12-12T10:00' ], 'en_US' );
\Nino\Elements::insertElement( $appData, '/events/board-meeting', [ 'title' => 'Board', 'start' => '2026-12-15T19:00', 'group' => 'board' ], 'en_US' );

$serve = static function( string $uri ) use ( &$appData ): array {
	$request = ninoVisit( $appData, $uri );
	\Nino\Http::response( $appData, $request );
	return [ $request['/nino/http/response']['statusCode'], (string) $request['/nino/http/response']['body'] ];
};

$list = \Nino\Html::renderHtml( $appData, '[events scope="all"][event-ics .slug][/events]' );
preg_match_all( '~href="/\.events/([a-z0-9-]+)\.ics"~', $list, $slugs );
preg_match_all( '~^UID:([a-z0-9-]+)@~m', $serve( '/.events.ics?k='. str_repeat( 'a', 64 ) )[1], $uids );
preg_match( '~href="([^"]+)"~', \Nino\Html::renderHtml( $appData, '[event-feed]' ), $feed );

$result = [
	'activated'	=> $activated === true,
	'class'			=> class_exists( '\\Nino\\Modules\\Members' ),
	'members'		=> \Nino\Modules\Events::members( $appData ),
	'viewer'		=> \Nino\Modules\Events::viewer( $appData ),
	'slugs'			=> $slugs[1],
	'uids'			=> $uids[1],
	'single'		=> $serve( '/.events/board-meeting.ics' )[0],
	'public'		=> $serve( '/.events/open-house.ics' )[0],
	'link'			=> $feed[1] ?? '',
	'tokens'		=> \Nino\Filesystem::fileExists( $appData, '/data/events.php' ),
];

\Nino\Filesystem::removeDir( ninoSandboxDir( $appData ) );

echo json_encode( $result ), "\n";
