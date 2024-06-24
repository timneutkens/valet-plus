<?php

declare(strict_types=1);

namespace WeProvide\ValetPlus;

use DomainException;
use Valet\Brew;
use Valet\CommandLine;
use Valet\Filesystem;
use WeProvide\ValetPlus\Extended\Site;

use function Valet\info;

class Elasticsearch extends AbstractDockerService
{
    /** @var string */
    protected const NGINX_CONFIGURATION_PATH = VALET_HOME_PATH . '/Nginx/elasticsearch.conf';

    /** @var string */
    protected const ES_DEFAULT_VERSION = 'opensearch'; // which is v2 in Brew, @todo; maybe support v1.2 using docker?
    /** @var string[] */
    protected const ES_SUPPORTED_VERSIONS = ['opensearch', 'elasticsearch6', 'elasticsearch7', 'elasticsearch8'];
    /** @var string[] */
    protected const ES_DOCKER_VERSIONS = ['elasticsearch6', 'elasticsearch7', 'elasticsearch8'];
    /** @var string[] */
    protected const ES_EOL_VERSIONS = ['elasticsearch@6'];

    /** @var Brew */
    protected $brew;
    /** @var Site */
    protected $site;

    /**
     * @param CommandLine $cli
     * @param Filesystem $files
     * @param Brew $brew
     * @param Site $site
     */
    public function __construct(
        CommandLine $cli,
        Filesystem $files,
        Brew $brew,
        Site $site
    ) {
        parent::__construct($cli, $files);

        $this->brew = $brew;
        $this->site = $site;
    }

    /**
     * Returns supported elasticsearch versions.
     *
     * @return string[]
     */
    public function getSupportedVersions()
    {
        return static::ES_SUPPORTED_VERSIONS;
    }

    /**
     * Returns supported elasticsearch versions running in Docker.
     *
     * @return string[]
     */
    public function getDockerVersions()
    {
        return static::ES_DOCKER_VERSIONS;
    }

    /**
     * Returns end-of-life elasticsearch versions.
     *
     * @return string[]
     */
    public function getEolVersions()
    {
        return static::ES_EOL_VERSIONS;
    }

    /**
     * Returns if provided version is supported.
     *
     * @param $version
     * @return bool
     */
    public function isSupportedVersion($version): bool
    {
        return in_array($version, $this->getSupportedVersions());
    }

    /**
     * Returns is provided version is running as Docker container. If not, it's running natively (installed with Brew).
     *
     * @param $version
     * @return bool
     */
    public function isDockerVersion($version): bool
    {
        return in_array($version, $this->getDockerVersions());
    }

    /**
     * Returns running elasticsearch version.
     *
     * @return string|null
     */
    public function getCurrentVersion(): ?string
    {
        $runningServices = $this->brew->getAllRunningServices()
            ->merge($this->getAllRunningContainers())
            ->filter(function ($service) {
                return $this->isSupportedVersion($service);
            });

        return $runningServices->first();
    }

    /**
     * Installs the requested version and switches to it.
     *
     * @param string $version
     * @param string $tld
     * @return bool
     */
    public function useVersion($version = self::ES_DEFAULT_VERSION, $tld = 'test')
    {
        if (!$this->isSupportedVersion($version)) {
            throw new DomainException(
                sprintf(
                    'Invalid Elasticsearch version given. Available versions: %s',
                    implode(', ', static::ES_SUPPORTED_VERSIONS)
                )
            );
        }

        $currentVersion = $this->getCurrentVersion();
        // If the requested version equals that of the current running version, do not switch.
        if ($version === $currentVersion) {
            info('Already on this version');

            return false;
        }
        if ($currentVersion) {
            // Stop current version.
            $this->stop($currentVersion);
        }

        $this->install($version, $tld);
        return true;
    }


    /**
     * Stop elasticsearch.
     *
     * @param string|null $version
     */
    public function stop($version = null)
    {
        $version = ($version ? $version : $this->getCurrentVersion());
        if (!$version) {
            return;
        }

        if ($this->isDockerVersion($version)) {
            $this->stopContainer($version);
            return;
        }

        if (!$this->brew->installed($version)) {
            return;
        }

        $this->brew->stopService($version);
        $this->cli->quietlyAsUser('brew services stop ' . $version);
    }

