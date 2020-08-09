<?php

namespace Arachnid\Adapters;

use Symfony\Component\DomCrawler\Crawler;
use Goutte\Client as GoutteClient;
use Symfony\Component\HttpClient\HttpClient;

class GoutteAdapter implements CrawlingAdapterInterface
{
    
    /**
     * guzzle client
     * @var GoutteClient
     */
    private $client;
    
    
    public function __construct($options)
    {
        $httpClient = HttpClient::create($options);
        $client = new GoutteClient($httpClient);
        $client->followRedirects();
        $this->client = $client;
    }
    
    public function requestPage($url): Crawler
    {
        return $this->client->request('GET', $url);
    }
    
    public function getClient()
    {
        return $this->client;
    }
}
