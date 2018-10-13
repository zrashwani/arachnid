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
                    $this->traverseSingle($childLink, $depth, $parentLink);
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
            $links = array_filter($this->links, function ($linkObj) {
                /*@var $linkObj Link */
                return $linkObj->shouldNotVisit();
            });
        }
        
        return $links;
    }

    /**
     * Crawl single URL
     * @param Link $link
     * @param int    $depth
     */
    protected function traverseSingle(Link $linkObj, $depth, $parentUrl = null)
    {        
        $linkObj->setMetaInfo('depth', $depth);
        $hash = $linkObj->getAbsoluteUrl(false);        
        $this->links[$hash] = $linkObj;        
        if ($linkObj->shouldNotVisit()==true) {
            return;
        }        
        if($linkObj->isCrawlable()==false){
            $linkObj->shouldNotVisit();
            $this->log(LogLevel::INFO, 'skipping "'.$hash.'" not crawlable link', ['depth'=>$depth]);
            return;
        }
        
        $filterLinks = $this->filterCallback;
        if ($filterLinks !== null && $filterLinks($linkObj) === false) {
                $linkObj->shouldNotVisit();
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
            
            if ($statusCode === 200) {
                $content_type = 'text/html'; //$response->getHeader('Content-Type');

                //traverse children in case the response in HTML document only
                if (strpos($content_type, 'text/html') !== false) {
                    $this->extractMetaInfo($crawler, $hash);

                    $childLinks = array();                    
                    if ($linkObj->isExternal()==false) {                        
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
                $linkObj->setErrorInfo($e->getResponse()->getStatusCode().' '.$e->getMessage().' '.$e->getFile());
                $this->log(LogLevel::ERROR, $hash.' broken link detected code='.$e->getResponse()->getStatusCode().' in line '.__LINE__);
            }
        } catch (\Exception $e) {                        
            if ($filterLinks && $filterLinks($linkObj) === false) {
                $this->log(LogLevel::INFO, $linkObj.' skipping broken link not matching filter criteria');
            } else {                
                $linkObj->setStatusCode(500); //TODO replace
                $linkObj->setErrorInfo($e->getMessage().' in line '.$e->getLine().' '.$e->getFile());
                $this->log(LogLevel::ERROR, $hash.' broken link detected code='.$e->getCode().' in line '.__LINE__.' '.__FILE__);
            }
        }
    }

    /**
     * create and configure goutte client used for scraping
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

    public function setScrapClient($client)
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
                $childLink->setMetaInfo('depth', $depth);		
            } else {		
                $originalLink = $this->links[$hash];
                $originalLink->addMetaInfo('original_urls',$childLink->getOriginalUrl());
                $originalLink->addMetaInfo('links_text',$childLink->getMetaInfo('links_text'));                
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
        $crawler->filter('a')->each(function ($node, $i) use (&$childLinks, $pageLink) {
            $nodeText = trim($node->html());               
            
            $href = $node->extract('href')[0];
            if(empty($href)==true){
                return;
            }
            $nodeLink = new Link($href,$pageLink);
            $nodeLink->addMetaInfo('links_text', $nodeText);
            
            $hash = $nodeLink->getAbsoluteUrl(false,false);
            if(isset($this->links[$hash]) == false){
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
        $currentLink->setMetaInfo('meta_keywords', '');
        $currentLink->setMetaInfo('meta_description', '');        
        
        $currentLink->setMetaInfo('title',strip_tags($crawler->filter('title')->html()));
        
        $crawler->filterXPath('//meta[@name="description"]')->each(function (DomCrawler $node) use (&$currentLink) {
            $currentLink->setMetaInfo('meta_description', strip_tags($node->attr('content')));
        });
        $crawler->filterXPath('//meta[@name="keywords"]')->each(function (DomCrawler $node) use (&$currentLink) {
            $currentLink->setMetaInfo('meta_keywords',trim($node->attr('content')));
        });

        $h1_count = $crawler->filter('h1')->count();
        $currentLink->setMetaInfo('h1_count',$h1_count);
        $currentLink->setMetaInfo('h1_contents', array());

        if ($h1_count > 0) {
            $crawler->filter('h1')->each(function (DomCrawler $node, $i) use ($currentLink) {
                $currentLink->addMetaInfo('h1_contents',trim($node->text()));
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
