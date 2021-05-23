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
	/** @var array */
	private $storedShareData;

	private $sharingAPIVersion = '1';

	/** @BeforeScenario */
	public function gatherContexts(BeforeScenarioScope $scope): void {
		/** @var InitializedContextEnvironment $environment */
		$environment = $scope->getEnvironment();
		$this->serverContext = $environment->getContext(ServerContext::class);
	}

	/**
	 * @Given /^as "([^"]*)" create a share with$/
	 */
	public function asCreatingAShareWith(string $user, TableNode $body): void {
		$this->serverContext->setCurrentUser($user);
		$this->createAShareWith($body);
		$this->serverContext->assertHttpStatusCode(200, 'Failed to create the share: ' . PHP_EOL . json_encode($this->serverContext->getOCSResponse()));
		$this->lastShareData = $this->serverContext->getOCSResponseData();
	}

	/**
	 * @Given creating a share with
	 */
	public function createAShareWith(TableNode $body): void {
		$fd = $body->getRowsHash();
		if (array_key_exists('expireDate', $fd)) {
			$dateModification = $fd['expireDate'];
			$fd['expireDate'] = date('Y-m-d', strtotime($dateModification));
		}
		$this->serverContext->sendOCSRequest('POST', $this->sharingApiUrl("shares"), $fd);
		if ($this->serverContext->getResponse()->getStatusCode() === 200) {
			$this->lastShareData = $this->serverContext->getOCSResponseData();
		}
	}

	/**
	 * @When /^Updating last share with$/
	 */
	public function updatingLastShare(TableNode $body): void {
		$shareId = (string)$this->lastShareData['id'];
		$fd = $body->getRowsHash();
		if (array_key_exists('expireDate', $fd)) {
			$dateModification = $fd['expireDate'];
			$fd['expireDate'] = date('Y-m-d', strtotime($dateModification));
		}

		$this->serverContext->sendOCSRequest('PUT', $this->sharingApiUrl("shares/$shareId"), $fd);
		$this->serverContext->assertHttpStatusCode(200, 'Failed to update the share: ' . PHP_EOL . json_encode($this->serverContext->getOCSResponse()));

		$this->lastShareData = $this->serverContext->getOCSResponseData();
	}

	public function getLastShareData(): array {
		return $this->lastShareData;
	}

	/**
	 * @When /^user "([^"]*)" accepts last share$/
	 */
	public function acceptLastPendingShare(string $user): void {
		$this->serverContext->setCurrentUser($user);
		$this->serverContext->sendOCSRequest('GET', $this->sharingApiUrl("remote_shares/pending"), null);
		$this->serverContext->assertHttpStatusCode(200);
		$this->serverContext->theOCSStatusCodeShouldBe(200);
		$response = $this->serverContext->getOCSResponseData();
		Assert::assertNotEmpty($response, 'No pending share found');
		$shareId = $response[0]['id'];
		$this->serverContext->sendOCSRequest('POST', $this->sharingApiUrl("remote_shares/pending/$shareId"), null);
		$this->serverContext->assertHttpStatusCode(200);
		$this->serverContext->theOCSStatusCodeShouldBe(200);
	}

	/**
	 * @When /^save the last share data as "([^"]*)"$/
	 */
	public function saveLastShareData($name): void {
		$this->storedShareData[$name] = $this->lastShareData;
	}

	/**
	 * @When /^restore the last share data from "([^"]*)"$/
	 */
	public function restoreLastShareData($name): void {
		$this->lastShareData = $this->storedShareData[$name];
	}

	/**
	 * @When deleting last share
	 */
	public function deletingLastShare(): void {
		$shareId = $this->lastShareData['id'];
		$this->serverContext->sendOCSRequest("DELETE", $this->sharingApiUrl("/shares/$shareId"), null);
	}

	private function sharingApiUrl(string $endpoint): string {
		return sprintf("/apps/files_sharing/api/v%s/", $this->sharingAPIVersion) . ltrim($endpoint, '/');
	}
}
