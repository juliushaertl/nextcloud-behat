<?php
/*
 * @copyright Copyright (c) 2021 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);


namespace JuliusHaertl\NextcloudBehat\Context;

use Behat\Behat\Context\Context;
use Behat\Behat\Context\Environment\InitializedContextEnvironment;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Sabre\DAV\Client as SabreClient;

class FilesContext implements Context {
	public const DAV_PATH_OLD = 'remote.php/webdav';
	public const DAV_PATH_NEW = 'remote.php/dav';
	public const DAV_PATH_PUBLIC = 'public.php/webdav';

	/** @var boolean */
	private $usingOldDavPath = false;

	/** @var ServerContext */
	private $serverContext;

	/** @BeforeScenario */
	public function gatherContexts(BeforeScenarioScope $scope) {
		/** @var InitializedContextEnvironment $environment */
		$environment = $scope->getEnvironment();
		$this->serverContext = $environment->getContext(ServerContext::class);
	}

	/**
	 * @Given User :arg1 uploads file :arg2 to :arg3
	 */
	public function userUploadsFileTo(string $user, string $source, string $destination): void {
		$this->serverContext->setCurrentUser($user);
		$this->serverContext->usingWebAsUser($user);
		$file = Utils::streamFor(fopen($source, 'rb'));
		$this->makeGuzzleDavRequest("PUT", $destination, null, $file);
	}

	/**
	 * @Given User :user creates a folder :destination
	 * @param string $user
	 * @param string $destination
	 */
	public function userCreatedAFolder(string $user, string $destination): void {
		$this->serverContext->setCurrentUser($user);
		$this->serverContext->usingWebAsUser($user);
		$destination = '/' . ltrim($destination, '/');
		$this->makeGuzzleDavRequest("MKCOL", $destination, []);
	}

	/**
	 * @Then /^as "([^"]*)" the (file|folder|entry) "([^"]*)" does not exist$/
	 */
	public function asTheFileOrFolderDoesNotExist(string $user, string $type, string $path): void {
		$this->serverContext->setCurrentUser($user);
		$client = $this->getSabreFilesClient();
		$response = $client->request('HEAD', ltrim($path, '/'));
		if ($response['statusCode'] !== 404) {
			throw new Exception($type . ' "' . $path . '" expected to not exist (status code ' . $response['statusCode'] . ', expected 404)');
		}
	}

	/**
	 * @Then /^as "([^"]*)" the (file|folder|entry) "([^"]*)" exists$/
	 * @param string $user
	 * @param string $type
	 * @param string $path
	 */
	public function asTheFileOrFolderExists(string $user, string $type, string $path): void {
		$this->serverContext->setCurrentUser($user);
		$sabreResponse = $this->listFolder($path, 0);
	}

	/**
	 * @Given User :user deletes file :path
	 */
	public function userDeletesFile(string $user, string $path): void {
		$this->serverContext->setCurrentUser($user);
		$this->serverContext->usingWebAsUser($user);
		$this->makeGuzzleDavRequest('DELETE', $path, []);
	}

	/**
	 * Returns the elements of a propfind, $folderDepth requires 1 to see elements without children
	 */
	public function listFolder($path, $folderDepth, $properties = null): array {
		$client = $this->getSabreFilesClient();
		if (!$properties) {
			$properties = [
				'{DAV:}getetag'
			];
		}
		return $client->propfind(ltrim($path, '/'), $properties, $folderDepth);
	}

	private function getDavFilesPath($user) {
		if ($this->usingOldDavPath === true) {
			return self::DAV_PATH_OLD . '/';
		}
		return self::DAV_PATH_NEW . '/files/' . $user . '/';
	}

	public function makeGuzzleDavRequest($method, $path, $headers = null, $body = null, $type = "files"): ResponseInterface {
		$fullUrl = $this->generateDavPath($type, ltrim($path, '/'));
		$options = array_filter([
			'headers' => $headers,
			'body' => $body,
			'auth' => $this->serverContext->getAuth()
		], static function ($e) {
			return $e !== null;
		});
		try {
			return $this->serverContext->sendRawRequest($method, $fullUrl, $options);
		} catch (ServerException | ClientException $e) {
			return $e->getResponse();
		}
	}

	public function getSabreFilesClient(): SabreClient {
		return $this->getSabreClient(null, $this->getDavFilesPath($this->serverContext->getCurrentUser()));
	}

	/** @depreacted Use getSabreFilesClient */
	public function getSabreClient(string $user = null, string $path = '/'): SabreClient {
		if ($user) {
			$this->serverContext->setCurrentUser($user);
		}
		$baseUrl = rtrim($this->serverContext->getBaseUrl() . $path, '/') . '/';
		$settings = [
			'baseUri' => $baseUrl,
			'userName' => $this->serverContext->getAuth()[0],
			'password' => $this->serverContext->getAuth()[1]
		];

		$settings['authType'] = SabreClient::AUTH_BASIC;

		return new SabreClient($settings);
	}

	public function getPublicSabreClient($shareToken, $password = null): SabreClient {
		$serverUrl = $this->serverContext->getBaseUrl();

		$settings = [
			'baseUri' => $serverUrl . self::DAV_PATH_PUBLIC . '/',
			'userName' => $shareToken,
			'password' => $password
		];

		$settings['authType'] = SabreClient::AUTH_BASIC;

		return new SabreClient($settings);
	}

	public function generateDavPath($type = 'files', $path = '/'): string {
		return $this->makeSabrePath($this->serverContext->getCurrentUser(), $path, $type);
	}

	/** @depreacted use generateDavPath */
	public function makeSabrePath($user, $path = '/', $type = 'files'): string {
		if ($type === 'files') {
			// Just needed as a fallback for the old dav path
			return $this->encodePath($this->getDavFilesPath($user) . $path);
		}
		return $this->encodePath(self::DAV_PATH_NEW . '/' . $type .  '/' . $user . '/' . ltrim($path, '/'));
	}

	/**
	 * URL encodes the given path but keeps the slashes
	 */
	private function encodePath(string $path): string {
		// slashes need to stay
		return str_replace('%2F', '/', rawurlencode($path));
	}
}
