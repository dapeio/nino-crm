<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Modules\Events\Tokens	see _nino/Nino/Modules/Modules.php for the
 *											package-level docblock
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules\Events {

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Tokens						The feed links of the members. A member's feed is the public feed
	 *										and the dates of their group, and the link that asks for it
	 *										carries a token, ?k=<64 hex characters>, because a calendar app
	 *										has no session.
	 *
	 *										/data/events.php holds a secret of 32 random bytes, made on the
	 *										first token, and an index of tokens: the sha256 of each token with
	 *										the id of the account it was made for. The token of an account is
	 *										derived from the secret and the id (HMAC-SHA256), so that the page
	 *										can show the same link every time it is drawn - a member puts it
	 *										into a calendar app once - while the index is what the feed looks
	 *										a token up in. It is never a rule of its own who a token is for: the
	 *										feed asks Members for the account at the moment it is read, so an
	 *										account that was deleted or switched off has no feed and the group
	 *										of an account that moved is the new one. Deleting the file ends
	 *										every link at once.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Tokens {

		// Where the secret and the index are kept, which the manifest names under data
		public const string FILE = '/data/events.php';

		// A token as the feed accepts it
		public const string PATTERN = '/^[a-f0-9]{64}$/D';

		/**
		 *	The token of an account, made and entered into the index where there is none yet
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$account			The id of the account
		 *
		 *	@return 	string								64 hex characters; '' where the file could not be written
		 */
		public static function forAccount( array &$appData, string $account ): string {

			// The page draws the link at every visit of a member: once the entry is there, that is a read and not a lock
			$state = self::_state( \Nino\Filesystem::getFileContent( $appData, self::FILE, [] ) );

			if( $state['secret'] !== '' ) {

				$token = self::_token( $state['secret'], $account );

				if( ( $state['tokens'][ hash( 'sha256', $token ) ]['account'] ?? null ) === $account )
					return $token;
			}

			$token	= '';
			$ready	= false;
			$stamp	= \Nino\Modules\Events::now( $appData )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );

			$written = \Nino\Filesystem::mutate( $appData, self::FILE, static function( mixed $state ) use ( $account, $stamp, &$token, &$ready ): ?array {

				$state = self::_state( $state );

				if( $state['secret'] === '' )
					$state['secret'] = bin2hex( random_bytes( 32 ) );

				$token	= self::_token( $state['secret'], $account );
				$hash		= hash( 'sha256', $token );

				// Made by a request that was quicker: nothing to write
				if( ( $state['tokens'][$hash]['account'] ?? null ) === $account ) {
					$ready = true;
					return null;
				}

				$state['tokens'][$hash] = [ 'account' => $account, 'created' => $stamp ];

				return $state;
			}, [] );

			return $written === true || $ready === true ? $token : '';
		}

		/**
		 *	The account a token was made for
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$token
		 *
		 *	@return 	string|null						The id of the account; null for a token that is none or that nobody holds
		 */
		public static function account( array &$appData, string $token ): ?string {

			if( preg_match( self::PATTERN, $token ) !== 1 )
				return null;

			$state = self::_state( \Nino\Filesystem::getFileContent( $appData, self::FILE, [] ) );

			return $state['tokens'][ hash( 'sha256', $token ) ]['account'] ?? null;
		}

		/**
		 *	The token of an account: the HMAC-SHA256 of its id under the secret, in hex
		 *
		 *	@param		string		$secret				64 hex characters
		 *	@param		string		$account
		 *
		 *	@return 	string								64 hex characters
		 */
		private static function _token( string $secret, string $account ): string {

			return hash_hmac( 'sha256', 'feed:'. $account, $secret );
		}

		/**
		 *	The file as it is: a secret and an index, and nothing else
		 *
		 *	@param		mixed			$state				What the file holds
		 *
		 *	@return 	array									format, secret (hex or ''), tokens (hash => account, created)
		 */
		private static function _state( mixed $state ): array {

			$state	= is_array( $state ) === true ? $state : [];
			$tokens	= [];

			foreach( is_array( $state['tokens'] ?? null ) === true ? $state['tokens'] : [] as $hash => $entry )
				if( is_string( $hash ) === true && preg_match( '/^[a-f0-9]{64}$/D', $hash ) === 1 && is_array( $entry ) === true && is_string( $entry['account'] ?? null ) === true )
					$tokens[$hash] = [ 'account' => $entry['account'], 'created' => is_string( $entry['created'] ?? null ) === true ? $entry['created'] : '' ];

			return [
				'format'	=> 1,
				'secret'	=> is_string( $state['secret'] ?? null ) === true && preg_match( '/^[a-f0-9]{64}$/D', $state['secret'] ) === 1 ? $state['secret'] : '',
				'tokens'	=> $tokens,
			];
		}
	}
}
