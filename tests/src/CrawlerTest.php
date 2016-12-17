<?php

namespace Arachnid;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class CrawlerTest extends \PHPUnit_Framework_TestCase
{	

        /**
         * test crawling remote file, ex. google.com
         */
	public function testRemoteFile(){
		$url = 'https://www.google.com/';
		$crawler = new Crawler($url,1); 
		$crawler->traverse();
		$links = $crawler->getLinks();		
		$this->assertEquals(get_class($crawler->getScrapClient()), \Goutte\Client::class );

		$this->assertEquals($links['https://www.google.com/']['status_code'],200, $url.' shall be 200 ok');
		$this->assertGreaterThan(3,count($links));
	}
        
        /**
         * test scrapping custom scrapper client
         */
        public function testSetScrapClient(){
            $crawler = new Crawler('index', 1);
            $crawler->setScrapClient(new Clients\FilesystemClient());
            
            $this->assertEquals(get_class($crawler->getScrapClient()), Clients\FilesystemClient::class);
        }

        /**
         * test crawling non-existent page
         */
	public function testFileNotExist(){
		$crawler = new Crawler('test',1, true); //non existing client		

		$crawler->traverse();		
		$ret = $crawler->getLinks();
                
		$this->assertArrayHasKey('test',$ret);
		$this->assertEquals($ret['test']['status_code'],404);
	}

        /**
         * test crawling one level pages
         */
	public function testScrapperClient1Level(){
		$filePath = __DIR__.'/../data/index';
		$crawler = new Crawler($filePath,1, true); //non existing client		
		$crawler->traverse();
		$links = $crawler->getLinks();
		
		$this->assertEquals(get_class($crawler->getScrapClient()), Clients\FilesystemClient::class );
		$this->assertEquals($links[$filePath]['status_code'],200, $filePath.' shall be 200 ok');
                
		$this->assertEquals(8,count($links));
	}	

        /**
         * test crawling two levels pages
         */
	public function testScrapperClient2Level(){
		$filePath = __DIR__.'/../data/index';
		$crawler = new Crawler($filePath,2,true); //non existing client		
		$crawler->traverse();

		$links = $crawler->getLinks();		

		$this->assertEquals($links[$filePath]['status_code'],200);
		$this->assertCount(8,$links); //TODO: fix and uncomment
	}	

	/**
	 * test get absolute url
	 * @dataProvider urlAbsoluteProvider
	 */
	public function testGetAbsoluteUrl($baseUrl,$nodeUrl, $expectedUrl){
                $method = new \ReflectionMethod(
                    '\Arachnid\Crawler', 'getAbsoluteUrl'
                );            
                $method->setAccessible(true);
                
		$crawler = new Crawler($baseUrl,1); 
                $retUrl = $method->invoke($crawler, $nodeUrl);
		$this->assertEquals($retUrl,$expectedUrl);
	}

        /**
         * provider data for absolute url method
         * @return array
         */
	public function urlAbsoluteProvider(){
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
		];
	}
        
        /**
         * @dataProvider crawlableLinksProvider
         * @param string $url
         * @param bool $expected
         */
        public function testCheckIfCrawlable($url, $expected){
            $method = new \ReflectionMethod(Crawler::class, 'checkIfCrawlable');
            $method->setAccessible(true);
            
            $ret = $method->invoke(new Crawler('http://example'), $url);
            $this->assertEquals($expected, $ret);
        }
        
        /**
         * data provider for checkIfCrawlable method
         * @return array
         */
        public function crawlableLinksProvider(){
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
        public function testGetPathFromUrl($baseUrl, $uri, $expected, $localFile){
            $method = new \ReflectionMethod(Crawler::class, 'getPathFromUrl');
            $method->setAccessible(true);
            
            $actual = $method->invoke(new Crawler($baseUrl,2,$localFile), $uri);            
            $this->assertEquals($expected, $actual);
        }

        /**
         * data provider for getPathFromUrl method
         * @return array
         */        
        public function getPathFromUrlProvider(){
            return [
              ['http://example.com', '/ar/testing', '/ar/testing', false],  
              ['http://example.com/ar/', '/ar/testing', '/ar/testing', false],  
              ['http://example.com/ar/', 'testing', '/ar/testing', false],  
              ['http://example.com', 'testing', '/testing', false],  
              ['http://example.com/', 'http://example.com/testing', '/testing', false],                
              [__DIR__.'/../data/index', '/index', __DIR__.'/../data/index', true],  
              [__DIR__.'/../data/index', '/index2', __DIR__.'/../data/index2', true],  
              [__DIR__.'/../data/index', 'sub', __DIR__.'/../data/index/sub', true],    
            ];
        }
 
        /**
         * test filtering links callback
         */
        public function testfilterCallback(){
            $logger = new Logger('name');
            $logger->pushHandler(new StreamHandler(sys_get_temp_dir().'/crawler.log'));
            
            $client = new Crawler('https://www.orbex.com/ar/',2);
            
            $client->setLogger($logger);
            $client->filterLinks(function($link){
                return preg_match('@.*/ar/.*$@i',$link);
            });
            $client->traverse();
            $links = $client->getLinks();
            
            foreach(array_keys($links) as $link){
                $this->assertRegExp('@.*/ar/.*$@i', $link);
            }
        }
}