<?php

namespace Arachnid;

use Goutte\Client as GoutteClient;
use Symfony\Component\BrowserKit\Client as ScrapClient;
use Guzzle\Http\Exception\CurlException;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use Psr\Log\LogLevel;

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
     * @var string
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
     * whether file to be crawled is local file or remote one
     * @var boolean
     */
    protected $localFile;
    
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
        $this->baseUrl = $baseUrl;
        $this->maxDepth = $maxDepth;
        $this->links = array();
        $this->localFile  = isset($config['localFile'])===true?
                                  $config['localFile']:false;
        unset($config['localFile']);
        
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
     * @param string $url
     * @return \Arachnid\Crawler
     */
    public function traverse($url = null)
    {
        if ($url === null) {
            $url = $this->baseUrl;
        }
        
        $this->links[$url] = array(
            'links_text' => array('BASE_URL'),
            'absolute_url' => $url,
            'frequency' => 1,
            'visited' => false,
            'external_link' => false,
            'original_urls' => array($url),
            'source_link'   => "",            
        );        
        $this->traverseSingle($url, 0);

        for($depth=1; $depth<$this->maxDepth; $depth++){            
            if(!isset($this->childrenByDepth[$depth])){
                $this->log(LogLevel::INFO, "skipping level#".$depth." no items found");
                continue;
            }
            
            foreach($this->childrenByDepth[$depth] as $parentLink => $urls){
                foreach($urls as $url){
                    $this->traverseSingle($url, $depth);
                }
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
            $links = array_filter($this->links, function ($link_info) {
                if (isset($link_info['dont_visit'])===true &&
                        $link_info['dont_visit']===true) {
                    return false;
                } else {
                    return true;
                }
            });
        }
        
        return $links;
    }

    /**
     * Crawl single URL
     * @param string $url
     * @param int    $depth
     */
    protected function traverseSingle($url, $depth)
    {

        $hash = $this->getPathFromUrl($url);
        
        if (isset($this->links[$hash]['dont_visit']) &&
                $this->links[$hash]['dont_visit']===true) {
            return;
        }
        
        $filterLinks = $this->filterCallback;
        if ($filterLinks !== null && $filterLinks($url) === false) {
                $this->links[$hash]['dont_visit'] = true;
                $this->log(LogLevel::INFO, 'skipping "'.$url.'" url not matching filter criteria', ['depth'=>$depth]);
                return;
        }
        
        try {
            $this->log(LogLevel::INFO, 'crawling '.$url. ' in process', ['depth'=> $depth]);
            $client = $this->getScrapClient();
            $crawler = $client->request('GET', $this->getAbsoluteUrl($url), [],[],[],null,false); //disable change history
            $statusCode = $client->getResponse()->getStatus();
                        
            if ($url == $this->baseUrl) {
                $hash = $url;
            } else {
                $hash = $this->getPathFromUrl($url, $this->baseUrl);
            }
            $this->links[$hash]['status_code'] = $statusCode;
            if(!isset($this->links[$hash]['depth'])){ //if already exist in previous depth, don't override
                $this->links[$hash]['depth'] = $depth;
            }
            
            if ($statusCode === 200) {
                $content_type = $client->getResponse()->getHeader('Content-Type');

                //traverse children in case the response in HTML document only
                if (strpos($content_type, 'text/html') !== false) {
                    $this->extractMetaInfo($crawler, $hash);

                    $childLinks = array();
                    if (isset($this->links[$hash]['external_link']) === true
                            && $this->links[$hash]['external_link'] === false) {
                        $childLinks = $this->extractLinksInfo($crawler, $hash);
                    }                    
                    $this->links[$hash]['visited'] = true;    
                    $this->traverseChildren($hash, $childLinks, $depth+1);
                }
            }
        } catch (CurlException $e) {             
            if ($filterLinks && $filterLinks($url) === false) {
                $this->log(LogLevel::INFO, $url.' skipping broken link not matching filter criteria');
            } else {
                $this->links[$url]['status_code'] = '404';
                $this->links[$url]['error_code'] = $e->getCode();
                $this->links[$url]['error_message'] = $e->getMessage();
                $this->log(LogLevel::ERROR, $url.' broken link detected code='.$e->getCode());
            }
        } catch (ClientException $e) {            
            if ($filterLinks && $filterLinks($url) === false) {
                $this->log(LogLevel::INFO, $url.' skipping storing broken link not matching filter criteria');
            } else {
                $this->links[$url]['status_code'] = $e->getResponse()->getStatusCode();
                $this->links[$url]['error_code'] = $e->getCode();
                $this->links[$url]['error_message'] = $e->getMessage().' in line '.$e->getLine();
                $this->log(LogLevel::ERROR, $url.' broken link detected code='.$e->getResponse()->getStatusCode());
            }
        } catch (\Exception $e) {
            if ($filterLinks && $filterLinks($url) === false) {
                $this->log(LogLevel::INFO, $url.' skipping broken link not matching filter criteria');
            } else {                
                $this->links[$url]['status_code'] = '404';
                $this->links[$url]['error_code'] = $e->getCode();
                $this->links[$url]['error_message'] = $e->getMessage().' in line '.$e->getLine();
                $this->log(LogLevel::ERROR, $url.' broken link detected code='.$e->getCode());
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
            if ($this->localFile ===false) {
                //default client will be Goutte php Scrapper
                $client = new GoutteClient();
                $client->followRedirects();
                $configOptions = $this->configureGuzzleOptions();
                $guzzleClient = new \GuzzleHttp\Client($configOptions);
                $client->setClient($guzzleClient);
            } else {
                //local file system crawler
                $client = new Clients\FilesystemClient();
            }
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
     * @param string $sourceUrl
     * @param array $childLinks
     * @param int   $depth
     */
    public function traverseChildren($sourceUrl, $childLinks, $depth)
    {                
        foreach ($childLinks as $url => $info) {            
            
            $filterCallback = $this->filterCallback;
            $hash = $this->getPathFromUrl($url, $sourceUrl);
            
            if ( isset($this->links[$hash]['dont_visit']) &&
                    $this->links[$hash]['dont_visit']===true) {
                return;
            }

            
            if ($filterCallback && $filterCallback($url)===false &&
                    isset($this->links[$hash]) === false) {
                    $this->links[$hash]['dont_visit'] = true;
                    $this->log(LogLevel::INFO, 'skipping '.$url.' link not match filter criteria');
                    return;
            }
            if (isset($this->links[$hash]) === false) {
                $this->links[$hash] = $info;
		$this->links[$hash]['source_link'] = $this->getAbsoluteUrl($sourceUrl);
		$this->links[$hash]['depth'] = $depth;
            } else {		
                $this->links[$hash]['original_urls'] = isset($this->links[$hash]['original_urls'])
                        ? array_merge($this->links[$hash]['original_urls'], $info['original_urls'])
                        : $info['original_urls'];
                $this->links[$hash]['links_text'] = isset($this->links[$hash]['links_text'])
                        ? array_merge($this->links[$hash]['links_text'], $info['links_text'])
                        : $info['links_text'];
                if (isset($this->links[$hash]['visited']) === true &&
                        $this->links[$hash]['visited'] === true) {
                    $oldFrequency = isset($info['frequency']) ? $info['frequency'] : 0;
                    $this->links[$hash]['frequency'] =
                            isset($this->links[$hash]['frequency'])
                            ? $this->links[$hash]['frequency'] + $oldFrequency
                            : 1;
                }
                }

            if (isset($this->links[$hash]['visited']) === false) {
                $this->links[$hash]['visited'] = false;
            }

            $this->childrenByDepth[$depth][$sourceUrl][] = $url;                        
        }
        
        
        
    }

    /**
     * Extract links information from url
     * @param  \Symfony\Component\DomCrawler\Crawler $crawler
     * @param  string                                $url
     * @return array
     */
    public function extractLinksInfo(DomCrawler $crawler, $url)
    {
        $childLinks = array();
        $crawler->filter('a')->each(function (DomCrawler $node, $i) use (&$childLinks, $url) {
            $nodeText = trim($node->text());
            $nodeUrl = $node->attr('href');
            $nodeUrlIsCrawlable = $this->checkIfCrawlable($nodeUrl);

            
            $normalizedLink = $this->normalizeLink($nodeUrl);
            $hash = $this->getPathFromUrl($normalizedLink, $url);
            $filterLinks = $this->filterCallback;
            if (isset($this->links[$hash]['dont_visit']) &&
                    $this->links[$hash]['dont_visit']===true) {
                return;
            }
            if ($filterLinks && $filterLinks($hash) === false) {
                $this->links[$hash]['dont_visit'] = true;
                $this->log(LogLevel::INFO, 'skipping '.$hash. ' not matching filter criteira');
                return;
            }

            if (isset($this->links[$hash]) === false) {
                $childLinks[$hash]['original_urls'][$nodeUrl] = $nodeUrl;
                $childLinks[$hash]['links_text'][$nodeText] = $nodeText;

                if ($nodeUrlIsCrawlable === true) {
                    // Ensure URL is formatted as absolute
                    $childLinks[$hash]['absolute_url'] = $this->getAbsoluteUrl($nodeUrl, $url);

                    // Is this an external URL?
                    $childLinks[$hash]['external_link'] = $this->checkIfExternal($childLinks[$hash]['absolute_url']);

                    //frequency or visited
                    if (isset($childLinks[$hash]['visited']) === false) {
                        $childLinks[$hash]['visited'] = false;
                    }
                    $childLinks[$hash]['frequency'] = isset($childLinks[$hash]['frequency']) ?
                            $childLinks[$hash]['frequency'] + 1 : 1;
                } else {
                    $childLinks[$hash]['visited'] = false;
                    $childLinks[$hash]['dont_visit'] = true;
                    $childLinks[$hash]['external_link'] = false;
                    if (!isset($this->links[$hash]['dont_visit'])) { //not already added to all links
                        $this->log(LogLevel::INFO, 'skipping "'.$hash.'" not crawlable link');
                    }
                }
            }
        });

        // Avoid cyclic loops with pages that link to themselves
        if (isset($childLinks[$url]) === true) {
            $childLinks[$url]['visited'] = true;
        }

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
        $this->links[$url]['title'] = '';
        $this->links[$url]['meta_keywords'] = '';
        $this->links[$url]['meta_description'] = '';
        
        $crawler->filterXPath('//head//title')->each(function (DomCrawler $node) use ($url) {
            $this->links[$url]['title'] = trim($node->text());
        });
        
        $crawler->filterXPath('//meta[@name="description"]')->each(function (DomCrawler $node) use ($url) {
            $this->links[$url]['meta_description'] = trim($node->attr('content'));
        });
        $crawler->filterXPath('//meta[@name="keywords"]')->each(function (DomCrawler $node) use ($url) {
            $this->links[$url]['meta_keywords'] = trim($node->attr('content'));
        });

        $h1_count = $crawler->filter('h1')->count();
        $this->links[$url]['h1_count'] = $h1_count;
        $this->links[$url]['h1_contents'] = array();

        if ($h1_count > 0) {
            $crawler->filter('h1')->each(function (DomCrawler $node, $i) use ($url) {
                $this->links[$url]['h1_contents'][$i] = trim($node->text());
            });
        }
    }

    /**
     * Is a given URL crawlable?
     * @param  string $uri
     * @return bool
     */
    protected function checkIfCrawlable($uri)
    {
        if (empty($uri) === true) {
            return false;
        }

        $stop_links = array(
            '@^javascript\:.*$@i',
            '@^#.*@',
            '@^mailto\:.*@i',
            '@^tel\:.*@i',
            '@^fax\:.*@i',
            '@.*(\.pdf)$@i'
        );

        foreach ($stop_links as $ptrn) {
            if (preg_match($ptrn, $uri) === 1) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Is URL external?
     * @param  string $url An absolute URL (with scheme)
     * @return bool
     */
    protected function checkIfExternal($url)
    {
        if ($this->localFile===true) {
            $ret = strpos($url, 'http://')===0 || strpos($url, 'https://')===0;
        } else {
            $baseUrlTrimmed = str_replace(array('http://', 'https://'), '', $this->baseUrl);
            $baseUrlTrimmed = explode('/', $baseUrlTrimmed)[0];

            $ret = preg_match("@http(s)?\://$baseUrlTrimmed@", $url) !== 1;
        }
        return $ret;
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
     * Normalize link (remove hash, etc.)
     * @param  string $uri
     * @return string
     */
    protected function normalizeLink($uri)
    {
        return preg_replace('@#.*$@', '', $uri);
    }

    /**
     * extrating the relative path from url string
     * @param  string $url
     * @param  string $sourceUrl
     * @return string
     */
    protected function getPathFromUrl($url, $sourceUrl = null)
    {
        if (is_null($sourceUrl)===true) {
            $sourceUrl = $this->baseUrl;
        }
        
        if ($this->checkIfCrawlable($url) === false) {
            $ret = $url;
        } elseif ($this->localFile === true) {
            $trimmedPath = dirname($sourceUrl);
            
            if (strpos($url, 'http://')===0 || strpos($url, 'https://')===0) { //different domain name
                $ret = $url;
            } elseif (strpos($url, $trimmedPath) === 0) { //url full url begin with baseUrl name
                $ret = $url;
            } elseif (strpos($url, '/')===0) {
                $ret = $trimmedPath.$url;
            } else {
                $ret = $trimmedPath.'/'.$url;
            }
        } else {
            $schemaAndHost = parse_url($sourceUrl, PHP_URL_SCHEME).'://'.
            parse_url($sourceUrl, PHP_URL_HOST);
            
            if (strpos($url, $schemaAndHost) === 0 && $url !== $schemaAndHost) {
                $ret = str_replace($schemaAndHost, '', $url);
            } elseif (strpos($url, 'http://')===0 || strpos($url, 'https://')===0) { //different domain name
                $ret = $url;
            } elseif (strpos($url, '/')!==0) {
                $path = rtrim(parse_url($sourceUrl, PHP_URL_PATH), '/');
                $ret = $path.'/'.$url;
            } else {
                $ret = $url;
            }
        }
        return $ret;
    }

    /**
     * converting nodeUrl to absolute url form
     * @param string      $nodeUrl
     * @param string|NULL $parentUrl
     * @return string
     */
    protected function getAbsoluteUrl($nodeUrl, $parentUrl = null)
    {
        
        $urlParts = parse_url($this->baseUrl);
        
        if (strpos($nodeUrl, 'http://')===0 || strpos($nodeUrl, 'https://')===0) {
                $ret = $nodeUrl;
        } elseif (strpos($nodeUrl, '#') === 0) {
            $ret = rtrim($this->baseUrl, '/').$nodeUrl;
        } elseif (!$this->checkIfCrawlable($nodeUrl)) {
            $ret = $nodeUrl;
        } elseif (strpos($nodeUrl, '//') === 0) {
                $ret = (isset($urlParts['scheme'])=== true?
                        $urlParts['scheme']:'http').':'.$nodeUrl;
        } elseif (isset($urlParts['scheme'])) {
            if (strpos($nodeUrl, '/')===0) {
                $ret = $urlParts['scheme'] . '://' . $urlParts['host'] . $nodeUrl;
            } else {
                $ret = $this->baseUrl.$nodeUrl;
            }
        } elseif ($this->localFile===true) {
            if(strpos($nodeUrl,$parentUrl)===false && empty($parentUrl) === false){
                $ret = dirname($parentUrl).'/'.$nodeUrl;
            }else{
                $ret = $nodeUrl;
            }
        }
        
        return $ret;
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
