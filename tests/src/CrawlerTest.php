<?php

namespace Arachnid;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class CrawlerTest extends \PHPUnit_Framework_TestCase
{
   

        /**
         * test crawling remote file, ex. google.com
         */
    public function testRemoteFile()
    {
        $url = 'https://www.google.com/';
        $crawler = new Crawler($url, 1);
        $crawler->traverse();
        $links = $crawler->getLinks();
        $this->assertEquals(get_class($crawler->getScrapClient()), \Goutte\Client::class);

        $this->assertEquals($links['https://www.google.com/']['status_code'], 200, $url.' shall be 200 ok');
        $this->assertGreaterThan(3, count($links));
    }
        
        /**
         * test scrapping custom scrapper client
         */
    public function testSetScrapClient()
    {
        $crawler = new Crawler('index', 1);
        $crawler->setScrapClient(new Clients\FilesystemClient());
            
        $this->assertEquals(get_class($crawler->getScrapClient()), Clients\FilesystemClient::class);
    }

        /**
         * test crawling non-existent page
         */
    public function testFileNotExist()
    {
        $crawler = new Crawler('test', 1, ['localFile'=>true]); //non existing url/path

        $crawler->traverse();
        $ret = $crawler->getLinks();
                
        $this->assertArrayHasKey('test', $ret);
        $this->assertEquals($ret['test']['status_code'], 404);
    }

        /**
         * test crawling one level pages
         */
    public function testScrapperClient1Level()
    {
        $filePath = __DIR__.'/../data/index.html';
        $crawler = new Crawler($filePath, 1, ['localFile'=>true]);
        $crawler->traverse();
        $links = $crawler->getLinks();
        
        $this->assertEquals(get_class($crawler->getScrapClient()), Clients\FilesystemClient::class);
        $this->assertEquals($links[$filePath]['status_code'], 200, $filePath.' shall be 200 ok');
                
        $this->assertEquals(8, count($links));
    }

        /**
         * test crawling two levels pages
         */
    public function testScrapperClient2Level()
    {
        $filePath = __DIR__.'/../data/index.html';
        $crawler = new Crawler($filePath, 1, ['localFile'=>true]);
        $crawler->traverse();

        $links = $crawler->getLinks();

        $this->assertEquals($links[$filePath]['status_code'], 200);
        $this->assertCount(8, $links);
    }

    /**
     * test crawling three levels pages
     */
    public function testScrapperClient3Level()
    {
        $filePath = __DIR__.'/../data/index.html';
        $crawler = new Crawler($filePath, 5, ['localFile'=>true]);
                
        $logger = new Logger('crawling logger');
        $logger->pushHandler(new StreamHandler(sys_get_temp_dir().'/crawler.log'));
        $crawler->setLogger($logger);
        $crawler->traverse();

        $links = $crawler->getLinks();

        $this->assertEquals($links[$filePath]['status_code'], 200);
        $this->greaterThan(8, count($links));
        
        $this->assertArrayHasKey('http://facebook.com', $links);
        $this->assertArrayHasKey(__DIR__.'/../data/sub_dir/level2-3.html', $links);
        
        $this->assertEquals(200, $links[__DIR__.'/../data/sub_dir/level2-3.html']['status_code']);
        $this->assertEquals(200, $links[__DIR__.'/../data/sub_dir/level1-3.html']['status_code']);
        $this->assertEquals(200, $links[__DIR__.'/../data/sub_dir/level1-4.html']['status_code']);
        $this->assertEquals(200, $links[__DIR__.'/../data/sub_dir/level1-5.html']['status_code']);
    }

        /**
         * test broken links
         */
    public function testBrokenLink()
    {
        $filePath = __DIR__.'/../data/sub_dir/level1-3.html2';
        $crawler = new Crawler($filePath, 2, ['localFile'=>true]);
        $crawler->traverse();

        $links = $crawler->getLinks();
                
        $this->assertEquals($links[$filePath]['status_code'], 404);
        
        $collection = new LinksCollection($links);
        $this->assertEquals($collection->getBrokenLinks()->count(),1);
    }

