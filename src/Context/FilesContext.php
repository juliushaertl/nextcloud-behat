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
use GuzzleHttp\Client;
use Sabre\DAV\Client as SabreClient;

class FilesContext implements Context {

	/** @var string */
	private $davPath = "remote.php/webdav";
	/** @var boolean */
	private $usingOldDavPath = true;

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
	public function userUploadsFileTo($user, $source, $destination) {
		$this->serverContext->setCurrentUser($user);
		$file = \GuzzleHttp\Psr7\stream_for(fopen($source, 'r'));
		$this->makeDavRequest($user, "PUT", $destination, [], $file);
	}

	public function getDavFilesPath($user) {
		if ($this->usingOldDavPath === true) {
			return $this->davPath;
		} else {
			return $this->davPath . '/files/' . $user;
		}
	}

	public function makeDavRequest($user, $method, $path, $headers, $body = null, $type = "files") {
		if ($type === "files") {
			$fullUrl = $this->serverContext->getBaseUrl() . $this->getDavFilesPath($user) . "$path";
		} elseif ($type === "uploads") {
			$fullUrl = $this->serverContext->getBaseUrl() . $this->davPath . "$path";
		}
		$client = new Client();
		$options = [
			'headers' => $headers,
			'body' => $body
		];
		$options['auth'] = $this->serverContext->getAuth();
		return $client->request($method, $fullUrl, $options);
	}

	public function makeSabrePath($user, $path, $type = 'files') {
		if ($type === 'files') {
			return $this->encodePath($this->getDavFilesPath($user) . $path);
		} else {
			return $this->encodePath($this->davPath . '/' . $type .  '/' . $user . '/' . $path);
		}
	}

	/**
	 * URL encodes the given path but keeps the slashes
	 *
	 * @param string $path to encode
	 * @return string encoded path
	 */
	private function encodePath($path) {
		// slashes need to stay
		return str_replace('%2F', '/', rawurlencode($path));
	}

	public function getSabreClient($user) {
		$fullUrl = $this->serverContext->getBaseUrl();

		$settings = [
			'baseUri' => $fullUrl,
			'userName' => $this->serverContext->getAuth()[0],
			'password' => $this->serverContext->getAuth()[1]
		];

		$settings['authType'] = SabreClient::AUTH_BASIC;

		return new SabreClient($settings);
	}
}
