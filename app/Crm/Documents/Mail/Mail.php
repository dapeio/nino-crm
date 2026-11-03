<?php
declare(strict_types=1);
/**
 *	Crm\Documents\Mail		The mail that tells the accounts of a client a document was released to the portal: one mail for each active
 *												account of the client, in German (a document is the owner's letter to his client, and an account of
 *												Members has no language of its own), with the absolute link to the page Dokumente and no attachment.
 *												All mails are one call of the kernel's mail, which counts a call against the cap of mails an hour and not
 *												a mail. The words are in text/de_DE.php, the markup in templates/release.tpl; a value enters the
 *												template after it was rendered, escaped, so that nothing in it is read as a fill or a shortcode.
 */
namespace Crm\Documents;

use Crm\Clients;
use Crm\Crm;

class Mail {

	// The page the mail leads to
	public const string PAGE = '/portal/dokumente';

	private static array $_texts = [];

	/**
	 *	A word of the mail
	 *
	 *	@param		string		$key
	 *	@param		string		...$params		Values for the %s of the word
	 *
	 *	@return 	string
	 */
	public static function text( string $key, string ...$params ): string {

		if( self::$_texts === [] )
			self::$_texts = (array) require __DIR__. '/text/de_DE.php';

		if( isset( self::$_texts[$key] ) === false )
			throw new \InvalidArgumentException( 'There is no mail word "'. $key. '"' );

		return $params === [] ? self::$_texts[$key] : vsprintf( self::$_texts[$key], $params );
	}

	/**
	 *	The mails for a document: one for each active account of its client that has an address
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			As Documents::get() has it
	 *
	 *	@return 	array											[ { to, subject, body, replyTo }, ... ]; none for a client without an account that can be written to, or where the site has no address
	 */
	public static function compose( array &$appData, array $document ): array {

		$link = \Nino\Html::absoluteUrl( $appData, self::PAGE );

		if( $link === '' || Crm::featureOn( $appData, 'members' ) === false )
			return [];

		$locale = \Nino\Locales::getCurrentLocale( $appData );

		\Nino\Locales::useLocale( $appData, \Nino\Locales::getNativeLocale( $appData ) );

		try {
			$markup = \Nino\Html::renderHtml( $appData, self::template( 'release' ) );
			$replyTo = trim( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/email' ) );
		}
		finally {
			\Nino\Locales::useLocale( $appData, $locale );
		}

		$replyTo = filter_var( $replyTo, FILTER_VALIDATE_EMAIL ) === false ? '' : $replyTo;
		$name = (string) $document['title'] !== '' ? (string) $document['title'] : (string) $document['number'];
		$about = self::text( 'line', self::text( 'kind.'. $document['category'] ), $name );
		$mails = [];

		foreach( Clients::accounts( $appData, (int) $document['client_id'] ) as $id ) {

			$account = \Nino\Modules\Members::account( $appData, $id );

			if( $account === null || $account['active'] !== true || filter_var( $account['email'], FILTER_VALIDATE_EMAIL ) === false )
				continue;

			$values = [
				'[[heading]]'  => self::text( 'title' ),
				'[[greeting]]' => self::text( 'greeting', $account['name'] !== '' ? $account['name'] : $account['email'] ),
				'[[intro]]'    => self::text( 'intro' ),
				'[[document]]' => $about,
				'[[link]]'     => $link,
				'[[button]]'   => self::text( 'button' ),
				'[[notice]]'   => self::text( 'notice' ),
				'[[closing]]'  => self::text( 'closing' ),
			];

			$mails[] = [
				'to'      => $account['email'],
				'subject' => self::text( 'subject', $about ),
				'body'    => str_replace( array_keys( $values ), array_map( static fn( string $value ): string => \Nino\Modules\Components::escape( $value ), array_values( $values ) ), $markup ),
				'replyTo' => $replyTo,
			];
		}

		return $mails;
	}

	/**
	 *	Mail the accounts of the client of a document
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			As Documents::get() has it
	 *
	 *	@return 	int|string								How many mails were delivered; 'address' where the site has no address to link to, 'limit' where the cap of mails an hour refused them all
	 */
	public static function release( array &$appData, array $document ): int|string {

		if( \Nino\Html::absoluteUrl( $appData, self::PAGE ) === '' )
			return 'address';

		$mails = self::compose( $appData, $document );

		if( $mails === [] )
			return 0;

		$delivered = \Nino\Mail::sendAll( $appData, $mails );

		if( \Nino\Mail::rateLimited( $appData ) === true )
			return 'limit';

		if( $delivered === false )
			trigger_error( 'Crm: the mails about document '. Render::name( $document ). ' were not all delivered', E_USER_WARNING );

		return count( array_filter( (array) ( $appData['./nino/mail/results'] ?? [] ), static fn( mixed $result ): bool => $result === true ) );
	}

	/**
	 *	The markup of the mail, read from the templates/ beside this class
	 *
	 *	@param		string		$name					A file of templates/ without .tpl: lower case letters and hyphens
	 *
	 *	@return 	string
	 */
	private static function template( string $name ): string {

		if( preg_match( '/^[a-z]+(-[a-z]+)*$/D', $name ) !== 1 )
			throw new \InvalidArgumentException( 'There is no mail template "'. $name. '"' );

		$markup = file_get_contents( __DIR__. '/templates/'. $name. '.tpl' );

		if( $markup === false )
			throw new \RuntimeException( 'The mail template "'. $name. '" cannot be read' );

		return rtrim( $markup, "\n" );
	}
}
