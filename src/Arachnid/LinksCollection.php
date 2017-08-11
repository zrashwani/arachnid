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
    public function filterByDepth($depth){
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
     * @param bool $showSummaryInfo if set to true, it will return only status_code and source page
     * @return LinksCollection
     */
    public function getBrokenLinks($showSummaryInfo = false){
        $brokenLinks = $this->filter(function($link){
            return isset($link['status_code']) && $link['status_code'] !== 200;
        });
        
        return $showSummaryInfo===false? //retrieve summary or details of links
                $brokenLinks:
                $brokenLinks->map(function($link){
            return [
                'source_page' => $link['source_link'],
                'link' => $link['absolute_url'],
                'status_code' => $link['status_code'],
            ];
        });
        
    }
    
    /**
     * getting links grouped by depth of first traversing
     * @return LinksCollection
     */
    public function groupLinksByDepth(){
        $final_items = [];
        $this->each(function($link_info, $uri) use(&$final_items){
            $final_items[$link_info['depth']][$uri] = [
               'link'  => isset($link_info['absolute_url'])?$link_info['absolute_url']:null,
               'source_page' => $link_info['source_link'],
            ];          
        });
        
        ksort($final_items);
        return $this->make($final_items);
    }
    
    /**
     * getting links organized by source page as following:
     * [ "source_page1" => [children links], "source_page2" => [children_links], ...]
     * @return LinksCollection
     */
    public function groupLinksGroupedBySource(){
        return $this->map(function($link_info){
                        return 
                            ['link' => isset($link_info['absolute_url'])?$link_info['absolute_url']:null,
                             'source_link' => $link_info['source_link']];                                
                    })
                    ->unique('link')
                    ->groupBy('source_link');        
    }
    
    /**
     * getting external links found in collection
     * @return LinksCollection
     */
    public function getExternalLinks(){
        return $this->filter(function($link_info){
            return isset($link_info['external_link'])===true && $link_info['external_link']===true;
        });
    }
}
