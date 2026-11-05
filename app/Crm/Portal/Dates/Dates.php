<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Dates		The component [crm-portal-dates] of the page Termine: the dates that are coming for the member signed in - the ones of
 *											the member's group and the ones for everyone, as the feature Events keeps and selects them (Events::dates() with the
 *											viewer of the request; nothing of its rules is copied here) - as the rows of the portal: the day, the title, the time, the
 *											place and the link that puts the date into a calendar app (the component [event-ics] of Events, which the rendering that
 *											follows draws). Where nothing is coming, or Events is off, an empty state says so. The subscription of the calendar is the
 *											page head's ([crm-portal-head calendar="1"], the component [event-feed] of Events). Nothing here is stored, and every
 *											value enters the markup escaped
 */
namespace Crm\Portal;

use Crm\Crm;
use Crm\Portal;

class Dates {

	/**
	 *	[crm-portal-dates]
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$args
	 *
	 *	@return 	string
	 */
	public static function componentCrmPortalDates( array &$appData, array $args ): string {

		if( Crm::featureOn( $appData, 'members' ) === false || \Nino\Modules\Members::current( $appData ) === null )
			return '';

		Portal::words( $appData );

		$classes = \Nino\Feature::classes( $args );
		$rows = '';

		foreach( self::upcoming( $appData ) as $date ) {

			if( preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/D', $date['slug'] ) !== 1 )
				continue;

			$rows .= Portal::render( 'date', [
				'[[slug]]'  => $date['slug'],
				'[[iso]]'   => $date['start']->format( $date['allDay'] === true ? 'Y-m-d' : 'Y-m-d\TH:i' ),
				'[[day]]'   => $date['start']->format( 'd' ),
				'[[month]]' => \Nino\Modules\Components::escape( self::month( $appData, $date['start'] ) ),
				'[[title]]' => \Nino\Modules\Components::escape( $date['title'] ),
				'[[when]]'  => \Nino\Modules\Components::escape( self::when( $appData, $date ) ),
				'[[place]]' => $date['place'] === '' ? '' : Portal::render( 'date-place', [ '[[place]]' => \Nino\Modules\Components::escape( $date['place'] ) ] ),
			] );
		}

		if( $rows === '' )
			return Portal::render( 'dates-empty', [ '[[classes]]' => $classes, '[[empty]]' => Portal::emptyState( $appData, 'calendar', '[[/project/crm-portal/dates/empty]]', 'crm-dates__empty' ) ] );

		return Portal::render( 'dates', [ '[[classes]]' => $classes, '[[rows]]' => $rows ] );
	}

	/**
	 *	The dates that are coming for the member of this request, soonest first, as Events selects them
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											Records of Events::dates(); none where Events is off or has no type
	 */
	public static function upcoming( array &$appData ): array {

		if( Crm::featureOn( $appData, 'events' ) === false || \Nino\Filesystem::fileExists( $appData, '/elements'. \Nino\Modules\Events::TYPE. '.php' ) === false )
			return [];

		$elements = [];

		foreach( (array) \Nino\Elements::queryElements( $appData, \Nino\Modules\Events::TYPE, [], '', [] ) as $element ) {

			$with = is_array( $element ) === true && is_string( $element['.uri'] ?? null ) === true ? \Nino\Elements::getElementWithFallback( $appData, $element['.uri'], '' ) : false;

			if( is_array( $with ) === true )
				$elements[] = $with;
		}

		return \Nino\Modules\Events::dates( $appData, $elements, 'upcoming', \Nino\Modules\Events::viewer( $appData ) );
	}

	/**
	 *	Now, as Events reads it
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	\DateTimeImmutable|null		null where Events is off
	 */
	public static function now( array &$appData ): ?\DateTimeImmutable {

		return Crm::featureOn( $appData, 'events' ) === true ? \Nino\Modules\Events::now( $appData ) : null;
	}

	/**
	 *	When a date takes place, in the words of the page: the day as Events writes it and the time, "ganztägig" for a day
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$date					A record of Events::dates()
	 *
	 *	@return 	string										Plain text, not escaped
	 */
	public static function when( array &$appData, array $date ): string {

		$day = \Nino\Modules\Events::pattern( $appData, $date['start'], true );

		if( $date['allDay'] === true )
			return $day. ', '. html_entity_decode( (string) \Nino\Html::resolveTextfill( $appData, '/project/crm-portal/dates/all-day' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$time = \Nino\Modules\Events::pattern( $appData, $date['start'], false );

		if( $date['end'] !== null && $date['end']->format( 'Y-m-d' ) === $date['start']->format( 'Y-m-d' ) )
			$time .= '–'. \Nino\Modules\Events::pattern( $appData, $date['end'], false );

		return $day. ', '. $time;
	}

	/**
	 *	The month of a date, short, in the language of the page: the intl extension's where it is loaded, else the number with a dot
	 *
	 *	@param		array 		&$appData
	 *	@param		\DateTimeImmutable	$moment
	 *
	 *	@return 	string										Plain text, not escaped
	 */
	private static function month( array &$appData, \DateTimeImmutable $moment ): string {

		if( extension_loaded( 'intl' ) === true ) {

			$formatter = new \IntlDateFormatter( \Nino\Locales::getCurrentLocale( $appData ), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $moment->getTimezone(), \IntlDateFormatter::GREGORIAN, 'LLL' );
			$text = $formatter->format( $moment );

			if( is_string( $text ) === true && $text !== '' )
				return $text;
		}

		return $moment->format( 'm' ). '.';
	}
}
