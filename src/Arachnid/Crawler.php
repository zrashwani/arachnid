<?php

namespace Arachnid;

use Goutte\Client as GoutteClient;
use Symfony\Component\BrowserKit\Client as ScrapClient;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use Psr\Log\LogLevel;
use Arachnid\Link;
use Symfony\Component\Panther\DomCrawler\Crawler as PantherCrawler;

/**
 * Crawler
 *
 * This class will crawl all unique internal links found on a given website
 * up to a specified maximum page depth.
 *
 * This library is based on the original blog post by Zeid Rashwani here:
 *
 * <http://zrashwani.com/simple-web-spider-php-goutte>
 *
 * Josh Lockhart adapted the original blog post's code (with permission)
 * for Composer and Packagist and updated the syntax to conform with
 * the PSR-2 coding standard.
 *
 * @package Crawler
 * @author  Josh Lockhart <https://github.com/codeguy>
 * @author  Zeid Rashwani <http://zrashwani.com>
 * @version 1.0.4
 */
class Crawler
{

    /**
     * scrap client used for crawling the files, can be either Goutte or local file system
     * @var ScrapClient $scrapClient
     */
    protected $scrapClient;

    /**
     * The base URL from which the crawler begins crawling
     * @var Link
     */
    protected $baseUrl;

    /**
     * The max depth the crawler will crawl
     * @var int
     */
    protected $maxDepth;

    /**
     * Array of links (and related data) found by the crawler
     * @var array
     */
    protected $links;
    
    /**
     * callable for filtering specific links and prevent crawling others
     * @var \Closure
     */
    protected $filterCallback;

    /**
     * set logger to the crawler
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;
    
    /**
     * store options to guzzle client associated with crawler
     * @var array
     */
    protected $configOptions;
    
    /**
     * store children links arranged by depth to apply breadth first search
     * @var array
     */
    private $childrenByDepth;
    
    /**
     * Constructor
     * @param string $baseUrl base url to be crawled
     * @param int    $maxDepth depth of links to be crawled
     * @param array  $config guzzle client extra options
     */
    public function __construct($baseUrl, $maxDepth = 3, $config = [])
    {        
        $this->baseUrl = new Link($baseUrl);       
        $this->maxDepth = $maxDepth;
        $this->links = array();
        
        $this->setCrawlerOptions($config);
    }
    
    /**
     * set crawler options
     * @param array $config
     */
    public function setCrawlerOptions(array $config)
    {
        $this->configOptions = $config;
    }

    /**
     *
     * Initiate the crawl
     * @param UriInterface $url
     * @return \Arachnid\Crawler
     */
    public function traverse(Link $url = null)
    {
        if ($url === null) {            
            $url = $this->baseUrl;
        }
        
        $this->links[$url->getAbsoluteUrl(false)] = $url;        
        $this->traverseSingle($url, 0);

        for($depth=1; $depth< $this->maxDepth; $depth++){ 
            $this->log(LogLevel::DEBUG, "crwaling in depth#".$depth);
            if(!isset($this->childrenByDepth[$depth])){
                $this->log(LogLevel::INFO, "skipping level#".$depth." no items found");
                continue;
            }
            
            $count=1;                        
            foreach($this->childrenByDepth[$depth] as $parentUrl => $parentChilds){                
                $this->log(LogLevel::DEBUG, '('.$count."/".count($this->childrenByDepth[$depth]).") crawling links of ".$parentUrl. ' count of links '.count($parentChilds));
                $parentLink = $this->links[$parentUrl];
                foreach($parentChilds as $childUrl){
                    $childLink = new Link($childUrl,$parentLink);
                    $this->traverseSingle($childLink, $depth);
                }
                $count++;
            }
            
        }
        
        return $this;
    }

    /**
     * Get links (and related data) found by the crawler
     * @return array
     */
    public function getLinks()
    {
        if ($this->filterCallback === null) {
            $links = $this->links;
        } else {
            $links = array_filter($this->links, function (Link $linkObj) {
                /*@var $linkObj Link */
                return $linkObj->shouldNotVisit() === false;
            });
        }
        
        return $links;
    }
    
