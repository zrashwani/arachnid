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
     * @return LinksCollection
     */
    public function getBrokenLinks(){
        return $this->filter(function($link){
            return isset($link['status_code']) && $link['status_code'] !== 200;
        })->map(function($link){
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
        $final_items = [];
        $this->each(function($link_info, $uri) use(&$final_items){
            $final_items[$link_info['source_link']][$uri] = [
                 'link'  => isset($link_info['absolute_url'])?$link_info['absolute_url']:null,
                 'status_code' => isset($link_info['status_code'])?$link_info['status_code']:null,                
            ];          
        });
                
        return $this->make($final_items);
    }
}
