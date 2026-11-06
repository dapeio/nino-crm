<?php
declare(strict_types=1);
/**
 *	Crm\Documents\Mail		The mail that tells the accounts of a client a document was released to the portal: one mail for each active
 *												account of the client, in German (a document is the owner's letter to his client, and an account of
 *												Members has no language of its own), with the absolute link to the document in the portal (Portal::documentHref(): its row in the list of documents, for a document of a project its card on the page of the project) and no attachment.
 *												All mails are one call of the kernel's mail, which counts a call against the cap of mails an hour and not
 *												a mail. The words are in text/de_DE.php, the markup in templates/release.tpl (changed.tpl for the mail to the owner about changed data, order-owner.tpl and order-confirm.tpl for the order of an offer, approval-owner.tpl for the approval of a project); a value enters the
 *												template after it was rendered, escaped, so that nothing in it is read as a fill or a shortcode. The same
 *												mail, in the words of a reminder (text/de_DE.php, remind.*), asks for the payment of an invoice that is overdue.
 */
namespace Crm\Documents;

use Crm\Clients;
use Crm\Crm;

class Mail {

	// The list of documents of the portal: the page of a document that has no project, and the page a mail asks for the address of the site with
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
	 *	The first sentence of a reminder: the day the invoice was due, the day it is due, or no day
	 *
	 *	@param		array 		$document			due_date
	 *	@param		string		$due					The due day as d.m.Y, '' for none
	 *
	 *	@return 	string
	 */
	private static function remindIntro( array $document, string $due ): string {

		if( $due === '' )
			return self::text( 'remind.intro-undated' );

		return (string) $document['due_date'] >= date( 'Y-m-d' ) ? self::text( 'remind.intro-due', $due ) : self::text( 'remind.intro', $due );
	}

