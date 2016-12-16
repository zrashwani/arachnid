<?php

namespace Arachnid\Clients;

use Symfony\Component\BrowserKit\Client;
use Symfony\Component\BrowserKit\Request;
use Symfony\Component\BrowserKit\Response;

/**
 * FileSystemClient
 * client for crawling files stored locally, that implement Symfony BrowserKit Client
 * credits for: http://stackoverflow.com/questions/31154193/using-goutte-to-read-from-a-file-string
 */
class FilesystemClient extends Client
{
    /**
     * @param object $request An origin request instance
     *
     * @return object An origin response instance
     */
    protected function doRequest($request)
    {
        $file = $this->getFilePath($request->getUri());
        if (!file_exists($file)) {
            return new Response('Page not found', 404, []);
        }

        $content = file_get_contents($file);

        return new Response($content, 200, [
            'Content-Type' => 'text/html',
            ]);
    }

    private function getFilePath($uri)
    {
        // convert an uri to a file path to your saved response
        // could be something like this:
        return preg_replace('@http://localhost@', '', $uri).'.html';
    }
}