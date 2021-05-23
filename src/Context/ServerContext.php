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
use Behat\Gherkin\Node\TableNode;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

class ServerContext implements Context {
	public const TEST_ADMIN_PASSWORD = 'admin';
	public const TEST_PASSWORD = '123456';

	private $servers;
	private $baseUrl;
	private $anonymousUser = false;
	private $currentUser;
	private $currentUserPassword;

	private $createdUsers = [];
	private $createdGroups = [];

	private $response;

	private $cookieJars = [];
	private $requestToken = '';
	private $cookieJarAnonymous;

	public function __construct($servers) {
		$this->servers = $servers;
		$this->baseUrl = $servers['default'];
		$this->setCurrentUser('admin');
		$this->cookieJarAnonymous = new CookieJar();
	}

	/**
	 * @AfterScenario
	 */
	public function tearDown(): void {
		foreach ($this->createdUsers as $uid => $state) {
			if ($uid !== 'admin') {
				$this->deleteUser($uid);
			}
		}
		foreach ($this->createdGroups as $gid => $state) {
			$this->deleteGroup($gid);
		}
	}

	private function deleteUser($user): void {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendOCSRequest('DELETE', '/cloud/users/' . $user);
		$this->setCurrentUser($currentUser);
		unset($this->createdUsers[$user]);
	}

	/**
	 * @Given /^on instance "([^"]*)"$/
	 */
	public function onInstance($arg1): void {
		$this->baseUrl = $this->servers[$arg1];
	}

	public function getServer($server): string {
		return $this->servers[$server];
	}

	/**
	 * @Given acting as user :user
	 */
	public function actingAsUser($user) {
		$this->usingWebAsUser($user);
	}

	/**
	 * @Given /^as user "([^"]*)"$/
	 */
	public function setCurrentUser(string $user): void {
		$this->anonymousUser = false;
		$this->currentUser = $user;
		$this->currentUserPassword = $user === 'admin' ? self::TEST_ADMIN_PASSWORD : self::TEST_PASSWORD;
	}

	public function getCurrentUser(): string {
		return $this->currentUser;
	}

	public function actAsAdmin(callable $callback): void {
		$this->actAsUser('admin', $callback);
	}

	public function actAsUser($userId, callable $callback): void {
		$lastUser = $this->getCurrentUser();
		$this->setCurrentUser($userId);
		$callback();
		$this->setCurrentUser($lastUser);
	}

	public function getBaseUrl(): string {
		return rtrim($this->baseUrl, '/') . '/';
	}

	public function getAuth(): array {
		return [$this->currentUser, $this->currentUserPassword];
	}

	/**
	 * @Given /^user "([^"]*)" exists$/
	 * @param string $user
	 */
	public function assureUserExists(string $user): void {
		$this->userExists($user);
		$this->createdUsers[$user] = true;
		if ($this->response->getStatusCode() !== 200) {
			$this->createUser($user);
		}
	}

	private function userExists($user): void {
		$this->sendOCSRequest('GET', '/cloud/users/' . $user);
	}

	private function createUser(string $user, string $displayName = null): void {
		$this->actAsAdmin(function () use ($user, $displayName) {
			$this->sendOCSRequest('POST', '/cloud/users', [
				'userid' => $user,
				'displayName' => $displayName ?? ($user . '-displayname'),
				'password' => self::TEST_PASSWORD,
			]);
			$this->assertHttpStatusCode(200, 'Failed to create user');
			$this->createdUsers[$user] = true;

			//Quick hack to login once with the current user
			$this->setCurrentUser($user);
			$this->sendOCSRequest('GET', '/cloud/users' . '/' . $user);
			$this->assertHttpStatusCode(200, 'Failed to do first login');
		});
	}

	/**
	 * @Given user :userId with displayname :displayName exists
	 */
	public function userWithDisplaynameExists(string $userId, string $displayName): void {
		$this->userExists($userId);
		if ($this->response->getStatusCode() !== 200) {
			$this->createUser($userId, $displayName);
		}
	}

	private function setUserDisplayName(string $userId, string $displayName = null): void {
		$this->actAsAdmin(function () use ($userId, $displayName) {
			$this->sendOCSRequest('PUT', '/cloud/users/' . $userId, [
				'key' => 'displayname',
				'value' => $displayName ?? ($userId . '-displayname')
			]);
		});
	}

