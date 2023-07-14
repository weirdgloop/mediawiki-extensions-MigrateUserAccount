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

use BadRequestError;
use ErrorPageError;
use HTMLForm;
use LogPage;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use SpecialPage;
use User;

class SpecialMigrateUserAccount extends SpecialPage {
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
	 * @param string $password
	 * @return string
	 */
	public function generateToken( string $username, string $password ): string {
		$secret = pack( 'H*', $this->getConfig()->get( 'MUATokenSecret' ) );
		$token = hash_hmac( 'sha256', $username . ':' . $password, $secret );
		return base64_encode( pack( 'H*', substr( $token, 0, 16 ) ) );
	}

	/**
	 * @return true|void
	 */
	public function showTokenDetails() {
		$vals = $this->getRequest()->getValues();

		if ( !array_key_exists( 'wpusername', $vals ) || !array_key_exists( 'wppassword', $vals ) ) {
			throw new BadRequestError( 'migrateuseraccount', '' );
		}

		$username = $vals['wpusername'];
		$password = $vals['wppassword'];

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
		if ( !$canMigrate ) { return true;
		}

		// Generate a token
		$token = $this->generateToken( $username, $password );

		// Check if user has edited their page with the token
		$verified = $this->verifyToken( $username, $token );

		if ( $verified ) {
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
			// If they have not edited their page, show information on how to verify their identity
			$this->getOutput()->addHTML(
				'<div class="mua-token-details"><h3>' . $this->msg( 'migrateuseraccount-token-title',
				$username, '<code>' . $token . '</code>' ) . '</h3><br />' .
				$this->msg( 'migrateuseraccount-token-help',
				$this->getConfig()->get( 'MUARemoteWikiContentPath' ) . "User:" .
				$username . "?action=edit" ) . '</div><br />'
			);

			$desc = [
				'username' => [
					'class' => 'HTMLHiddenField',
					'default' => $username
				],
				'password' => [
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
						$this->msg( 'migrateuseraccount-token-retry' )
					) );
			}
		}
	}

	/**
	 * @param string $username
	 * @param string $token
	 * @return bool
	 */
	private function verifyToken( string $username, string $token ): bool {
		$un = rawurlencode( $username );
		$comment = null;

		$url = $this->getConfig()->get( 'MUARemoteWikiAPI' ) .
			'?format=json&formatversion=2&action=query&prop=revisions&titles=User:' . $un . '&rvuser=' .
			$un . '&rvprop=comment&rvlimit=1';
		$res = MediaWikiServices::getInstance()->getHttpRequestFactory()->get( $url );

		if ( $res ) {
			$data = json_decode( $res, true );
			if ( isset( $data['query']['pages'] ) ) {
				$firstPage = current( $data['query']['pages'] );

				if ( isset( $firstPage['revisions'] ) ) {
					$revision = current( $firstPage['revisions'] );
					if ( isset( $revision['comment'] ) ) {
						$comment = trim( $revision['comment'] );
					}
				}
			}
		}

		if ( $comment === $token ) {
			return true;
		} else {
			return false;
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
