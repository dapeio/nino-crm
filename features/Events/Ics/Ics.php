<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Modules\Events\Ics		see _nino/Nino/Modules/Modules.php for the
 *											package-level docblock
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules\Events {

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Ics								The one thing in this feature that is assembled in php rather
	 *										than filled into a template, and why: a calendar file is a
	 *										document format built from data (RFC 5545), a serialisation
	 *										and no view of anything - like sitemap.xml and robots.txt in
	 *										the Seo feature. It has no design to keep out of here; what it
	 *										has is a grammar: content lines that end in CRLF and are folded
	 *										at 75 octets, a TEXT value that escapes the backslash, the
	 *										semicolon, the comma and the newline, a moment written in UTC
	 *										and a day written as a DATE. The class knows nothing of
	 *										elements, members or the site; it is handed the values.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Ics {

		// The line ending of the format, and the most octets a content line holds before it is folded
		public const string EOL = "\r\n";
		public const int WIDTH = 75;

		// What a calendar says of the program that made it: the name of the feature, no version in it
		public const string PRODID = '-//Nino//Events//EN';

		/**
		 *	A calendar with the events it is given
		 *
		 *	@param		string		$name					The name of the calendar, plain text
		 *	@param		array 		$events				A list of events as event() reads them
		 *
		 *	@return 	string								The whole file, every line ending in CRLF
		 */
		public static function calendar( string $name, array $events ): string {

			$lines = [ 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:'. self::PRODID, 'CALSCALE:GREGORIAN' ];

			if( $name !== '' )
				$lines[] = 'X-WR-CALNAME:'. self::text( $name );

			foreach( $events as $event )
				foreach( self::event( $event ) as $line )
					$lines[] = $line;

			$lines[] = 'END:VCALENDAR';

			return implode( '', array_map( static fn( string $line ): string => self::fold( $line ). self::EOL, $lines ) );
		}

		/**
		 *	The lines of one VEVENT, not folded yet
		 *
		 *	@param		array 		$event				uid (string), stamp (\DateTimeImmutable), start (\DateTimeImmutable), end
		 *																	(\DateTimeImmutable|null), allDay (bool: a DATE and not a moment), summary,
		 *																	location, description (plain text) and url - a value that is '' is left out
		 *
		 *	@return 	array									The content lines
		 */
		public static function event( array $event ): array {

			$utc			= new \DateTimeZone( 'UTC' );
			$moment		= static fn( \DateTimeImmutable $at ): string => $at->setTimezone( $utc )->format( 'Ymd\THis\Z' );
			$start		= $event['start'];
			$lines		= [ 'BEGIN:VEVENT', 'UID:'. self::text( (string) $event['uid'] ), 'DTSTAMP:'. $moment( $event['stamp'] ) ];

			if( $event['allDay'] === true ) {

				$lines[] = 'DTSTART;VALUE=DATE:'. $start->format( 'Ymd' );
				$lines[] = 'DTEND;VALUE=DATE:'. $start->modify( '+1 day' )->format( 'Ymd' );
			}
			else {

				$lines[] = 'DTSTART:'. $moment( $start );

				if( $event['end'] instanceof \DateTimeImmutable )
					$lines[] = 'DTEND:'. $moment( $event['end'] );
			}

			foreach( [ 'SUMMARY' => 'summary', 'LOCATION' => 'location', 'DESCRIPTION' => 'description' ] as $property => $key )
				if( (string) $event[$key] !== '' )
					$lines[] = $property. ':'. self::text( (string) $event[$key] );

			// A URI value is not a TEXT: it is written as it is, with no character in it that would end the line
			if( (string) $event['url'] !== '' )
				$lines[] = 'URL:'. preg_replace( '/[\x00-\x20\x7F]+/', '', mb_scrub( (string) $event['url'], 'UTF-8' ) );

			$lines[] = 'END:VEVENT';

			return $lines;
		}

		/**
		 *	A value as the format's TEXT: the backslash, the semicolon and the comma
		 *	written with a backslash in front, every kind of line break as \n, the other
		 *	control characters left out, a byte that is no UTF-8 replaced
		 *
		 *	@param		string		$value
		 *
		 *	@return 	string
		 */
		public static function text( string $value ): string {

			$value = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', str_replace( [ "\r\n", "\r" ], "\n", mb_scrub( $value, 'UTF-8' ) ) );

			return str_replace( [ '\\', ';', ',', "\n" ], [ '\\\\', '\;', '\\,', '\\n' ], $value );
		}

		/**
		 *	A content line folded the way the format asks: no more than 75 octets in a
		 *	line, the next line begins with one space which counts as one of its octets,
		 *	and a character is never cut in the middle of its bytes
		 *
		 *	@param		string		$line					Without its line ending
		 *
		 *	@return 	string								The line, with CRLF and a space where it breaks, and no ending of its own
		 */
		public static function fold( string $line ): string {

			if( strlen( $line ) <= self::WIDTH )
				return $line;

			$parts		= [];
			$current	= '';
			$limit		= self::WIDTH;

			foreach( (array) mb_str_split( $line, 1, 'UTF-8' ) as $character ) {

				if( strlen( $current ) + strlen( $character ) > $limit ) {
					$parts[]	= $current;
					$current	= '';
					$limit		= self::WIDTH - 1;
				}

				$current .= $character;
			}

			$parts[] = $current;

			return implode( self::EOL. ' ', $parts );
		}
	}
}
