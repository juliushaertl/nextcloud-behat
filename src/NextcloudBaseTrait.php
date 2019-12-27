<?php

namespace JuliusHaertl\NextcloudBehat;


trait NextcloudBaseTrait {

    public $baseUrl;

    public function setBaseUrl($baseUrl) {
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
    }
}
