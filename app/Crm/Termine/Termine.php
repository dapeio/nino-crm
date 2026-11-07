<?php
declare(strict_types=1);
/**
 *	Crm\Termine		What the owner has entered as dates, read from the elements of the type /events of the feature Events. The dates are made, changed and
 *								removed in the screens Termine and Termin of the surface and in the Elements panel (the surface drives the panel's own actions for the type,
 *								see Surface\Elements); this class only reads them, the way the feature reads them for the portal (Events::record()). A date belongs to a
 *								client by its group: the group of the client is its number as a slug (k-0003), the one its accounts have in Members, and a date with
 *								that group is for the members of that client and for nobody else. A date without a group is for everyone, a date with another group is
 *								one the owner made by hand for a group of Members. The type has no field that names a project, so a date is the client's and not a
 *								project's.
 *
 *								The feature may be off, or its type not there: missing() says so, and while it does, there are no dates.
 */
namespace Crm;

class Termine {

	// The element type of the feature
	public const string TYPE = '/events';
	private const string FILE = '/elements/events.php';

	// What the name of a date looks like: the slug the feature serves it under, or any name an element of the type has
	public const string ID = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/D';

	// How far back the list reaches for the dates that are over, and how far ahead it reads by default, in days
	public const int PAST = 30;
	public const int AHEAD = 60;

	/**
	 *	Whether there are no dates to read: the feature Events is not active, or its type is not there
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	bool
	 */
	public static function missing( array &$appData ): bool {

		return Crm::featureOn( $appData, 'events' ) === false || \Nino\Filesystem::fileExists( $appData, self::FILE ) === false;
	}

	/**
	 *	Now, in the timezone of the site, as the feature reads it
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	\DateTimeImmutable
	 */
	public static function now( array &$appData ): \DateTimeImmutable {

		return \Nino\Modules\Events::now( $appData );
	}

	/**
	 *	The dates that are coming and the dates that are over, each soonest first
	 *
	 *	@param		array 		&$appData
	 *	@param		string|null	$group				Only the dates with this group (k-0003); null for every date
	 *	@param		int				$ahead				How many days ahead a date may begin
	 *
	 *	@return 	array											upcoming (dates that are not over and begin within the days ahead, the soonest first) and past (those over within the last PAST days, the newest first), each date as shape() has it
	 */
	public static function list( array &$appData, ?string $group = null, int $ahead = self::AHEAD ): array {

		$now = self::now( $appData );
		$clients = self::clients( $appData );
		$upcoming = [];
		$past = [];

		foreach( self::read( $appData ) as $element ) {

			$record = \Nino\Modules\Events::record( $appData, $element );

			if( $record === null || ( $group !== null && $record['group'] !== $group ) )
				continue;

			if( \Nino\Modules\Events::selects( $record, 'upcoming', $now, $ahead, 0 ) === true )
				$upcoming[] = self::shape( $record, $clients, $now );
			elseif( \Nino\Modules\Events::selects( $record, 'past', $now, $ahead, self::PAST ) === true )
				$past[] = self::shape( $record, $clients, $now );
		}

		usort( $upcoming, static fn( array $a, array $b ): int => [ $a['start'], $a['id'] ] <=> [ $b['start'], $b['id'] ] );
		usort( $past, static fn( array $a, array $b ): int => [ $b['start'], $b['id'] ] <=> [ $a['start'], $a['id'] ] );

		return [ 'upcoming' => $upcoming, 'past' => $past ];
	}

	/**
	 *	One date with the words it is written in
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$id						The name of the element, the last part of its uri
	 *
	 *	@return 	array|null								As shape() has it, with text (as stored, the markup of the paragraphs) and link; null for none
	 */
	public static function get( array &$appData, string $id ): ?array {

		if( preg_match( self::ID, $id ) !== 1 || self::missing( $appData ) === true )
			return null;

		$element = \Nino\Elements::getElement( $appData, self::TYPE. '/'. $id, \Nino\Locales::getNativeLocale( $appData ), [] );

		if( is_array( $element ) === false || $element === [] )
			return null;

		$record = \Nino\Modules\Events::record( $appData, $element + [ '.uri' => self::TYPE. '/'. $id ] );

		if( $record === null )
			return null;

		return self::shape( $record, self::clients( $appData ), self::now( $appData ) ) + [ 'text' => $record['text'], 'link' => $record['link'] ];
	}

	/**
	 *	The date of a client that comes next
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$clientNumber	K-0003
	 *
	 *	@return 	array|null								As shape() has it; null where none is coming
	 */
	public static function next( array &$appData, string $clientNumber ): ?array {

		if( self::missing( $appData ) === true )
			return null;

		return self::list( $appData, Clients::group( $clientNumber ), 3660 )['upcoming'][0] ?? null;
	}

	/**
	 *	Whether a group is one a date may be made for: none (the date is for everyone) or the group of a client that is there and active
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$group
	 *
	 *	@return 	bool
	 */
	public static function groupAllowed( array &$appData, string $group ): bool {

		return $group === '' || ( self::clients( $appData )[$group]['active'] ?? false ) === true;
	}

	/**
	 *	The clients by their group
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											k-0003 => the client as Clients::list() has it
	 */
	private static function clients( array &$appData ): array {

		$clients = [];

		foreach( Clients::list( $appData ) as $client )
			$clients[Clients::group( $client['number'] )] = $client;

		return $clients;
	}

	/**
	 *	Every date of the type, in the native language
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											Elements with their '.uri'
	 */
	private static function read( array &$appData ): array {

		if( self::missing( $appData ) === true )
			return [];

		$found = [];

		foreach( (array) \Nino\Elements::queryElements( $appData, self::TYPE, [], '', [] ) as $element ) {

			$with = is_array( $element ) === true && is_string( $element['.uri'] ?? null ) === true ? \Nino\Elements::getElementWithFallback( $appData, $element['.uri'], '' ) : false;

			if( is_array( $with ) === true )
				$found[] = $with;
		}

		return $found;
	}

	/**
	 *	@param		array 		$record				As Events::record() has it
	 *	@param		array 		$clients			By group
	 *	@param		\DateTimeImmutable	$now
	 *
	 *	@return 	array											id, title, place, start and end (Y-m-d\TH:i in the timezone of the site; end is '' for none), all_day, running (it has begun and is not over), group ('' for a date for everyone), and the client it belongs to (client_id, client - the number -, client_title; 0 and '' for a date of no client)
	 */
	private static function shape( array $record, array $clients, \DateTimeImmutable $now ): array {

		$client = $clients[$record['group']] ?? null;

		return [
			'id'           => $record['slug'],
			'title'        => $record['title'],
			'place'        => $record['place'],
			'start'        => $record['start']->format( 'Y-m-d\TH:i' ),
			'end'          => $record['end'] === null ? '' : $record['end']->format( 'Y-m-d\TH:i' ),
			'all_day'      => $record['allDay'],
			'running'      => $record['start'] <= $now && $record['over'] >= $now,
			'group'        => $record['group'],
			'client_id'    => $client === null ? 0 : $client['id'],
			'client'       => $client === null ? '' : $client['number'],
			'client_title' => $client === null ? '' : $client['title'],
		];
	}
}
