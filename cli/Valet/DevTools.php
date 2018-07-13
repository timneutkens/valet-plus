<?php

namespace Valet;

use Exception;
use ValetDriver;
use Symfony\Component\Process\Process;

class DevTools
{
    const PV_TOOL = 'pv';
    const GEOIP_TOOL = 'geoip';

    const BREW_SUPPORTED_TOOLS = [
        self::PV_TOOL,
        self::GEOIP_TOOL
    ];

    var $brew;
    var $cli;
    var $files;
    var $configuration;
    var $site;
    var $mysql;

    /**
     * Create a new Nginx instance.
     *
     * @param  Brew $brew
     * @param  CommandLine $cli
     * @param  Filesystem $files
     * @param  Configuration $configuration
     * @param  Site $site
     * @param Mysql $mysql
     */
    function __construct(Brew $brew, CommandLine $cli, Filesystem $files,
                         Configuration $configuration, Site $site, Mysql $mysql)
    {
        $this->cli = $cli;
        $this->brew = $brew;
        $this->site = $site;
        $this->files = $files;
        $this->configuration = $configuration;
        $this->mysql = $mysql;
    }

    /**
     * Install development tools using brew.
     *
     * @return void
     */
    function install($skipTools)
    {
        info('[devtools] Installing tools');

        foreach (self::BREW_SUPPORTED_TOOLS as $tool) {
            if (in_array($tool, $skipTools)) {
                continue;
            }
            if ($this->brew->installed($tool)) {
                info('[devtools] ' . $tool . ' already installed');
            } else {
                $this->brew->ensureInstalled($tool, []);
            }
        }

        if (!in_array('wp-cli', $skipTools)) {
            info('Installing wp-cli...');
            if ($this->cli->runAsUser('wp --info')) {
                info('wp-cli is already installed');
            } else {
                $process = new Process(
                    'curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && ' .
                    'php wp-cli.phar --info && ' .
                    'chmod +x wp-cli.phar && ' .
                    'sudo mv wp-cli.phar /usr/local/bin/wp && '.
                    'wp --info'
                );
                $process->run(function ($type, $buffer) {
                    if (Process::ERR === $type) {
                        warning($buffer);
                    } else {
                        info($buffer);
                    }
                });
            }
        }
    }

    /**
     * Uninstall development tools using brew.
     *
     * @return void
     */
    function uninstall()
    {
        info('[devtools] Uninstalling tools');

        foreach (self::BREW_SUPPORTED_TOOLS as $tool) {
            if (!$this->brew->installed($tool)) {
                info('[devtools] ' . $tool . ' already uninstalled');
            } else {
                $this->brew->ensureUninstalled($tool, ['--force']);
            }
        }
    }

    function sshkey()
    {
        $this->cli->passthru('pbcopy < ~/.ssh/id_rsa.pub');
        info('Copied ssh key to your clipboard');
    }

    function phpstorm()
    {
        info('Opening PHPstorm');

        $this->cli->runAsUser('open -a PhpStorm ./');
    }

    function sourcetree()
    {
        info('Opening SourceTree');
        $this->cli->runAsUser('open -a SourceTree ./');
    }

    function vscode()
    {
        info('Opening Visual Studio Code');
        $command = false;

        if ($this->files->exists('/usr/local/bin/code')) {
            $command = '/usr/local/bin/code';
        }

        if ($this->files->exists('/usr/local/bin/vscode')) {
            $command = '/usr/local/bin/vscode';
        }

        if (!$command) {
            throw new Exception('/usr/local/bin/code command not found. Please install it.');
        }

        $output = $this->cli->runAsUser($command . ' $(git rev-parse --show-toplevel)');

        if (strpos($output, 'fatal: Not a git repository') !== false) {
            throw new Exception('Could not find git directory');
        }
    }

    function tower()
    {
        info('Opening git tower');
        if (!$this->files->exists('/Applications/Tower.app/Contents/MacOS/gittower')) {
            throw new Exception('gittower command not found. Please install gittower by following the instructions provided here: https://www.git-tower.com/help/mac/integration/cli-tool');
        }

        $output = $this->cli->runAsUser('/Applications/Tower.app/Contents/MacOS/gittower $(git rev-parse --show-toplevel)');

        if (strpos($output, 'fatal: Not a git repository') !== false) {
            throw new Exception('Could not find git directory');
        }
    }

    function configure()
    {
        require realpath(__DIR__ . '/../drivers/require.php');

        $driver = ValetDriver::assign(getcwd(), basename(getcwd()), '/');

        $secured = $this->site->secured();
        $domain = $this->site->host(getcwd()) . '.' . $this->configuration->read()['domain'];
        $isSecure = in_array($domain, $secured);
        $url = ($isSecure ? 'https://' : 'http://') . $domain;

        if (method_exists($driver, 'configure')) {
            return $driver->configure($this, $url);
        }

        info('No configuration settings found.');
    }
}