    /**
     * test get absolute url
     * @dataProvider urlAbsoluteProvider
     */
    public function testGetAbsoluteUrl($baseUrl, $nodeUrl, $expectedUrl, $localFile = false)
    {
        $method = new \ReflectionMethod(
            \Arachnid\Crawler::class,
            'getAbsoluteUrl'
        );
        $method->setAccessible(true);
                
        $crawler = new Crawler($baseUrl, 1, ['localFile'=>$localFile]);
                $retUrl = $method->invoke($crawler, $nodeUrl);
        $this->assertEquals($retUrl, $expectedUrl);
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
                'https://example.com#title'
            ],
            [
                'https://example.com/',
                'test',
                'https://example.com/test'
            ],
            [
                'https://example.com/',
                'javascript:void(0);',
                'javascript:void(0);'
            ],
            [
                __DIR__.'/../data/index.html',
                'http://facebook.com',
                'http://facebook.com',
                                 true //local file
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
        $method = new \ReflectionMethod(Crawler::class, 'checkIfCrawlable');
        $method->setAccessible(true);
            
        $ret = $method->invoke(new Crawler('http://example'), $url);
        $this->assertEquals($expected, $ret);
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
        ['/test.html', true],
        ];
    }

        /**
         * @dataProvider getPathFromUrlProvider
         * @param string $baseUrl
         * @param string $uri
         * @param string $expected
         * @param boolean $localFile
         */
    public function testGetPathFromUrl($baseUrl, $uri, $expected, $localFile)
    {
        $method = new \ReflectionMethod(Crawler::class, 'getPathFromUrl');
        $method->setAccessible(true);
            
        $actual = $method->invoke(new Crawler($baseUrl, 2, ['localFile'=>$localFile]), $uri);
        $this->assertEquals($expected, $actual, 'error on base url '.$baseUrl);
    }

        /**
         * data provider for getPathFromUrl method
         * @return array
         */
    public function getPathFromUrlProvider()
    {
        return [
        ['http://example.com', '/ar/testing', '/ar/testing', false],
        ['http://example.com/ar/', '/ar/testing', '/ar/testing', false],
        ['http://example.com/ar/', 'testing', '/ar/testing', false],
        ['http://example.com', 'testing', '/testing', false],
        ['http://example.com/', 'http://example.com/testing', '/testing', false],
        ['http://example.com/', 'mailto: zrashwani@gmail.com', 'mailto: zrashwani@gmail.com', false],
        ['http://example.com', 'https://www.pinterest.com/OrbexFX/', 'https://www.pinterest.com/OrbexFX/', false],
        [__DIR__.'/../data/index.html', '/index.html', __DIR__.'/../data/index.html', true],
        [__DIR__.'/../data/index.html', '/index2.html', __DIR__.'/../data/index2.html', true],
        [__DIR__.'/../data/index.html', 'sub', __DIR__.'/../data/sub', true],
        ];
    }
 
        /**
         * test filtering links callback
         */
    public function testfilterCallback()
    {
        $logger = new Logger('crawler logger');
        $logger->pushHandler(new StreamHandler(sys_get_temp_dir().'/crawler.log'));
            
        $client = new Crawler('https://github.com/blog/', 2);
            
        $links = $client->setLogger($logger)
                    ->filterLinks(function ($link) {
                        return (bool)preg_match('/.*\/blog.*$/u', $link); //crawling only blog links
                    })
                    ->traverse()
                    ->getLinks();
            
        foreach ($links as $uri => $link_info) {
            $this->assertRegExp('/.*\/blog.*$/u', isset($link_info['absolute_url'])?
                    $link_info['absolute_url']:$uri);
        }
        
        $testHandler = new \Monolog\Handler\TestHandler();
        $logger2 = new Logger('crawler logger');
        $logger2->pushHandler($testHandler);
        
        $filePath = __DIR__.'/../data/sub_dir/index.html';
        $crawler2 = new Crawler($filePath, 2, ['localFile'=>true]);
        $crawler2
                ->setLogger($logger2)
                ->filterLinks(function($link){
                    return strpos($link,'level1-2.html')!==false;
                })
                ->traverse();
        $testHandler->hasRecordThatMatches(
                '/.*(skipping\s"level1-2.html).*/',
                200
        );
        
    }
        
        /**
         * test filtering links callback 2
         */
    public function testfilterCallback2()
    {
        $logger = new Logger('crawler logger');
        $logger->pushHandler(new StreamHandler(sys_get_temp_dir().'/crawler.log'));
            
        $client = new Crawler(__DIR__.'/../data/filter1.html', 4);
            
        $client->setLogger($logger)
           ->filterLinks(function ($link) {
                 return preg_match('/.*(dont\-visit).*/u', $link)===0; // prevent any link containing dont-visit in url
           })->traverse();
        $links = $client->getLinks();
            
        foreach ($links as $uri => $link_info) {
            $this->assertNotRegExp('/.*dont\-visit.*/U', isset($link_info['absolute_url'])?
                    $link_info['absolute_url']:$uri);
        }
    }
        
        /**
         * test crawling one level pages
         */
    public function testMetaInfo()
    {
        $filePath = __DIR__.'/../data/index.html';
        $crawler = new Crawler($filePath, 1, ['localFile'=>true]);
        $crawler->traverse();
        $links = $crawler->getLinks();
                
        $this->assertEquals($links[$filePath]['status_code'], 200, $filePath.' shall be 200 ok');
        $this->assertEquals($links[$filePath]['title'], 'Main Page');
        $this->assertEquals($links[$filePath]['meta_description'], 'meta description for main page');
        $this->assertEquals($links[$filePath]['meta_keywords'], 'keywords1, keywords2');
    }
    
    /**
     * testing depth functionality
     */
    public function testFilterByDepth()
    {
        $filePath = __DIR__.'/../data/index.html';
        $crawler = new Crawler($filePath, 3, ['localFile'=>true]);
        $crawler->traverse();
        $links = $crawler->getLinks();
        
        $collection = new LinksCollection($links);
        $depth1Links = $collection->filterByDepth(1);
        $depth2Links = $collection->filterByDepth(2);
        
        $this->assertEquals(7, $depth1Links->count());
        //ignoring already traversed links in previous levels
        $this->assertEquals(6, $depth2Links->count()); 
    }
        
        /**
         * test setting guzzle options
         */
    public function testConfigureGuzzleOptions()
    {
            
        $options = array(
        'curl' => array(
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        ),
        'timeout' => 30,
        'connect_timeout' => 30,
        );
            
        $crawler = new Crawler('http://github.com', 2);
        $crawler->setCrawlerOptions($options);
            
        /*@var $guzzleClient \GuzzleHttp\Client */
        $guzzleClient = $crawler->getScrapClient()->getClient();
            
        $this->assertEquals(30, $guzzleClient->getConfig('timeout'));
        $this->assertEquals(30, $guzzleClient->getConfig('connect_timeout'));
        $this->assertEquals(false, $guzzleClient->getConfig('curl')[CURLOPT_SSL_VERIFYHOST]);
            
        $crawler2 = new Crawler('http://github.com', 2, array(
        'auth' => array('username', 'password'),
        ));
        /*@var $guzzleClient \GuzzleHttp\Client */
        $guzzleClient2 = $crawler2->getScrapClient()->getClient();
            
        $actualConfigs = $guzzleClient2->getConfig();
        $this->assertArrayHasKey('auth', $actualConfigs);
    }
        
    public function testNotVisitUnCrawlableUrl()
    {

        $testHandler = new \Monolog\Handler\TestHandler();
        $logger = new Logger('test logger');
        $logger->pushHandler($testHandler);
                
        $filePath = __DIR__.'/../data/index.html';
        $crawler = new Crawler($filePath, 2, ['localFile'=>true]);
        $crawler->setLogger($logger);
        $crawler->traverse();
                
                
        $this->assertFalse(
            $testHandler->hasRecordThatMatches(
                '/.*(crawling\stel\:).*/',
                200
            )
        );       
        $this->assertEquals(
            1,
            $testHandler->hasRecordThatMatches(
                '/.*(skipping\s"tel\:).*/',
                200
            )
        );
    }
}
