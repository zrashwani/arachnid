<?php

namespace Arachnid\Adapters;
use Symfony\Component\DomCrawler\Crawler;
use Goutte\Client as GoutteClient;

class GoutteAdapter implements CrawlingAdapterInterface{
    
    /**
     * guzzle client
     * @var GoutteClient
     */
    private $client;
    
    
    public function __construct($options) {
        $client = new GoutteClient();
        $client->followRedirects();
        $guzzleClient = new \GuzzleHttp\Client($this->prepareOptions($options));
        $client->setClient($guzzleClient);
        $this->client = $client;
    }
    
    public function requestPage($url): Crawler {
        return $this->client->request('GET', $url);      
    }
    

    /**
     * get default configuration options for guzzle
     * @return array
     */
    protected function prepareOptions(array $options = [])
    {
        $cookieName = time()."_".substr(md5(microtime()), 0, 5).".txt";
                
        $defaultConfig = array(
            'curl' => array(
                CURLOPT_COOKIEJAR      => $cookieName,
                CURLOPT_COOKIEFILE     => $cookieName,
            ),
        );
        $configOptions = array_merge_recursive($options, $defaultConfig);
        
        return $configOptions;
    }

    public function getClient() {
        return $this->client;
    }

}