    /**
     * Crawl single URL
     * @param Link $link
     * @param int    $depth
     */
    protected function traverseSingle(Link $linkObj, $depth)
    {        
        $linkObj->setCrawlDepth($depth);
        $hash = $linkObj->getAbsoluteUrl(false);        
        $this->links[$hash] = $linkObj;        
        if ($linkObj->shouldNotVisit()===true) {
            return;
        }        
        if($linkObj->isCrawlable()===false){
            $linkObj->setAsShouldVisit(false);
            $this->log(LogLevel::INFO, 'skipping "'.$hash.'" not crawlable link', ['depth'=>$depth]);
            return;
        }
        
        $filterLinks = $this->filterCallback;
        if ($filterLinks !== null && $filterLinks($linkObj) === false) {
                $linkObj->setAsShouldVisit(false);
                $this->log(LogLevel::INFO, 'skipping "'.$hash.'" url not matching filter criteria', ['depth'=>$depth]);
                return;
        }
        
        try {
            $this->log(LogLevel::INFO, 'crawling '.$hash. ' in process', ['depth'=> $depth]);
            $client = $this->getScrapClient();                 
            $crawler = $client->request('GET', $linkObj->getAbsoluteUrl()); 
            /*@var $response \Symfony\Component\BrowserKit\Response */
            
            $response = $client->getResponse();
            $statusCode =  $response->getStatus();
            
            $linkObj->setStatusCode($statusCode);            
            
            if ($statusCode >= 200 && $statusCode <= 299) {
                $contentType = $response->getHeader('Content-Type');
                $linkObj->setContentType($contentType);

                //traverse children in case the response in HTML document only
                if (strpos($contentType, 'text/html') !== false) {
                    $this->extractMetaInfo($crawler, $hash);

                    $childLinks = array();                    
                    if ($linkObj->isExternal()===false) {                        
                        $childLinks = $this->extractLinksInfo($crawler, $linkObj);
                    }                    
                    $linkObj->setAsVisited();    
                    $this->traverseChildren($linkObj, $childLinks, $depth+1);
                }
            }else{
                $linkObj->setStatusCode($statusCode);
            }
        } catch (ClientException $e) {  
            if ($filterLinks && $filterLinks($linkObj) === false) {
                $this->log(LogLevel::INFO, $hash.' skipping storing broken link not matching filter criteria');
            } else {                
                $linkObj->setStatusCode($e->getResponse()->getStatusCode());
                $linkObj->setErrorInfo($e->getResponse()->getStatusCode());
                $this->log(LogLevel::ERROR, $hash.' broken link detected code='.$e->getResponse()->getStatusCode());
            }
        } catch (\Exception $e) {                        
            if ($filterLinks && $filterLinks($linkObj) === false) {
                $this->log(LogLevel::INFO, $linkObj.' skipping broken link not matching filter criteria');
            } else {                
                $linkObj->setStatusCode(500);
                $linkObj->setErrorInfo($e->getMessage());
                $this->log(LogLevel::ERROR, $hash.' broken link detected code='.$e->getCode());
            }
        }
    }

    /**
     * create and configure client used for scrapping
     * it will configure goutte client by default
     * @return GoutteClient
     */
    public function getScrapClient()
    {
        if (!$this->scrapClient) {
            //default client will be Goutte php Scrapper
            $client = new GoutteClient();
            $client->followRedirects();
            $configOptions = $this->configureGuzzleOptions();
            $guzzleClient = new \GuzzleHttp\Client($configOptions);
            $client->setClient($guzzleClient);
            
            $this->scrapClient = $client;
        }

        return $this->scrapClient;
    }

    /**
     * set custom scrap client
     * @param ScrapClient $client
     */
    public function setScrapClient(ScrapClient $client)
    {
        $this->scrapClient = $client;
    }
        
    /**
     * set callback to filter links by specific criteria
     * @param \Closure $filterCallback
     * @return \Arachnid\Crawler
     */
    public function filterLinks(\Closure $filterCallback)
    {
        $this->filterCallback = $filterCallback;
        return $this;
    }

    /**
     * Crawl child links
     * @param Link $sourceUrl
     * @param array $childLinks
     * @param int   $depth
     */
    public function traverseChildren(Link $sourceUrl, $childLinks, $depth)
    {                
        foreach ($childLinks as $url => $info) {            
            
            $filterCallback = $this->filterCallback;
            $childLink = new Link($url,$sourceUrl);
            $hash = $childLink->getAbsoluteUrl(false);
            $this->links[$hash]  = $childLink;
            
            if ($filterCallback && $filterCallback($url)===false &&
                    isset($this->links[$hash]) === false) {
                    $childLink->setAsShouldVisit(false);
                    $this->log(LogLevel::INFO, 'skipping '.$url.' link not match filter criteria');
                    return;
            }
            if (isset($this->links[$hash]) === false) {
                $this->links[$hash] = $info;
                $childLink->setCrawlDepth($depth);		
            } else {		
                $originalLink = $this->links[$hash];
                $originalLink->addMetaInfo('originalUrls',$childLink->getOriginalUrl());
                $originalLink->addMetaInfo('linksText',$childLink->getMetaInfo('linksText'));                
            }

            $this->childrenByDepth[$depth][$sourceUrl->getAbsoluteUrl(false)][] = $hash;                        
        }
        
    }

