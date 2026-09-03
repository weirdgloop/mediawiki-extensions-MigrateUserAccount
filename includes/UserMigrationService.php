<?php

namespace MediaWiki\Extension\MigrateUserAccount;

use MediaWiki\Config\Config;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\RenameUser\RenameUserFactory;
use MediaWiki\Session\Session;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserRigorOptions;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;

class UserMigrationService {
	private RenameUserFactory $renameUserFactory;

	private UserFactory $userFactory;

	private Config $config;

	private LoggerInterface $logger;

	private ILoadBalancer $loadBalancer;

	private HttpRequestFactory $httpRequestFactory;

	private WANObjectCache $wanCache;

	public function __construct(
		RenameUserFactory $renameUserFactory,
		UserFactory $userFactory,
		Config $config,
		ILoadBalancer $loadBalancer,
		HttpRequestFactory $httpRequestFactory,
		WANObjectCache $wanCache
	) {
		$this->renameUserFactory = $renameUserFactory;
		$this->userFactory = $userFactory;
		$this->config = $config;
		$this->loadBalancer = $loadBalancer;
		$this->httpRequestFactory = $httpRequestFactory;
		$this->wanCache = $wanCache;
		$this->logger = LoggerFactory::getInstance( 'MigrateUserAccount' );
	}

	/**
	 * Complete the migration process for a user.
	 * @param string $username
	 * @param string $password
	 * @param string|null $newUsername
	 * @return StatusValue
	 */
	public function migrateUser( string $username, string $password, ?string $newUsername = null ): StatusValue {
		$status = StatusValue::newGood();
		$user = $this->userFactory->newFromName( $username );

		if ( !$user->isRegistered() ) {
			return $status->fatal( 'migrateuseraccount-not-registered' );
		}
		if ( !$user->isValidPassword( $password ) ) {
			return $status->fatal( 'migrateuseraccount-invalid-password' );
		}

		if ( $newUsername ) {
			$newUser = $this->userFactory->newFromName( $newUsername, UserRigorOptions::RIGOR_CREATABLE );
			if ( $newUser === null || $newUser->isRegistered() ) {
				return $status->fatal( 'migrateuseraccount-invalid-username' );
			}

			$rename = $this->renameUserFactory->newRenameUser(
				User::newSystemUser( $this->config->get( 'MUAFallbackRenameActor' ), [ 'steal' => true ] ),
				$user,
				$newUsername,
				'Conflicting username when migrating user account',
				[ 'movePages' => true ]
			);

			if ( !$rename->renameUnsafe()->isOK() ) {
				return $status->fatal( 'migrateuseraccount-rename-failed' );
			}

			$this->logger->info( $user->getName() . ' has renamed their account to ' . $newUser->getName() );

			$user = $this->userFactory->newFromName( $newUser->getName() );
		}

		if ( !$user->changeAuthenticationData( [
			'password' => $password,
			'retype' => $password
		] ) ) {
			$this->logger->error( $user->getName() . ' failed to change auth data: ' .
				$status->getMessages()[0]->getKey()
			);
			return $status->fatal( 'migrateuseraccount-failed' );
		}

		$this->logger->info( $user->getName() . ' has migrated their account successfully from ' .
			$this->config->get( 'MUARemoteWikiAPI' )
		);

		if ( $this->config->get( 'MUALogToWiki' ) ) {
			$this->logMigration( $user );
		}

		return $status->setResult( true, $user );
	}

	/**
	 * Checks whether a particular username is migratable
	 * @param string $username
	 * @return StatusValue
	 */
	public function checkUserCanMigrate( string $username ): StatusValue {
		$status = StatusValue::newGood();
		$isFallback = false;
		$fallbackSuffix = $this->config->get( 'MUAFallbackSuffix' );

		while ( true ) {
			// Ensure that the user is a stub (has no password set) before continuing
			$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
			$row = $dbr->selectRow( 'user', [
				'user_id', 'user_password', 'user_is_temp' ], [ 'user_name' => $username ],
				__METHOD__ );

			if ( !$row || $row->user_password != '' || $row->user_is_temp ) {
				// User is not a stub
				if ( !empty( $fallbackSuffix && !$isFallback ) ) {
					// If a fallback suffix is set, try again but with that suffix
					$username .= $fallbackSuffix;
					$isFallback = true;
					continue;
				}

				// If a fallback suffix is not set, or we tried with the fallback suffix and no dice
				return $status->fatal( 'migrateuseraccount-error-user-invalid' );
			}

			break;
		}

		return $status->setResult( true, $username );
	}

