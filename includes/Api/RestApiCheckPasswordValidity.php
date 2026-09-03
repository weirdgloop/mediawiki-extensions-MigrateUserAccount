<?php

namespace MediaWiki\Extension\MigrateUserAccount\Api;

use MediaWiki\Extension\MigrateUserAccount\UserMigrationService;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\User\UserFactory;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API endpoint for checking password validity during user migration.
 * Only intended for use on the special page for the extension.
 *
 * Similar to ApiValidatePassword in MediaWiki core, but allowing checks on an existing user, provided that the token
 * is valid to use for it.
 */
class RestApiCheckPasswordValidity extends SimpleHandler {
	private UserMigrationService $userMigrationService;

	private UserFactory $userFactory;

	public function __construct(
		UserMigrationService $userMigrationService,
		UserFactory $userFactory
	) {
		$this->userMigrationService = $userMigrationService;
		$this->userFactory = $userFactory;
	}

	/**
	 * @return array|LocalizedHttpException
	 */
	public function run() {
		$body = $this->getValidatedBody();
		'@phan-var array $body';
		$username = $body['username'];
		$password = $body['password'];

		$canMigrate = $this->userMigrationService->checkUserCanMigrate( $username );

		if ( !$canMigrate->isGood() ) {
			return new LocalizedHttpException(
				MessageValue::new( 'migrateuseraccount-error-user-invalid' ) );
		}

		$localUsername = $canMigrate->getValue();
		$token = $this->userMigrationService->generateToken( $localUsername, $this->getSession() );
		$verify = $this->userMigrationService->verifyToken( $username, $token );

		if ( !$verify->isGood() ) {
			// If the token can't be verified, return nothing. The special page will bump the user off on submit.
			return [];
		}

		$user = $this->userFactory->newFromName( $localUsername );
		$r = [];
		$validity = $user->checkPasswordValidity( $password );
		$r['validity'] = $validity->isGood() ? 'Good' : ( $validity->isOK() ? 'Change' : 'Invalid' );

		$messages = [];
		foreach ( $validity->getMessages( 'error' ) as $msg ) {
			$messages[] = $this->getResponseFactory()->getFormattedMessage(
				MessageValue::newFromSpecifier( $msg )
			);
		}
		foreach ( $validity->getMessages( 'warning' ) as $msg ) {
			$messages[] = $this->getResponseFactory()->getFormattedMessage(
				MessageValue::newFromSpecifier( $msg )
			);
		}
		if ( $messages ) {
			$r['validitymessages'] = $messages;
		}

		return $r;
	}

	/**
	 * @inheritDoc
	 */
	public function getBodyParamSettings(): array {
		return [
			'username' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true
			],
			'password' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true
			]
		];
	}
}
