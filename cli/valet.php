#!/usr/bin/env php
<?php

use Silly\Application;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

use function Valet\info;
use function Valet\output;
use function Valet\table;
use function Valet\warning;

// Determine directory where Laravel's Valet runs in.
$laravelDir = __DIR__ . '/../vendor/laravel/valet';
if (!file_exists($laravelDir)) {
    $laravelDir = __DIR__ . '/../../../laravel/valet';
}

// Require Laravel's Valet.
require_once $laravelDir . '/cli' . '/app.php';
require_once __DIR__ . '/includes/extends.php';
require_once __DIR__ . '/includes/events.php';


// Change name and version.
/** @var Application $app */
$laravelValetName = $app->getName();
$laravelValetVersion = $app->getVersion();
$valetPlusName = 'Valet+';
$valetPlusVersion = '3.0.3';

$app->setVersion($laravelValetName . ' ' . $laravelValetVersion);
$app->setName(' ' . $valetPlusName . ' ' . $valetPlusVersion . "\n");

/**
 * Extend the 'install' command.
 */
$cmd = $app->get('install');
$app
    ->command('install', function (InputInterface $input, OutputInterface $output, $withMariadb, $withMysql80, $withMysql81, $withBinary) use ($cmd) {
        $types = $withMariadb + $withMysql80 + $withMysql81;
        if ($types > 1) {
            throw new RuntimeException('Cannot install Valet+ with multiple DBMS, please pick one.');
        }
        $mySqlVersion = $withMariadb ? 'mariadb' : 'mysql@5.7';
        $mySqlVersion = $withMysql81 ? 'mysql' : $mySqlVersion;
        $mySqlVersion = $withMysql80 ? 'mysql@8.0' : $mySqlVersion;

        // Add custom options to original command to fake 'm.
        $cmd->addOption('with-mysql80', null, InputOption::VALUE_NONE)
            ->addOption('with-mysql81', null, InputOption::VALUE_NONE)
            ->addOption('with-mariadb', null, InputOption::VALUE_NONE)
            ->addOption('with-binary', 'b', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL);
        // Run original command.
        $cmd->run($input, $output);

        info("\nInstalling Valet+ services");

        Mysql::install($mySqlVersion);
        Mailhog::install(Configuration::read()['tld']);
        Nginx::restart();

        // If 'with-binary' option is omitted, $withBinary is an empty array, we install all.
        // If 'with-binary' option is provided without values, $withBinary is an array with only NULL as value, we install none.
        // Otherwise, we install the provided binaries.
        if (empty($withBinary)) {
            Binary::install();
        } else {
            foreach ($withBinary as $binary) {
                Binary::installBinary($binary);
            }
        }

        info("\nValet+ installed successfully!");
    })
    ->descriptions('Install the Valet+ services, with MySQL 5.7 as default DBMS.')
    ->addOption('with-mysql80', null, InputOption::VALUE_NONE, "Install with MySQL 8.0")
    ->addOption('with-mysql81', null, InputOption::VALUE_NONE, "Install with MySQL 8.1")
    ->addOption('with-mariadb', null, InputOption::VALUE_NONE, "Install with MariaDB")
    ->addOption(
        'with-binary',
        'b',
        InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
        "Install with binary, by default all binaries are installed\n" .
        "Use `-b [binary] -b [binary]` to install multiple binaries you wish\n" .
        "Supported binaries: " . implode(', ', Binary::getSupported()) . "\n"
    );


/**
 * Most commands are available only if Valet+ is installed.
 */