	/**
	 * @Given Using web as user :user
	 */
	public function usingWebAsUser(string $user = null): void {
		if ($user === null) {
			$this->anonymousUser = true;
			return;
		}
		$this->setCurrentUser($user);
		if (isset($this->cookieJars[$user])) {
			// Breaking change Add method to get new token
			// return;
		}

		$loginUrl = $this->getBaseUrl() . 'index.php/login';
		// Request a new session and extract CSRF token
		$client = new Client();
		$response = $client->get(
			$loginUrl,
			[
				'cookies' => $this->getCookieJar(),
			]
		);
		$this->extractRequestTokenFromResponse($response);

		// Login and extract new token
		$client = new Client();
		$response = $client->post(
			$loginUrl,
			[
				'form_params' => [
					'user' => $this->currentUser,
					'password' => $this->currentUserPassword,
					'requesttoken' => $this->requestToken,
				],
				'cookies' => $this->getCookieJar(),
			]
		);
		$this->extractRequestTokenFromResponse($response);
	}

	/**
	 * @Given Using web as guest
	 */
	public function usingWebasGuest(): void {
		$this->anonymousUser = true;
	}

	private function extractRequestTokenFromResponse(ResponseInterface $response): void {
		$this->requestToken = substr(preg_replace('/(.*)data-requesttoken="(.*)">(.*)/sm', '\2', $response->getBody()->getContents()), 0, 89);
	}

	public function getWebOptions(): array {
		return [
			'cookies' => $this->getCookieJar(),
			'headers' => [
				'requesttoken' => $this->requestToken,
			]
		];
	}

	public function getCookieJar(): CookieJar {
		if ($this->anonymousUser) {
			return $this->cookieJarAnonymous;
		}
		if (!isset($this->cookieJars[$this->getCurrentUser()])) {
			$this->cookieJars[$this->getCurrentUser()] = new CookieJar();
			$this->usingWebAsUser($this->getCurrentUser());
		}
		return $this->cookieJars[$this->getCurrentUser()];
	}

	public function getRequestToken(): string {
		return $this->requestToken;
	}

	/**
	 * @throws GuzzleException
	 */
	public function sendRawRequest($method, $url, $options = [], $data = null): ResponseInterface {
		$client = new Client;
		try {
			$this->response = $client->request(
				$method,
				$this->getBaseUrl() . ltrim($url, '/'),
				array_merge([
					'auth' => $this->getAuth(),
					'json' => $data,
				], $options)
			);
		} catch (ClientException | ServerException $e) {
			$this->response = $e->getResponse();
			throw $e;
		}
		return $this->response;
	}

	public function sendJSONRequest($method, $url, $data = []): void {
		$client = new Client;
		try {
			$this->response = $client->request(
				$method,
				$this->getBaseUrl() . ltrim($url, '/'),
				[
					'cookies' => $this->getCookieJar(),
					'json' => $data,
					'headers' => [
						'requesttoken' => $this->getRequestToken(),
					]
				]
			);
		} catch (ClientException | ServerException $e) {
			$this->response = $e->getResponse();
		}
	}

	public function sendOCSRequest($method, $url, $data = [], $options = []): void {
		$client = new Client;
		try {
			$this->response = $client->request(
				$method,
				$this->getBaseUrl() . 'ocs/v2.php/' . ltrim($url, '/'),
				array_merge([
					'json' => $data,
					'headers' => [
						'OCS-APIREQUEST' => 'true',
						'Accept' => 'application/json'
					],
					'auth' => $this->getAuth()
				], $options)
			);
		} catch (ClientException | ServerException $e) {
			$this->response = $e->getResponse();
		}
	}

	public function getResponse(): ResponseInterface {
		return $this->response;
	}

