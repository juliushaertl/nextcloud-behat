<?php

namespace JuliusHaertl\NextcloudBehat;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Message\ResponseInterface;

class UserContextTrait {

    use NextcloudBaseTrait;

    /** @var string|null */
    protected $currentUser;

    /**
     * @Given /^user "([^"]*)" exists$/
     * @param string $user
     */
    public function assureUserExists($user) {
        try {
            $this->userExists($user);
        } catch (\GuzzleHttp\Exception\ClientException $ex) {
            $this->createUser($user);
            // Set a display name different than the user ID to be able to
            // ensure in the tests that the right value was returned.
            $this->setUserDisplayName($user);
        }
        $response = $this->userExists($user);
        $this->assertStatusCode($response, 200);
    }

    /**
     * @Given /^as user "([^"]*)"$/
     * @param string $user
     */
    public function setCurrentUser($user) {
        $this->currentUser = $user;
        $this->currentUserPassword = $user === 'admin' ? 'admin' : '123456';
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
            // Set a display name different than the user ID to be able to
            // ensure in the tests that the right value was returned.
            $this->setUserDisplayName($user);
        }
        $response = $this->userExists($user);
        $this->assertStatusCode($response, 200);
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
}
