<?php
namespace Arachnid;

use Goutte\Client;

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
 * @version 1.0.0
 */
class Crawler
{
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
     * Constructor
     * @param string $baseUrl
     * @param int    $maxDepth
     */
    public function __construct($baseUrl, $maxDepth = 3)
    {
        $this->baseUrl = $baseUrl;
        $this->maxDepth = $maxDepth;
        $this->links = array();
    }

    /**
     * Initiate the crawl
     * @param string $url
     */
    public function traverse($url = null)
    {
        if ($url === null) {
            $url = $this->baseUrl;
            $this->links[$url] = array(
                'links_text' => array('BASE_URL'),
                'absolute_url' => $url,
                'frequency' => 1,
                'visited' => false,
                'external_link' => false,
                'original_urls' => array($url)
            );
        }

        $this->traverseSingle($url, $this->maxDepth);
    }

    /**
     * Get links (and related data) found by the crawler
     * @return array
     */
    public function getLinks() {
        return $this->links;
    }

    /**
     * Crawl single URL
     * @param string $url
     * @param int    $depth
     */
    protected function traverseSingle($url, $depth)
    {
        try {
            $client = new Client();			
			$client->followRedirects();
			
            $crawler = $client->request('GET', $url);			
            $statusCode = $client->getResponse()->getStatus();

            $this->links[$url]['status_code'] = $statusCode;

            if ($statusCode === 200) {
                $this->extractTitleInfo($crawler, $url);

                $childLinks = array();
                if (isset($this->links[$url]['external_link']) === true && $this->links[$url]['external_link'] === false) {
                    $childLinks = $this->extractLinksInfo($crawler, $url);
                }				

                $this->links[$url]['visited'] = true;
                $this->traverseChildren($childLinks, $depth - 1);
            }
        } catch (\Guzzle\Http\Exception\CurlException $e) {
            $this->links[$url]['status_code'] = '404';
            $this->links[$url]['error_code'] = $e->getCode();
            $this->links[$url]['error_message'] = $e->getMessage();
        } catch (\Exception $e) {
            $this->links[$url]['status_code'] = '404';
            $this->links[$url]['error_code'] = $e->getCode();
            $this->links[$url]['error_message'] = $e->getMessage();
        }		
    }

    /**
     * Crawl child links
     * @param array $childLinks
     * @param int   $depth
     */
    protected function traverseChildren($childLinks, $depth)
    {
        if ($depth === 0) {
            return;
        }

        foreach ($childLinks as $uri => $info) {
            if (isset($this->links[$uri]) === false) {
                $this->links[$uri] = $info;
            } else {
                $this->links[$uri]['original_urls'] = isset($this->links[$uri]['original_urls']) ? array_merge($this->links[$uri]['original_urls'], $info['original_urls']) : $info['original_urls'];
                $this->links[$uri]['links_text'] = isset($this->links[$uri]['links_text']) ? array_merge($this->links[$uri]['links_text'], $info['links_text']) : $info['links_text'];
                if (isset($this->links[$uri]['visited']) === true && $this->links[$uri]['visited'] === true) {
                    $oldFrequency = isset($info['frequency']) ? $info['frequency'] : 0;
                    $this->links[$uri]['frequency'] = isset($this->links[$uri]['frequency']) ? $this->links[$uri]['frequency'] + $oldFrequency : 1;
                }
            }
			
			if(isset($this->links[$uri]['visited'])===false){
				$this->links[$uri]['visited'] = false;
			}
			
            if (empty($uri) === false && $this->links[$uri]['visited'] === false && isset($this->links[$uri]['dont_visit']) === false) {
                $this->traverseSingle($this->normalizeLink($childLinks[$uri]['absolute_url']), $depth);
            }
        }
    }

    /**
     * Extract links information from url
     * @param  \Symfony\Component\DomCrawler\Crawler $crawler
     * @param  string                                $url
     * @return array
     */
    protected function extractLinksInfo(\Symfony\Component\DomCrawler\Crawler $crawler, $url)
    {
        $childLinks = array();
        $crawler->filter('a')->each(function(\Symfony\Component\DomCrawler\Crawler $node, $i) use (&$childLinks) {
            $node_text = trim($node->text());
            $node_url = $node->attr('href');
            $node_url_is_crawlable = $this->checkIfCrawlable($node_url);
            $hash = $this->normalizeLink($node_url);

            if (isset($this->links[$hash]) === false) {
                $childLinks[$hash]['original_urls'][$node_url] = $node_url;
                $childLinks[$hash]['links_text'][$node_text] = $node_text;

                if ($node_url_is_crawlable === true) {
                    // Ensure URL is formatted as absolute
										
                    if (preg_match("@^http(s)?@", $node_url) == false) {
						if(strpos($node_url,'/') ===0){
							$parsed_url = parse_url($this->baseUrl);
							$childLinks[$hash]['absolute_url'] = $parsed_url['scheme'].'://'.$parsed_url['host'].$node_url;
						}else{
							$childLinks[$hash]['absolute_url'] = $this->baseUrl . $node_url;
						}
                    } else {
                        $childLinks[$hash]['absolute_url'] = $node_url;
                    }

                    // Is this an external URL?
                    $childLinks[$hash]['external_link'] = $this->checkIfExternal($childLinks[$hash]['absolute_url']);

                    // Additional metadata
                    $childLinks[$hash]['visited'] = false;
                    $childLinks[$hash]['frequency'] = isset($childLinks[$hash]['frequency']) ? $childLinks[$hash]['frequency'] + 1 : 1;
                } else {
                    $childLinks[$hash]['dont_visit'] = true;
                    $childLinks[$hash]['external_link'] = false;
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
     * Extract title information from url
     * @param \Symfony\Component\DomCrawler\Crawler $crawler
     * @param string                                $url
     */
    protected function extractTitleInfo(\Symfony\Component\DomCrawler\Crawler $crawler, $url)
    {
        $this->links[$url]['title'] = trim($crawler->filterXPath('html/head/title')->text());

        $h1_count = $crawler->filter('h1')->count();
        $this->links[$url]['h1_count'] = $h1_count;
        $this->links[$url]['h1_contents'] = array();

        if ($h1_count > 0) {
            $crawler->filter('h1')->each(function(\Symfony\Component\DomCrawler\Crawler $node, $i) use ($url) {
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
        );

        foreach ($stop_links as $ptrn) {
            if (preg_match($ptrn, $uri) == true) {
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
        $base_url_trimmed = str_replace(array('http://', 'https://'), '', $this->baseUrl);

        return preg_match("@http(s)?\://$base_url_trimmed@", $url) == false;
    }

    /**
     * Normalize link (remove hash, etc.)
     * @param  string $url
     * @return string
     */
    protected function normalizeLink($uri)
    {
        return preg_replace('@#.*$@', '', $uri);
    }
}