	/**
	 * @return array
	 */
	public function getOCSResponse(): array {
		$this->response->getBody()->seek(0);
		return json_decode($this->response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
	}

	public function getOCSResponseData() {
		return $this->getOCSResponse()['ocs']['data'];
	}

	/**
	 * @Then /^the OCS status code should be "([^"]*)"$/
	 * @param int $statusCode
	 */
	public function theOCSStatusCodeShouldBe($statusCode): void {
		Assert::assertEquals($statusCode, $this->getOCSResponse()['ocs']['meta']['statuscode']);
	}

	/**
	 * @Then the HTTP status code should be :statusCode
	 * @param int $statusCode
	 */
	public function assertHttpStatusCode(int $statusCode, string $message = ''): void {
		Assert::assertEquals($statusCode, $this->response->getStatusCode(), $message);
	}

	/**
	 * @Then the HTTP Content-Type should be :statusCode
	 * @Then the response Content-Type should be :arg1
	 * @Then /^the Content-Type should be "([^"]*)"$/
	 * @param string $contentType
	 */
	public function assertHttpContentType($contentType): void {
		Assert::assertEquals($contentType, $this->response->getHeader('Content-Type')[0]);
	}

	/**
	 * @Then the response should be a JSON array with the following mandatory values
	 * @param TableNode $table
	 * @throws InvalidArgumentException
	 */
	public function theResponseShouldBeAJsonArrayWithTheFollowingMandatoryValues(TableNode $table): void {
		$this->response->getBody()->seek(0);
		$expectedValues = $table->getColumnsHash();
		$realResponseArray = json_decode($this->response->getBody()->getContents(), true);
		foreach ($expectedValues as $value) {
			if ((string)$realResponseArray[$value['key']] !== (string)$value['value']) {
				throw new InvalidArgumentException(
					sprintf(
						'Expected %s for key %s got %s',
						(string)$value['value'],
						$value['key'],
						(string)$realResponseArray[$value['key']]
					)
				);
			}
		}
	}

	/**
	 * @Then the response should be a JSON array with a length of :length
	 * @param int $length
	 * @throws InvalidArgumentException
	 */
	public function theResponseShouldBeAJsonArrayWithALengthOf($length): void {
		$this->response->getBody()->seek(0);
		$realResponseArray = json_decode($this->response->getBody()->getContents(), true);
		if (count($realResponseArray) !== (int)$length) {
			throw new InvalidArgumentException(
				sprintf(
					'Expected %d as length got %d',
					$length,
					count($realResponseArray)
				)
			);
		}
	}


	/**
	 * @Given /^group "([^"]*)" exists$/
	 * @param string $group
	 */
	public function assureGroupExists(string $group): void {
		$response = $this->groupExists($group);
		if ($response->getStatusCode() !== 200) {
			$this->createGroup($group);
			$this->groupExists($group);
			$this->assertHttpStatusCode(200);
		}
	}

	private function groupExists(string $group): ResponseInterface {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendOCSRequest('GET', '/cloud/groups/' . $group);
		$this->setCurrentUser($currentUser);
		return $this->response;
	}

	private function createGroup($group): void {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendOCSRequest('POST', '/cloud/groups', [
			'groupid' => $group,
		]);
		$this->setCurrentUser($currentUser);

		$this->createdGroups[] = $group;
	}

	private function deleteGroup($group): void {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendOCSRequest('DELETE', '/cloud/groups/' . $group);
		$this->setCurrentUser($currentUser);

		unset($this->createdGroups[array_search($group, $this->createdGroups, true)]);
	}

	/**
	 * @When /^user "([^"]*)" is member of group "([^"]*)"$/
	 * @Given user :userId belongs to group :group
	 */
	public function addingUserToGroup(string $userId, string $group): void {
		$this->actAsAdmin(function () use ($userId, $group) {
			$this->sendOCSRequest('POST', "/cloud/users/$userId/groups", [
				'groupid' => $group,
			]);
			$this->assertHttpStatusCode(200);
		});
	}

	/**
	 * @When /^user "([^"]*)" is not member of group "([^"]*)"$/
	 * @param string $user
	 * @param string $group
	 */
	public function removeUserFromGroup(string $user, string $group): void {
		$currentUser = $this->currentUser;
		$this->setCurrentUser('admin');
		$this->sendOCSRequest('DELETE', "/cloud/users/$user/groups", [
			'groupid' => $group,
		]);
		$this->assertHttpStatusCode(200);
		$this->setCurrentUser($currentUser);
	}
}
