<?php

namespace Arachnid;
use GuzzleHttp\Psr7\Uri as GuzzleUri;
use GuzzleHttp\Psr7\UriResolver;


/**
 * Link object for url to crawl
 *
 */
class Link extends GuzzleUri
{    
    /**
     * original url
     * @var string
     */
    private $originalUrl;
    
    /**
     * parent link which this crawled from
     * @var Link
     */
    private $parentLink;
    
    private $metaInfo;
    
    private $statusCode;
    
    private $status;
    
    private $contentType;
    
    private $crawlDepth;
    
    private $shouldVisit = true;
    
    private $errorInfo;
       
    public function __construct($uri = '', $parentLink = null){        
        $this->originalUrl = $uri;
        $this->parentLink = $parentLink;
        if(strpos($this->originalUrl,"//") === 0 
                && $this->parentLink !== null){ //uri starts with "//"
            $uri = $this->parentLink->getScheme().":".$uri;
        }
        $this->crawlDepth = $this->parentLink != null ? 
                $this->parentLink->getCrawlDepth() + 1 : 0;
        parent::__construct($uri);        
    }
    
    public function getParentLink() {
        return $this->parentLink;
    }
    
    public function setStatusCode($statusCode){
        $this->statusCode = $statusCode;
        return $this;
    }
    
    public function getStatusCode(){
        return $this->statusCode;
    }
    
    public function setStatus($status){
        $this->status = $status;
        return $this;
    }
    
    public function getStatus(){
        return $this->status;
    }

    public function setContentType($contentType){
        $this->contentType = $contentType;
        return $this;
    }
    
    public function getContentType(){
        return $this->contentType;
    }
    
    public function getCrawlDepth(){
        return $this->crawlDepth != null ? $this->crawlDepth : 0;
    }
    
    public function setErrorInfo($errorInfo){
        $this->errorInfo = $errorInfo;
    }
    
    public function getErrorInfo(){
        return $this->errorInfo;
    }
    
    public function getOriginalUrl(){
        return $this->originalUrl;
    }
    
    public function setAsShouldVisit($value=true){
        $this->shouldVisit = $value;
    }
    
    public function shouldNotVisit(){
        return $this->shouldVisit === false;
    }
    
    public function getMetaInfoArray(){
        return $this->metaInfo;
    }
    
    public function getMetaInfo($name){
        return isset($this->metaInfo[$name])===true?
              $this->metaInfo[$name]:null;
    }
    

    public function setMetaInfo($name, $value){
        $this->metaInfo[$name] = $value;
        return $this;
    }
    
    public function addMetaInfo($name, $value){
        $this->metaInfo[$name][] = $value;
        return $this;
    }
    
    public function getParentUrl(){
        return $this->parentLink!==null?$this->parentLink->getAbsoluteUrl():null;
    }    

    /**
     * converting nodeUrl to absolute Url form
     * @param boolean $withFragment    
     * @return string
     */
    public function getAbsoluteUrl($withFragment=true)
    {              
        if ($this->isCrawlable() === false && empty($this->getFragment())) {
            $absolutePath = $this->getOriginalUrl();
        } else {            
            if ($this->parentLink !== null) {
                $newUri = UriResolver::resolve($this->parentLink, $this);
            } else {
                $newUri = $this;
            }
            
            $absolutePath = GuzzleUri::composeComponents(
                $newUri->getScheme(),
                $newUri->getAuthority(),
                $newUri->getPath(),
                $newUri->getQuery(),
                $withFragment===true?$this->getFragment():""
            );           
        }
        
        return $absolutePath;
    }  
    
    /**
     * Is a given URL crawlable?
     * @param  string $url
     * @return bool
     */
    public function isCrawlable()
    {
        if (empty($this->getPath()) === true && 
                ($this->parentLink && empty($this->parentLink->getPath()))) {
            return false;
        }

        $stop_links = array(
            '@^javascript\:.*$@i',           
            '@^mailto\:.*@i',
            '@^tel\:.*@i',
            '@^skype\:.*@i',
            '@^fax\:.*@i',            
        );

        foreach ($stop_links as $ptrn) {
            if (preg_match($ptrn, $this->getOriginalUrl()) === 1) {                
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Is URL external?
     * @param bool $localFile is the link from local file?
     * @return bool
     */
    public function isExternal()
    {
        $parentLink = $this->parentLink;
        if($parentLink===null){ //parentLink is null in case of baseUrl
            return false;
        }
                    
        $isExternal = $this->getHost() !== "" && 
                    ($this->getHost() != $parentLink->getHost());       

        return $isExternal;
    }    
    
    public function getComputedPath(){        
        if(!$this->isCrawlable() || $this->isExternal()){
            $path = $this->getOriginalUrl();
        }else{
            $path = $this->removeDotsFromPath();        
        }
        return $path;    
    }    
    
    public function __toString() {
        return $this->originalUrl;
    }
    
    /**
     * check if specific status code can be crawled or not
     * @param int $statusCode
     * @return boolean
     */
    public function checkCrawlableStatusCode(): bool{
       $statusCode = $this->getStatusCode(); 
       if( $statusCode >= 400 && $statusCode <= 599 ){
           return false;
       }else{
           return true;
       }
    }    
    
    /**
     * remove dots from uri
     * @return string
     */
    protected function removeDotsFromPath(){        
        if ($this->parentLink !== null) {
            $newUri = UriResolver::resolve($this->parentLink, $this);
        } else {
            $newUri = $this;
        }
        $finalPath = UriResolver::removeDotSegments($newUri->getPath());
        
        return $finalPath;
    }
 
}