	/**
	 * Generate a unique token for the username + session pair, which can be used for migrating.
	 * @param string $username
	 * @param Session $session
	 * @return string
	 */
	public function generateToken( string $username, Session $session ): string {
		$secret = pack( 'H*', $this->config->get( 'MUATokenSecret' ) );
		$token = hash_hmac( 'sha256', $username . ':' . $session->getId(), $secret );
		return base64_encode( pack( 'H*', substr( $token, 0, 16 ) ) );
	}

	/**
	 * Get the URL to the remote wiki of the page that the user should edit
	 * @param string $remoteUsername
	 * @return string
	 */
	public function getRemoteUrl( string $remoteUsername ) {
		return $this->config->get( 'MUARemoteWikiContentPath' ) . "User:"
			. rawurlencode( $remoteUsername ) . "?action=edit";
	}

	/**
	 * Verify whether the token has been included on the remote wiki.
	 * @param string $remoteUsername
	 * @param string $token
	 * @return StatusValue
	 */
	public function verifyToken( string $remoteUsername, string $token ) {
		$status = StatusValue::newGood();
		$wanKey = $this->wanCache->makeKey( 'migrateuseraccount', 'verify', $remoteUsername, $token );

		if ( $this->wanCache->get( $wanKey ) ) {
			// Token was verified within the last 10 mins, no need to redo our work
			return $status;
		}

		$un = rawurlencode( $remoteUsername );
		$textToTest = '';

		$pageUrl = $this->getRemoteUrl( $remoteUsername );
		$apiUrl = $this->config->get( 'MUARemoteWikiAPI' ) .
			'?format=json&formatversion=2&action=query&prop=revisions&titles=User:' . $un .
			'&rvprop=comment|content|timestamp|user&rvlimit=1&rvslots=main';
		$res = $this->httpRequestFactory->get( $apiUrl, [], __METHOD__ );

		if ( $res ) {
			$data = json_decode( $res, true );

			// Get the first page
			if ( isset( $data['query']['pages'] ) ) {
				$firstPage = current( $data['query']['pages'] );

				// Get the first revision
				if ( isset( $firstPage['revisions'] ) ) {
					$revision = current( $firstPage['revisions'] );

					// If the most recent edit was more than 10 minutes ago, show a special error message
					if ( isset( $revision['timestamp'] ) ) {
						$currTimestamp = time();
						$editTimestamp = strtotime( $revision['timestamp'] );

						if ( $editTimestamp && ( $editTimestamp < ( $currTimestamp - 10 * 60 ) ) ) {
							return $status->fatal( 'migrateuseraccount-token-no-recent-edit',
								'[' . $pageUrl . ' ' . urlencode( $remoteUsername ) . ']' );
						}
					}

					// If the username of the most recent edit is not the target user, show a special error message
					if ( !isset( $revision['user'] ) || $revision['user'] !== $remoteUsername ) {
						return $status->fatal( 'migrateuseraccount-token-username-no-match',
							'[' . $pageUrl . ' ' . urlencode( $remoteUsername ) . ']' );
					}

					// Get the slots (for the revision content)
					if ( isset( $revision['slots'] ) ) {
						$textToTest .= trim( $revision['slots']['main']['content'] );
					}

					// Get the edit summary
					if ( isset( $revision['comment'] ) ) {
						$textToTest .= trim( $revision['comment'] );
					}
				}
			}
		} else {
			$this->logger->error( 'Got an invalid response from ' . $apiUrl );
		}

		// If the token is present in the text we're testing, then this was successful
		if ( str_contains( $textToTest, $token ) ) {
			// Set a temporary WAN cache key so that we can verify the token for the next 10 min without more API calls
			$this->wanCache->set(
				$wanKey,
				true,
				ExpirationAwareness::TTL_MINUTE * 10,
			);

			return $status;
		} else {
			return $status->fatal( 'migrateuseraccount-token-no-token',
				'[' . $pageUrl . ' ' . urlencode( $remoteUsername ) . ']' );
		}
	}

	/**
	 * Log the migration of a user to the wiki that they performed it on.
	 * @param User $user
	 * @return void
	 */
	private function logMigration( User $user ): void {
		$logEntry = new ManualLogEntry( 'newusers', 'migrated' );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $user->getUserPage() );
		$logId = $logEntry->insert();
		$logEntry->publish( $logId );
	}
}
