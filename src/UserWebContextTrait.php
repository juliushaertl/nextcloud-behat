<?php

namespace JuliusHaertl\NextcloudBehat;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response;

trait UserWebContextTrait {

    use UserContextTrait;

    private $cookieJar;
    private $requestToken;

	/**
	 * @Given Using web as guest
	 * @param string $user
	 */
	public function usingWebasGuest() {
		return $this->usingWebAsUser(null);
	}

	/**
	 * @Given Using web as user :user
	 * @param string $user
	 */
	public function usingWebAsUser($user = null) {
		$this->cookieJar = new CookieJar();

		$loginUrl = $this->baseUrl . '/index.php/login';
		// Request a new session and extract CSRF token
		$client = new Client();
		$response = $client->get(
			$loginUrl,
			[
				'cookies' => $this->cookieJar,
			]
		);
		$this->extracRequestTokenFromResponse($response);

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
		$this->extracRequestTokenFromResponse($response);
	}

	/**
	 * @param Response $response
	 */
	private function extracRequestTokenFromResponse(Response $response) {
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
}
