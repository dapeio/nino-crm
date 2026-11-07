<?php
declare(strict_types=1);
/**
 *	Crm\Documents\Mail		The mail that tells the accounts of a client a document was released to the portal: one mail for each active
 *												account of the client, in German (a document is the owner's letter to his client, and an account of
 *												Members has no language of its own), with, for a release, a subject, a button and a sentence of the kind of the document (told()) and the absolute link to the document in the portal (Portal::documentHref(): its row in the list of documents, for a document of a project its card on the page of the project) and no attachment.
 *												All mails are one call of the kernel's mail, which counts a call against the cap of mails an hour and not
 *												a mail. The words are in text/de_DE.php, the markup in templates/release.tpl (changed.tpl for the mail to the owner about changed data, order-owner.tpl and order-confirm.tpl for the order of an offer, approval-owner.tpl for the approval of a project, order-consent.tpl for a sentence about a consent that was given with an order, revocation-owner.tpl for the revocation of a consent, decline-owner.tpl for the decline of an offer, payment-owner.tpl for the report of a payment); a value enters the
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
	 *	@param		string		$note					A sentence of the owner to the customer, in a paragraph of its own under the introduction: escaped, its line breaks kept (release only; '' for none)
	 *
	 *	@return 	array											[ { to, subject, body, replyTo }, ... ]; none for a client without an account that can be written to, or where the site has no address
	 */
	public static function compose( array &$appData, array $document, string $kind = 'release', bool $download = false, string $note = '' ): array {

		$link = \Nino\Html::absoluteUrl( $appData, \Crm\Portal::documentHref( $document, $document['category'] === 'offer' ? 'angebot' : 'belege' ) );

		if( $link === '' || Crm::featureOn( $appData, 'members' ) === false )
			return [];

		$locale = \Nino\Locales::getCurrentLocale( $appData );

		\Nino\Locales::useLocale( $appData, \Nino\Locales::getNativeLocale( $appData ) );

		try {
			$markup = \Nino\Html::renderHtml( $appData, self::template( 'release' ) );
			$files = \Nino\Html::renderHtml( $appData, self::template( 'release-files' ) );
			$detail = \Nino\Html::renderHtml( $appData, self::template( 'release-detail' ) );
			$sentence = \Nino\Html::renderHtml( $appData, self::template( 'release-note' ) );
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

		// The sentence of the owner stands in the place of its slot, or the slot goes: the text is escaped - so that nothing in it is a slot or a shortcode - and only then do its line breaks become breaks
		$markup = str_replace( '[[note]]', $note === '' || $kind !== 'release' ? '' : str_replace( '[[note-text]]', str_replace( "\n", '<br>', $escape( $note ) ), $sentence ), $markup );

		$replyTo = filter_var( $replyTo, FILTER_VALIDATE_EMAIL ) === false ? '' : $replyTo;
		$name = (string) $document['title'] !== '' ? (string) $document['title'] : (string) $document['number'];
		$about = self::text( 'line', self::text( 'kind.'. $document['category'] ), $name );
		$told = $kind === 'release' ? self::told( $appData, $document ) : null;

		// The sentence of the kind of the document stands in the place of the empty slot of the template, or the slot goes
		$markup = str_replace( '[[detail]]', $told === null ? '' : str_replace( '[[detail-text]]', $escape( $told['detail'] ), $detail ), $markup );
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
				'[[cta]]'      => $told === null ? self::text( $prefix. 'button' ) : $told['button'],
				'[[notice]]'   => self::text( $prefix. 'notice' ),
				'[[closing]]'  => self::text( 'closing' ),
			];

			$mails[] = [
				'to'      => $account['email'],
				'subject' => $told === null ? self::text( $prefix. 'subject', $about ) : $told['subject'],
				'body'    => str_replace( array_keys( $values ), array_map( $escape, array_values( $values ) ), $markup ),
				'replyTo' => $replyTo,
			];
		}

		return $mails;
	}

	/**
	 *	What the mail of a released document tells in its own words, by the kind of the document: the subject - the kind and its number, for an offer the project, the gross sum and the
	 *	day it is valid until; for an invoice the sum and the day it is due ("zahlbar sofort" without one) -, the button, and one sentence for the body. The subject is one line (a title
	 *	is typed by the owner, so every control character in it is a blank), and every sum is Money's
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			category, number, title, project, gross, valid_until, due_date
	 *
	 *	@return 	array											subject (plain text), button and detail (plain text, to be escaped by the caller)
	 */
	private static function told( array &$appData, array $document ): array {

		$one = static fn( string $value ): string => trim( (string) preg_replace( '/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $value ) );
		$category = (string) $document['category'];
		$kind = self::text( 'kind.'. $category );
		$number = $one( (string) ( $document['number'] ?? '' ) );
		$title = $one( (string) ( $document['title'] ?? '' ) );
		$id = in_array( $category, [ 'contract', 'privacy', 'information' ], true ) === true ? ( $title !== '' ? $title : $number ) : ( $number !== '' ? $number : $title );
		$head = $id === '' ? $kind : self::text( 'subject.head', $kind, $id );
		$gross = is_int( $document['gross'] ?? null ) === true && in_array( $category, [ 'offer', 'invoice' ], true ) === true ? Money::format( $document['gross'], \Crm\Settings::get( $appData, 'currency' ) ) : '';
		$day = Render::day( (string) ( $category === 'offer' ? ( $document['valid_until'] ?? '' ) : ( $document['due_date'] ?? '' ) ) );
		$facts = [];

		if( $category === 'offer' ) {
			$project = $one( (string) ( $document['project_title'] ?? ( (string) ( $document['project'] ?? '' ) === '' ? '' : ( \Crm\Projekte::get( $appData, (string) $document['project'] )['title'] ?? '' ) ) ) );
			$head = $project === '' ? $head : self::text( 'subject.project', $head, $project );
			$facts = array_filter( [ $gross, $day === '' ? '' : self::text( 'subject.valid', $day ) ], static fn( string $fact ): bool => $fact !== '' );
			$detail = self::text( 'detail.offer' ). ( $day === '' ? '' : ' '. self::text( 'detail.offer-valid', $day ) );
			$button = self::text( 'button.offer' );
		}
		elseif( $category === 'invoice' ) {
			$facts = array_filter( [ $gross, $day === '' ? self::text( 'subject.due-none' ) : self::text( 'subject.due', $day ) ], static fn( string $fact ): bool => $fact !== '' );
			$ways = array_filter( [
				\Crm\Settings::get( $appData, 'iban' ) !== '' || \Crm\Settings::get( $appData, 'bank_name' ) !== '' ? self::text( 'detail.pay-transfer' ) : '',
				\Crm\Portal::paypalLink( $appData, $document ) !== '' ? self::text( 'detail.pay-paypal' ) : '',
			], static fn( string $way ): bool => $way !== '' );
			$detail = ( $gross === '' ? self::text( 'detail.invoice' ) : ( $day === '' ? self::text( 'detail.invoice-now', $gross ) : self::text( 'detail.invoice-due', $gross, $day ) ) )
				. ( $ways === [] ? '' : ' '. self::text( 'detail.pay', implode( self::text( 'detail.pay-or' ), $ways ) ) );
			$button = self::text( 'button.invoice' );
		}
		else {
			$detail = self::text( 'detail.'. $category );
			$button = self::text( 'button.kind', $kind );
		}

		return [ 'subject' => $facts === [] ? $head : self::text( 'subject.facts', $head, implode( ', ', $facts ) ), 'button' => $button, 'detail' => $detail ];
	}

	/**
	 *	Mail the accounts of the client of a document that it was released
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			As Documents::get() has it
	 *	@param		bool			$download			Whether the release switches the download of the project's files on, which the mail says
	 *	@param		string		$note					The sentence of the owner to the customer, see compose()
	 *
	 *	@return 	int|string								How many mails were delivered; 'address' where the site has no address to link to, 'limit' where the cap of mails an hour refused them all
	 */
	public static function release( array &$appData, array $document, bool $download = false, string $note = '' ): int|string {

		return self::send( $appData, $document, 'release', $download, $note );
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
	 *	@param		array 		$consents			The records of kind consent that were given with the order: each is a sentence in both mails (consent.purpose.*), none where none was given
	 *
	 *	@return 	int|string								How many mails were delivered; 'address' where the site has no address to link to, 'limit' where the cap of mails an hour refused them
	 */
	public static function ordered( array &$appData, array $document, array $record, array $client, array $consents = [] ): int|string {

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
			$consentMarkup = \Nino\Html::renderHtml( $appData, self::template( 'order-consent' ) );
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

		// A sentence for each consent that was given with the order, in the words of the purpose; nothing where none was
		$rows = static function( string $key ) use ( $consentMarkup, $escape, $consents ): string {

			$rows = '';

			foreach( $consents as $consent )
				if( in_array( $consent['purpose'] ?? '', \Crm\Records::PURPOSES, true ) === true )
					$rows .= str_replace( '[[sentence]]', $escape( self::text( $key, self::text( 'consent.purpose.'. $consent['purpose'] ) ) ), $consentMarkup );

			return $rows;
		};

		if( filter_var( $owner, FILTER_VALIDATE_EMAIL ) !== false )
			$mails[] = [
				'to'      => $owner,
				'subject' => self::text( 'order.owner.subject', $number, $name, $who ),
				'body'    => str_replace( '[[consents]]', $rows( 'order.owner.consent' ), $replace( $ownerMarkup, [
					'[[heading]]' => self::text( 'order.owner.title' ),
					'[[intro]]'   => self::text( 'order.owner.intro' ),
					'[[offer]]'   => self::text( 'order.owner.offer', $number ),
					'[[client]]'  => self::text( 'order.owner.client', $name ),
					'[[sum]]'     => self::text( 'order.owner.sum', Money::format( (int) $document['gross'], \Crm\Settings::get( $appData, 'currency' ) ) ),
					'[[account]]' => self::text( 'order.owner.account', (string) $record['member_name'] !== '' ? $record['member_name']. ' <'. $record['member_email']. '>' : (string) $record['member_email'] ),
					'[[link]]'    => \Nino\Html::absoluteUrl( $appData, \Crm\Surface::ROUTE. '#beleg-'. (int) $document['id'] ),
					'[[cta]]'     => self::text( 'order.owner.button' ),
					'[[notice]]'  => self::text( 'order.owner.notice' ),
				] ) ),
				'replyTo' => filter_var( $record['member_email'], FILTER_VALIDATE_EMAIL ) === false ? '' : (string) $record['member_email'],
			];

		if( filter_var( $record['member_email'], FILTER_VALIDATE_EMAIL ) !== false ) {

			$project = preg_match( \Crm\Projekte::ID, (string) $document['project'] ) === 1 ? (string) $document['project'] : '';

			$mails[] = [
				'to'      => (string) $record['member_email'],
				'subject' => self::text( 'order.confirm.subject', $number ),
				'body'    => str_replace( '[[consents]]', $rows( 'order.confirm.consent' ), $replace( $confirmMarkup, [
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
				] ) ),
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
	 *	Mail the owner that a client took a consent back in the portal: one mail to the contact address of the company (subject "Einwilligung widerrufen: <purpose>, Projekt <id and
	 *	title>, Kunde <number and title> durch <account>", the reply to the account, the link to the card of the project in the surface - to the card of the client where the consent was
	 *	not for a project). The customer gets no mail. Own template (templates/revocation-owner.tpl) and own words (text/de_DE.php, revocation.*, consent.purpose.*); the names enter
	 *	the subject without control characters
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$revocation		The record of kind revocation, as Records::revoke() has it: it holds the purpose and the project of the consent
	 *	@param		array 		$client				As Clients::get() has it
	 *
	 *	@return 	int|string								How many mails were delivered; 'address' where the site has no address to link to or the company has no contact address, 'limit' where the cap of mails an hour refused it
	 */
	public static function revoked( array &$appData, array $revocation, array $client ): int|string {

		$project = (string) $revocation['project'];
		$link = \Nino\Html::absoluteUrl( $appData, \Crm\Surface::ROUTE. ( $project === '' ? '#kunde-'. (int) $client['id'] : '#projekt-'. rawurlencode( $project ) ) );

		if( $link === '' ) {
			trigger_error( 'Crm: the mail about the revocation of a consent was not sent: the site has no address to link to', E_USER_WARNING );

			return 'address';
		}

		$locale = \Nino\Locales::getCurrentLocale( $appData );

		\Nino\Locales::useLocale( $appData, \Nino\Locales::getNativeLocale( $appData ) );

		try {
			$markup = \Nino\Html::renderHtml( $appData, self::template( 'revocation-owner' ) );
			$owner = trim( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/email' ) );
		}
		finally {
			\Nino\Locales::useLocale( $appData, $locale );
		}

		if( filter_var( $owner, FILTER_VALIDATE_EMAIL ) === false ) {
			trigger_error( 'Crm: the mail about the revocation of a consent was not sent: the company has no contact address', E_USER_WARNING );

			return 'address';
		}

		$line = static fn( string $text ): string => trim( (string) preg_replace( '/[\s\p{C}]+/u', ' ', $text ) );
		$escape = static fn( string $value ): string => \Nino\Modules\Components::escape( $value );
		$found = $project === '' ? null : \Crm\Projekte::get( $appData, $project );
		$name = $line( $project. ( $found !== null && (string) $found['title'] !== '' ? ' '. $found['title'] : '' ) );
		$purpose = self::text( 'consent.purpose.'. $revocation['purpose'] );
		$customer = $line( (string) $client['number']. ' '. (string) $client['title'] );
		$who = $line( (string) $revocation['member_name'] !== '' ? (string) $revocation['member_name'] : (string) $revocation['member_email'] );
		$account = $line( (string) $revocation['member_name'] !== '' ? $revocation['member_name']. ' <'. $revocation['member_email']. '>' : (string) $revocation['member_email'] );
		$values = [
			'[[heading]]' => self::text( 'revocation.owner.title' ),
			'[[intro]]'   => self::text( 'revocation.owner.intro' ),
			'[[purpose]]' => self::text( 'revocation.owner.purpose', $purpose ),
			'[[project]]' => $project === '' ? self::text( 'revocation.owner.project.all' ) : self::text( 'revocation.owner.project', $name ),
			'[[client]]'  => self::text( 'revocation.owner.client', $customer ),
			'[[account]]' => self::text( 'revocation.owner.account', $account ),
			'[[link]]'    => $link,
			'[[cta]]'     => self::text( $project === '' ? 'revocation.owner.button.client' : 'revocation.owner.button.project' ),
			'[[notice]]'  => self::text( 'revocation.owner.notice' ),
		];

		$delivered = \Nino\Mail::sendAll( $appData, [ [
			'to'      => $owner,
			'subject' => self::text( 'revocation.owner.subject', $purpose, $project === '' ? self::text( 'revocation.owner.all' ) : $name, $customer, $who ),
			'body'    => str_replace( array_keys( $values ), array_map( $escape, array_values( $values ) ), $markup ),
			'replyTo' => filter_var( $revocation['member_email'], FILTER_VALIDATE_EMAIL ) === false ? '' : (string) $revocation['member_email'],
		] ] );

		if( \Nino\Mail::rateLimited( $appData ) === true ) {
			trigger_error( 'Crm: the mail about the revocation of a consent was refused by the cap of mails an hour', E_USER_WARNING );

			return 'limit';
		}

		if( $delivered === false )
			trigger_error( 'Crm: the mail about the revocation of a consent was not delivered', E_USER_WARNING );

		return count( array_filter( (array) ( $appData['./nino/mail/results'] ?? [] ), static fn( mixed $result ): bool => $result === true ) );
	}

	/**
	 *	Mail the owner that a client declined an offer in the portal: one mail to the contact address of the company (subject "Angebot abgelehnt: <number> von Kunde <number and title>
	 *	durch <account>", the reply to the account, the reason the customer gave or the sentence that it gave none, the link to the document in the surface). The customer gets no mail.
	 *	Own template (templates/decline-owner.tpl) and own words (text/de_DE.php, decline.owner.*); the names enter the subject without control characters and the reason enters the
	 *	body escaped, its line breaks as breaks
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			The offer, as Documents::get() has it, declined
	 *	@param		array 		$record				The record of the decline, as Records::of() has it
	 *	@param		array 		$client				As Clients::get() has it
	 *	@param		string		$reason				As Records::reason() has it; '' for none
	 *
	 *	@return 	int|string								How many mails were delivered; 'address' where the site has no address to link to or the company has no contact address, 'limit' where the cap of mails an hour refused it
	 */
	public static function declined( array &$appData, array $document, array $record, array $client, string $reason ): int|string {

		$line = static fn( string $text ): string => trim( (string) preg_replace( '/[\s\p{C}]+/u', ' ', $text ) );
		$number = $line( (string) $document['number'] !== '' ? (string) $document['number'] : (string) $document['title'] );
		$customer = $line( (string) $client['number']. ' '. (string) $client['title'] );

		return self::toOwner( $appData, 'decline-owner', 'decline.owner', 'decline', $record, \Nino\Html::absoluteUrl( $appData, \Crm\Surface::ROUTE. '#beleg-'. (int) $document['id'] ), [
			'[[offer]]'  => self::text( 'decline.owner.offer', $number ),
			'[[client]]' => self::text( 'decline.owner.client', $customer ),
		], [
			'[[reason]]' => $reason === '' ? self::text( 'decline.owner.reason-none' ) : self::text( 'decline.owner.reason', $reason ),
		], [ $number, $customer ], 'document '. Render::name( $document ) );
	}

	/**
	 *	Mail the owner that a client reported in the portal that it has paid an invoice: one mail to the contact address of the company (subject "Zahlung gemeldet: Rechnung <number>
	 *	<sum> von Kunde <number and title> durch <account>", the reply to the account, the link to the document in the surface). The customer gets no mail. Own template
	 *	(templates/payment-owner.tpl) and own words (text/de_DE.php, payment.owner.*); the names enter the subject without control characters
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			The invoice, as Documents::get() has it
	 *	@param		array 		$record				The record of the payment, as Records::of() has it
	 *	@param		array 		$client				As Clients::get() has it
	 *
	 *	@return 	int|string								How many mails were delivered; 'address' where the site has no address to link to or the company has no contact address, 'limit' where the cap of mails an hour refused it
	 */
	public static function paid( array &$appData, array $document, array $record, array $client ): int|string {

		$line = static fn( string $text ): string => trim( (string) preg_replace( '/[\s\p{C}]+/u', ' ', $text ) );
		$number = $line( (string) $document['number'] !== '' ? (string) $document['number'] : (string) $document['title'] );
		$customer = $line( (string) $client['number']. ' '. (string) $client['title'] );
		$sum = Money::format( (int) $document['gross'], \Crm\Settings::get( $appData, 'currency' ) );

		return self::toOwner( $appData, 'payment-owner', 'payment.owner', 'payment notice', $record, \Nino\Html::absoluteUrl( $appData, \Crm\Surface::ROUTE. '#beleg-'. (int) $document['id'] ), [
			'[[invoice]]' => self::text( 'payment.owner.invoice', $number ),
			'[[client]]'  => self::text( 'payment.owner.client', $customer ),
			'[[sum]]'     => self::text( 'payment.owner.sum', $sum ),
		], [], [ $number, $sum, $customer ], 'document '. Render::name( $document ) );
	}

	/**
	 *	The one mail to the contact address of the company that tells what a customer did in the portal: the template and the words of a kind, the account that did it as the
	 *	reply-to and in the body, the link and its button
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$template			A file of templates/ without .tpl
	 *	@param		string		$words				The prefix of the words: <prefix>.subject, .title, .intro, .account, .button and .notice
	 *	@param		string		$about				What happened, for the line of the log
	 *	@param		array 		$record				The record, as Records::of() has it: it holds the account
	 *	@param		string		$link					Absolute; '' where the site has no address
	 *	@param		array 		$values				The tokens of the body that are the kind's and are escaped
	 *	@param		array 		$free					Tokens of the body that hold text a customer wrote: escaped, their line breaks made breaks
	 *	@param		array 		$subject			The values of the words <prefix>.subject before the account
	 *	@param		string		$name					What the line of the log names
	 *
	 *	@return 	int|string								How many mails were delivered; 'address' or 'limit'
	 */
	private static function toOwner( array &$appData, string $template, string $words, string $about, array $record, string $link, array $values, array $free, array $subject, string $name ): int|string {

		if( $link === '' ) {
			trigger_error( 'Crm: the mail about the '. $about. ' of '. $name. ' was not sent: the site has no address to link to', E_USER_WARNING );

			return 'address';
		}

		$locale = \Nino\Locales::getCurrentLocale( $appData );

		\Nino\Locales::useLocale( $appData, \Nino\Locales::getNativeLocale( $appData ) );

		try {
			$markup = \Nino\Html::renderHtml( $appData, self::template( $template ) );
			$owner = trim( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/email' ) );
		}
		finally {
			\Nino\Locales::useLocale( $appData, $locale );
		}

		if( filter_var( $owner, FILTER_VALIDATE_EMAIL ) === false ) {
			trigger_error( 'Crm: the mail about the '. $about. ' of '. $name. ' was not sent: the company has no contact address', E_USER_WARNING );

			return 'address';
		}

		$line = static fn( string $text ): string => trim( (string) preg_replace( '/[\s\p{C}]+/u', ' ', $text ) );
		$escape = static fn( string $value ): string => \Nino\Modules\Components::escape( $value );
		$who = $line( (string) $record['member_name'] !== '' ? (string) $record['member_name'] : (string) $record['member_email'] );
		$account = $line( (string) $record['member_name'] !== '' ? $record['member_name']. ' <'. $record['member_email']. '>' : (string) $record['member_email'] );
		$values += [
			'[[heading]]' => self::text( $words. '.title' ),
			'[[intro]]'   => self::text( $words. '.intro' ),
			'[[account]]' => self::text( $words. '.account', $account ),
			'[[link]]'    => $link,
			'[[cta]]'     => self::text( $words. '.button' ),
			'[[notice]]'  => self::text( $words. '.notice' ),
		];
		$body = str_replace( array_keys( $values ), array_map( $escape, array_values( $values ) ), $markup );

		foreach( $free as $token => $text )
			$body = str_replace( $token, str_replace( [ "\r\n", "\r", "\n" ], '<br>', $escape( $text ) ), $body );

		$delivered = \Nino\Mail::sendAll( $appData, [ [
			'to'      => $owner,
			'subject' => self::text( $words. '.subject', ...array_merge( $subject, [ $who ] ) ),
			'body'    => $body,
			'replyTo' => filter_var( $record['member_email'], FILTER_VALIDATE_EMAIL ) === false ? '' : (string) $record['member_email'],
		] ] );

		if( \Nino\Mail::rateLimited( $appData ) === true ) {
			trigger_error( 'Crm: the mail about the '. $about. ' of '. $name. ' was refused by the cap of mails an hour', E_USER_WARNING );

			return 'limit';
		}

		if( $delivered === false )
			trigger_error( 'Crm: the mail about the '. $about. ' of '. $name. ' was not delivered', E_USER_WARNING );

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
	private static function send( array &$appData, array $document, string $kind, bool $download = false, string $note = '' ): int|string {

		if( \Nino\Html::absoluteUrl( $appData, self::PAGE ) === '' )
			return 'address';

		$mails = self::compose( $appData, $document, $kind, $download, $note );

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
