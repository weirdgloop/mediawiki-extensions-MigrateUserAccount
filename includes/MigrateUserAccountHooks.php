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

use SpecialPage;

class MigrateUserAccountHooks {
	/**
	 * @param SpecialPage $special
	 * @return void
	 */
	public static function onSpecialPageBeforeExecute( SpecialPage $special ) {
		global $wgMUAOverrideLoginPrompt;
		if ( !$wgMUAOverrideLoginPrompt ) {
			return;
		}

		$special->getOutput()->addModuleStyles( [ 'ext.migrateuseraccount.styles' ] );

		$name = $special->getName();

		if ( $name === 'Userlogin' || $name === 'CreateAccount' ) {
			$special->getOutput()->addHTML( '<div class="mua-notice">' .
				$special->msg( 'migrateuseraccount-loginprompt' ) . '</div>' );
		}
	}
}
