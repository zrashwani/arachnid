<?php

namespace Arachnid;

use GuzzleHttp\Exception\ClientException;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use Arachnid\Adapters\CrawlingAdapterInterface;
use Arachnid\Adapters\CrawlingFactory;
use Psr\Log\LogLevel;
use Arachnid\Link;
use Arachnid\Exceptions\InvalidUrlException;

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
    const HTML_CONTENT_TYPE = 'text/html';

    /**
     * Scrap client used for crawling the files, can be either Goutte or Panther chrome headless browser
     * @var CrawlingAdapterInterface $scrapClient
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
    protected $visitedLinks;

    /**
     * queue of links to visit
     * @var SplQueue
     */
    protected $linksToVisit;

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
     * configuration for scrapping client
     * @var array
     */
    private $config;

    /**
     * use headless browser crawler
     * @var boolean
     */
    private $headlessBrowserEnabled;

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
        $this->config = $config;
    }

    /**
     * Initiate the crawl
     * @param UriInterface $link
     * @return \Arachnid\Crawler
     */
    public function traverse(Link $link = null)
    {
        if ($link === null) {
            $link = $this->baseUrl;
        }

        $this->linksToVisit = new \SplQueue();
        $this->linksToVisit->enqueue($link);

        while (!$this->linksToVisit->isEmpty()) {
            /* @var $currLink Link */
            $currLink = $this->linksToVisit->dequeue();
            $currUrl = $currLink->getAbsoluteUrl(false);
            if (isset($this->visitedLinks[$currUrl]) === true) {
                continue;
            }
            if ($currLink->getCrawlDepth() >= $this->maxDepth &&
               $currLink->isCrawlable()) {
                //if link is same of exceeding max crawl depth, just check
                //the headers (to see if it is broken or not) and don't traverse
                $this->fillHeaderInfo($currLink);
                $currLink->setAsShouldVisit(false);
            } else {
                $this->traverseSingle($currLink);
            }
            $this->visitedLinks[$currUrl] = $currLink;
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
            $links = $this->visitedLinks;
        } else {
            $links = array_filter($this->visitedLinks, function (Link $linkObj) {
                /* @var $linkObj Link */
                return $linkObj->shouldNotVisit() === false;
            });
        }

        return $links;
    }

    /**
     * get links information as array
     * @return array
     */
    public function getLinksArray($includeOnlyVisited = false)
    {
        $links = $this->getLinks();

        if ($includeOnlyVisited === true) {
            $links = array_filter($links, function (Link $linkObj) {
                /* @var $linkObj Link */
                return $linkObj->shouldNotVisit() === false;
            });
        }

        return array_map(function (Link $link) {
            return [
                'fullUrl' => $link->getAbsoluteUrl(),
                'uri' => $link->getPath(),
                'metaInfo' => $link->getMetaInfoArray(),
                'parentLink' => $link->getParentUrl(),
                'statusCode' => $link->getStatusCode(),
                'status' => $link->getStatus(),
                'contentType' => $link->getContentType(),
                'errorInfo' => $link->getErrorInfo(),
                'crawlDepth' => $link->getCrawlDepth(),
                'isExternal' => $link->isExternal()
            ];
        }, $links);
    }

    /**
     * Crawl single URL
     * @param Link $linkObj
     * @param int    $depth
     */
    protected function traverseSingle(Link $linkObj)
    {
        $depth = $linkObj->getCrawlDepth();
        $fullUrl = $linkObj->getAbsoluteUrl(false);
        $this->links[$fullUrl] = $linkObj;
        if ($linkObj->shouldNotVisit() === true) {
            return;
        }
        if ($linkObj->isCrawlable() === false) {
            $linkObj->setAsShouldVisit(false);
            $this->log(LogLevel::INFO, 'skipping "' . $fullUrl . '" not crawlable link', ['depth' => $depth]);
            return;
        }

        $filterLinks = $this->filterCallback;
        if ($filterLinks !== null && $filterLinks($linkObj) === false) {
            $linkObj->setAsShouldVisit(false);
            $this->log(LogLevel::INFO, 'skipping "' . $fullUrl . '" url not matching filter criteria', ['depth' => $depth]);
            return;
        }

        try {
            $this->log(LogLevel::INFO, 'crawling ' . $fullUrl . ' in process', ['depth' => $depth]);

            $this->fillHeaderInfo($linkObj);

            if ($linkObj->checkCrawlableStatusCode() === true) {
                $this->fillSingleLinkInfo($linkObj);
            }
        } catch (ClientException $e) {
            if ($filterLinks && $filterLinks($linkObj) === false) {
                $this->log(LogLevel::INFO, $fullUrl . ' skipping storing broken link not matching filter criteria');
            } else {
                $linkObj->setStatusCode($e->getResponse()->getStatusCode());
                $linkObj->setErrorInfo($e->getResponse()->getStatusCode());
                $this->log(LogLevel::ERROR, $fullUrl . ' broken link detected code=' . $e->getResponse()->getStatusCode());
            }
        } catch (\Exception $e) {
            if ($filterLinks && $filterLinks($linkObj) === false) {
                $this->log(LogLevel::INFO, $linkObj . ' skipping broken link not matching filter criteria');
            } else {
                $linkObj->setStatusCode(500);
                $linkObj->setErrorInfo($e->getMessage());
                $this->log(LogLevel::ERROR, $fullUrl . ' broken link detected code=' . $e->getCode());
            }
        }
    }

    /**
     * create and configure client used for scrapping
     * it will configure goutte client by default
     * @return CrawlingAdapterInterface
     */
    public function getScrapClient()
    {
        if ($this->scrapClient === null) {
            if ($this->headlessBrowserEnabled === true) {
                $scrapClient = CrawlingFactory::create(CrawlingFactory::TYPE_HEADLESS_BROWSER, $this->config);
            } else {
                $scrapClient = CrawlingFactory::create(CrawlingFactory::TYPE_GOUTTE, $this->config);
            }
            $this->setScrapClient($scrapClient);
        }
        return $this->scrapClient;
    }

    /**
     * set custom scrap client
     * @param CrawlingAdapterInterface $client
     */
    public function setScrapClient(CrawlingAdapterInterface $client)
    {
        $this->scrapClient = $client;
    }

    /**
     * enable headless browser by using chrome client in the background
     * @return $this
     */
    public function enableHeadlessBrowserMode()
    {
        $this->headlessBrowserEnabled = true;
        return $this;
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
     * Extract links information from url
     * @param  \Symfony\Component\DomCrawler\Crawler $crawler
     * @param Link $pageLink
     */
    public function extractLinksInfo(DomCrawler $crawler, Link $pageLink)
    {
        $crawler->filter('a')->each(function (DomCrawler $node, $i) use ($pageLink) {
            $nodeText = trim($node->html());
            $href = $node->extract(['href']);
            if (count($href) == 0 || empty($href[0]) === true) {
                return;
            }
            $pageLinkClone = new Link(
                $pageLink->getAbsoluteUrl(false),
                $pageLink->getParentLink()
            );
            $nodeLink = new Link($href[0], $pageLinkClone);
            $nodeLink->addMetaInfo('linksText', $nodeText);
            if (!isset($this->visitedLinks[$nodeLink->getAbsoluteUrl(false)])) {
                $this->linksToVisit->enqueue($nodeLink);
            }
        });
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
    
    protected function fillHeaderInfo(Link $linkObj)
    {
        try {
            $headers = $this->extractHeaders($linkObj);
            $statusCode = $headers['status-code'];
            $linkObj->setStatusCode($statusCode);
            $linkObj->setStatus($headers['status']);
            if (isset($headers['content-type']) == true) {
                $linkObj->setContentType($headers['content-type']);
            }
        } catch (InvalidUrlException $ex) {
            $linkObj->setStatusCode(404);
            $linkObj->setStatus("Invalid URL");
        }
    }

    protected function fillSingleLinkInfo(Link $linkObj)
    {
        if (strpos($linkObj->getContentType(), Crawler::HTML_CONTENT_TYPE) !== false) {
            if ($linkObj->isExternal() === false) {
                $client = $this->getScrapClient();
                $crawler = $client->requestPage($linkObj->getAbsoluteUrl());
                $this->extractMetaInfo($crawler, $linkObj);
                $this->extractLinksInfo($crawler, $linkObj);
            }
        }
    }

    /**
     * Extract meta title/description/keywords information from url
     * @param DomCrawler $crawler
     * @param Link $currentLink
     */
    protected function extractMetaInfo(DomCrawler $crawler, Link $currentLink)
    {
        $currentLink->setMetaInfo('title', '');
        $currentLink->setMetaInfo('metaKeywords', '');
        $currentLink->setMetaInfo('metaDescription', '');

        $currentLink->setMetaInfo('title', trim(strip_tags($crawler->filter('title')->html())));

        $crawler->filterXPath('//meta[@name="description"]')->each(function (DomCrawler $node) use (&$currentLink) {
            $currentLink->setMetaInfo('metaDescription', strip_tags($node->attr('content')));
        });
        $crawler->filterXPath('//meta[@name="keywords"]')->each(function (DomCrawler $node) use (&$currentLink) {
            $currentLink->setMetaInfo('metaKeywords', trim($node->attr('content')));
        });
        $crawler->filterXPath('//link[@rel="canonical"]')->each(function (DomCrawler $node) use (&$currentLink) {
            $currentLink->setMetaInfo('canonicalLink', trim($node->attr('href')));
        });

        $h1Count = $crawler->filter('h1')->count();
        $currentLink->setMetaInfo('h1Count', $h1Count);
        $currentLink->setMetaInfo('h1Contents', array());
        if ($h1Count > 0) {
            $crawler->filter('h1')->each(function (DomCrawler $node, $i) use ($currentLink) {
                $currentLink->addMetaInfo('h1Contents', trim($node->text()));
            });
        }

        $h2Count = $crawler->filter('h2')->count();
        $currentLink->setMetaInfo('h2Count', $h2Count);
        $currentLink->setMetaInfo('h2Contents', array());
        if ($h2Count > 0) {
            $crawler->filter('h2')->each(function (DomCrawler $node, $i) use ($currentLink) {
                $currentLink->addMetaInfo('h2Contents', trim($node->text()));
            });
        }
    }
    
    /**
     * extract headers array for the link url
     * @return array
     */
    protected function extractHeaders(Link $linkObj)
    {
        stream_context_set_default([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                ],
        ]);
        
        $absoluteUrl = $linkObj->getAbsoluteUrl();
        if (filter_var($absoluteUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidUrlException("Invalid Url: {$absoluteUrl}");
        }
        $headersArrRaw = @get_headers($absoluteUrl, 1);
        
        if ($headersArrRaw === false) {
            throw new InvalidUrlException("cannot get headers for {$absoluteUrl}");
        }
        
        $headersArr = array_change_key_case($headersArrRaw, CASE_LOWER);
        if (isset($headersArr[0]) === true && strpos($headersArr[0], 'HTTP/') !== false) {
            $statusStmt = $headersArr[0];
            $statusParts = explode(' ', $statusStmt);
            $headersArr['status-code'] = $statusParts[1];
            
            $statusIndex = strrpos($statusStmt, $statusParts[1])+ strlen($statusParts[1])+1;
            $headersArr['status'] = trim(substr($statusStmt, $statusIndex));
        }
        if (is_array($headersArr['content-type']) === true) {
            $headersArr['content-type'] = end($headersArr['content-type']);
        }
        
        return $headersArr;
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
}