    /**
     * Restart elasticsearch.
     *
     * @param string|null $version
     */
    public function restart($version = null)
    {
        $version = ($version ? $version : $this->getCurrentVersion());
        if (!$version) {
            return;
        }

        if ($this->isDockerVersion($version)) {
            $this->stopContainer($version);
            $this->upContainer($version);
            return;
        }

        if (!$this->brew->installed($version)) {
            return;
        }

        info("Restarting {$version}...");
        $this->cli->quietlyAsUser('brew services restart ' . $version);
    }

    /**
     * Install the requested version of elasticsearch.
     *
     * @param string $version
     * @param string $tld
     */
    public function install($version = self::ES_DEFAULT_VERSION, $tld = 'test')
    {
        if (!$this->isSupportedVersion($version)) {
            throw new DomainException(
                sprintf(
                    'Invalid Elasticsearch version given. Available versions: %s',
                    implode(', ', static::ES_SUPPORTED_VERSIONS)
                )
            );
        }

        if (!$this->isDockerVersion($version)) {
            // For Docker versions we don't need to anything here.

            // todo; install java dependency? and remove other java deps? seems like there can be only one running.
            // opensearch requires openjdk (installed automatically)
            // elasticsearch@6 requires openjdk@17 (installed automatically)
            //      seems like there can be only one openjdk when installing. after installing it doesn't matter.
            //      if this dependency is installed we need to launch es with this java version,
            //      see https://github.com/Homebrew/homebrew-core/issues/100260

            $this->brew->ensureInstalled($version);

            // todo: switch config still needed? > not between opensearch and elasticsearch@6
            // ==> opensearch
            //Data:    /usr/local/var/lib/opensearch/
            //Logs:    /usr/local/var/log/opensearch/*.log
            //Plugins: /usr/local/var/opensearch/plugins/
            //Config:  /usr/local/etc/opensearch/
            // ==> elasticsearch@6
            //Data:    /usr/local/var/lib/elasticsearch/
            //Logs:    /usr/local/var/log/elasticsearch/*.log
            //Plugins: /usr/local/var/elasticsearch/plugins/
            //Config:  /usr/local/etc/elasticsearch/

            // todo; add support for adding plugins like 'analysis-icu' and 'analysis-phonetic'?
        }

        $this->restart($version);
        $this->site->proxyCreate('elasticsearch', 'http://127.0.0.1:9200', true);
    }

    /**
     * Uninstall all supported versions.
     */
    public function uninstall()
    {
        $this->site->proxyDelete('elasticsearch');

        // Remove nginx domain listen file.
        $this->files->unlink(static::NGINX_CONFIGURATION_PATH);

        $versions = array_merge($this->getSupportedVersions(), $this->getEolVersions());
        foreach ($versions as $version) {
            $this->stop($version);
            if ($this->isDockerVersion($version)) {
                $this->downContainer($version);
            } else {
                $this->brew->uninstallFormula($version);
            }
        }

        // Legacy elasticsearch files
        if (file_exists(BREW_PREFIX . '/var/elasticsearch')) {
            $this->files->rmDirAndContents(BREW_PREFIX . '/var/elasticsearch');
        }
        $this->files->unlink(BREW_PREFIX . '/var/log/elasticsearch.log');
        if (file_exists(BREW_PREFIX . '/var/log/elasticsearch')) {
            $this->files->rmDirAndContents(BREW_PREFIX . '/var/log/elasticsearch');
        }
        if (file_exists(BREW_PREFIX . '/var/lib/elasticsearch')) {
            $this->files->rmDirAndContents(BREW_PREFIX . '/var/lib/elasticsearch');
        }
        if (file_exists(BREW_PREFIX . '/etc/elasticsearch')) {
            $this->files->rmDirAndContents(BREW_PREFIX . '/etc/elasticsearch');
        }

        // Opensearch files
        if (file_exists(BREW_PREFIX . '/var/opensearch')) {
            $this->files->rmDirAndContents(BREW_PREFIX . '/var/opensearch');
        }
        $this->files->unlink(BREW_PREFIX . '/var/log/opensearch.log');
        if (file_exists(BREW_PREFIX . '/var/log/opensearch')) {
            $this->files->rmDirAndContents(BREW_PREFIX . '/var/log/opensearch');
        }
        if (file_exists(BREW_PREFIX . '/var/lib/opensearch')) {
            $this->files->rmDirAndContents(BREW_PREFIX . '/var/lib/opensearch');
        }
        if (file_exists(BREW_PREFIX . '/etc/opensearch')) {
            $this->files->rmDirAndContents(BREW_PREFIX . '/etc/opensearch');
        }
    }
}
