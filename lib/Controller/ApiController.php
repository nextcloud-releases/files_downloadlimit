<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2021 John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @author John Molakvoæ <skjnldsv@protonmail.com>
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

namespace OCA\Files_DownloadCounter\Controller;

use OCA\Files_DownloadCounter\AppInfo\Application;
use OCA\Files_DownloadCounter\Db\Limit;
use OCA\Files_DownloadCounter\Db\LimitMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;

class ApiController extends OCSController {

	/** @var IConfig */
	private $config;

	/** @var IManager */
	private $shareManager;

	/** @var IUserSession */
	private $userSession;

	/** @var LimitMapper */
	private $mapper;

	public function __construct(IRequest $request,
								IConfig $config,
								IManager $shareManager,
								IUserSession $userSession,
								LimitMapper $mapper) {
		parent::__construct(Application::APP_ID, $request);
		$this->config = $config;
		$this->shareManager = $shareManager;
		$this->userSession = $userSession;
		$this->mapper = $mapper;
	}

	/**
	 * @NoAdminRequired
	 *
	 * Set the download limit for a given link share
	 */
	public function setDownloadLimit(string $token, int $limit): Response {
		$this->validateToken($token);

		// Count needs to be at least 1
		if ($limit < 1) {
			throw new OCSBadRequestException('Limit needs to be greater or equal than 1');
		}

		// Getting existing limit and init if unset
		$insert = false;
		try {
			$shareLimit = $this->mapper->get($token);
		} catch (DoesNotExistException $e) {
			$shareLimit = new Limit();
			$shareLimit->setId($token);
			$insert = true;
		}

		// Update DB
		$shareLimit->setLimit($limit);
		if ($insert) {
			$this->mapper->insert($shareLimit);
		} else {
			$this->mapper->update($shareLimit);
		}

		return new DataResponse();
	}

	/**
	 * @NoAdminRequired
	 *
	 * Remove the download limit for a given link share
	 */
	public function removeDownloadLimit(string $token): Response {
		$this->validateToken($token);

		try {
			$shareLimit = $this->mapper->get($token);
			$this->mapper->delete($shareLimit);
		} catch (DoesNotExistException $e) {
			// Ignore if does not exists
		}
	
		return new DataResponse();
	}

	/**
	 * @NoAdminRequired
	 *
	 * Get the download limit for a given link share
	 */
	public function getDownloadLimit(string $token): Response {
		$this->validateToken($token);

		try {
			$shareLimit = $this->mapper->get($token);
		} catch (DoesNotExistException $e) {
			return new DataResponse([
				'limit' => null,
				'count' => null
			]);
		}

		return new DataResponse([
			'limit' => $shareLimit->getLimit(),
			'count' => $shareLimit->getDownloads()
		]);
	}

	protected function validateToken(string $token = '') {
		$user = $this->userSession->getUser();

		try {
			$share = $this->shareManager->getShareByToken($token);
		} catch (ShareNotFound $e) {
			throw new OCSNotFoundException('Unknown share');
		}

		// Make sure the user is owner of the share
		if ($user == null || $share->getShareOwner() !== $user->getUID()) {
			throw new OCSNotFoundException('Unknown share');
		}

		// Download count limit only works on links
		if ($share->getShareType() !== IShare::TYPE_LINK
			&& $share->getShareType() !== IShare::TYPE_EMAIL) {
			throw new OCSNotFoundException('Invalid share type');
		}

		// Download count limit only works on single file shares
		if ($share->getNodeType() !== 'file') {
			throw new OCSNotFoundException('Invalid file type');
		}
	}
}
