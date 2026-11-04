<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Modules\Pdf\Layout\Allow	The HTML a document may hold: the tags and attributes that stay, and what becomes of the rest
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules\Pdf\Layout {

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Allow							The allowlist of the HTML a PDF is made of. The engine understands a
	 *										small subset (see Html); this is the subset, written once, and it is
	 *										held to twice: when a person saves a text - a block of the library, a
	 *										text of a template's own, the header, the footer - and again when a
	 *										document is laid out, so that what a caller hands in as a slot, and
	 *										the two sample texts of the preview, are held to the same list.
	 *
	 *										Kept: h1, h2, h3, p, div, br, strong, b, em, i, ul, ol, li, a, hr,
	 *										table, thead, tbody, tfoot, tr, th, td, img and span - the tags the
	 *										engine draws, which the kernel's sanitizer for blocks (paragraphs,
	 *										lists, links) would flatten: a heading, a table or a rule is the
	 *										point of a document. And of their attributes, only what the engine
	 *										reads, each in the one shape it reads it in:
	 *
	 *										  width    a th or td: a percentage (the column); an img: a number of mm
	 *										  align    a p, div, h1, h2, h3, th or td: left, right or center
	 *										  style    the same elements, reduced to its text-align declaration
	 *										  class    a p or div: address, and no other class (the rules of
	 *										           the sheet's .address)
	 *										  border   a table: a whole number, 0 for none
	 *										  src      an img, and only pdf:logo - any other img goes
	 *
	 *										The href of a link is not kept: the engine draws the text of a link
	 *										underlined and makes no annotation of it, so the address would only
	 *										be something to carry along (a javascript: address included).
	 *
	 *										The rest: a tag that is not listed is unwrapped, what it holds stays
	 *										as it was; a script, a style, a head, a title and a template go with
	 *										their content, which is no text of a document; a comment and a
	 *										processing instruction go; every attribute not named above is removed
	 *										(onclick, a class that is not address, id, a style without a text-align).
	 *
	 *										body() is the entry for the engine: the text parsed and cleaned, as a
	 *										DOM. html() is the entry for a save: the same body written out as a
	 *										string, tags and attributes as listed, text escaped, so that what is
	 *										stored is what the engine will read - and what an editor shows after a
	 *										save. The parse is the engine's: the text is handed to libxml as
	 *										numeric entities, which is the way a UTF-8 string reaches its HTML
	 *										parser unchanged without an envelope of markup around it, and the
	 *										document is what the parser puts in the body.
	 *
	 *										The string written out is data, not a view: it is the tags of the
	 *										list round the text the person typed.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Allow {

		// The tags that stay, each with the attributes it may keep, in the order they are written
		public const array TAGS = [
			'h1'			=> [ 'align', 'style' ],
			'h2'			=> [ 'align', 'style' ],
			'h3'			=> [ 'align', 'style' ],
			'p'				=> [ 'align', 'style', 'class' ],
			'div'			=> [ 'align', 'style', 'class' ],
			'br'			=> [],
			'strong'	=> [],
			'b'				=> [],
			'em'			=> [],
			'i'				=> [],
			'ul'			=> [],
			'ol'			=> [],
			'li'			=> [],
			'a'				=> [],
			'hr'			=> [],
			'table'		=> [ 'border' ],
			'thead'		=> [],
			'tbody'		=> [],
			'tfoot'		=> [],
			'tr'			=> [],
			'th'			=> [ 'width', 'align', 'style' ],
			'td'			=> [ 'width', 'align', 'style' ],
			'img'			=> [ 'src', 'width' ],
			'span'		=> [],
		];

		// Elements that go with what they hold: it is no text of the document
		private const array DROPPED = [ 'script', 'style', 'head', 'title', 'template' ];

		// Elements with no content and no closing tag
		private const array VOID = [ 'br', 'hr', 'img' ];

		/**
		 *	The body of a text, parsed and cleaned
		 *
		 *	@param		string		$html
		 *
		 *	@return 	\DOMElement|null						Null for a text with nothing in it
		 */
		public static function body( string $html ): ?\DOMElement {

			$html = trim( $html );

			if( $html === '' )
				return null;

			// A byte that is not UTF-8 would end the text node at it (libxml 2.13): it becomes the replacement character first
			if( preg_match( '//u', $html ) !== 1 ) {
				$substitute = mb_substitute_character();
				mb_substitute_character( 0xFFFD );
				$html = (string) mb_convert_encoding( $html, 'UTF-8', 'UTF-8' );
				mb_substitute_character( $substitute );
			}

			$previous = libxml_use_internal_errors( true );
			$doc = new \DOMDocument();
			$doc->loadHTML( (string) mb_encode_numericentity( $html, [ 0x80, 0x10FFFF, 0, 0x1FFFFF ], 'UTF-8' ), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			$body = $doc->getElementsByTagName( 'body' )->item( 0 );

			if( $body instanceof \DOMElement === false )
				return null;

			self::_clean( $body );

			return $body;
		}

		/**
		 *	A text held to the list, as the string a save stores
		 *
		 *	@param		string		$html
		 *
		 *	@return 	string
		 */
		public static function html( string $html ): string {

			$body = self::body( $html );

			return $body === null ? '' : trim( self::_write( $body ) );
		}

		/**
		 *	The children of a node held to the list: what is not listed is unwrapped or removed, in place
		 *
		 *	@param		\DOMNode	$parent
		 *
		 *	@return 	void
		 */
		private static function _clean( \DOMNode $parent ): void {

			foreach( iterator_to_array( $parent->childNodes ) as $child ) {

				// Text stays; a comment, a processing instruction and the like go
				if( $child instanceof \DOMText )
					continue;

				if( $child instanceof \DOMElement === false ) {
					$parent->removeChild( $child );
					continue;
				}

				$tag = strtolower( $child->nodeName );

				// An img that is not the logo is nothing the engine draws
				if( in_array( $tag, self::DROPPED, true ) === true || ( $tag === 'img' && self::_value( 'src', $child->getAttribute( 'src' ) ) === null ) ) {
					$parent->removeChild( $child );
					continue;
				}

				self::_clean( $child );

				if( isset( self::TAGS[$tag] ) === false ) {

					while( $child->firstChild !== null )
						$parent->insertBefore( $child->firstChild, $child );

					$parent->removeChild( $child );
					continue;
				}

				self::_attributes( $child, $tag );
			}
		}

		/**
		 *	The attributes of an element held to the list: those it may keep, in their shape, in the order of the list
		 *
		 *	@param		\DOMElement	$element
		 *	@param		string			$tag
		 *
		 *	@return 	void
		 */
		private static function _attributes( \DOMElement $element, string $tag ): void {

			$kept = [];

			foreach( self::TAGS[$tag] as $name ) {

				$value = $element->hasAttribute( $name ) === true ? self::_value( $name, $element->getAttribute( $name ), $tag ) : null;

				if( $value !== null )
					$kept[$name] = $value;
			}

			foreach( iterator_to_array( $element->attributes ) as $attribute )
				$element->removeAttributeNode( $attribute );

			foreach( $kept as $name => $value )
				$element->setAttribute( $name, $value );
		}

		/**
		 *	The value of an attribute in the one shape the engine reads, or null where it is none
		 *
		 *	@param		string		$name
		 *	@param		string		$value
		 *	@param		string		$tag
		 *
		 *	@return 	string|null
		 */
		private static function _value( string $name, string $value, string $tag = 'img' ): ?string {

			$value = trim( $value );

			switch( $name ) {

				case 'align':
					return in_array( strtolower( $value ), [ 'left', 'right', 'center' ], true ) === true ? strtolower( $value ) : null;

				case 'style':
					return self::_alignment( $value );

				case 'class':
					return strtolower( $value ) === 'address' ? 'address' : null;

				case 'border':
					return preg_match( '/^\d{1,2}$/D', $value ) === 1 ? $value : null;

				case 'src':
					return strtolower( $value ) === 'pdf:logo' ? 'pdf:logo' : null;

				case 'width':
					if( $tag === 'img' )
						return preg_match( '/^(\d{1,4}(?:\.\d{1,2})?)\s*(mm)?$/Di', $value, $found ) === 1 ? $found[1]. strtolower( $found[2] ?? '' ) : null;

					return preg_match( '/^\d{1,3}(?:\.\d{1,2})?%$/D', $value ) === 1 ? $value : null;
			}

			return null;
		}

		/**
		 *	The text-align declaration of a style attribute, and nothing else of it: the last valid one, as a cascade has it
		 *
		 *	@param		string		$style
		 *
		 *	@return 	string|null								text-align:left, text-align:right or text-align:center
		 */
		private static function _alignment( string $style ): ?string {

			$found = null;

			foreach( explode( ';', $style ) as $declaration ) {

				$pair = explode( ':', $declaration, 2 );

				if( count( $pair ) === 2 && strtolower( trim( $pair[0] ) ) === 'text-align' && preg_match( '/^(left|right|center)(?:\s*!important)?$/Di', trim( $pair[1] ), $value ) === 1 )
					$found = 'text-align:'. strtolower( $value[1] );
			}

			return $found;
		}

		/**
		 *	The children of a node as a string: the elements that are left with the attributes that are left, the text escaped
		 *
		 *	@param		\DOMNode	$parent
		 *
		 *	@return 	string
		 */
		private static function _write( \DOMNode $parent ): string {

			$out = '';

			foreach( $parent->childNodes as $node ) {

				if( $node instanceof \DOMText ) {
					$out .= str_replace( "\u{A0}", '&nbsp;', htmlspecialchars( $node->data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
					continue;
				}

				if( $node instanceof \DOMElement === false )
					continue;

				$tag = strtolower( $node->nodeName );
				$attributes = '';

				foreach( $node->attributes as $attribute )
					$attributes .= ' '. $attribute->name. '="'. htmlspecialchars( $attribute->value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ). '"';

				$out .= '<'. $tag. $attributes. '>'. ( in_array( $tag, self::VOID, true ) === true ? '' : self::_write( $node ). '</'. $tag. '>' );
			}

			return $out;
		}
	}
}
