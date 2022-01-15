# Arachnid Web Crawler

This library will crawl all unique internal links found on a given website
up to a specified maximum page depth.

This library is using [_symfony/panther_](https://github.com/symfony/panther) & [FriendsOfPHP/Goutte](https://github.com/FriendsOfPHP/Goutte) libraries to scrap site pages and extract main SEO-related info, including: 
`title`, `h1 elements`, `h2 elements`, `statusCode`, `contentType`, `meta description`, `meta keyword` and `canonicalLink`.


This library is based on the original blog post by Zeid Rashwani here:

<http://zrashwani.com/simple-web-spider-php-goutte>

Josh Lockhart adapted the original blog post's code (with permission)
for Composer and Packagist and updated the syntax to conform with
the PSR-2 coding standard.

[![Build Status](https://travis-ci.com/zrashwani/arachnid.svg?branch=master)](https://travis-ci.com/zrashwani/arachnid)
[![codecov](https://codecov.io/gh/zrashwani/arachnid/branch/master/graph/badge.svg)](https://codecov.io/gh/zrashwani/arachnid)

## How to Install

You can install this library with [Composer][composer]. Drop this into your `composer.json`
manifest file:

    {
        "require": {
            "zrashwani/arachnid": "dev-master"
        }
    }

Then run `composer install`.

## Getting Started

### Basic Usage:
Here's a quick demo to crawl a website:
```php
    <?php
    require 'vendor/autoload.php';

    $url = 'http://www.example.com';
    $linkDepth = 3;
    // Initiate crawl, by default it will use http client (GoutteClient), 
    $crawler = new \Arachnid\Crawler($url, $linkDepth);
    $crawler->traverse();

    // Get link data
    $links = $crawler->getLinksArray(); //to get links as objects use getLinks() method
    print_r($links);
```

### Enabling Headless Browser mode:

Headless browser mode can be enabled, so it will use Chrome engine in background which is useful to get contents of javacript-based sites.

`enableHeadlessBrowserMode` method set the scraping adapter used to be `PantherChromeAdapter` which is based on [Symfony Panther](https://github.com/symfony/panther) library: 
```php
    $crawler = new \Arachnid\Crawler($url, $linkDepth);
    $crawler->enableHeadlessBrowserMode()
            ->traverse()
            ->getLinksArray();
```

In order to use this, you need to have [chrome-driver](https://sites.google.com/a/chromium.org/chromedriver/) installed on your machine, you can use `dbrekelmans/browser-driver-installer` to install chromedriver locally: 
```
composer require --dev dbrekelmans/bdi
./vendor/bin/bdi driver:chromedriver drivers
```
    
## Advanced Usage:

   Set additional options to underlying http client, by specifying array of options in constructor 
or creating Http client scrapper with desired options:

```php
    <?php
        use \Arachnid\Adapters\CrawlingFactory;
        //third parameter is the options used to configure http client
        $clientOptions = ['auth_basic' => array('username', 'password')];
        $crawler = new \Arachnid\Crawler('http://github.com', 2, $clientOptions);
           
        //or by creating and setting scrap client
        $options = array(
            'verify_host' => false,
            'verify_peer' => false,
            'timeout' => 30,
        );
                        
        $scrapperClient = CrawlingFactory::create(CrawlingFactory::TYPE_HTTP_CLIENT, $options);
        $crawler->setScrapClient($scrapperClient);
```

   You can inject a [PSR-3][psr3] compliant logger object to monitor crawler activity (like [Monolog][monolog]):
```php
    <?php    
    $crawler = new \Arachnid\Crawler($url, $linkDepth); // ... initialize crawler   

    //set logger for crawler activity (compatible with PSR-3)
    $logger = new \Monolog\Logger('crawler logger');
    $logger->pushHandler(new \Monolog\Handler\StreamHandler(sys_get_temp_dir().'/crawler.log'));
    $crawler->setLogger($logger);
    ?>
```

   You can set crawler to visit only pages with specific criteria by specifying callback closure using `filterLinks` method:

```php
    <?php
    //filter links according to specific callback as closure
    $links = $crawler->filterLinks(function($link) {
                        //crawling only links with /blog/ prefix
                        return (bool)preg_match('/.*\/blog.*$/u', $link); 
                    })
                    ->traverse()
                    ->getLinks();

```    
    
   You can use `LinksCollection` class to get simple statistics about the links, as following:
```php
    <?php
    $links = $crawler->traverse()
                     ->getLinks();
    $collection = new LinksCollection($links);

    //getting broken links
    $brokenLinks = $collection->getBrokenLinks();
   
    //getting links for specific depth
    $depth2Links = $collection->getByDepth(2);

    //getting external links inside site
    $externalLinks = $collection->getExternalLinks();
```

## How to Contribute

1. Fork this repository
2. Create a new branch for each feature or improvement
3. Apply your code changes along with corresponding unit test
4. Send a pull request from each feature branch

It is very important to separate new features or improvements into separate feature branches,
and to send a pull request for each branch. This allows me to review and pull in new features
or improvements individually.

All pull requests must adhere to the [PSR-2 standard][psr2].

## System Requirements

* PHP 7.2.0+

## Authors

* Josh Lockhart <https://github.com/codeguy>
* Zeid Rashwani <http://zrashwani.com>

## License

MIT Public License

[composer]: http://getcomposer.org/
[psr2]: https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-2-coding-style-guide.md
[psr3]: https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-3-logger-interface.md
[monolog]: https://github.com/Seldaek/monolog

