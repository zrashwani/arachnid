<?php

namespace Arachnid\Adapters;

use Arachnid\Adapters\CrawlingAdapterInterface;

class CrawlingFactory
{
    const TYPE_HEADLESS_BROWSER = 1;
    const TYPE_HTTP_CLIENT = 2;
    /**
     * @deprecated since version 2.1.0
     */
    const TYPE_GOUTTE = 2;
    
    public static function create(string $type, array $options = []): CrawlingAdapterInterface
    {
        $client = null;
        switch ($type) {
            case self::TYPE_HEADLESS_BROWSER:
                $client = new PantherChromeAdapter($options);
                break;
            
            case self::TYPE_GOUTTE:
            case self::TYPE_HTTP_CLIENT:    
                $client = new GoutteAdapter($options);
                break;
            
            default:
                throw new \RuntimeException("unrecognized crawling type $type");
        }
        
        return $client;
    }
}
