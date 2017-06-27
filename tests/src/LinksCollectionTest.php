<?php

namespace Arachnid;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class LinksCollectionTest extends \PHPUnit_Framework_TestCase
{
    /**
     * test detecting external links
     */
    public function testExternalLinks(){
        
        $collection = new LinksCollection($this->getSampleLinks());
        
        $externalLinks = $collection->getExternalLinks();
        $this->assertGreaterThanOrEqual(1, $externalLinks->count());
    }
    
    /**
     * test detecting broken links, both summary and detailed info
     */
    public function testBrokenLinks(){
        $collection = new LinksCollection($this->getSampleLinks());
        $brokenLinksDetailed = $collection->getBrokenLinks();
        
        $this->assertArrayHasKey('/broken-link', $brokenLinksDetailed);
        $this->assertArrayHasKey('links_text', $brokenLinksDetailed['/broken-link']);
        
        $brokenLinksSummary = $collection->getBrokenLinks(true);
        $this->assertArrayNotHasKey('links_text', $brokenLinksSummary['/broken-link']);
    }
    
    protected function getSampleLinks(){
        return [
            '/test' => [
                "original_urls" => [],
                "links_text" => [],
                "absolute_url" => "http://test.com/index.html",
                "external_link" => false,
                "visited" => true,
                "frequency" => 1,
                "source_link" => "http://test.com/",
                "depth" => 1,
                "status_code" => 200,
                "title" => "Test Link",
                "meta_keywords" => "",
                "meta_description" => "",
                "h1_count" => 1,
                "h1_contents" => ['test'],
                ],
                'http://external-link.com' => [
                    'external_link' => true,
                    'links_text' => ['external-link'],
                    'depth' => 2,
                    'source_link' => '/test',
                    'absolute_url' => 'http://external-link.com',
                ],            
                '/broken-link' => [
                    'status_code' => 404,
                    'links_text' => ['dead link'],
                    'depth' => 2,
                    'source_link' => '/test',
                    'absolute_url' => 'http://test.com/broken-link',
                ]
        ];        
    }
}