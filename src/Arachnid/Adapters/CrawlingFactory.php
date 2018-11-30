<?php

namespace Arachnid\Adapters;
use Arachnid\Adapters\CrawlingAdapterInterface;

class CrawlingFactory{
    
    CONST TYPE_HEADLESS_BROWSER = 1;
    CONST TYPE_GOUTTE = 2;
    
    public static function create(string $type, array $options = []): CrawlingAdapterInterface{
        
        $client = null;
        switch($type){
            case self::TYPE_HEADLESS_BROWSER:
                $client = new PantherChromeAdapter($options);
                break;
            
            case self::TYPE_GOUTTE:
                $client = new GoutteAdapter($options);
                break;
            
            default:
                throw new \RuntimeException("unrecognized crawling type $type");
        }
        
        return $client;
    }
}
