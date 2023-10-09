<?php

/**
 *  MigrateUserAccount
 *  Copyright (C) 2023  Jayden Bailey <jayden@weirdgloop.org>
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace MediaWiki\Extension\MigrateUserAccount;

use ErrorPageError;
use HTMLForm;
use LogPage;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Session\Session;
use Psr\Log\LoggerInterface;
use SpecialPage;
use User;

class SpecialMigrateUserAccount extends SpecialPage {

	/**
	 * @var Session
	 */
	private $session;

	/**
	 * @var string
	 */
	private $localUsername;

	private $remoteUsername;

	/**
	 * @var User
	 */
	private $user;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var string
	 */
	private string $remoteUrl;

	private string $fallbackSuffix;

	public function __construct() {
		parent::__construct( 'MigrateUserAccount' );
	}

	/**
	 * Whether or not we're using a fallback username. Used for Weird Gloop wikis.
	 * This isn't relevant to external wikis utilising this extension.
	 * @return bool
	 */
	private function isUsingFallback(): bool {
		return $this->localUsername !== $this->remoteUsername;
	}

	/**
	 * @param string|null $par
	 * @return void
	 */
	public function execute( $par ) {
		$this->logger = LoggerFactory::getInstance( 'MigrateUserAccount' );

		$this->checkReadOnly();
		$this->getOutput()->enableOOUI();
		$this->getOutput()->addModules( 'special.migrateuseraccount' );
		$this->getOutput()->addModuleStyles( [ 'ext.migrateuseraccount.styles' ] );

		$this->fallbackSuffix = $this->getConfig()->get( 'FallbackSuffix' );

		if ( version_compare( MW_VERSION, '1.38', '>=' ) ) {
			$this->getOutput()->disableClientCache();
		}

		// Persist a session, so that we can use the ID for hashing later
		$this->session = $this->getRequest()->getSession();
		$this->session->persist();

		$user = $this->getUser();

		// If the user is logged in, show an error.
		if ( !$user->isAnon() ) {
			throw new ErrorPageError( 'migrateuseraccount', 'migrateuseraccount-error-loggedin' );
		}

		$this->getOutput()->addWikiMsg( 'migrateuseraccount-help' );

		parent::execute( $par );

		if ( !$this->getRequest()->wasPosted() ) {
			// Wasn't POSTed, show the form
			$this->showForm();
		} else {
			// Show the token
			$this->showTokenDetails();
		}
	}

	/**
	 * @return void
	 */
	public function showForm() {
		$desc = [
			'username' => [
				'class' => 'HTMLTextField',
				'label-message' => 'migrateuseraccount-form-username',
				'help-message' => 'migrateuseraccount-form-username-help',
				'required' => true
			]
		];

		$form = HTMLForm::factory( 'ooui', $desc, $this->getContext() );
		$form
			->setFormIdentifier( 'form1' )
			->setSubmitCallback( static function () {} )
			->show();
	}

	/**
	 * @return void
	 */
	public function showFinalForm() {
		$desc = [
			'username' => [
				'class' => 'HTMLHiddenField',
				'default' => $this->remoteUsername
			],
			'password' => [
				'type' => 'password',
				'label-message' => 'migrateuseraccount-form-password',
				'help-message' => 'migrateuseraccount-form-password-help',
				'cssclass' => 'mw-migrateuseraccount-validate-password',
				'required' => true
			],
			'confirmpassword' => [
				'type' => 'password',
				'label-message' => 'migrateuseraccount-form-confirm-password',
				'required' => true
			]
		];

		if ( $this->isUsingFallback() ) {
			$desc['newusername'] = [
				'class' => 'HTMLTextField',
				'label-message' => 'migrateuseraccount-form-newusername',
				'help-message' => 'migrateuseraccount-form-newusername-help',
				'required' => true
			];
		}

		$form = HTMLForm::factory( 'ooui', $desc, $this->getContext() );
		$form
			->setFormIdentifier( 'form3' )
			->setSubmitCallback( static function () {
			} )
			->show();
	}

	/**
	 * @return bool
	 */
	public function checkUserCanMigrate(): bool {
		$username = $this->localUsername;
		$isFallback = false;

		while ( true ) {
			// Ensure that the user is a stub (has no password set) before continuing
			$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
			$row = $dbr->selectRow( 'user', [ 'user_id', 'user_password' ], [ 'user_name' => $username ],
				__METHOD__ );

			if ( !$row || $row->user_password != '' ) {
				// User is not a stub
				if ( !empty( $this->fallbackSuffix && !$isFallback ) ) {
					// If a fallback suffix is set, try again but with that suffix
					$username = $username . $this->fallbackSuffix;
					$isFallback = true;
					continue;
				}

				// If a fallback suffix is not set, or we tried with the fallback suffix and no dice, show an error
				$this->getOutput()->addHTML(
					\Html::errorBox(
						$this->msg( 'migrateuseraccount-error-user-' . ( !$row ? 'nonexistent' : 'migrated' ) )->text()
					)
				);
				$this->showForm();
				return false;
			}

			break;
		}

		if ( $isFallback ) {
			$this->localUsername = $username;
		}

		return true;
	}

	/**
	 * @return string
	 */
	public function generateToken(): string {
		$secret = pack( 'H*', $this->getConfig()->get( 'MUATokenSecret' ) );
		$token = hash_hmac( 'sha256', $this->remoteUsername . ':' . $this->session->getId(), $secret );
		return base64_encode( pack( 'H*', substr( $token, 0, 16 ) ) );
	}

	/**
	 * @return bool|void
	 */
	public function showTokenDetails() {
		$vals = $this->getRequest()->getValues();

		$this->remoteUsername = $vals['wpusername'];
		$this->user = MediaWikiServices::getInstance()->getUserFactory()->newFromName( $this->localUsername );
		$this->remoteUrl = $this->getConfig()->get( 'MUARemoteWikiContentPath' ) . "User:" . $this->remoteUsername .
			"?action=edit";

		$canMigrate = $this->checkUserCanMigrate();
		if ( !$canMigrate ) {
			// User cannot migrate, jump out
			return false;
		}

		// Generate a token
		$token = $this->generateToken();

		$this->logger->debug( $this->user->getName() . ' generated a new migration token for ' .
			$this->getConfig()->get( 'MUARemoteWikiAPI' )
		);

		// Check if user has edited their page with the token (will either be `true` or a string to an error msg)
		$verified = $this->verifyToken( $token );

		if ( $verified === true ) {
			if ( !array_key_exists( 'wppassword', $vals ) || !array_key_exists( 'wpconfirmpassword', $vals ) ) {
				// At this point, if a password hasn't been passed to us yet, show them the final form to provide it
				$this->showFinalForm();
				return true;
			}

			$password = $vals['wppassword'];
			$confirmPassword = $vals['wpconfirmpassword'];

			// Anything past this point assumes that we have the information we need to change their credentials

			// Check that both passwords match
			if ( $password !== $confirmPassword ) {
				$this->getOutput()->addHTML(
					\Html::errorBox(
						$this->msg( 'migrateuseraccount-wrong-confirm-password' )->text()
					)
				);
				$this->showFinalForm();
				return true;
			}

			if ( !$this->user->isValidPassword( $password ) ) {
				$this->getOutput()->addHTML(
					\Html::errorBox(
						$this->msg( 'migrateuseraccount-invalid-password' )->text()
					)
				);
				$this->showFinalForm();
				return true;
			}

			// Change user's credentials
			$status = $this->user->changeAuthenticationData( [
				'password' => $password,
				'retype' => $password
			] );

			if ( !$status->isGood() ) {
				$this->logger->error( $this->user->getName() . ' failed to migrate their account from ' .
					$this->getConfig()->get( 'MUARemoteWikiAPI' ) . ': ' . $status->getMessage()->text()
				);

				$this->getOutput()->addHTML(
					\Html::errorBox(
						$this->msg( 'migrateuseraccount-failed' )->text()
					)
				);
				$this->showFinalForm();
				return true;
			}

			$this->logger->info( $this->user->getName() . ' has migrated their account successfully from ' .
				$this->getConfig()->get( 'MUARemoteWikiAPI' )
			);

			// TODO if remote un is different to local un, we must have fallen back at some point, so rename them(?)

			// Password change was successful by this point :)
			$this->getOutput()->addHTML(
				\Html::successBox(
					$this->msg( 'migrateuseraccount-success', $this->user->getName() )
				)
			);

			// Save to the on-wiki log, if enabled
			if ( $this->getConfig()->get( 'MUALogToWiki' ) ) {
				$this->saveToLog( $this->user );
			}

			return true;
		} else {
			// If they have not edited their page, show information on how to verify their identity
			$this->getOutput()->addHTML(
				'<div class="mua-token-details"><h3>' . $this->msg( 'migrateuseraccount-token-title',
				$this->remoteUsername, '<code>' . $token . '</code>' ) . '</h3><br />' .
				$this->msg( 'migrateuseraccount-token-help',
					$this->remoteUrl ) . '</div><br />'
			);

			$desc = [
				'username' => [
					'class' => 'HTMLHiddenField',
					'default' => $this->remoteUsername
				],
			];
			$form = HTMLForm::factory( 'ooui', $desc, $this->getContext() );
			$form
				->setFormIdentifier( 'form2' )
				->setSubmitTextMsg( 'migrateuseraccount-token-button' )
				->setSubmitCallback( static function () {
				} )
				->show();

			if ( $vals['wpFormIdentifier'] == 'form2' ) {
				// If we're here after the second form, it should be because we retried and it didn't work.
				$this->getOutput()->addHTML( '<br />' . \Html::errorBox(
					$verified
				) );
			}
		}
	}

	/**
	 * @param string $token
	 * @return bool|string
	 */
	private function verifyToken( string $token ) {
		$un = rawurlencode( $this->user->getName() );
		$textToTest = '';

		$url = $this->getConfig()->get( 'MUARemoteWikiAPI' ) .
			'?format=json&formatversion=2&action=query&prop=revisions&titles=User:' . $un .
			'&rvprop=comment|content|timestamp|user&rvlimit=1&rvslots=main';
		$res = MediaWikiServices::getInstance()->getHttpRequestFactory()->get( $url );

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
							return $this->getOutput()->msg( 'migrateuseraccount-token-no-recent-edit',
								'[' . $this->remoteUrl . ' ' . urlencode( $this->remoteUsername ) . ']' );
						}
					}

					// If the username of the most recent edit is not the target user, show a special error message
					if ( !isset( $revision['user'] ) || $revision['user'] !== $this->remoteUsername ) {
						return $this->getOutput()->msg( 'migrateuseraccount-token-username-no-match',
							'[' . $this->remoteUrl . ' ' . urlencode( $this->remoteUsername ) . ']' );
					}

					// Get the slots (for the revision content)
					if ( isset( $revision['slots'] ) ) {
						$textToTest = $textToTest . trim( $revision['slots']['main']['content'] );
					}

					// Get the edit summary
					if ( isset( $revision['comment'] ) ) {
						$textToTest = $textToTest . trim( $revision['comment'] );
					}
				}
			}
		} else {
			$this->logger->error( 'Got an invalid response from ' . $url );
		}

		// If the token is present in the text we're testing, then this was successful
		if ( str_contains( $textToTest, $token ) ) {
			return true;
		} else {
			return $this->getOutput()->msg( 'migrateuseraccount-token-no-token',
				'[' . $this->remoteUrl . ' ' . urlencode( $this->remoteUsername ) . ']' );
		}
	}

	/**
	 * @param User $user
	 * @return void
	 */
	private function saveToLog( User $user ) {
		$log = new LogPage( 'newusers' );
		$log->addEntry(
			'migrated',
			$user->getUserPage(),
			'',
			[ $user->getId() ],
			$user
		);
	}

	public function doesWrites(): bool {
		return true;
	}
}
