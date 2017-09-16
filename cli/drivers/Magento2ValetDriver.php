<?php

class Magento2ValetDriver extends ValetDriver
{
    public function configure($devtools, $url) {
        info('Configuring Magento 2...');
        $devtools->cli->quietlyAsUser('chmod +x bin/magento');

        $sitePath = getcwd();

        if(!$this->envExists($sitePath)) {
            info('env.php missing. Installing default env.php...');
            $devtools->files->putAsUser(
                $sitePath.'/app/etc/env.php',
                str_replace(
                    'DBNAME',
                    $devtools->mysql->getDirName(),
                    $devtools->files->get(__DIR__.'/../stubs/magento2/env.php')
                )
            );
        }

        if(!$this->moduleConfigExists($sitePath)) {
            info('config.php missing. Enabling all modules...');
            $devtools->cli->quietlyAsUser('bin/magento module:enable --all');
        }

        info('Setting base url...');
        $devtools->cli->quietlyAsUser('n98-magerun2 config:set web/unsecure/base_url ' . $url . '/');
        $devtools->cli->quietlyAsUser('n98-magerun2 config:set web/secure/base_url ' . $url . '/');

        info('Setting elastic search hostname...');
        $devtools->cli->quietlyAsUser('n98-magerun2 config:set catalog/search/elasticsearch_server_hostname 127.0.0.1');
        
        info('Enabling URL rewrites...');
        $devtools->cli->quietlyAsUser('n98-magerun2 config:set web/seo/use_rewrites 1');
        
        info('Flushing cache...');
        $devtools->cli->quietlyAsUser('n98-magerun2 cache:flush');

        info('Configured Magento 2');
    }

    /**
     * Determine if the driver serves the request.
     *
     * @param  string $sitePath
     * @param  string $siteName
     * @param  string $uri
     * @return bool
     */
    public function serves($sitePath, $siteName, $uri)
    {
        return file_exists($sitePath . '/pub/index.php') &&
            file_exists($sitePath . '/bin/magento');
    }

    public function envExists($sitePath) {
        return file_exists($sitePath.'/app/etc/env.php');
    }

    public function moduleConfigExists($sitePath) {
        return file_exists($sitePath.'/app/etc/config.php');
    }

    public function installed($sitePath) {
        return $this->envExists($sitePath) && $this->moduleConfigExists($sitePath);
    }

    /**
     * Determine if the incoming request is for a static file.
     *
     * @param  string $sitePath
     * @param  string $siteName
     * @param  string $uri
     * @return string|false
     */
    public function isStaticFile($sitePath, $siteName, $uri)
    {
        $isMagentoStatic = false;
        $resource = $uri;

        if(strpos($uri,'/pub') === 0 && file_exists($sitePath.'/setup'.$uri)) {
            return $sitePath.'/setup'.$uri;
        }

        if (strpos($uri, '/static/') !== false) {
            $isMagentoStatic = true;
        }

        if (!$isMagentoStatic && strpos($uri, '/media/') === false) {
            return false;
        }

        if ($isMagentoStatic) {
            $resource = preg_replace('#static(/version[0-9]+)?/#', '', $uri, 1);
            $uri = '/static' . $resource;
        }

        if (file_exists($staticFilePath = $sitePath . '/pub' . $uri)) {
            return $staticFilePath;
        }

        if (strpos($uri, '/static/') === 0) {
            $_GET['resource'] = $resource;
            include($sitePath . DIRECTORY_SEPARATOR . 'pub' . DIRECTORY_SEPARATOR . 'static.php');
            exit;
        }

        if (strpos($uri, '/media/') === 0) {
            include($sitePath . DIRECTORY_SEPARATOR . 'pub' . DIRECTORY_SEPARATOR . 'get.php');
            exit;
        }

        return false;
    }

    /**
     * Get the fully resolved path to the application's front controller.
     *
     * @param  string $sitePath
     * @param  string $siteName
     * @param  string $uri
     * @return string
     */
    public function frontControllerPath($sitePath, $siteName, $uri)
    {
        if(isset($_GET['profile'])) {
            $_SERVER['MAGE_PROFILER'] = 'html';
        }

        if($uri === '/setup') {
            Header('HTTP/1.1 301 Moved Permanently');
            Header('Location: http://' . $_SERVER['HTTP_HOST'] . $uri . '/');
            die;
        }

        if(strpos($uri, '/setup') === 0) {
            $_SERVER['SCRIPT_FILENAME'] = $sitePath.'/setup/index.php';
            $_SERVER['SCRIPT_NAME'] = '/index.php';
            $_SERVER['DOCUMENT_ROOT'] = $sitePath.'/setup/';
            $_SERVER['REQUEST_URI'] = str_replace('/setup', '', $_SERVER['REQUEST_URI']);

            if($_SERVER['REQUEST_URI'] === '') {
                $_SERVER['REQUEST_URI'] = '/';
            }
            return $sitePath.'/setup/index.php';
        }

        if(!$this->installed($sitePath)) {
            http_response_code(404);
            require __DIR__.'/../templates/magento2.php';
            exit;
        }

        $_SERVER['DOCUMENT_ROOT'] = $sitePath;

        return $sitePath . '/pub/index.php';
    }
}
