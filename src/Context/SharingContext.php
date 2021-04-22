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
use Behat\Gherkin\Node\TableNode;
use PHPUnit\Framework\Assert;

class SharingContext implements Context {

	/** @var ServerContext */
	private $serverContext;
	/** @var array */
	private $lastShareData;

	/** @BeforeScenario */
	public function gatherContexts(BeforeScenarioScope $scope) {
		/** @var InitializedContextEnvironment $environment */
		$environment = $scope->getEnvironment();
		$this->serverContext = $environment->getContext(ServerContext::class);
	}

	/**
	 * @Given /^as "([^"]*)" create a share with$/
	 * @param string $user
	 * @param TableNode $body
	 */
	public function asCreatingAShareWith($user, TableNode $body) {
		$fd = $body->getRowsHash();
		if (array_key_exists('expireDate', $fd)) {
			$dateModification = $fd['expireDate'];
			$fd['expireDate'] = date('Y-m-d', strtotime($dateModification));
		}

		$this->serverContext->sendOCSRequest('POST', "/apps/files_sharing/api/v1/shares", $fd);

		$this->lastShareData = $this->serverContext->getOCSResponseData();
	}

	/**
	 * @When /^Updating last share with$/
	 */
	public function updatingLastShare(TableNode $body) {
		$share_id = (string) $this->lastShareData['id'];
		$fd = $body->getRowsHash();
		if (array_key_exists('expireDate', $fd)) {
			$dateModification = $fd['expireDate'];
			$fd['expireDate'] = date('Y-m-d', strtotime($dateModification));
		}

		$this->serverContext->sendOCSRequest('PUT', "/apps/files_sharing/api/v1/shares/${share_id}", $fd);

		$this->lastShareData = $this->serverContext->getOCSResponseData();
	}

	public function getLastShareData() {
		return $this->lastShareData;
	}

	/**
	 * @When /^user "([^"]*)" accepts last share$/
	 * @param string $user
	 * @param string $server
	 */
	public function acceptLastPendingShare($user) {
		$this->serverContext->setCurrentUser($user);
		$this->serverContext->sendOCSRequest('GET', "/apps/files_sharing/api/v1/remote_shares/pending", null);
		$this->serverContext->assertHttpStatusCode(200);
		$this->serverContext->theOCSStatusCodeShouldBe(200);
		$response = $this->serverContext->getOCSResponseData();
		Assert::assertNotEmpty($response, 'No pending share found');
		$share_id = $response[0]['id'];
		$this->serverContext->sendOCSRequest('POST', "/apps/files_sharing/api/v1/remote_shares/pending/{$share_id}", null);
		$this->serverContext->assertHttpStatusCode(200);
		$this->serverContext->theOCSStatusCodeShouldBe(200);
	}
}
