#!/usr/bin/env php
<?php

/**
 * Load correct autoloader depending on install location.
 */
if (file_exists(__DIR__.'/../vendor/autoload.php')) {
    require __DIR__.'/../vendor/autoload.php';
} else {
    require __DIR__.'/../../../autoload.php';
}

use Silly\Application;
use Illuminate\Container\Container;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Create the application.
 */
Container::setInstance(new Container);

$version = '1.0.0';

$app = new Application('Squire', $version);

/**
 * Prune missing directories and symbolic links on every command.
 */
if (is_dir(SQUIRE_HOME_PATH)) {
    Configuration::prune();

    Site::pruneLinks();
}

/**
 * Allow Squire to be run more conveniently by allowing the Node proxy to run password-less sudo.
 */
$app->command('install [--with-mariadb]', function ($withMariadb) {
    Nginx::stop();
    PhpFpm::stop();
    Mysql::stop();
    Redis::stop();
    DevTools::install();

    Configuration::install();
    Nginx::install();
    PhpFpm::install();
    DnsMasq::install();
    Mysql::install($withMariadb ? 'mariadb' : 'mysql');
    Redis::install();
    Mailhog::install();
    Nginx::restart();
    Squire::symlinkToUsersBin();
    Mysql::setRootPassword();

    output(PHP_EOL.'<info>Squire installed successfully!</info>');
})->descriptions('Install the Squire services');

/**
 * Most commands are available only if squire is installed.
 */
