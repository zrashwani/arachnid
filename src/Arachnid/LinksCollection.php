<?php

namespace Arachnid;

use Illuminate\Support\Collection;

/**
 * LinksCollection
 * class to get simple statistics about links
 */
class LinksCollection extends Collection{
    
    public function __construct($items = array()) {
        parent::__construct($items);
    }
    
    /**
     * getting links in specific depth
     * @param int $depth
     * @return LinksCollection
     */
    public function getByDepth($depth){
        return $this->filter(function($link) use($depth){
            return isset($link['depth']) && $link['depth'] == $depth;
        })->map(function($link){
            return [
                'source_page' => $link['source_link'],
                'link' => isset($link['absolute_url'])?$link['absolute_url']:null,
            ];
        });
    }
    
    /**
     * getting broken links
     * @return LinksCollection
     */
    public function getBrokenLinks(){
        return $this->filter(function($link){
            return $link['status_code'] !== 200;
        })->map(function($link){
            return [
                'source_page' => $link['source_link'],
                'link' => $link['absolute_url'],
                'status_code' => $link['status_code'],
            ];
        });
    }
}