    /**
     * Extract links information from url
     * @param  \Symfony\Component\DomCrawler\Crawler $crawler
     * @param  string                                $url
     * @return array
     */
    public function extractLinksInfo(DomCrawler $crawler, Link $pageLink)
    {        
        $childLinks = array();
        $crawler->filter('a')->each(function (DomCrawler $node, $i) use (&$childLinks, $pageLink) {
            $nodeText = trim($node->html());               
            
            $href = $node->extract('href')[0];
            if(empty($href)===true){
                return;
            }
            $nodeLink = new Link($href,$pageLink);
            $nodeLink->addMetaInfo('linksText', $nodeText);
            
            $hash = $nodeLink->getAbsoluteUrl(false,false);
            if(isset($this->links[$hash]) === false){
                $childLinks[$hash] = $nodeLink;
            }
            
            
            $filterCallback = $this->filterCallback;
            if ($filterCallback && $filterCallback($hash) === false) {            
                $nodeLink->setAsShouldVisit(false);
                $this->log(LogLevel::INFO, 'skipping '.$hash. ' not matching filter criteira');
                return;
            }
        });


        return $childLinks;
    }

    /**
     * set logger to the crawler
     * @param $logger \Psr\Log\LoggerInterface
     * @return \Arachnid\Crawler
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;
        return $this;
    }
    
    public function getLinksArray(){
        $links = $this->getLinks();
        
        return array_map(function(Link $link){
            return [
              'fullUrl' => $link->getAbsoluteUrl(),
              'uri' => $link->getPath(),
              'metaInfo' => $link->getMetaInfoArray(),
              'parentLink' => $link->getParentUrl(),
              'statusCode' => $link->getStatusCode(), 
              'contentType' => $link->getContentType(), 
            ];
        },$links);
    }    
    
    /**
     * Extract meta title/description/keywords information from url
     * @param \Symfony\Component\DomCrawler\Crawler $crawler
     * @param string                                $url
     */
    protected function extractMetaInfo(DomCrawler $crawler, $url)
    {
        /*@var $currentLink Link */
        $currentLink = $this->links[$url];
        $currentLink->setMetaInfo('title', '');
        $currentLink->setMetaInfo('metaKeywords', '');
        $currentLink->setMetaInfo('metaDescription', '');        
        
        $currentLink->setMetaInfo('title',strip_tags($crawler->filter('title')->html()));
        
        $crawler->filterXPath('//meta[@name="description"]')->each(function (DomCrawler $node) use (&$currentLink) {
            $currentLink->setMetaInfo('metaDescription', strip_tags($node->attr('content')));
        });
        $crawler->filterXPath('//meta[@name="keywords"]')->each(function (DomCrawler $node) use (&$currentLink) {
            $currentLink->setMetaInfo('metaKeywords',trim($node->attr('content')));
        });

        $h1Count = $crawler->filter('h1')->count();
        $currentLink->setMetaInfo('h1Count',$h1Count);
        $currentLink->setMetaInfo('h1Contents', array());
        if ($h1Count > 0) {
            $crawler->filter('h1')->each(function (DomCrawler $node, $i) use ($currentLink) {
                $currentLink->addMetaInfo('h1Contents',trim($node->text()));
            });
        }        
        
        $h2Count = $crawler->filter('h2')->count();
        $currentLink->setMetaInfo('h2Count',$h2Count);
        $currentLink->setMetaInfo('h2Contents', array());
        if ($h2Count > 0) {
            $crawler->filter('h2')->each(function (DomCrawler $node, $i) use ($currentLink) {
                $currentLink->addMetaInfo('h2Contents',trim($node->text()));
            });
        }        
    }

    /**
     * logging activity of the crawler in case logger is associated
     * @param string $level
     * @param string $message
     * @param array $context
     */
    protected function log($level, $message, array $context = array())
    {
        if (isset($this->logger) === true) {
            $this->logger->log($level, $message, $context);
        }
    }

    /**
     * configure guzzle objects
     * @return array
     */
    protected function configureGuzzleOptions()
    {
        $cookieName = time()."_".substr(md5(microtime()), 0, 5).".txt";
                
        $defaultConfig = array(
            'curl' => array(
                CURLOPT_COOKIEJAR      => $cookieName,
                CURLOPT_COOKIEFILE     => $cookieName,
            ),
        );
        $configOptions = array_merge_recursive($this->configOptions, $defaultConfig);
        
        return $configOptions;
    }
}
