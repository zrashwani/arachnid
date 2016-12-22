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
		$crawler = new Crawler('test',1, true); //non existing url/path

		$crawler->traverse();		
		$ret = $crawler->getLinks();
                
		$this->assertArrayHasKey('test',$ret);
		$this->assertEquals($ret['test']['status_code'],404);
	}

        /**
         * test crawling one level pages
         */
	public function testScrapperClient1Level(){
		$filePath = __DIR__.'/../data/index.html';
		$crawler = new Crawler($filePath,1, true);
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
		$filePath = __DIR__.'/../data/index.html';
		$crawler = new Crawler($filePath,2,true); 
		$crawler->traverse();

		$links = $crawler->getLinks();		

		$this->assertEquals($links[$filePath]['status_code'],200);
		$this->assertCount(8,$links); 
	}	

        /**
         * test crawling three levels pages
         */
	public function testScrapperClient3Level(){
		$filePath = __DIR__.'/../data/index.html';
		$crawler = new Crawler($filePath,5,true); 
		$crawler->traverse();

		$links = $crawler->getLinks();		

		$this->assertEquals($links[$filePath]['status_code'],200);
		$this->greaterThan(8,count($links)); 
                $this->assertArrayHasKey('http://facebook.com', $links);
                $this->assertArrayHasKey(__DIR__.'/../data/sub_dir/level2-3.html', $links);
                $this->assertEquals($links[__DIR__.'/../data/sub_dir/level2-3.html']['status_code'], 200);

	}	

        /**
         * test broken links
         */
	public function testBrokenLink(){
		$filePath = __DIR__.'/../data/sub_dir/level1-3.html2';
		$crawler = new Crawler($filePath,2,true); 
		$crawler->traverse();

		$links = $crawler->getLinks();		
                
		$this->assertEquals($links[$filePath]['status_code'],404);		
	}	

	/**
	 * test get absolute url
	 * @dataProvider urlAbsoluteProvider
	 */
	public function testGetAbsoluteUrl($baseUrl,$nodeUrl, $expectedUrl, $localFile = false){
                $method = new \ReflectionMethod(
                    \Arachnid\Crawler::class, 'getAbsoluteUrl'
                );            
                $method->setAccessible(true);
                
		$crawler = new Crawler($baseUrl,1,$localFile); 
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
				__DIR__.'/../data/index',
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
            $this->assertEquals($expected, $actual, 'error on base url '.$baseUrl);
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
        public function testfilterCallback(){
            $logger = new Logger('name');
            $logger->pushHandler(new StreamHandler(sys_get_temp_dir().'/crawler.log'));
            
            $client = new Crawler('https://github.com/blog/',2);
            
            $client->setLogger($logger);
            $client->filterLinks(function($link){
                return (bool)preg_match('/.*\/blog.*$/u',$link); //crawling only blog links
            });
            $client->traverse();
            $links = $client->getLinks();
            
            foreach($links as $uri => $link_info){                
                $this->assertRegExp('/.*\/blog.*$/u', isset($link_info['absolute_url'])?
                        $link_info['absolute_url']:$uri);
            }
        }
        
        /**
         * test crawling one level pages
         */
	public function testMetaInfo(){
		$filePath = __DIR__.'/../data/index.html';
		$crawler = new Crawler($filePath,1, true); //non existing client		
		$crawler->traverse();
		$links = $crawler->getLinks();
				
		$this->assertEquals($links[$filePath]['status_code'],200, $filePath.' shall be 200 ok');                		
		$this->assertEquals($links[$filePath]['title'],'Main Page');                		
		$this->assertEquals($links[$filePath]['meta_description'],'meta description for main page');                		
		$this->assertEquals($links[$filePath]['meta_keywords'],'keywords1, keywords2');                				
	}	
        
}
