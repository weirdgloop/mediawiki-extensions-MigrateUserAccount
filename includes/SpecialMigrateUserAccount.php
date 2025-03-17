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

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Session\Session;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserNameUtils;
use Psr\Log\LoggerInterface;

class SpecialMigrateUserAccount extends SpecialPage {
	private Session $session;

	private string $localUsername;

	private string $remoteUsername;

	private LoggerInterface $logger;

	private UserNameUtils $userNameUtils;

	private UserMigrationService $userMigrationService;

	public function __construct(
		UserNameUtils $userNameUtils,
		UserMigrationService $userMigrationService
	) {
		parent::__construct( 'MigrateUserAccount' );
		$this->userNameUtils = $userNameUtils;
		$this->userMigrationService = $userMigrationService;
		$this->logger = LoggerFactory::getInstance( 'MigrateUserAccount' );
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
	 * @param string|null $subPage
	 * @return void
	 */
	public function execute( $subPage ) {
		$this->getOutput()->disallowUserJs();
		$this->checkReadOnly();
		$this->getOutput()->enableOOUI();
		$this->getOutput()->addModules( 'special.migrateuseraccount' );
		$this->getOutput()->addModuleStyles( [ 'ext.migrateuseraccount.styles' ] );

		if ( version_compare( MW_VERSION, '1.38', '>=' ) ) {
			$this->getOutput()->disableClientCache();
		}

		// Persist a session, so that we can use the ID for hashing later
		$this->session = $this->getRequest()->getSession();
		$this->session->persist();

		$user = $this->getUser();

		// If the user is logged in, show an error.
		if ( !$user->isAnon() && !$user->isTemp() ) {
			throw new ErrorPageError( 'migrateuseraccount', 'migrateuseraccount-error-loggedin' );
		}

		$this->getOutput()->addWikiMsg( 'migrateuseraccount-help' );

		parent::execute( $subPage );

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
			->setSubmitCallback( static function () {
			} )
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

		$config = $this->getConfig();
		if ( $config->get( MainConfigNames::EnableEmail ) && $config->get( 'MUAShowEmailField' ) ) {
			$desc['email'] = [
				'type' => 'email',
				'label-message' => 'migrateuseraccount-form-email',
				'help-message' => 'migrateuseraccount-form-email-help',
			];
		}

		if ( $this->isUsingFallback() ) {
			$desc['newusername'] = [
				'class' => 'HTMLTextField',
				'label-message' => 'migrateuseraccount-form-newusername',
				'help-message' => [ 'migrateuseraccount-form-newusername-help', $this->remoteUsername ],
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
	 * @return bool|void
	 */
	public function showTokenDetails() {
		$vals = $this->getRequest()->getValues();

		// These two are set to the same for now, but may change later if we fallback.
		$this->localUsername = $this->userNameUtils->getCanonical( $vals['wpusername'] );
		$this->remoteUsername = $this->localUsername;

		// Check whether the user can migrate. This does a few things:
		// - Does the user already exist?
		// - If the user does exist, and we have a fallback suffix defined, does a fallback user exist?
		$canMigrate = $this->userMigrationService->checkUserCanMigrate( $this->localUsername );
		if ( !$canMigrate->isGood() ) {
			$this->getOutput()->addHTML(
				Html::errorBox(
					$this->msg( $canMigrate->getMessages()[0] )->parse()
				)
			);
			$this->showForm();
			return false;
		}

		$this->localUsername = $canMigrate->getValue();

		// At this point, $this->localUsername may have changed, if we've defined a fallback username.
		// For other non-Weird Gloop users of this extension, a fallback username will not be used here.
		// In future, we can check $this->isUsingFallback() to determine whether we're using a fallback username.

		// Generate a token
		$token = $this->userMigrationService->generateToken( $this->localUsername, $this->session );

		$this->logger->debug( $this->localUsername . ' generated a new migration token for ' .
			$this->getConfig()->get( 'MUARemoteWikiAPI' )
		);

		// Check if user has edited their page with the token (will either be `true` or a string to an error msg)
		$verified = $this->userMigrationService->verifyToken( $this->remoteUsername, $token );

		if ( $verified->isGood() ) {
			// Persist the target username in the Session object, so that we can check it during API calls
			if ( !array_key_exists( 'wppassword', $vals ) || !array_key_exists( 'wpconfirmpassword', $vals ) ) {
				// At this point, if a password hasn't been passed to us yet, show them the final form to provide it
				$this->showFinalForm();
				return true;
			}

			$password = $vals['wppassword'];
			$confirmPassword = $vals['wpconfirmpassword'];
			$email = $vals['wpemail'] ?? null;
			$newUsername = $vals['wpnewusername'] ?? null;

			// Anything past this point assumes that we have the information we need to change their credentials

			// Check that both passwords match
			if ( $password !== $confirmPassword ) {
				$this->getOutput()->addHTML(
					Html::errorBox(
						$this->msg( 'migrateuseraccount-wrong-confirm-password' )->parse()
					)
				);
				$this->showFinalForm();
				return true;
			}

			if ( !empty( $email ) && !Sanitizer::validateEmail( $email ) ) {
				$this->getOutput()->addHTML(
					Html::errorBox(
						$this->msg( 'migrateuseraccount-invalid-email' )->parse()
					)
				);
				$this->showFinalForm();
				return true;
			}

			// Actually perform the migration
			$result = $this->userMigrationService->migrateUser(
				$this->localUsername,
				$password,
				( $this->isUsingFallback() && $newUsername !== null ) ? $newUsername : null
			);

			if ( !$result->isGood() ) {
				$this->getOutput()->addHTML(
					Html::errorBox( $this->msg( $result->getMessages()[0]->getKey() )->parse() )
				);
				$this->showFinalForm();
				return true;
			}

			$user = $result->getValue();
			$emailMessage = '';
			if ( !empty( $email ) ) {
				$status = $user->setEmailWithConfirmation( $email );
				if ( !$status->isGood() ) {
					$this->logger->error( $this->localUsername . ' failed to change email: ' .
						$status->getMessage()->text()
					);
					$emailMessage = $this->msg( 'migrateuseraccount-email-failed' )->parse();
				} else if ( $status->value === 'eauth' ) {
					$emailMessage = $this->msg( 'migrateuseraccount-email-confirm', $email )->parse();
				}
			}

			// Password change was successful by this point :)
			$this->getOutput()->addHTML(
				Html::successBox(
					$this->msg( 'migrateuseraccount-success', $user->getName() )->parse() .
					'<br />' . $emailMessage
				)
			);

			return true;
		} else {
			// If they have not edited their page, show information on how to verify their identity
			$this->getOutput()->addHTML(
				'<div class="mua-token-details"><h3>' . $this->msg( 'migrateuseraccount-token-title',
				$this->remoteUsername, '<code>' . $token . '</code>' )->parse() . '</h3><br />' .
				$this->msg( 'migrateuseraccount-token-help',
					$this->userMigrationService->getRemoteUrl( $this->remoteUsername ) )->parse() . '</div><br />'
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
				$this->getOutput()->addHTML( '<br />' . Html::errorBox(
					$this->msg( $verified->getMessages()[0]->getKey(),
						$verified->getMessages()[0]->getParams() )->parse()
				) );
			}
		}
	}

	public function doesWrites(): bool {
		return true;
	}
}
