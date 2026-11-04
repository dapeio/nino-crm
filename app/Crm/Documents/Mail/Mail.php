<?php
declare(strict_types=1);
/**
 *	Crm\Documents\Mail		The mail that tells the accounts of a client a document was released to the portal: one mail for each active
 *												account of the client, in German (a document is the owner's letter to his client, and an account of
 *												Members has no language of its own), with the absolute link to the page Belege and no attachment.
 *												All mails are one call of the kernel's mail, which counts a call against the cap of mails an hour and not
 *												a mail. The words are in text/de_DE.php, the markup in templates/release.tpl (and changed.tpl for the mail to the owner); a value enters the
 *												template after it was rendered, escaped, so that nothing in it is read as a fill or a shortcode. The same
 *												mail, in the words of a reminder (text/de_DE.php, remind.*), asks for the payment of an invoice that is overdue.
 */
namespace Crm\Documents;

use Crm\Clients;
use Crm\Crm;

class Mail {

	// The page the mail leads to
	public const string PAGE = '/portal/belege';

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
	 *	@param		string		$kind					release (a document is there for the client) or remind (an invoice is overdue)
	 *
	 *	@return 	array											[ { to, subject, body, replyTo }, ... ]; none for a client without an account that can be written to, or where the site has no address
	 */
	public static function compose( array &$appData, array $document, string $kind = 'release' ): array {

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
		$prefix = $kind === 'remind' ? 'remind.' : '';
		$due = $kind === 'remind' ? Render::day( (string) ( $document['due_date'] ?? '' ) ) : '';

		foreach( Clients::accounts( $appData, (int) $document['client_id'] ) as $id ) {

			$account = \Nino\Modules\Members::account( $appData, $id );

			if( $account === null || $account['active'] !== true || filter_var( $account['email'], FILTER_VALIDATE_EMAIL ) === false )
				continue;

			$values = [
				'[[heading]]'  => self::text( $prefix. 'title' ),
				'[[greeting]]' => self::text( 'greeting', $account['name'] !== '' ? $account['name'] : $account['email'] ),
				'[[intro]]'    => $kind === 'remind' ? self::text( 'remind.intro', $due ) : self::text( 'intro' ),
				'[[document]]' => $about,
				'[[link]]'     => $link,
				'[[cta]]'      => self::text( $prefix. 'button' ),
				'[[notice]]'   => self::text( $prefix. 'notice' ),
				'[[closing]]'  => self::text( 'closing' ),
			];

			$mails[] = [
				'to'      => $account['email'],
				'subject' => self::text( $prefix. 'subject', $about ),
				'body'    => str_replace( array_keys( $values ), array_map( static fn( string $value ): string => \Nino\Modules\Components::escape( $value ), array_values( $values ) ), $markup ),
				'replyTo' => $replyTo,
			];
		}

		return $mails;
	}

	/**
	 *	Mail the accounts of the client of a document that it was released
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			As Documents::get() has it
	 *
	 *	@return 	int|string								How many mails were delivered; 'address' where the site has no address to link to, 'limit' where the cap of mails an hour refused them all
	 */
	public static function release( array &$appData, array $document ): int|string {

		return self::send( $appData, $document, 'release' );
	}

	/**
	 *	Mail the accounts of the client of an invoice that it is overdue
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			As Documents::get() has it
	 *
	 *	@return 	int|string								As release() has it
	 */
	public static function remind( array &$appData, array $document ): int|string {

		return self::send( $appData, $document, 'remind' );
	}

	/**
	 *	Mail the owner that a client changed its data in the portal (page Meine Daten): one mail to the contact address of the company, with the
	 *	client's number and company, the fields that changed as they were and as they are now, and the account that changed them; the reply
	 *	goes to that account. Own template (templates/changed.tpl, changed-row.tpl) and own words (text/de_DE.php, data.*)
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client				As Clients::get() has it, after the change
	 *	@param		array 		$changes			field => [ before, after ] of each field that is different
	 *	@param		array 		$account			The account of Members that changed them: email and name
	 *
	 *	@return 	int|string								How many mails were delivered; 'address' where the company has no contact address that is one, 'limit' where the cap of mails an hour refused it
	 */
	public static function dataChanged( array &$appData, array $client, array $changes, array $account ): int|string {

		$locale = \Nino\Locales::getCurrentLocale( $appData );

		\Nino\Locales::useLocale( $appData, \Nino\Locales::getNativeLocale( $appData ) );

		try {
			$markup = \Nino\Html::renderHtml( $appData, self::template( 'changed' ) );
			$row = \Nino\Html::renderHtml( $appData, self::template( 'changed-row' ) );
			$owner = trim( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/email' ) );
		}
		finally {
			\Nino\Locales::useLocale( $appData, $locale );
		}

		if( filter_var( $owner, FILTER_VALIDATE_EMAIL ) === false )
			return 'address';

		$escape = static fn( string $value ): string => \Nino\Modules\Components::escape( $value );
		$empty = self::text( 'data.empty' );
		$rows = '';

		foreach( $changes as $field => [ $before, $after ] )
			$rows .= str_replace(
				[ '[[field]]', '[[before]]', '[[after]]' ],
				[ $escape( self::text( 'data.field.'. $field ) ), $escape( $before !== '' ? $before : $empty ), $escape( $after !== '' ? $after : $empty ) ],
				$row
			);

		$who = (string) $account['name'] !== '' ? $account['name']. ' <'. $account['email']. '>' : (string) $account['email'];
		$name = (string) $client['number']. ' '. ( (string) $client['company'] !== '' ? $client['company'] : $client['name'] );
		$values = [
			'[[heading]]' => self::text( 'data.title' ),
			'[[intro]]'   => self::text( 'data.intro' ),
			'[[client]]'  => $name,
			'[[head-field]]'  => self::text( 'data.head.field' ),
			'[[head-before]]' => self::text( 'data.head.before' ),
			'[[head-after]]'  => self::text( 'data.head.after' ),
			'[[account]]' => self::text( 'data.account', $who ),
			'[[notice]]'  => self::text( 'data.notice' ),
		];
		$body = str_replace( array_keys( $values ), array_map( $escape, array_values( $values ) ), $markup );

		$mail = [
			'to'      => $owner,
			'subject' => self::text( 'data.subject', $name ),
			'body'    => str_replace( '[[rows]]', $rows, $body ),
			'replyTo' => filter_var( $account['email'], FILTER_VALIDATE_EMAIL ) === false ? '' : (string) $account['email'],
		];

		$delivered = \Nino\Mail::sendAll( $appData, [ $mail ] );

		if( \Nino\Mail::rateLimited( $appData ) === true ) {
			trigger_error( 'Crm: the mail about the changed data of client '. $client['number']. ' was refused by the cap of mails an hour', E_USER_WARNING );

			return 'limit';
		}

		if( $delivered === false )
			trigger_error( 'Crm: the mail about the changed data of client '. $client['number']. ' was not delivered', E_USER_WARNING );

		return count( array_filter( (array) ( $appData['./nino/mail/results'] ?? [] ), static fn( mixed $result ): bool => $result === true ) );
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		array 		$document
	 *	@param		string		$kind					release or remind, see compose()
	 *
	 *	@return 	int|string
	 */
	private static function send( array &$appData, array $document, string $kind ): int|string {

		if( \Nino\Html::absoluteUrl( $appData, self::PAGE ) === '' )
			return 'address';

		$mails = self::compose( $appData, $document, $kind );

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
