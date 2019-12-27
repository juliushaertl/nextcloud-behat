<?php
/**
 * @copyright Copyright (c) 2019 Julius Härtl <jus@bitgrid.net>
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

namespace JuliusHaertl\NextcloudBehat;

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Sabre\DAV\Client as SabreClient;
use GuzzleHttp\Cookie\CookieJar;

trait FilesDavContextTrait {

	use NextcloudBaseTrait;

	/** @var string */
	protected $davPath = "/remote.php/dav";

	/**
	 * @When User :user uploads file :source to :destination
	 * @param string $user
	 * @param string $source
	 * @param string $destination
	 */
	public function userUploadsAFileTo($user, $source, $destination) {
		$file = \GuzzleHttp\Psr7\stream_for(fopen($source, 'r'));
		try {
			$this->response = $this->makeDavRequest($user, "PUT", $destination, [], $file);
		} catch (\GuzzleHttp\Exception\ServerException $e) {
			$this->response = $e->getResponse();
		}
	}

	public function getSabreClient($user) {
		$fullUrl = $this->baseUrl;

		$settings = [
			'baseUri' => $fullUrl,
			'userName' => $user,
		];

		if ($user === 'admin') {
			$settings['password'] = 'admin';
		} else {
			$settings['password'] = $this->currentUserPassword;
		}
		$settings['authType'] = SabreClient::AUTH_BASIC;

		return new SabreClient($settings);
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


	public function getDavFilesPath($user) {
		return $this->davPath . '/files/' . $user;
	}

	public function makeDavRequest($user, $method, $path, $headers, $body = null) {
		$fullUrl = $this->baseUrl . $this->getDavFilesPath($user) . "$path";

		$client = new Client();
		$options = [
			'headers' => $headers,
			'body' => $body
		];
		if ($user === 'admin') {
			$options['auth'] = $this->adminUser;
		} else {
			$options['auth'] = [$user, $this->currentUserPassword];
		}
		return $client->request($method, $fullUrl, $options);
	}

}