	/**
	 *	The mails for a document: one for each active account of its client that has an address
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			As Documents::get() has it
	 *	@param		string		$kind					release (a document is there for the client) or remind (an invoice that is open or sent, released to the portal)
	 *	@param		bool			$download			Whether the release also switches the download of the files of the document's project on: a second sentence says so, with the way to the files (release only)
	 *
	 *	@return 	array											[ { to, subject, body, replyTo }, ... ]; none for a client without an account that can be written to, or where the site has no address
	 */
	public static function compose( array &$appData, array $document, string $kind = 'release', bool $download = false ): array {

		$link = \Nino\Html::absoluteUrl( $appData, \Crm\Portal::documentHref( $document, $document['category'] === 'offer' ? 'angebot' : 'belege' ) );

		if( $link === '' || Crm::featureOn( $appData, 'members' ) === false )
			return [];

		$locale = \Nino\Locales::getCurrentLocale( $appData );

		\Nino\Locales::useLocale( $appData, \Nino\Locales::getNativeLocale( $appData ) );

		try {
			$markup = \Nino\Html::renderHtml( $appData, self::template( 'release' ) );
			$files = \Nino\Html::renderHtml( $appData, self::template( 'release-files' ) );
			$replyTo = trim( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/email' ) );
		}
		finally {
			\Nino\Locales::useLocale( $appData, $locale );
		}

		// The sentence about the files stands in the place of the empty slot of the template, or the slot goes
		$project = $download === true && $kind === 'release' && (string) $document['project'] !== '' ? \Crm\Projekte::get( $appData, (string) $document['project'] ) : null;
		$escape = static fn( string $value ): string => \Nino\Modules\Components::escape( $value );
		$markup = str_replace( '[[files]]', $project === null ? '' : str_replace(
			[ '[[files-text]]', '[[files-link]]', '[[files-cta]]' ],
			[ $escape( self::text( 'files.text', $project['title'] !== '' ? $project['title'] : $project['id'] ) ), $escape( \Nino\Html::absoluteUrl( $appData, '/portal/projekte?projekt='. rawurlencode( $project['id'] ). '&tab=dateien' ) ), $escape( self::text( 'files.button' ) ) ],
			$files
		), $markup );

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
				'[[intro]]'    => $kind === 'remind' ? self::remindIntro( $document, $due ) : self::text( 'intro' ),
				'[[document]]' => $about,
				'[[link]]'     => $link,
				'[[cta]]'      => self::text( $prefix. 'button' ),
				'[[notice]]'   => self::text( $prefix. 'notice' ),
				'[[closing]]'  => self::text( 'closing' ),
			];

			$mails[] = [
				'to'      => $account['email'],
				'subject' => self::text( $prefix. 'subject', $about ),
				'body'    => str_replace( array_keys( $values ), array_map( $escape, array_values( $values ) ), $markup ),
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
	 *	@param		bool			$download			Whether the release switches the download of the project's files on, which the mail says
	 *
	 *	@return 	int|string								How many mails were delivered; 'address' where the site has no address to link to, 'limit' where the cap of mails an hour refused them all
	 */
	public static function release( array &$appData, array $document, bool $download = false ): int|string {

		return self::send( $appData, $document, 'release', $download );
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
	 *	@param		array 		$changes			field => [ before, after ] of each field that is different - the fields of the form, and login: the address the account signs in with, which the member changed
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
		$name = (string) $client['number']. ' '. (string) $client['title'];
		$values = [
			'[[heading]]' => self::text( 'data.title' ),
			'[[intro]]'   => self::text( 'data.intro' ),
			'[[client]]'  => $name,
			'[[head-field]]'  => self::text( 'data.head.field' ),
			'[[head-before]]' => self::text( 'data.head.before' ),
			'[[head-after]]'  => self::text( 'data.head.after' ),
			'[[account]]' => self::text( 'data.account', $who ),
			'[[notice]]'  => self::text( array_key_exists( 'login', $changes ) === true ? 'data.notice-login' : 'data.notice' ),
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
	 *	Mail the owner and the customer that an offer was ordered in the portal: one call of the kernel's mail with two mails. The owner's goes to the contact address
	 *	of the company (subject "Auftrag erteilt: Angebot <number> von Kunde <number and company> durch <account>", the reply to the account, the link to the
	 *	document in the surface); the customer's goes to the account that ordered (the offer, the day, the way to the project page or to the page Belege and the
	 *	address of the PDF - no attachment, the reply to the company). Own templates (templates/order-owner.tpl, order-confirm.tpl) and own words (text/de_DE.php, order.*)
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			The offer, as Documents::get() has it, accepted
	 *	@param		array 		$record				The record of the order, as Records::of() has it
	 *	@param		array 		$client				As Clients::get() has it
	 *
	 *	@return 	int|string								How many mails were delivered; 'address' where the site has no address to link to, 'limit' where the cap of mails an hour refused them
	 */
	public static function ordered( array &$appData, array $document, array $record, array $client ): int|string {

		$documents = \Nino\Html::absoluteUrl( $appData, self::PAGE );

		if( $documents === '' ) {
			trigger_error( 'Crm: the mails about the order of document '. Render::name( $document ). ' were not sent: the site has no address to link to', E_USER_WARNING );

			return 'address';
		}

		$locale = \Nino\Locales::getCurrentLocale( $appData );

		\Nino\Locales::useLocale( $appData, \Nino\Locales::getNativeLocale( $appData ) );

		try {
			$ownerMarkup = \Nino\Html::renderHtml( $appData, self::template( 'order-owner' ) );
			$confirmMarkup = \Nino\Html::renderHtml( $appData, self::template( 'order-confirm' ) );
			$owner = trim( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/email' ) );
		}
		finally {
			\Nino\Locales::useLocale( $appData, $locale );
		}

		$escape = static fn( string $value ): string => \Nino\Modules\Components::escape( $value );
		$replace = static fn( string $markup, array $values ): string => str_replace( array_keys( $values ), array_map( $escape, array_values( $values ) ), $markup );
		$number = (string) $document['number'] !== '' ? (string) $document['number'] : (string) $document['title'];
		$who = (string) $record['member_name'] !== '' ? (string) $record['member_name'] : (string) $record['member_email'];
		$name = (string) $client['number']. ' '. (string) $client['title'];
		$day = Render::day( substr( (string) $record['at'], 0, 10 ) );
		$mails = [];

		if( filter_var( $owner, FILTER_VALIDATE_EMAIL ) !== false )
			$mails[] = [
				'to'      => $owner,
				'subject' => self::text( 'order.owner.subject', $number, $name, $who ),
				'body'    => $replace( $ownerMarkup, [
					'[[heading]]' => self::text( 'order.owner.title' ),
					'[[intro]]'   => self::text( 'order.owner.intro' ),
					'[[offer]]'   => self::text( 'order.owner.offer', $number ),
					'[[client]]'  => self::text( 'order.owner.client', $name ),
					'[[sum]]'     => self::text( 'order.owner.sum', Money::format( (int) $document['gross'], \Crm\Settings::get( $appData, 'currency' ) ) ),
					'[[account]]' => self::text( 'order.owner.account', (string) $record['member_name'] !== '' ? $record['member_name']. ' <'. $record['member_email']. '>' : (string) $record['member_email'] ),
					'[[link]]'    => \Nino\Html::absoluteUrl( $appData, \Crm\Surface::ROUTE. '#beleg-'. (int) $document['id'] ),
					'[[cta]]'     => self::text( 'order.owner.button' ),
					'[[notice]]'  => self::text( 'order.owner.notice' ),
				] ),
				'replyTo' => filter_var( $record['member_email'], FILTER_VALIDATE_EMAIL ) === false ? '' : (string) $record['member_email'],
			];

		if( filter_var( $record['member_email'], FILTER_VALIDATE_EMAIL ) !== false ) {

			$project = preg_match( \Crm\Projekte::ID, (string) $document['project'] ) === 1 ? (string) $document['project'] : '';

			$mails[] = [
				'to'      => (string) $record['member_email'],
				'subject' => self::text( 'order.confirm.subject', $number ),
				'body'    => $replace( $confirmMarkup, [
					'[[heading]]'        => self::text( 'order.confirm.title' ),
					'[[greeting]]'       => self::text( 'greeting', $who ),
					'[[intro]]'          => self::text( 'order.confirm.intro', $day ),
					'[[offer]]'          => self::text( 'order.confirm.offer', $number ),
					'[[link]]'           => \Nino\Html::absoluteUrl( $appData, \Crm\Portal::documentHref( $document, 'angebot' ) ),
					'[[cta]]'            => self::text( $project === '' ? 'order.confirm.button.documents' : 'order.confirm.button.project' ),
					'[[download]]'       => \Nino\Html::absoluteUrl( $appData, '/.crm/pdf/'. (int) $document['id']. '?download=1' ),
					'[[download-label]]' => self::text( 'order.confirm.download' ),
					'[[notice]]'         => self::text( 'order.confirm.notice' ),
					'[[closing]]'        => self::text( 'closing' ),
				] ),
				'replyTo' => filter_var( $owner, FILTER_VALIDATE_EMAIL ) === false ? '' : $owner,
			];
		}

		if( $mails === [] )
			return 0;

		$delivered = \Nino\Mail::sendAll( $appData, $mails );

		if( \Nino\Mail::rateLimited( $appData ) === true ) {
			trigger_error( 'Crm: the mails about the order of document '. Render::name( $document ). ' were refused by the cap of mails an hour', E_USER_WARNING );

			return 'limit';
		}

		if( $delivered === false )
			trigger_error( 'Crm: the mails about the order of document '. Render::name( $document ). ' were not all delivered', E_USER_WARNING );

		return count( array_filter( (array) ( $appData['./nino/mail/results'] ?? [] ), static fn( mixed $result ): bool => $result === true ) );
	}

	/**
	 *	Mail the owner that a client approved a project in the portal: one mail to the contact address of the company (subject "Projekt abgenommen: <id and title> von Kunde <number and
	 *	title> durch <account>", the reply to the account, the link to the card of the project in the surface). The customer gets no mail. Own template (templates/approval-owner.tpl) and own
	 *	words (text/de_DE.php, approval.*); the names enter the subject without control characters
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$project			As Projekte::get() has it
	 *	@param		array 		$record				The record of the approval, as Records::of() has it
	 *	@param		array 		$client				As Clients::get() has it
	 *	@param		int				$files				How many files the approval was of
	 *
	 *	@return 	int|string								How many mails were delivered; 'address' where the site has no address to link to, 'limit' where the cap of mails an hour refused it
	 */
	public static function approved( array &$appData, array $project, array $record, array $client, int $files ): int|string {

		$link = \Nino\Html::absoluteUrl( $appData, \Crm\Surface::ROUTE. '#projekt-'. rawurlencode( (string) $project['id'] ) );

		if( $link === '' ) {
			trigger_error( 'Crm: the mail about the approval of project '. $project['id']. ' was not sent: the site has no address to link to', E_USER_WARNING );

			return 'address';
		}

		$locale = \Nino\Locales::getCurrentLocale( $appData );

		\Nino\Locales::useLocale( $appData, \Nino\Locales::getNativeLocale( $appData ) );

		try {
			$markup = \Nino\Html::renderHtml( $appData, self::template( 'approval-owner' ) );
			$owner = trim( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/email' ) );
		}
		finally {
			\Nino\Locales::useLocale( $appData, $locale );
		}

		if( filter_var( $owner, FILTER_VALIDATE_EMAIL ) === false ) {
			trigger_error( 'Crm: the mail about the approval of project '. $project['id']. ' was not sent: the company has no contact address', E_USER_WARNING );

			return 'address';
		}

		$line = static fn( string $text ): string => trim( (string) preg_replace( '/[\s\p{C}]+/u', ' ', $text ) );
		$escape = static fn( string $value ): string => \Nino\Modules\Components::escape( $value );
		$name = $line( (string) $project['id']. ( (string) $project['title'] !== '' ? ' '. $project['title'] : '' ) );
		$customer = $line( (string) $client['number']. ' '. (string) $client['title'] );
		$who = $line( (string) $record['member_name'] !== '' ? (string) $record['member_name'] : (string) $record['member_email'] );
		$account = $line( (string) $record['member_name'] !== '' ? $record['member_name']. ' <'. $record['member_email']. '>' : (string) $record['member_email'] );
		$values = [
			'[[heading]]' => self::text( 'approval.owner.title' ),
			'[[intro]]'   => self::text( 'approval.owner.intro' ),
			'[[project]]' => self::text( 'approval.owner.project', $name ),
			'[[client]]'  => self::text( 'approval.owner.client', $customer ),
			'[[files]]'   => self::text( 'approval.owner.files', (string) $files ),
			'[[account]]' => self::text( 'approval.owner.account', $account ),
			'[[link]]'    => $link,
			'[[cta]]'     => self::text( 'approval.owner.button' ),
			'[[notice]]'  => self::text( 'approval.owner.notice' ),
		];

		$delivered = \Nino\Mail::sendAll( $appData, [ [
			'to'      => $owner,
			'subject' => self::text( 'approval.owner.subject', $name, $customer, $who ),
			'body'    => str_replace( array_keys( $values ), array_map( $escape, array_values( $values ) ), $markup ),
			'replyTo' => filter_var( $record['member_email'], FILTER_VALIDATE_EMAIL ) === false ? '' : (string) $record['member_email'],
		] ] );

		if( \Nino\Mail::rateLimited( $appData ) === true ) {
			trigger_error( 'Crm: the mail about the approval of project '. $project['id']. ' was refused by the cap of mails an hour', E_USER_WARNING );

			return 'limit';
		}

		if( $delivered === false )
			trigger_error( 'Crm: the mail about the approval of project '. $project['id']. ' was not delivered', E_USER_WARNING );

		return count( array_filter( (array) ( $appData['./nino/mail/results'] ?? [] ), static fn( mixed $result ): bool => $result === true ) );
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		array 		$document
	 *	@param		string		$kind					release or remind, see compose()
	 *	@param		bool			$download			See compose()
	 *
	 *	@return 	int|string
	 */
	private static function send( array &$appData, array $document, string $kind, bool $download = false ): int|string {

		if( \Nino\Html::absoluteUrl( $appData, self::PAGE ) === '' )
			return 'address';

		$mails = self::compose( $appData, $document, $kind, $download );

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
