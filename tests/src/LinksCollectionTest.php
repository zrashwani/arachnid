<?php

namespace Arachnid;
use PHPUnit\Framework\TestCase;

class LinksCollectionTest extends TestCase
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
        $brokenLinksDetailed = $collection->getBrokenLinks()->toArray();        
        
        $this->assertArrayHasKey('/broken-link', $brokenLinksDetailed);
        $this->assertArraySubset(['dead link'], $brokenLinksDetailed['/broken-link']->getMetaInfo('links_code'));
        
        $brokenLinksSummary = $collection->getBrokenLinks(true);
        $this->assertArrayNotHasKey('links_text', $brokenLinksSummary['/broken-link']);
    }
    
    protected function getSampleLinks(){
        $links = array();
        $links['/test'] = (new Link('/test',new Link('http://test.com/')))                           
                           ->setStatusCode(200)
                           ->addMetaInfo('title', 'Test Link')
                           ->addMetaInfo('h1_count', 1)
                           ->addMetaInfo('h1_contents', ['test']);
        $links['http://external-link.com'] = 
                (new Link('http://external-link2.com', new Link('http://external-link.com')))
                ->setMetaInfo('depth', 2)
                ->addMetaInfo('links_text', ['external-link'])
                ;
        $links['/broken-link'] = (new Link('/broken-link',new Link('http://test.com/test')))
                               ->setStatusCode(404)
                               ->setMetaInfo('depth', 2)
                               ->addMetaInfo('links_code', 'dead link')
                ;
        return $links;       
    }
}