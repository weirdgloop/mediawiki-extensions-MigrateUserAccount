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
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Session\Session;
use SpecialPage;
use User;

class SpecialMigrateUserAccount extends SpecialPage {

	/**
	 * @var Session
	 */
	private $session;

	public function __construct() {
		parent::__construct( 'MigrateUserAccount' );
	}

	/**
	 * @param string|null $par
	 * @return void
	 */
	public function execute( $par ) {
		$this->checkReadOnly();
		$this->getOutput()->enableOOUI();
		$this->getOutput()->addModuleStyles( [ 'ext.migrateuseraccount.styles' ] );
		$this->getOutput()->disableClientCache();

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
			],
			'password' => [
				'type' => 'password',
				'label-message' => 'migrateuseraccount-form-password',
				'help-message' => 'migrateuseraccount-form-password-help',
				'required' => true
			],
			'confirmpassword' => [
				'type' => 'password',
				'label-message' => 'migrateuseraccount-form-confirm-password',
				'required' => true
			]
		];

		$form = HTMLForm::factory( 'ooui', $desc, $this->getContext() );
		$form
			->setFormIdentifier( 'form1' )
			->setSubmitCallback( static function () {
			} )
			->show();
	}

	/**
	 * @param string $username
	 * @return bool
	 */
	public function checkUserCanMigrate( string $username ): bool {
		// Ensure that the user is a stub (has no password set) before continuing
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$row = $dbr->selectRow( 'user', [ 'user_id', 'user_password' ], [ 'user_name' => $username ], __METHOD__ );
		if ( !$row || $row->user_password != '' ) {
			$this->getOutput()->addHTML(
				Html::errorBox(
					$this->msg( 'migrateuseraccount-error-invalid-user' )->text()
				)
			);
			$this->showForm();
			return false;
		}
		return true;
	}

	/**
	 * @param string $username
	 * @param string $sessionToken
	 * @return string
	 */
	public function generateToken( string $username, string $sessionToken ): string {
		$secret = pack( 'H*', $this->getConfig()->get( 'MUATokenSecret' ) );
		$token = hash_hmac( 'sha256', $username . ':' . $sessionToken, $secret );
		return base64_encode( pack( 'H*', substr( $token, 0, 16 ) ) );
	}

	/**
	 * @return true|void
	 */
	public function showTokenDetails() {
		$vals = $this->getRequest()->getValues();

		$username = $vals['wpusername'];
		$password = $vals['wppassword'];
		$confirmPassword = $vals['wpconfirmpassword'];

		if ( $password !== $confirmPassword ) {
			$this->getOutput()->addHTML(
				Html::errorBox(
					$this->msg( 'migrateuseraccount-wrong-confirm-password' )->text()
				)
			);
			$this->showForm();
			return true;
		}

		$user = MediaWikiServices::getInstance()->getUserFactory()->newFromName( $username );

		// Check if the password they provided is actually valid or not
		if ( !$user->isValidPassword( $password ) ) {
			$this->getOutput()->addHTML(
				Html::errorBox(
					$this->msg( 'migrateuseraccount-invalid-password' )->text()
				)
			);
			$this->showForm();
			return true;
		}

		$canMigrate = $this->checkUserCanMigrate( $username );
		if ( !$canMigrate ) {
			return true;
		}

		// Generate a token
		$token = $this->generateToken( $username, $this->session->getId() );

		// Check if user has edited their page with the token (will either be `true` or a string to an error msg)
		$verified = $this->verifyToken( $username, $token );

		if ( $verified === true ) {
			// Change user's credentials
			$status = $user->changeAuthenticationData( [
				'password' => $password,
				'retype' => $password
			] );

			if ( !$status->isGood() ) {
				$this->getOutput()->addHTML(
					Html::errorBox(
						$this->msg( 'migrateuseraccount-failed' )->text()
					)
				);
				return true;
			}

			// Password change was successful by this point :)
			$this->getOutput()->addHTML(
				Html::successBox(
					$this->msg( 'migrateuseraccount-success', $username )
				)
			);

			// Save to the on-wiki log, if enabled
			if ( $this->getConfig()->get('MUALogToWiki') ) {
				$this->saveToLog( $user );
			}

			return true;
		} else {
			$remoteUrl = $this->getConfig()->get( 'MUARemoteWikiContentPath' ) . "User:" . $username . "?action=edit";

			// If they have not edited their page, show information on how to verify their identity
			$this->getOutput()->addHTML(
				'<div class="mua-token-details"><h3>' . $this->msg( 'migrateuseraccount-token-title',
				$username, '<code>' . $token . '</code>' ) . '</h3><br />' .
				$this->msg( 'migrateuseraccount-token-help',
				$remoteUrl ) . '</div><br />'
			);

			$desc = [
				'username' => [
					'class' => 'HTMLHiddenField',
					'default' => $username
				],
				'password' => [
					'class' => 'HTMLHiddenField',
					'default' => $password
				],
				'confirmpassword' => [
					'class' => 'HTMLHiddenField',
					'default' => $password
				]
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
				$this->getOutput()->addHTML( '<br />' . Html::errorBox(
						$this->msg( $verified )
					) );
			}
		}
	}

	/**
	 * @param string $username
	 * @param string $token
	 * @return bool|string
	 */
	private function verifyToken( string $username, string $token ) {
		$un = rawurlencode( $username );
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
							return 'migrateuseraccount-token-no-recent-edit';
						}
					}

					// If the username of the most recent edit is not the target user, show a special error message
					if ( !isset( $revision['user'] ) || $revision['user'] !== $username ) {
						return 'migrateuseraccount-token-username-no-match';
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
		}

		// If the token is present in the text we're testing, then this was successful
		if ( str_contains( $textToTest, $token ) ) {
			return true;
		} else {
			return 'migrateuseraccount-token-no-token';
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
