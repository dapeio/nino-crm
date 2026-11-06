<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Icons		The icons of the customer portal: the geometry of the Lucide icons the portal draws, as inline SVG. A template of the
 *											portal asks for one with [[icon:<name>]] (Portal::template() puts the markup in), a class asks svg() for it. An icon is
 *											decorative unless it is given a label: then it is an image with that name for a screen reader, else it is hidden from it.
 *											Geometry rather than a view (AGENTS.md, "Markup belongs in a template"): the parts of each icon are kept as data, in the
 *											shape the kernel's components keep theirs, and the one line of markup that frames them is the property $html. The
 *											icons are Lucide's (THIRD-PARTY-NOTICES.md, "The icons of the project's features"); nothing here is a textfill.
 */
namespace Crm\Portal;

class Icons {

	// The frame of an icon, declared once: [[attributes]] says whether it is decorative or named, [[parts]] are the shapes
	public static $html = '<svg class="crm-icon[[classes]]" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"[[attributes]]>[[parts]]</svg>';

	// The icons by name: the parts of each, as [ tag, attributes ]
	public const array ICONS = [
		'arrow-left'     => [ [ 'path', [ 'd' => 'm12 19-7-7 7-7' ] ], [ 'path', [ 'd' => 'M19 12H5' ] ] ],
		'arrow-right'    => [ [ 'path', [ 'd' => 'M5 12h14' ] ], [ 'path', [ 'd' => 'm12 5 7 7-7 7' ] ] ],
		'calendar'       => [ [ 'path', [ 'd' => 'M8 2v4' ] ], [ 'path', [ 'd' => 'M16 2v4' ] ], [ 'rect', [ 'width' => '18', 'height' => '18', 'x' => '3', 'y' => '4', 'rx' => '2' ] ], [ 'path', [ 'd' => 'M3 10h18' ] ] ],
		'calendar-plus'  => [ [ 'path', [ 'd' => 'M16 19h6' ] ], [ 'path', [ 'd' => 'M16 2v4' ] ], [ 'path', [ 'd' => 'M19 16v6' ] ], [ 'path', [ 'd' => 'M21 12.598V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8.5' ] ], [ 'path', [ 'd' => 'M3 10h18' ] ], [ 'path', [ 'd' => 'M8 2v4' ] ] ],
		'check'          => [ [ 'path', [ 'd' => 'M20 6 9 17l-5-5' ] ] ],
		'chevron-down'   => [ [ 'path', [ 'd' => 'm6 9 6 6 6-6' ] ] ],
		'chevron-right'  => [ [ 'path', [ 'd' => 'm9 18 6-6-6-6' ] ] ],
		'circle-alert'   => [ [ 'circle', [ 'cx' => '12', 'cy' => '12', 'r' => '10' ] ], [ 'line', [ 'x1' => '12', 'x2' => '12', 'y1' => '8', 'y2' => '12' ] ], [ 'line', [ 'x1' => '12', 'x2' => '12.01', 'y1' => '16', 'y2' => '16' ] ] ],
		'circle-check'   => [ [ 'circle', [ 'cx' => '12', 'cy' => '12', 'r' => '10' ] ], [ 'path', [ 'd' => 'm9 12 2 2 4-4' ] ] ],
		'clock'          => [ [ 'circle', [ 'cx' => '12', 'cy' => '12', 'r' => '10' ] ], [ 'path', [ 'd' => 'M12 6v6l4 2' ] ] ],
		'download'       => [ [ 'path', [ 'd' => 'M12 15V3' ] ], [ 'path', [ 'd' => 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4' ] ], [ 'path', [ 'd' => 'm7 10 5 5 5-5' ] ] ],
		'external-link'  => [ [ 'path', [ 'd' => 'M15 3h6v6' ] ], [ 'path', [ 'd' => 'M10 14 21 3' ] ], [ 'path', [ 'd' => 'M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6' ] ] ],
		'file'           => [ [ 'path', [ 'd' => 'M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z' ] ], [ 'path', [ 'd' => 'M14 2v5a1 1 0 0 0 1 1h5' ] ] ],
		'file-text'      => [ [ 'path', [ 'd' => 'M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z' ] ], [ 'path', [ 'd' => 'M14 2v5a1 1 0 0 0 1 1h5' ] ], [ 'path', [ 'd' => 'M10 9H8' ] ], [ 'path', [ 'd' => 'M16 13H8' ] ], [ 'path', [ 'd' => 'M16 17H8' ] ] ],
		'folder'         => [ [ 'path', [ 'd' => 'M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z' ] ] ],
		'house'          => [ [ 'path', [ 'd' => 'M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8' ] ], [ 'path', [ 'd' => 'M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z' ] ] ],
		'id-card'        => [ [ 'path', [ 'd' => 'M16 10h2' ] ], [ 'path', [ 'd' => 'M16 14h2' ] ], [ 'path', [ 'd' => 'M6.17 15a3 3 0 0 1 5.66 0' ] ], [ 'circle', [ 'cx' => '9', 'cy' => '11', 'r' => '2' ] ], [ 'rect', [ 'x' => '2', 'y' => '5', 'width' => '20', 'height' => '14', 'rx' => '2' ] ] ],
		'image'          => [ [ 'rect', [ 'width' => '18', 'height' => '18', 'x' => '3', 'y' => '3', 'rx' => '2', 'ry' => '2' ] ], [ 'circle', [ 'cx' => '9', 'cy' => '9', 'r' => '2' ] ], [ 'path', [ 'd' => 'm21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21' ] ] ],
		'inbox'          => [ [ 'polyline', [ 'points' => '22 12 16 12 14 15 10 15 8 12 2 12' ] ], [ 'path', [ 'd' => 'M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z' ] ] ],
		'info'           => [ [ 'circle', [ 'cx' => '12', 'cy' => '12', 'r' => '10' ] ], [ 'path', [ 'd' => 'M12 16v-4' ] ], [ 'path', [ 'd' => 'M12 8h.01' ] ] ],
		'landmark'       => [ [ 'path', [ 'd' => 'M10 18v-7' ] ], [ 'path', [ 'd' => 'M11.12 2.198a2 2 0 0 1 1.76.006l7.866 3.847c.476.233.31.949-.22.949H3.474c-.53 0-.695-.716-.22-.949z' ] ], [ 'path', [ 'd' => 'M14 18v-7' ] ], [ 'path', [ 'd' => 'M18 18v-7' ] ], [ 'path', [ 'd' => 'M3 22h18' ] ], [ 'path', [ 'd' => 'M6 18v-7' ] ] ],
		'lock'           => [ [ 'rect', [ 'width' => '18', 'height' => '11', 'x' => '3', 'y' => '11', 'rx' => '2', 'ry' => '2' ] ], [ 'path', [ 'd' => 'M7 11V7a5 5 0 0 1 10 0v4' ] ] ],
		'log-out'        => [ [ 'path', [ 'd' => 'm16 17 5-5-5-5' ] ], [ 'path', [ 'd' => 'M21 12H9' ] ], [ 'path', [ 'd' => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4' ] ] ],
		'mail'           => [ [ 'path', [ 'd' => 'm22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7' ] ], [ 'rect', [ 'x' => '2', 'y' => '4', 'width' => '20', 'height' => '16', 'rx' => '2' ] ] ],
		'map-pin'        => [ [ 'path', [ 'd' => 'M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0' ] ], [ 'circle', [ 'cx' => '12', 'cy' => '10', 'r' => '3' ] ] ],
		'message-square' => [ [ 'path', [ 'd' => 'M22 17a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 21.286V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z' ] ] ],
		'music'          => [ [ 'path', [ 'd' => 'M9 18V5l12-2v13' ] ], [ 'circle', [ 'cx' => '6', 'cy' => '18', 'r' => '3' ] ], [ 'circle', [ 'cx' => '18', 'cy' => '16', 'r' => '3' ] ] ],
		'pen-line'       => [ [ 'path', [ 'd' => 'M13 21h8' ] ], [ 'path', [ 'd' => 'M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z' ] ] ],
		'phone'          => [ [ 'path', [ 'd' => 'M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384' ] ] ],
		'play'           => [ [ 'path', [ 'd' => 'M5 5a2 2 0 0 1 3.008-1.728l11.997 6.998a2 2 0 0 1 .003 3.458l-12 7A2 2 0 0 1 5 19z' ] ] ],
		'receipt'        => [ [ 'path', [ 'd' => 'M12 17V7' ] ], [ 'path', [ 'd' => 'M16 8h-6a2 2 0 0 0 0 4h4a2 2 0 0 1 0 4H8' ] ], [ 'path', [ 'd' => 'M4 3a1 1 0 0 1 1-1 1.3 1.3 0 0 1 .7.2l.933.6a1.3 1.3 0 0 0 1.4 0l.934-.6a1.3 1.3 0 0 1 1.4 0l.933.6a1.3 1.3 0 0 0 1.4 0l.933-.6a1.3 1.3 0 0 1 1.4 0l.934.6a1.3 1.3 0 0 0 1.4 0l.933-.6A1.3 1.3 0 0 1 19 2a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1 1.3 1.3 0 0 1-.7-.2l-.933-.6a1.3 1.3 0 0 0-1.4 0l-.934.6a1.3 1.3 0 0 1-1.4 0l-.933-.6a1.3 1.3 0 0 0-1.4 0l-.933.6a1.3 1.3 0 0 1-1.4 0l-.934-.6a1.3 1.3 0 0 0-1.4 0l-.933.6a1.3 1.3 0 0 1-.7.2 1 1 0 0 1-1-1z' ] ] ],
		'rss'            => [ [ 'path', [ 'd' => 'M4 11a9 9 0 0 1 9 9' ] ], [ 'path', [ 'd' => 'M4 4a16 16 0 0 1 16 16' ] ], [ 'circle', [ 'cx' => '5', 'cy' => '19', 'r' => '1' ] ] ],
		'shield-check'   => [ [ 'path', [ 'd' => 'M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z' ] ], [ 'path', [ 'd' => 'm9 12 2 2 4-4' ] ] ],
		'triangle-alert' => [ [ 'path', [ 'd' => 'm21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3' ] ], [ 'path', [ 'd' => 'M12 9v4' ] ], [ 'path', [ 'd' => 'M12 17h.01' ] ] ],
		'upload'         => [ [ 'path', [ 'd' => 'M12 3v12' ] ], [ 'path', [ 'd' => 'm17 8-5-5-5 5' ] ], [ 'path', [ 'd' => 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4' ] ] ],
		'user'           => [ [ 'path', [ 'd' => 'M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2' ] ], [ 'circle', [ 'cx' => '12', 'cy' => '7', 'r' => '4' ] ] ],
		'video'          => [ [ 'path', [ 'd' => 'm16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5' ] ], [ 'rect', [ 'x' => '2', 'y' => '6', 'width' => '14', 'height' => '12', 'rx' => '2' ] ] ],
		'x'              => [ [ 'path', [ 'd' => 'M18 6 6 18' ] ], [ 'path', [ 'd' => 'm6 6 12 12' ] ] ],
	];

	/**
	 *	One icon as inline SVG
	 *
	 *	@param		string		$name					A key of ICONS
	 *	@param		string		$label				What the icon says, for a screen reader; '' for an icon that is decoration beside its words (aria-hidden)
	 *
	 *	@return 	string										'' for a name that is no icon
	 */
	public static function svg( string $name, string $label = '' ): string {

		if( isset( self::ICONS[$name] ) === false )
			return '';

		$parts = '';

		foreach( self::ICONS[$name] as [ $tag, $attributes ] ) {

			$parts .= '<'. $tag;

			foreach( $attributes as $attribute => $value )
				$parts .= ' '. $attribute. '="'. $value. '"';

			$parts .= '/>';
		}

		return str_replace(
			[ '[[classes]]', '[[attributes]]', '[[parts]]' ],
			[ ' crm-icon--'. $name, $label === '' ? ' aria-hidden="true" focusable="false"' : ' role="img" aria-label="'. \Nino\Modules\Components::escape( $label ). '"', $parts ],
			self::$html
		);
	}

	/**
	 *	The icons a template asks for: every [[icon:<name>]] becomes the SVG of that icon, decorative; a name that is no icon becomes nothing
	 *
	 *	@param		string		$markup
	 *
	 *	@return 	string
	 */
	public static function fill( string $markup ): string {

		return (string) preg_replace_callback( '/\[\[icon:([a-z-]+)\]\]/', static fn( array $found ): string => self::svg( $found[1] ), $markup );
	}

	/**
	 *	The icon of a file, by its mime type: music for audio, video, image, and the plain file for the rest
	 *
	 *	@param		string		$mime
	 *
	 *	@return 	string										The name of the icon
	 */
	public static function ofFile( string $mime ): string {

		return match( true ) {
			str_starts_with( $mime, 'audio/' ) => 'music',
			str_starts_with( $mime, 'video/' ) => 'video',
			str_starts_with( $mime, 'image/' ) => 'image',
			default                            => 'file',
		};
	}
}
