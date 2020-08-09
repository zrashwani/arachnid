<?php

namespace Arachnid;

use Tightenco\Collect\Support\Collection;
use Arachnid\Link;

/**
 * LinksCollection
 * class to get simple statistics about links
 */
class LinksCollection extends Collection
{
    public function __construct($items = array())
    {
        parent::__construct($items);
    }
    
    /**
     * getting links in specific depth
     * @param int $depth
     * @return LinksCollection
     */
    public function filterByDepth($depth)
    {
        return $this->filter(function (Link $link) use ($depth) {
            return $link->getCrawlDepth() == $depth;
        })->map(function (Link $link) {
            return [
                'source_page' => $link->getParentUrl(),
                'link' => $link->getAbsoluteUrl(),
            ];
        });
    }
    
    /**
     * getting broken links
     * @param bool $showSummaryInfo if set to true, it will return only status_code and source page
     * @return LinksCollection
     */
    public function getBrokenLinks($showSummaryInfo = false)
    {
        $brokenLinks = $this->filter(function (Link $link) {
            return !$link->shouldNotVisit() &&  //exclude links that are not visited
                    $link->checkCrawlableStatusCode()===false;
        });
        
        return $showSummaryInfo===false? //retrieve summary or details of links
                $brokenLinks:
                $brokenLinks->map(function (Link $link) {
                    return [
                'source_page' => $link->getParentUrl(),
                'link' => $link->getAbsoluteUrl(),
                'status_code' => $link->getStatusCode(),
            ];
                });
    }
    
    /**
     * getting links grouped by depth of first traversing
     * @return LinksCollection
     */
    public function groupLinksByDepth()
    {
        $final_items = [];
        $this->each(function (Link $linkObj, $uri) use (&$final_items) {
            $final_items[$linkObj->getCrawlDepth()][$uri] = [
               'link'  => $linkObj->getAbsoluteUrl(),
               'source_page' => $linkObj->getParentUrl(),
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
    public function groupLinksGroupedBySource()
    {
        return $this->map(function (Link $linkObj) {
            return
                            ['link' => $linkObj->getAbsoluteUrl(),
                             'source_link' => $linkObj->getParentUrl()];
        })
                    ->unique('link')
                    ->groupBy('source_link');
    }
    
    /**
     * getting external links found in collection
     * @return LinksCollection
     */
    public function getExternalLinks()
    {
        return $this->filter(function (Link $linkObj) {
            return $linkObj->isExternal();
        });
    }
}
