<?php

namespace Arachnid;

use Arachnid\Link;
use PHPUnit\Framework\TestCase;

class LinkTest extends TestCase
{
    
    /**
     * test get absolute Url
     * @dataProvider urlAbsoluteProvider
     */
    public function testGetAbsoluteUrl($baseUrl, $nodeUrl, $expectedUrl)
    {
        $link = new Link($nodeUrl,new Link($baseUrl));
        $this->assertEquals($expectedUrl, $link->getAbsoluteUrl());
    }

    /**
     * provider data for absolute url method
     * @return array
     */
    public function urlAbsoluteProvider()
    {       
        return [
            [
                'http://example.com',
                '/test',
                'http://example.com/test'
            ],
            [
                'http://example.com/',
                '/test',
                'http://example.com/test'
            ],
            [
                'http://example.com/',
                '/test/sub',
                'http://example.com/test/sub'
            ],
            [
                'http://example.com/',
                'http://example2.com',
                'http://example2.com'
            ],
            [
                'http://example.com/',
                '//example2.com',
                'http://example2.com'
            ],
            [
                'https://example.com/',
                '//example2.com',
                'https://example2.com'
            ],
            [
                'https://example.com/',
                '#title',
                'https://example.com/#title'
            ],
            [
                'https://example.com/',
                'test',
                'https://example.com/test'
            ],
            [
                'http://127.0.0.1:8000/', //test case with port specified
                'test-link',
                'http://127.0.0.1:8000/test-link'
            ],
            [
                'https://example.com/',
                'javascript:void(0);',
                'javascript:void(0);'
            ],
            [
                'http://localhost/test/../data/index.html',
                'http://facebook.com',
                'http://facebook.com',                
            ],
            [
                'http://toastytech.com/evil/',
                '../links/index.html',
                'http://toastytech.com/links/index.html',
            ],
            [
                'http://toastytech.com/evil/evil2/',
                '../../links/index.html',
                'http://toastytech.com/links/index.html',
            ],
        ];
    }
        
    /**
     * @dataProvider crawlableLinksProvider
     * @param string $url
     * @param bool $expected
     */
    public function testCheckIfCrawlable($url, $expected)
    {
        $parentLink = new Link('http://example');
        $link = new Link($url,$parentLink);
        $this->assertEquals($expected, $link->isCrawlable());
    }
        
    /**
    * @dataProvider ExternalLinksProvider
    * @param Link $url
    * @param bool $expected
    */
    public function testCheckIfExternal($url, $expected)
    {
        $parentLink = new Link('http://mysite.com');    
        $link = new Link($url, $parentLink);
        $this->assertEquals($expected, $link->isExternal());
    }
        
    /**
    * data provider for checkIfCrawlable method
    * @return array
    */
    public function crawlableLinksProvider()
    {
        return [
        ['javascript:void(0)', false],
        ['', false],
        ['#title', false],
        ['tel:565645654', false],
        ['mailto: zaid@wewebit.com', false],
        ['/sample-doc.pdf', false],
        ['skype:+44020444444?call', false],    
        ['/test.html', true],
        ];
    }
    
    /**
     * data provider for checkIfExternal method
     * @return array
     */
    public function ExternalLinksProvider()
    {
        return [
        ['http://mysite.com/my-url', false],
        ['/my-url2', false],        
        ['http://other-site.com/other-url', true],
        ['http://sub.mysite.com/other-url', true],
        ['/sample-doc.pdf', false],
        ['/test.html', false],
        ];
    }

        /**
         * @dataProvider getPathFromUrlProvider
         * @param string $baseUrl
         * @param string $uri
         * @param string $expected
         */
    public function testGetPathFromUrl($baseUrl, $uri, $expected)
    {
        $parentLink = new Link($baseUrl);
        $link = new Link($uri, $parentLink);
        $actual = $link->getComputedPath();
        $this->assertEquals($expected, $actual, 'error on base url '.$baseUrl);
    }

        /**
         * data provider for getPathFromUrl method
         * @return array
         */
    public function getPathFromUrlProvider()
    {       
        return [            
        ['http://example.com', '/ar/testing', '/ar/testing'],
        ['http://example.com/ar/', '/ar/testing', '/ar/testing'],
        ['http://example.com/ar/', 'testing', '/ar/testing'],
        ['http://example.com', 'testing', '/testing'],
        ['http://example.com', '/evil/../index.html', '/index.html'],
        ['http://example.com', '/evil/evil2/../index.html', '/evil/index.html'],
        ['http://example.com', '/evil/evil2/../../index.html', '/index.html'],
        ['http://example.com/', 'http://example.com/testing', '/testing'],
        ['http://example.com/', 'mailto: zrashwani@gmail.com', 'mailto: zrashwani@gmail.com'],
        ['http://example.com', 'https://www.pinterest.com/OrbexFX/', 'https://www.pinterest.com/OrbexFX/'],
        ['http://example.com/index.html', 'index2.html', '/index2.html'],        
        ['http://localhost:9001/main/../data/index.html', '/index2.html', '/index2.html'],
        ['http://localhost:9001/main/../data/index.html', 'index3.html', '/data/index3.html'],
        ['http://localhost:9001/main/../data/index.html', 'sub', '/data/sub'],
        ];
    }
      
    /**
     * data provide for checking status code if crawlable
     * @return array data for check crawlable status code
     */
    public function crawlableStatusCodeProvider(){
       return [
           [301, true],
           [302, true],
           [200, true],
           [201, true],
           [404, false],
           [403, false],
           [500, false],
           [503, false],
       ];
    }
    
    /**
     * @dataProvider crawlableStatusCodeProvider
     * @param int $statusCode
     * @param bool $expected
     */
    public function testCrawlableStatusCode($statusCode, $expected){
        $link = new Link('http://example.com');
        $link->setStatusCode($statusCode);
        
        $this->assertEquals($link->checkCrawlableStatusCode(), $expected);
    }    
}