<?php

namespace Arachnid;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class LinksCollectionTest extends \PHPUnit_Framework_TestCase
{
    /**
     * test macros
     */
    public function testExternalLinks(){
        $items = [
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
                ],            
        ];
        
        $collection = new LinksCollection($items);
        
        $externalLinks = $collection->getExternalLinks();
        $this->assertGreaterThanOrEqual(1, $externalLinks->count());
    }
}