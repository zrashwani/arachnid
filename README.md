# Arachnid Web Crawler

This library will crawl all unique internal links found on a given website
up to a specified maximum page depth.

This library is based on the original blog post by Zeid Rashwani here:

<http://zrashwani.com/simple-web-spider-php-goutte>

Josh Lockhart adapted the original blog post's code (with permission)
for Composer and Packagist and updated the syntax to conform with
the PSR-2 coding standard.

## How to Install

You can install this library with [Composer][composer]. Drop this into your `composer.json`
manifest file:

    {
        "require": {
            "codeguy/arachnid": "1.*"
        }
    }

Then run `composer install`.

## Getting Started

Here's a quick demo to crawl a website:

    <?php
    require 'vendor/autoload.php';

    // Initiate crawl
    $crawler = new \Arachnid\Crawler('http://www.example.com', 3);
    $crawler->traverse();

    // Get link data
    $links = $crawler->getLinks();
    print_r($links);

## How to Contribute

1. Fork this repository
2. Create a new branch for each feature or improvement
3. Send a pull request from each feature branch

It is very important to separate new features or improvements into separate feature branches,
and to send a pull request for each branch. This allows me to review and pull in new features
or improvements individually.

All pull requests must adhere to the [PSR-2 standard][psr2].

## System Requirements

* PHP 5.3.0+

## Authors

* Josh Lockhart <https://github.com/codeguy>
* Zeid Rashwani <http://zrashwani.com>

## License

MIT Public License

[composer]: http://getcomposer.org/
[psr2]: https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-2-coding-style-guide.md
