<?php
/**
 * ownCloud - Officeonline App
 *
 * @author Frank Karlitschek
 * @copyright 2013-2014 Frank Karlitschek karlitschek@kde.org
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Officeonline\AppInfo;

use OCA\Officeonline\PermissionManager;

$currentUser = \OC::$server->getUserSession()->getUser();
if ($currentUser !== null) {
	/** @var PermissionManager $permissionManager */
	$permissionManager = \OC::$server->query(PermissionManager::class);
	if (!$permissionManager->isEnabledForUser($currentUser)) {
		return;
	}
}

$eventDispatcher = \OC::$server->getEventDispatcher();
$eventDispatcher->addListener(
	'OCA\Files::loadAdditionalScripts',
	function () {
		\OCP\Util::addScript('officeonline', 'files', 'viewer');
	}
);
$eventDispatcher->addListener(
	'OCA\Files_Sharing::loadAdditionalScripts',
	function () {
		\OCP\Util::addScript('officeonline', 'files', 'viewer');
	}
);

$app = \OC::$server->query(Application::class);
$app->registerProvider();
$app->updateCSP();
