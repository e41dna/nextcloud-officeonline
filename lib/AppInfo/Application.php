<?php
/**
 * @copyright Copyright (c) 2016 Lukas Reschke <lukas@statuscode.ch>
 *
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Officeonline\AppInfo;

use OC\Files\Type\Detection;
use OC\Security\CSP\ContentSecurityPolicy;
use OCA\Federation\TrustedServers;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Files_Sharing\Listener\LoadAdditionalListener;
use OCA\Officeonline\Capabilities;
use OCA\Officeonline\Hooks\WopiLockHooks;
use OCA\Officeonline\Listener\FilesScriptListener;
use OCA\Officeonline\Listener\LoadViewerListener;
use OCA\Officeonline\Middleware\WOPIMiddleware;
use OCA\Officeonline\PermissionManager;
use OCA\Officeonline\Preview\MSExcel;
use OCA\Officeonline\Preview\MSWord;
use OCA\Officeonline\Preview\OOXML;
use OCA\Officeonline\Preview\OpenDocument;
use OCA\Officeonline\Preview\Pdf;
use OCA\Officeonline\Service\FederationService;
use OCA\Viewer\Event\LoadViewer;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Template\ITemplateManager;
use OCP\Files\Template\TemplateFileCreator;
use OCP\IPreview;
use Psr\Log\LoggerInterface;
use OCP\IL10N;
use OCP\IConfig;

class Application extends App implements IBootstrap {
	public const APP_ID = 'officeonline';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerCapability(Capabilities::class);
		$context->registerMiddleWare(WOPIMiddleware::class);
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, FilesScriptListener::class);
		$context->registerEventListener(LoadAdditionalListener::class, FilesScriptListener::class);
		$context->registerEventListener(LoadViewer::class, LoadViewerListener::class);
	}

	public function boot(IBootContext $context): void {
		if (!$this->isEnabled()) {
			return;
		}

		$this->registerProvider();
		$this->updateCSP();
		$this->registerNewFileCreators($context);
	}

	public function isEnabled(): bool {
		$currentUser = \OC::$server->getUserSession()->getUser();
		if ($currentUser !== null) {
			/** @var PermissionManager $permissionManager */
			$permissionManager = \OC::$server->query(PermissionManager::class);
			if (!$permissionManager->isEnabledForUser($currentUser)) {
				return false;
			}
		}

		return true;
	}

	public function registerProvider() {
		$container = $this->getContainer();

		// Register mimetypes
		/** @var Detection $detector */
		$detector = $container->query(\OCP\Files\IMimeTypeDetector::class);
		$detector->getAllMappings();
		$detector->registerType('ott', 'application/vnd.oasis.opendocument.text-template');
		$detector->registerType('ots', 'application/vnd.oasis.opendocument.spreadsheet-template');
		$detector->registerType('otp', 'application/vnd.oasis.opendocument.presentation-template');

		/** @var IPreview $previewManager */
		$previewManager = $container->query(IPreview::class);

		$previewManager->registerProvider('/application\/vnd.ms-excel/', function () use ($container) {
			return $container->query(MSExcel::class);
		});

		$previewManager->registerProvider('/application\/msword/', function () use ($container) {
			return $container->query(MSWord::class);
		});

		$previewManager->registerProvider('/application\/vnd.openxmlformats-officedocument.*/', function () use ($container) {
			return $container->query(OOXML::class);
		});

		$previewManager->registerProvider('/application\/vnd.oasis.opendocument.*/', function () use ($container) {
			return $container->query(OpenDocument::class);
		});

		$previewManager->registerProvider('/application\/pdf/', function () use ($container) {
			return $container->query(Pdf::class);
		});

		$container->query(WopiLockHooks::class)->register();
	}

	public function updateCSP() {
		if (\OC::$CLI) {
			return;
		}

		$container = $this->getContainer();

		$publicWopiUrl = \OC::$server->getConfig()->getAppValue('officeonline', 'wopi_url');
		$publicWopiUrl = $publicWopiUrl === '' ? \OC::$server->getConfig()->getAppValue('officeonline', 'wopi_url') : $publicWopiUrl;
		$cspManager = $container->getServer()->getContentSecurityPolicyManager();
		$policy = new ContentSecurityPolicy();
		if ($publicWopiUrl !== '') {
			$policy->addAllowedFrameDomain('\'self\'');
			$policy->addAllowedFrameDomain($this->domainOnly($publicWopiUrl));
			if (method_exists($policy, 'addAllowedFormActionDomain')) {
				$policy->addAllowedFormActionDomain($this->domainOnly($publicWopiUrl));
			}
		}

		/**
		 * Dynamically add CSP for federated editing
		 */
		$path = '';
		try {
			$path = $container->getServer()->getRequest()->getPathInfo();

			if (strpos($path, '/apps/files') === 0 && $container->getServer()->getAppManager()->isEnabledForUser('federation')) {
				/** @var TrustedServers $trustedServers */
				$trustedServers = $container->query(TrustedServers::class);
				/** @var FederationService $federationService */
				$federationService = $container->query(FederationService::class);
				$remoteAccess = $container->getServer()->getRequest()->getParam('officeonline_remote_access');

				if ($remoteAccess && $trustedServers->isTrustedServer($remoteAccess)) {
					$remoteCollabora = $federationService->getRemoteCollaboraURL($remoteAccess);
					$policy->addAllowedFrameDomain($remoteAccess);
					$policy->addAllowedFrameDomain($remoteCollabora);
				}
			}
		} catch (\Throwable $e) {
			\OC::$server->query(LoggerInterface::class)->warning('Failed to gather federation hosts for CSP', [
				'exception' => $e,
				'app' => 'officeonline'
			]);
		}

		$cspManager->addDefaultPolicy($policy);
	}

	/**
	 * Strips the path and query parameters from the URL.
	 *
	 * @param string $url
	 * @return string
	 */
	private function domainOnly(string $url): string {
		$parsed_url = parse_url(trim($url));
		$scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
		$host = isset($parsed_url['host']) ? $parsed_url['host'] : '';
		$port = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
		return "$scheme$host$port";
	}

	private function registerNewFileCreators($context) {
		$context->injectFn(function (ITemplateManager $templateManager, IL10N $l10n, IConfig $config) {
			if (!$this->isEnabled()) {
				return;
			}
			$ooxml = $config->getAppValue(self::APP_ID, 'doc_format', '') === 'ooxml';
			$templateManager->registerTemplateFileCreator(function () use ($l10n, $ooxml) {
				$odtType = new TemplateFileCreator('richdocuments', $l10n->t('New document'), ($ooxml ? '.docx' : '.odt'));
				if ($ooxml) {
					$odtType->addMimetype('application/msword');
					$odtType->addMimetype('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
				} else {
					$odtType->addMimetype('application/vnd.oasis.opendocument.text');
					$odtType->addMimetype('application/vnd.oasis.opendocument.text-template');
				}
				$odtType->setIconClass('icon-filetype-document');
				$odtType->setRatio(21 / 29.7);
				return $odtType;
			});
			$templateManager->registerTemplateFileCreator(function () use ($l10n, $ooxml) {
				$odsType = new TemplateFileCreator('richdocuments', $l10n->t('New spreadsheet'), ($ooxml ? '.xlsx' : '.ods'));
				if ($ooxml) {
					$odsType->addMimetype('application/vnd.ms-excel');
					$odsType->addMimetype('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
				} else {
					$odsType->addMimetype('application/vnd.oasis.opendocument.spreadsheet');
					$odsType->addMimetype('application/vnd.oasis.opendocument.spreadsheet-template');
				}
				$odsType->setIconClass('icon-filetype-spreadsheet');
				$odsType->setRatio(16 / 9);
				return $odsType;
			});
			$templateManager->registerTemplateFileCreator(function () use ($l10n, $ooxml) {
				$odpType = new TemplateFileCreator('richdocuments', $l10n->t('New presentation'), ($ooxml ? '.pptx' : '.odp'));
				if ($ooxml) {
					$odpType->addMimetype('application/vnd.ms-powerpoint');
					$odpType->addMimetype('application/vnd.openxmlformats-officedocument.presentationml.presentation');
				} else {
					$odpType->addMimetype('application/vnd.oasis.opendocument.presentation');
					$odpType->addMimetype('application/vnd.oasis.opendocument.presentation-template');
				}
				$odpType->setIconClass('icon-filetype-presentation');
				$odpType->setRatio(16 / 9);
				return $odpType;
			});
		});
	}
}