if (is_dir(SQUIRE_HOME_PATH)) {
    /**
     * Get or set the domain currently being used by Squire.
     */
    $app->command('domain [domain]', function ($domain = null) {
        if ($domain === null) {
            return info(Configuration::read()['domain']);
        }

        DnsMasq::updateDomain(
            $oldDomain = Configuration::read()['domain'], $domain = trim($domain, '.')
        );

        Configuration::updateKey('domain', $domain);

        Site::resecureForNewDomain($oldDomain, $domain);
        PhpFpm::restart();
        Nginx::restart();

        info('Your Squire domain has been updated to ['.$domain.'].');
    })->descriptions('Get or set the domain used for Squire sites');

    /**
     * Add the current working directory to the paths configuration.
     */
    $app->command('park [path]', function ($path = null) {
        Configuration::addPath($path ?: getcwd());

        info(($path === null ? "This" : "The [{$path}]") . " directory has been added to Squire's paths.");
    })->descriptions('Register the current working (or specified) directory with Squire');

    /**
     * Remove the current working directory from the paths configuration.
     */
    $app->command('forget [path]', function ($path = null) {
        Configuration::removePath($path ?: getcwd());

        info(($path === null ? "This" : "The [{$path}]") . " directory has been removed from Squire's paths.");
    })->descriptions('Remove the current working (or specified) directory from Squire\'s list of paths');

    /**
     * Register a symbolic link with Squire.
     */
    $app->command('link [name] [--secure]', function ($name, $secure) {
        $domain = Site::link(getcwd(), $name = $name ?: basename(getcwd()));

        if ($secure) {
            $this->runCommand('secure '.$name);
        }

        info('Current working directory linked to '.$domain);
    })->descriptions('Link the current working directory to Squire');

    /**
     * Register a subdomain link with Squire.
     */
    $app->command('subdomain [action] [name] [--secure]', function ($action, $name, $secure) {
        if($action === 'list') {
            $links = Site::links(basename(getcwd()));

            table(['Site', 'SSL', 'URL', 'Path'], $links->all());
            return;
        }

        if($action === 'add') {
            $domain = Site::link(getcwd(), $name.'.'.basename(getcwd()));

            if ($secure) {
                $this->runCommand('secure '. $name);
            }

            info('Current working directory linked to '.$domain);
            return;
        }

        throw new DomainException('Specified command not found');
    })->descriptions('Manage subdomains');

    /**
     * Display all of the registered symbolic links.
     */
    $app->command('links', function () {
        $links = Site::links();

        table(['Site', 'SSL', 'URL', 'Path'], $links->all());
    })->descriptions('Display all of the registered Squire links');

    /**
     * Unlink a link from the Squire links directory.
     */
    $app->command('unlink [name]', function ($name) {
        Site::unlink($name = $name ?: basename(getcwd()));

        info('The ['.$name.'] symbolic link has been removed.');
    })->descriptions('Remove the specified Squire link');

    /**
     * Secure the given domain with a trusted TLS certificate.
     */
    $app->command('secure [domain]', function ($domain = null) {
        $url = ($domain ?: Site::host(getcwd())).'.'.Configuration::read()['domain'];

        Site::secure($url);

        PhpFpm::restart();

        Nginx::restart();

        info('The ['.$url.'] site has been secured with a fresh TLS certificate.');
    })->descriptions('Secure the given domain with a trusted TLS certificate');

    /**
     * Stop serving the given domain over HTTPS and remove the trusted TLS certificate.
     */
    $app->command('unsecure [domain]', function ($domain = null) {
        $url = ($domain ?: Site::host(getcwd())).'.'.Configuration::read()['domain'];

        Site::unsecure($url);

        PhpFpm::restart();

        Nginx::restart();

        info('The ['.$url.'] site will now serve traffic over HTTP.');
    })->descriptions('Stop serving the given domain over HTTPS and remove the trusted TLS certificate');

    /**
     * Determine which Squire driver the current directory is using.
     */
    $app->command('which', function () {
        require __DIR__.'/drivers/require.php';

        $driver = SquireDriver::assign(getcwd(), basename(getcwd()), '/');

        if ($driver) {
            info('This site is served by ['.get_class($driver).'].');
        } else {
            warning('Squire could not determine which driver to use for this site.');
        }
    })->descriptions('Determine which Squire driver serves the current working directory');

    /**
     * Display all of the registered paths.
     */
    $app->command('paths', function () {
        $paths = Configuration::read()['paths'];

        if (count($paths) > 0) {
            output(json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            info('No paths have been registered.');
        }
    })->descriptions('Get all of the paths registered with Squire');

    /**
     * Open the current or given directory in the browser.
     */
    $app->command('open [domain]', function ($domain = null) {
        $url = "http://".($domain ?: Site::host(getcwd())).'.'.Configuration::read()['domain'];

        passthru("open ".escapeshellarg($url));
    })->descriptions('Open the site for the current (or specified) directory in your browser');

    /**
     * Generate a publicly accessible URL for your project.
     */
    $app->command('share', function () {
        warning("It looks like you are running `cli/squire.php` directly, please use the `squire` script in the project root instead.");
    })->descriptions('Generate a publicly accessible URL for your project');

    /**
     * Echo the currently tunneled URL.
     */
    $app->command('fetch-share-url', function () {
        output(Ngrok::currentTunnelUrl());
    })->descriptions('Get the URL to the current Ngrok tunnel');

    /**
     * Start the daemon services.
     */
    $app->command('start [services]*', function ($services) {
        if(empty($services)) {
            PhpFpm::restart();
            Nginx::restart();
            Mysql::restart();
            Redis::restart();
            Mailhog::restart();
            Elasticsearch::restart();
            info('Squire services have been started.');
            return;
        }

        foreach($services as $service) {
            switch($service) {
                case 'nginx': {
                    Nginx::restart();
                    break;
                }
                case 'mysql':
                case 'mariadb': {
                    Mysql::restart();
                    break;
                }
                case 'php': {
                    PhpFpm::restart();
                    break;
                }
                case 'mailhog': {
                    Mailhog::restart();
                    break;
                }
                case 'elasticsearch': {
                    Elasticsearch::restart();
                    break;
                }
            }
        }

        info('Specified Squire services have been started.');
    })->descriptions('Start the Squire services');

    /**
     * Restart the daemon services.
     */
    $app->command('restart [services]*', function ($services) {
        if(empty($services)) {
            PhpFpm::restart();
            Nginx::restart();
            Mysql::restart();
            Redis::restart();
            Mailhog::restart();
            Elasticsearch::restart();
            info('Squire services have been started.');
            return;
        }

        foreach($services as $service) {
            switch($service) {
                case 'nginx': {
                    Nginx::restart();
                    break;
                }
                case 'mysql': {
                    Mysql::restart();
                    break;
                }
                case 'php': {
                    PhpFpm::restart();
                    break;
                }
                case 'mailhog': {
                    Mailhog::restart();
                    break;
                }
                case 'elasticsearch': {
                    Elasticsearch::restart();
                    break;
                }
            }
        }

        info('Specified Squire services have been started.');
    })->descriptions('Restart the Squire services');

    /**
     * Stop the daemon services.
     */
    /**
     * Start the daemon services.
     */
    $app->command('stop [services]*', function ($services) {
        if(empty($services)) {
            PhpFpm::stop();
            Nginx::stop();
            Mysql::stop();
            Redis::stop();
            Mailhog::stop();
            Elasticsearch::stop();
            info('Squire services have been stopped.');
            return;
        }

        foreach($services as $service) {
            switch($service) {
                case 'nginx': {
                    Nginx::stop();
                    break;
                }
                case 'mysql': {
                    Mysql::stop();
                    break;
                }
                case 'php': {
                    PhpFpm::stop();
                    break;
                }
                case 'mailhog': {
                    Mailhog::stop();
                    break;
                }
                case 'elasticsearch': {
                    Elasticsearch::stop();
                    break;
                }
            }
        }

        info('Specified Squire services have been stopped.');
    })->descriptions('Stop the Squire services');

    /**
     * Uninstall Squire entirely.
     */
    $app->command('uninstall', function () {
        Nginx::uninstall();
        Mysql::uninstall();
        Redis::uninstall();
        Mailhog::uninstall();
        Elasticsearch::uninstall();

        info('Squire has been uninstalled.');
    })->descriptions('Uninstall the Squire services');

    /**
     * Determine if this is the latest release of Squire.
     */
    $app->command('on-latest-version', function () use ($version) {
        if (Squire::onLatestVersion($version)) {
            output('YES');
        } else {
            output('NO');
        }
    })->descriptions('Determine if this is the latest version of Squire');

    /**
     * Switch between versions of PHP
     */
    $app->command('use [phpVersion]', function ($phpVersion) {
        $switched = PhpFpm::switchTo($phpVersion);

        if(!$switched) {
            info('Already on this version');
            return;
        }
        info('Squire is now using php'.$phpVersion.'.');
    })->descriptions('Switch between versions of PHP');

    /**
     * Create database
     */
    $app->command('db [run] [name] [optional]', function ($input, $output, $run, $name, $optional) {
        $helper = $this->getHelperSet()->get('question');

        if($run === 'list' || $run === 'ls') {
            Mysql::listDatabases();
            return;
        }

        if($run === 'create') {
            $databaseName = Mysql::createDatabase($name);

            if(!$databaseName) {
                return warning('Error creating database');
            }

            info('Database "' . $databaseName . '" created successfully');
            return;
        }

        if($run === 'drop') {
            $question = new ConfirmationQuestion('Are you sure you want to delete the database? [y/N] ', false);
            if (!$helper->ask($input, $output, $question)) {
                warning('Aborted');
                return;
            }
            $databaseName = Mysql::dropDatabase($name);

            if(!$databaseName) {
                return warning('Error dropping database');
            }

            info('Database "' . $databaseName . '" dropped successfully');
            return;
        }

        if($run === 'reset') {
            $question = new ConfirmationQuestion('Are you sure you want to reset the database? [y/N] ', false);
            if (!$helper->ask($input, $output, $question)) {
                warning('Aborted');
                return;
            }

            $dropped = Mysql::dropDatabase($name);

            if(!$dropped) {
                return warning('Error creating database');
            }

            $databaseName = Mysql::createDatabase($name);

            if(!$databaseName) {
                return warning('Error creating database');
            }

            info('Database "' . $databaseName . '" reset successfully');
            return;
        }

        if($run === 'open') {
            if($name === '.') {
                $name = basename(getcwd());
            }

            info('Opening database...');

            Mysql::openSequelPro($name);
            return;
        }

        if($run === 'import') {
            info('Importing database...');
            if(!$name) {
                throw new Exception('Please provide a dump file');
            }
            Mysql::importDatabase($name, $optional);
            return;
        }

        if($run === 'reimport') {
            $question = new ConfirmationQuestion('Are you sure you want to reimport the database? [y/N] ', false);
            if (!$helper->ask($input, $output, $question)) {
                warning('Aborted');
                return;
            }
            info('Resetting database, importing database...');
            if(!$name) {
                throw new Exception('Please provide a dump file');
            }
            Mysql::reimportDatabase($name, $optional);
            return;
        }

        if($run === 'export' || $run === 'dump') {
            info('Exporting database...');
            $data = Mysql::exportDatabase($name, $optional);
            info('Database "' . $data['database'] . '" exported into file "' . $data['filename'] . '"');
            return;
        }

        throw new Exception('Command not found');
    })->descriptions('Database commands (list/ls, create, drop, reset, open, import, reimport, export/dump)');

    $app->command('configure', function () {
        DevTools::configure();
    })->descriptions('Configure application connection settings');

    $app->command('xdebug [mode]', function ($mode) {
        if($mode == '' || $mode == 'status') {
            PhpFpm::isExtensionEnabled('xdebug');
            return;
        }

        if($mode === 'on' || $mode === 'enable') {
            PhpFpm::enableExtension('xdebug');
            return;
        }

        if($mode === 'off' || $mode === 'disable') {
            PhpFpm::disableExtension('xdebug');
            return;
        }

        throw new Exception('Mode not found. Available modes: on / off');
    })->descriptions('Enable / disable Xdebug');

    $app->command('ioncube [mode]', function ($mode) {
        if($mode == '' || $mode == 'status') {
            PhpFpm::isExtensionEnabled('ioncubeloader');
            return;
        }

        if($mode === 'on' || $mode === 'enable') {
            PhpFpm::enableExtension('ioncubeloader');
            return;
        }

        if($mode === 'off' || $mode === 'disable') {
            PhpFpm::disableExtension('ioncubeloader');
            return;
        }

        throw new Exception('Mode not found. Available modes: on / off');
    })->descriptions('Enable / disable ioncube');

    $app->command('elasticsearch [mode]', function ($mode) {
        if($mode === 'install' || $mode === 'on') {
            Elasticsearch::install();
            return;
        }

        throw new Exception('Sub-command not found. Available: install');
    })->descriptions('Enable / disable Elasticsearch');

    $app->command('tower', function () {
        DevTools::tower();
    })->descriptions('Open closest git project in Tower');

    $app->command('phpstorm', function () {
        DevTools::phpstorm();
    })->descriptions('Open closest git project in PHPstorm');

    $app->command('vscode', function () {
        DevTools::vscode();
    })->descriptions('Open closest git project in Visual Studio Code');

    $app->command('ssh-key', function () {
        DevTools::sshkey();
    })->descriptions('Copy ssh key');
}

/**
 * Load all of the Squire extensions.
 */
foreach (Squire::extensions() as $extension) {
    include $extension;
}

/**
 * Run the application.
 */
$app->run();
