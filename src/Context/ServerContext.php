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
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

class ServerContext implements Context {
	private $servers;
	private $baseUrl;
	private $currentUser;
	private $currentUserPassword;

	private $response;

	private $cookieJar;
	private $requestToken = '';

	public function __construct($servers) {
		$this->servers = $servers;
		$this->baseUrl = $servers['default'];
		$this->cookieJar = new CookieJar();
	}

	/**
	 * @Given /^on instance "([^"]*)"$/
	 */
	public function onInstance($arg1) {
		$this->baseUrl = $this->servers[$arg1];
	}

	/**
	 * @Given /^as user "([^"]*)"$/
	 * @param string $user
	 */
	public function setCurrentUser($user) {
		$this->currentUser = $user;
		$this->currentUserPassword = $user === 'admin' ? 'admin' : $user;
		$this->usingWebAsUser($user);
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
	public function assureUserExists($user) {
		try {
			$this->userExists($user);
		} catch (\GuzzleHttp\Exception\ClientException $ex) {
			$this->createUser($user);
			$this->setUserDisplayName($user);
		}
		$this->response = $this->userExists($user);
		$this->theHTTPStatusCodeShouldBe(200);
	}

	private function userExists($user) {
		$client = new Client();
		$options = [
			'auth' => ['admin', 'admin'],
			'headers' => [
				'OCS-APIREQUEST' => 'true',
			],
		];
		return $client->get($this->baseUrl . 'ocs/v2.php/cloud/users/' . $user, $options);
	}

	private function createUser($user) {
	}

	private function setUserDisplayName($user) {
	}

	/**
	 * @Given Using web as user :user
	 * @param string $user
	 */
	public function usingWebAsUser($user = null) {
		$this->cookieJar = new CookieJar();

		$loginUrl = $this->getBaseUrl() . '/index.php/login';
		// Request a new session and extract CSRF token
		$client = new Client();
		$response = $client->get(
			$loginUrl,
			[
				'cookies' => $this->cookieJar,
			]
		);
		$this->extractRequestTokenFromResponse($response);

		if ($user === null) {
			return;
		}

		// Login and extract new token
		$password = ($user === 'admin') ? 'admin' : $this->currentUserPassword;
		$client = new Client();
		$response = $client->post(
			$loginUrl,
			[
				'form_params' => [
					'user' => $user,
					'password' => $password,
					'requesttoken' => $this->requestToken,
				],
				'cookies' => $this->cookieJar,
			]
		);
		$this->extractRequestTokenFromResponse($response);
	}

	/**
	 * @Given Using web as guest
	 * @param string $user
	 */
	public function usingWebasGuest() {
		return $this->usingWebAsUser(null);
	}

	private function extractRequestTokenFromResponse(ResponseInterface $response) {
		$this->requestToken = substr(preg_replace('/(.*)data-requesttoken="(.*)">(.*)/sm', '\2', $response->getBody()->getContents()), 0, 89);
	}

	public function getWebOptions() {
		$options = [
			'cookies' => $this->cookieJar,
			'headers' => [
				'requesttoken' => $this->requestToken,
			]
		];
		return $options;
	}

	public function getCookieJar(): CookieJar {
		return $this->cookieJar;
	}

	public function getReqestToken(): string {
		return $this->requestToken;
	}

	public function sendJSONrequest($method, $url, $data = []) {
		$client = new Client;
		try {
			$this->response = $client->request(
				$method,
				$this->getBaseUrl() . ltrim($url, '/'),
				[
					'cookies' => $this->getCookieJar(),
					'json' => $data,
					'headers' => [
						'requesttoken' => $this->getReqestToken(),
					]
				]
			);
		} catch (ClientException $e) {
			$this->response = $e->getResponse();
		}
	}

	public function sendOCSRequest($method, $url, $data = [], $options = []) {
		$client = new Client;
		try {
			$this->response = $client->request(
				$method,
				rtrim($this->getBaseUrl(), '/') . '/ocs/v2.php/' . ltrim($url, '/'),
				array_merge([
					'cookies' => $this->getCookieJar(),
					'json' => $data,
					'headers' => [
						'requesttoken' => $this->getReqestToken(),
						'OCS-APIREQUEST' => 'true',
						'Accept' => 'application/json'
					]
				], $options)
			);
		} catch (ClientException $e) {
			$this->response = $e->getResponse();
		}
	}

	public function getResponse(): ResponseInterface {
		return $this->response;
	}

	/**
	 * @return array
	 */
	public function getOCSResponse() {
		$this->response->getBody()->seek(0);
		return json_decode($this->response->getBody()->getContents(), true);
	}

	public function getOCSResponseData() {
		return $this->getOCSResponse()['ocs']['data'];
	}

	/**
	 * @Then /^the OCS status code should be "([^"]*)"$/
	 * @param int $statusCode
	 */
	public function theOCSStatusCodeShouldBe($statusCode) {
		Assert::assertEquals($statusCode, $this->getOCSResponse()['ocs']['meta']['statuscode']);
	}

	/**
	 * @Then /^the HTTP status code should be "([^"]*)"$/
	 * @param int $statusCode
	 */
	public function theHTTPStatusCodeShouldBe($statusCode) {
		Assert::assertEquals($statusCode, $this->response->getStatusCode());
	}

	/**
	 * @Then /^the Content-Type should be "([^"]*)"$/
	 * @param string $contentType
	 */
	public function theContentTypeShouldbe($contentType) {
		Assert::assertEquals($contentType, $this->response->getHeader('Content-Type')[0]);
	}

	/**
	 * @Then the response should be a JSON array with the following mandatory values
	 * @param TableNode $table
	 * @throws InvalidArgumentException
	 */
	public function theResponseShouldBeAJsonArrayWithTheFollowingMandatoryValues(TableNode $table) {
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
	public function theResponseShouldBeAJsonArrayWithALengthOf($length) {
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
}
