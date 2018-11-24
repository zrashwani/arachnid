<?php

namespace Arachnid\Adapters;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Panther\Client as PantherClient;

class PantherChromeAdapter implements CrawlingAdapterInterface{
    
    private $client;
    
    public function __construct() {
        $this->client = PantherClient::createChromeClient();
    }
    
    public function requestPage($url): Crawler {
        return $this->client->request('GET', $url);
    }

    public function getClient() {
        return $this->client;   
    }

}