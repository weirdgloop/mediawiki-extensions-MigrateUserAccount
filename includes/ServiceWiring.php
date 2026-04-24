<?php

namespace MediaWiki\Extension\MigrateUserAccount;

use MediaWiki\MediaWikiServices;

return [
	'MigrateUserAccount.UserMigrationService' => static function ( MediaWikiServices $services ): UserMigrationService {
		return new UserMigrationService(
			$services->getRenameUserFactory(),
			$services->getUserFactory(),
			$services->getMainConfig(),
			$services->getDBLoadBalancer(),
			$services->getHttpRequestFactory(),
			$services->getMainWANObjectCache()
		);
	}
];
