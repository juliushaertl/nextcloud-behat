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

use GuzzleHttp\Client;
use \PHPUnit\Framework\Assert;
use Behat\Gherkin\Node\TableNode;

trait FilesSharingContextTrait {

	public $lastShareData;
	public $response;

	use NextcloudBaseTrait;
	use UserContextTrait;

	/**
	 * @Given /^as "([^"]*)" create a share with$/
	 * @param string $user
	 * @param TableNode|null $body
	 */
	public function asCreatingAShareWith($user, TableNode $body) {
		$fullUrl = $this->baseUrl . "ocs/v2.php/apps/files_sharing/api/v1/shares";
		$client = new Client();
		$options = [
			'headers' => [
				'OCS-APIREQUEST' => 'true',
			],
			'auth' => $this->getAuth()
		];

		if ($body instanceof TableNode) {
			$fd = $body->getRowsHash();
			if (array_key_exists('expireDate', $fd)){
				$dateModification = $fd['expireDate'];
				$fd['expireDate'] = date('Y-m-d', strtotime($dateModification));
			}
			$options['form_params'] = $fd;
		}

		try {
			$this->response = $client->request("POST", $fullUrl, $options);
		} catch (\GuzzleHttp\Exception\ClientException $ex) {
			$this->response = $ex->getResponse();
			throw $ex;
		}

		$this->lastShareData = simplexml_load_string($this->response->getBody()->getContents());
	}

	/**
	 * @When /^Updating last share with$/
	 * @param TableNode|null $body
	 */
	public function updatingLastShare($body) {
		$share_id = (string) $this->lastShareData->data[0]->id;
		$fullUrl = $this->baseUrl . "ocs/v2.php/apps/files_sharing/api/v1/shares/$share_id";
		$client = new Client();
		$options = [
			'headers' => [
				'OCS-APIREQUEST' => 'true',
			],
			'auth' => $this->getAuth()
		];

		if ($body instanceof TableNode) {
			$fd = $body->getRowsHash();
			if (array_key_exists('expireDate', $fd)){
				$dateModification = $fd['expireDate'];
				$fd['expireDate'] = date('Y-m-d', strtotime($dateModification));
			}
			$options['form_params'] = $fd;
		}

		try {
			$this->response = $client->request("PUT", $fullUrl, $options);
			$data = simplexml_load_string($this->response->getBody()->getContents());
		} catch (\GuzzleHttp\Exception\ClientException $ex) {
			$this->response = $ex->getResponse();
			throw $ex;
		}
	}

	/**
	 * @Then /^the HTTP status code should be "([^"]*)"$/
	 * @param int $statusCode
	 */
	public function theHTTPStatusCodeShouldBe($statusCode) {
		Assert::assertEquals($statusCode, $this->response->getStatusCode());
	}

}
