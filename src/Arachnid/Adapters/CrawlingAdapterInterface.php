<?php

namespace Arachnid\Adapters;
use \Symfony\Component\DomCrawler\Crawler;

interface CrawlingAdapterInterface{
    
    public function getClient();
    
    public function requestPage($url):Crawler;
       
   
}