if (is_dir(VALET_HOME_PATH)) {
    /**
     * Extend the 'on-latest-version' command.
     */
    $cmd = $app->get('on-latest-version');
    $app->command('on-latest-version', function (InputInterface $input, OutputInterface $output) use ($cmd, $valetPlusVersion) {
        output('On latest Valet+ version?');
        if (Valet::onLatestPlusVersion($valetPlusVersion)) {
            output('Yes');
        } else {
            output(sprintf('Your version of Valet+ (%s) is not the latest version available.', $valetPlusVersion));
        }

        output('');
        output('On latest Laravel Valet version?');
        $cmd->run($input, $output);
    }, ['latest'])->descriptions('Determine if this is the latest version of Valet+ and Laravel Valet');

    /**
     * Extend the 'tld' command.
     */
    $cmd = $app->get('tld');
    $app->command('tld [tld]', function (InputInterface $input, OutputInterface $output, $tld = null) use ($cmd) {
        $oldTld = Configuration::read()['tld'];
        $cmd->run($input, $output);
        $newTld = Configuration::read()['tld'];

        if ($newTld != $oldTld) {
            Mailhog::updateDomain(Configuration::read()['tld']);
            Mailhog::restart();

            Elasticsearch::updateDomain(Configuration::read()['tld']);

            PhpFpm::restart();
            Nginx::restart();

            info('Your Valet+ TLD has been updated to [' . $tld . '].');
        }
    }, ['domain'])->descriptions('Get or set the TLD used for Valet sites.');

    /**
     * Extend the 'start' command.
     */
    $cmd = $app->get('start');
    $app->command('start [service]', function (InputInterface $input, OutputInterface $output, $service) use ($cmd) {
        switch ($service) {
            case '':
                Mysql::restart();
                Mailhog::restart();
                Varnish::restart();
                RedisService::restart();
                Rabbitmq::restart();
                break;
            case 'mysql':
                Mysql::restart();

                return info('Mysql has been started.');
            case 'mailhog':
                Mailhog::restart();

                return info('Mailhog has been started.');
            case 'varnish':
                Varnish::restart();

                return info('Varnish has been started.');
            case 'redis':
                RedisService::restart();

                return info('Redis has been started.');
            case 'rabbitmq':
                Rabbitmq::restart();

                return info('Rabbitmq has been started.');
        }

        $cmd->run($input, $output);
    })->descriptions('Start the Valet services');

    /**
     * Extend the 'restart' command.
     */
    $cmd = $app->get('restart');
    $app->command('restart [service]', function (InputInterface $input, OutputInterface $output, $service) use ($cmd) {
        switch ($service) {
            case '':
                Mysql::restart();
                Mailhog::restart();
                Varnish::restart();
                RedisService::restart();
                Rabbitmq::restart();
                break;
            case 'mysql':
                Mysql::restart();

                return info('Mysql has been restarted.');
            case 'mailhog':
                Mailhog::restart();

                return info('Mailhog has been restarted.');
            case 'varnish':
                Varnish::restart();

                return info('Varnish has been restarted.');
            case 'redis':
                RedisService::restart();

                return info('Redis has been restarted.');
            case 'rabbitmq':
                Rabbitmq::restart();

                return info('Rabbitmq has been restarted.');
        }

        $cmd->run($input, $output);
    })->descriptions('Restart the Valet services');

    /**
     * Extend the 'stop' command.
     */
    $cmd = $app->get('stop');
    $app->command('stop [service]', function (InputInterface $input, OutputInterface $output, $service) use ($cmd) {
        switch ($service) {
            case '':
                Mysql::stop();
                Mailhog::stop();
                Varnish::stop();
                RedisService::stop();
                Rabbitmq::stop();
                break;
            case 'mysql':
                Mysql::stop();

                return info('Mysql has been stopped.');
            case 'mailhog':
                Mailhog::stop();

                return info('Mailhog has been stopped.');
            case 'varnish':
                Varnish::stop();

                return info('Varnish has been stopped.');
            case 'redis':
                RedisService::stop();

                return info('Redis has been stopped.');
            case 'rabbitmq':
                Rabbitmq::stop();

                return info('Rabbitmq has been stopped.');
        }

        $cmd->run($input, $output);
    })->descriptions('Stop the Valet services');

    /**
     * Extend the 'uninstall' command.
     */
    $cmd = $app->get('uninstall');
    $app->command('uninstall [--force]', function (InputInterface $input, OutputInterface $output, $force) use ($cmd) {
        if ($force) {
            warning('YOU ARE ABOUT TO UNINSTALL Valet+ services, configs and logs.');
            $helper   = $this->getHelperSet()->get('question');
            $question = new ConfirmationQuestion('Are you sure you want to proceed? [y/N]', false);

            if (false === $helper->ask($input, $output, $question)) {
                return warning('Uninstall aborted.');
            }

            info('Removing mysql...');
            Mysql::uninstall();
            info('Removing mailhog...');
            Mailhog::uninstall();
            info('Removing binaries...');
            Binary::uninstall();
            info('Removing varnish...');
            Varnish::uninstall();
            info('Removing redis...');
            RedisService::uninstall();
            info('Removing rabbitmq...');
            Rabbitmq::uninstall();
            info('Removing elasticsearch...');
            Elasticsearch::uninstall();
        }

        $cmd->run($input, $output);
    })->descriptions('Uninstall the Valet services', ['--force' => 'Do a forceful uninstall of Valet and related Homebrew pkgs']);

    /**
     * @todo: Extend the 'log' command to log 'elasticsearch', 'mysql'.
     */


    /**
     * Mailhog services.
     */
    $app
        ->command('mailhog', function (OutputInterface $output, string $mode = null) {
            $modes = ['install', 'on', 'enable', 'off', 'disable', 'uninstall'];

            if (!in_array($mode, $modes)) {
                throw new RuntimeException(sprintf('Not enough arguments (missing: "mode"). Available modes: %s', implode(', ', $modes)));
            }

            switch ($mode) {
                case 'install':
                    Mailhog::install(Configuration::read()['tld']);

                    return;
                case 'enable':
                case 'on':
                    Mailhog::enable();

                    return;
                case 'disable':
                case 'off':
                    Mailhog::disable();

                    return;
                case 'uninstall':
                    Mailhog::uninstall();

                    return;
            }

            PhpFpm::restart();
            Nginx::restart();
        })
        ->descriptions('Enable/disable Mailhog')
        ->addArgument('mode', InputArgument::REQUIRED, 'Available modes: ' . implode(', ', ['install', 'on', 'enable', 'off', 'disable', 'uninstall']));

    /**
     * Varnish services.
     */
    $app
        ->command('varnish', function (OutputInterface $output, string $mode = null) {
            $modes = ['install', 'on', 'enable', 'off', 'disable', 'uninstall'];

            if (!in_array($mode, $modes)) {
                throw new RuntimeException(sprintf('Not enough arguments (missing: "mode"). Available modes: %s', implode(', ', $modes)));
            }

            switch ($mode) {
                case 'install':
                    Varnish::install();

                    break;
                case 'enable':
                case 'on':
                    Varnish::enable();

                    break;
                case 'disable':
                case 'off':
                    Varnish::disable();

                    break;
                case 'uninstall':
                    Varnish::uninstall();

                    break;
            }
        })
        ->descriptions('Enable/disable Varnish')
        ->addArgument('mode', InputArgument::REQUIRED, 'Available modes: ' . implode(', ', ['install', 'on', 'enable', 'off', 'disable', 'uninstall']));

    /**
     * Redis services.
     */
    $app
        ->command('redis', function (OutputInterface $output, string $mode = null) {
            $modes = ['install', 'on', 'enable', 'off', 'disable', 'uninstall'];

            if (!in_array($mode, $modes)) {
                throw new RuntimeException(sprintf('Not enough arguments (missing: "mode"). Available modes: %s', implode(', ', $modes)));
            }

            switch ($mode) {
                case 'install':
                    RedisService::install();

                    break;
                case 'enable':
                case 'on':
                    RedisService::enable();

                    break;
                case 'disable':
                case 'off':
                    RedisService::disable();

                    break;
                case 'uninstall':
                    RedisService::uninstall();

                    break;
            }
        })
        ->descriptions('Enable/disable Redis')
        ->addArgument('mode', InputArgument::REQUIRED, 'Available modes: ' . implode(', ', ['install', 'on', 'enable', 'off', 'disable', 'uninstall']));

    /**
     * Rabbitmq services.
     */
    $app
        ->command('rabbitmq', function (OutputInterface $output, string $mode = null) {
            $modes = ['install', 'on', 'enable', 'off', 'disable', 'uninstall'];

            if (!in_array($mode, $modes)) {
                throw new RuntimeException(sprintf('Not enough arguments (missing: "mode"). Available modes: %s', implode(', ', $modes)));
            }

            switch ($mode) {
                case 'install':
                    Rabbitmq::install();

                    break;
                case 'enable':
                case 'on':
                    Rabbitmq::enable();

                    break;
                case 'disable':
                case 'off':
                    Rabbitmq::disable();

                    break;
                case 'uninstall':
                    Rabbitmq::uninstall();

                    break;
            }
        })
        ->descriptions('Enable/disable Rabbitmq')
        ->addArgument('mode', InputArgument::REQUIRED, 'Available modes: ' . implode(', ', ['install', 'on', 'enable', 'off', 'disable', 'uninstall']));

    /**
     * Database services and commands.
     */
    $app
        ->command('db [run] [name] [optional] [-y|--yes] [-s|--show]', function ($input, $output, $run, $name, $optional) {
            $helper   = $this->getHelperSet()->get('question');
            $defaults = $input->getOptions();

            if ($run === 'list' || $run === 'ls') {
                Mysql::listDatabases();

                return;
            }

            if ($run === 'create') {
                $databaseName = Mysql::createDatabase($name);
                if (!$databaseName) {
                    warning('Error creating database');

                    return;
                }

                info('Database "' . $databaseName . '" created successfully');

                return;
            }

            if ($run === 'drop') {
                if (!$defaults['yes']) {
                    $question = new ConfirmationQuestion('Are you sure you want to delete the database? [y/N] ', false);
                    if (!$helper->ask($input, $output, $question)) {
                        return;
                    }
                }

                $databaseName = Mysql::dropDatabase($name);
                if (!$databaseName) {
                    warning('Error dropping database');

                    return;
                }

                info('Database "' . $databaseName . '" dropped successfully');

                return;
            }

            if ($run === 'reset') {
                if (!$defaults['yes']) {
                    $question = new ConfirmationQuestion('Are you sure you want to reset the database? [y/N] ', false);
                    if (!$helper->ask($input, $output, $question)) {
                        return;
                    }
                }

                $dropped = Mysql::dropDatabase($name);
                if (!$dropped) {
                    warning('Error deleting database');

                    return;
                }

                $databaseName = Mysql::createDatabase($name);
                if (!$databaseName) {
                    warning('Error creating database');

                    return;
                }

                info('Database "' . $databaseName . '" reset successfully');

                return;
            }

            if ($run === 'import') {
                info('Importing database...');
                if (!$name) {
                    throw new RuntimeException('Please provide a dump file');
                }

                // check if database already exists.
                if (Mysql::isDatabaseExists($optional)) {
                    $question = new ConfirmationQuestion('Database already exists are you sure you want to continue? [y/N] ', false);
                    if (!$helper->ask($input, $output, $question)) {
                        return;
                    }
                }

                Mysql::importDatabase($name, $optional);

                return;
            }

            if ($run === 'reimport') {
                if (!$defaults['yes']) {
                    $question = new ConfirmationQuestion('Are you sure you want to reimport the database? [y/N] ', false);
                    if (!$helper->ask($input, $output, $question)) {
                        warning('Aborted');

                        return;
                    }
                }
                info('Resetting database, importing database...');
                if (!$name) {
                    throw new RuntimeException('Please provide a dump file');
                }
                Mysql::reimportDatabase($name, $optional);

                return;
            }

            if ($run === 'export' || $run === 'dump') {
                info('Exporting database...');
                $data = Mysql::exportDatabase($name, $optional);
                info('Database "' . $data['database'] . '" exported into file "' . $data['filename'] . '"');

                return;
            }

            if ($run === 'pwd' || $run === 'password') {
                if ($defaults['show']) {
                    $question = new ConfirmationQuestion('Are you sure you want to show the configured root password? [y/N] ', false);
                    if ($defaults['yes'] || $helper->ask($input, $output, $question)) {
                        info('Current configured password for root user: ' . Mysql::getConfigRootPassword());
                        output('<fg=yellow>Please note this is the password as configured in Valet+!</>');
                    }

                    return;
                }

                if ($name === null || $optional === null) {
                    throw new RuntimeException('Missing arguments to change root user password. Use: "valet db pwd <old> <new>"');
                }

                info('Setting password for root user...');
                Mysql::setRootPassword($name, $optional);

                return;
            }

            if ($run === 'uninstall') {
                if (!$defaults['yes']) {
                    $question = new ConfirmationQuestion('Are you sure you want to remove mysql? [y/N] ', false);
                    if (!$helper->ask($input, $output, $question)) {
                        return;
                    }
                }

                Mysql::uninstall();

                return;
            }

            throw new RuntimeException('Command not found');
        })
        ->descriptions('Database commands (list/ls, create, drop, reset, import, reimport, export/dump, pwd/password, uninstall)');

    /**
     * Elasticsearch/opensearch services.
     */
    $esVersions = Elasticsearch::getSupportedVersions();
    $esDockerVersions = Elasticsearch::getDockerVersions();
    $app
        ->command('elasticsearch', function (InputInterface $input, OutputInterface $output, $mode, $targetVersion = null, $current = false) {
            $modes         = ['install', 'use', 'on', 'enable', 'off', 'disable', 'uninstall'];
            $targetVersion = $targetVersion ?? 'opensearch'; //@todo only when we don't have any installed versions, if we do pick the first installed?

            if ($current) {
                // Show current running version information.
                $esCurrentVersion = Elasticsearch::getCurrentVersion();
                output(sprintf('Current running version: <fg=green>%s</>', ($esCurrentVersion ?: 'none')));
                return;
            }

            if (!in_array($mode, $modes)) {
                throw new RuntimeException(sprintf('Not enough arguments (missing: "mode"). Available modes: %s', implode(', ', $modes)));
            }

            $restart = true;
            switch ($mode) {
                case 'install':
                case 'use':
                    $restart = Elasticsearch::useVersion($targetVersion, Configuration::read()['tld']);

                    break;
                case 'on':
                case 'enable':
                    Elasticsearch::restart();

                    break;
                case 'off':
                case 'disable':
                    Elasticsearch::stop();

                    break;
                case 'uninstall':
                    Elasticsearch::uninstall();

                    break;
            }

            if ($restart) {
                PhpFpm::restart();
                Nginx::restart();
            }
        })
        ->descriptions(
                'Enable/disable/switch Elasticsearch. ' .
                'The versions [' . implode(', ', $esDockerVersions) . '] require Docker.'
        )
        ->setAliases(['es'])
        ->addArgument('mode', InputArgument::OPTIONAL, 'Available modes: ' . implode(', ', ['install', 'use', 'on', 'enable', 'off', 'disable', 'uninstall']))
        ->addArgument('targetVersion', InputArgument::OPTIONAL, "Version to use, supported versions: " . implode(', ', $esVersions), null)
        ->addOption(
            'current',
            'c',
            null,
            'Displays the current running version of Elasticsearch.'
        );


    /**
     * Xdebug php extension.
     */
    $app->command('xdebug [mode]', function ($mode) {
        $modes = ['on', 'enable', 'off', 'disable'];

        if (!in_array($mode, $modes)) {
            throw new RuntimeException(sprintf('Not enough arguments (missing: "mode"). Available modes: %s', implode(', ', $modes)));
        }

        $restart = false;
        switch ($mode) {
            case 'enable':
            case 'on':
                $restart = Xdebug::install();
                break;
            case 'disable':
            case 'off':
                $restart = Xdebug::uninstall();
                break;
        }
        if ($restart) {
            PhpFpm::restart();
        }
    })->descriptions('Enable/disable Xdebug');

    /**
     * Memcache php extension.
     */
    $app->command('memcache [mode]', function ($mode) {
        $modes = ['on', 'enable', 'off', 'disable'];

        if (!in_array($mode, $modes)) {
            throw new RuntimeException(sprintf('Not enough arguments (missing: "mode"). Available modes: %s', implode(', ', $modes)));
        }

        $restart = false;
        switch ($mode) {
            case 'enable':
            case 'on':
                $restart = Memcache::install();
                break;
            case 'disable':
            case 'off':
                $restart = Memcache::uninstall();
                break;
        }
        if ($restart) {
            PhpFpm::restart();
        }
    })->descriptions('Enable/disable Memcache');


    /**
     * Rewrite commands.
     */
    $app->command('rewrite [url]', function ($url = null) {
        if (!$url) {
            warning('Aborting, url is required');

            return;
        }

        $host = Site::host(getcwd());
        $url  = Site::rewrite($url, $host);
        if ($url === false) {
            warning('Aborting, url rewrite failed, might already exist');

            return;
        }

        info("The [$url] will now rewrite traffic to [$host].");
    })->descriptions('Rewrite any public URL to your local site instance.');

    $app->command('unrewrite [url]', function ($url = null) {
        if (!$url) {
            warning('Aborting, url is required');

            return;
        }

        $url = Site::unrewrite($url);
        if ($url === false) {
            warning('Aborting, url unrewrite failed, might not exist');

            return;
        }

        info("The [$url] will no longer rewrite traffic.");
    })->descriptions('Remove a rewrite of an URL to your local site instance.');

    $app->command('rewrites', function () {
        $rewrites = Site::rewrites();

        table(['Site', 'URL'], $rewrites->all());
    })->descriptions('Display all of the registered Valet rewrites');

    /**
     * Configuring framework command.
     */
    $app->command('configure', function () {
        DriverConfigurator::configure();
    })->descriptions('Configure application with know framework settings');

    /**
     * Which-uri command which outputs the URL of the current site.
     */
    $app->command('which-uri', function () {
        output(Site::domain(null));
    })->descriptions('Displays the URL of the current site');
}

/**
 * Run the application.
 */
$app->run();
