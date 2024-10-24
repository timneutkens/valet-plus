<?php

declare(strict_types=1);

namespace WeProvide\ValetPlus;

use DomainException;
use mysqli;
use Valet\Brew;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Site;

use function Valet\info;
use function Valet\output;
use function Valet\table;
use function Valet\tap;
use function Valet\warning;

class Mysql
{
    private const MYSQL_CONF_DIR = 'etc';
    private const MYSQL_CONF = 'etc/my.cnf';
    private const MAX_FILES_CONF = '/Library/LaunchDaemons/limit.maxfiles.plist';
    private const MYSQL_DATA_DIR = 'var/mysql';
    private const MYSQL_ROOT_PASSWORD = 'root';
    private const MYSQL_DEFAULT_VERSION = 'mysql@5.7';
    private const MYSQL_SUPPORTED_VERSIONS = ['mysql', 'mysql@8.0', 'mysql@5.7', 'mariadb'];

    /** @var Brew */
    protected $brew;
    /** @var CommandLine */
    protected $cli;
    /** @var Filesystem */
    protected $files;
    /** @var Configuration */
    protected $configuration;
    /** @var Site */
    protected $site;

    /** @var string[] */
    protected $systemDatabase = ['sys', 'performance_schema', 'information_schema', 'mysql'];
    /** @var Mysqli */
    protected $link = false;

    /**
     * @param Brew $brew
     * @param CommandLine $cli
     * @param Filesystem $files
     * @param Configuration $configuration
     * @param Site $site
     */
    public function __construct(
        Brew $brew,
        CommandLine $cli,
        Filesystem $files,
        Configuration $configuration,
        Site $site
    ) {
        $this->brew          = $brew;
        $this->cli           = $cli;
        $this->files         = $files;
        $this->configuration = $configuration;
        $this->site          = $site;
    }

    /**
     * Install the service.
     *
     * @param $type
     */
    public function install($type = self::MYSQL_DEFAULT_VERSION)
    {
        $this->verifyType($type);

        $currentlyInstalled = $this->installedVersion();
        if ($currentlyInstalled) {
            $type = $currentlyInstalled;
        }

        $this->stop();
        $this->resetConfigRootPassword();
        $this->removeConfiguration();
        $this->files->copy(__DIR__ . '/../stubs/mysql/limit.maxfiles.plist', static::MAX_FILES_CONF);
        $this->cli->quietly('launchctl load -w ' . static::MAX_FILES_CONF);

        if (!$this->installedVersion()) {
            $this->brew->installOrFail($type);
        }

        $this->stop();
        $this->installConfiguration($type);
        $this->restart();

        // If formula is versioned link the formula as the binary.
        if (strpos($type, '@') > 0) {
            $this->cli->runAsUser("brew link $type --force", function () {
                warning('Failed linking MySQL!');
            });
        }

        $this->setRootPassword();
    }

    /**
     * check if type is valid.
     *
     * @param $type
     *
     * @throws DomainException
     */
    public function verifyType($type)
    {
        if (!in_array($type, $this->getSupportedVersions())) {
            $supportedVersionsString = implode(', ', $this->getSupportedVersions());
            throw new DomainException(
                sprintf('Invalid Mysql type given. Available: %s', $supportedVersionsString)
            );
        }
    }

    /**
     * Get supported version of database.
     *
     * @return array
     */
    public function getSupportedVersions()
    {
        return static::MYSQL_SUPPORTED_VERSIONS;
    }

    /**
     * Get installed version of database system.
     *
     * @param bool $default
     *
     * @return bool|string
     */
    public function installedVersion($default = false)
    {
        return collect($this->getSupportedVersions())->filter(function ($version) {
            return $this->brew->installed($version);
        })->first(null, $default);
    }

    /**
     * Stop the Mysql service.
     */
    public function stop()
    {
        $version = $this->installedVersion() ?: self::MYSQL_DEFAULT_VERSION;
        info("Stopping {$version}...");
        $this->cli->quietly('brew services stop ' . $version);
    }

    /**
     * Install the configuration files.
     *
     * @param string $type
     */
    public function installConfiguration($type = self::MYSQL_DEFAULT_VERSION)
    {
        info('Updating ' . $type . ' configuration...');

        if (!$this->files->isDir($directory = BREW_PREFIX . '/' . static::MYSQL_CONF_DIR)) {
            $this->files->mkdirAsUser($directory);
        }

        $contents = $this->files->get(__DIR__ . '/../stubs/mysql/' . $type . '.cnf');
        $contents = str_replace('VALET_HOME_PATH', VALET_HOME_PATH, $contents);

        $this->files->putAsUser(
            BREW_PREFIX . '/' . static::MYSQL_CONF,
            $contents
        );
    }

    /**
     * Restart the Mysql service.
     */
    public function restart()
    {
        $version = $this->installedVersion() ?: self::MYSQL_DEFAULT_VERSION;
        info("Restarting {$version}...");
        $this->cli->quietly('brew services stop ' . $version);
        $this->cli->quietlyAsUser('brew services start ' . $version);
    }

    /**
     * Set root password of Mysql.
     *
     * @param string $oldPwd
     * @param string $newPwd
     */
    public function setRootPassword($oldPwd = '', $newPwd = self::MYSQL_ROOT_PASSWORD)
    {
        $success = true;
        $version = $this->installedVersion();

        switch ($version) {
            case 'mariadb':
            case 'mysql@5.7':
                $this->cli->runAsUser(
                    "mysqladmin -u root --password='" . $oldPwd . "' password " . $newPwd,
                    function () use (&$success) {
                        $success = false;
                    }
                );
                break;

            case 'mysql@8.0':
            case 'mysql':
                $retval = $this->query(
                    "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '" . $newPwd . "';",
                    false,
                    true
                );
                if (!$retval) {
                    $success = false;
                }
                break;
        }

        if ($success === false) {
            warning(
                "\n" .
                'Setting mysql password for root user failed.'
            );
            output(
                "\n" .
                'You can show the current configured password with the following command:' . "\n" .
                '  <fg=yellow>valet-plus db password --show</>' . "\n" .
                "\n" .
                'And try to set the root password manually with the following command:' . "\n" .
                '  <fg=yellow>valet-plus db password <old> <new></>' . "\n"
            );
        }
        if ($success !== false) {
            $this->setConfigRootPassword($newPwd);
        }
    }

    /**
     * Uninstall Mysql.
     */
    public function uninstall()
    {
        $version = $this->installedVersion();
        if ($version) {
            $this->stop();
            $this->brew->uninstallFormula($version);
        }

        $this->removeConfiguration();
        if (file_exists(BREW_PREFIX . '/' . static::MYSQL_DATA_DIR)) {
            $this->files->rmDirAndContents(BREW_PREFIX . '/' . static::MYSQL_DATA_DIR);
        }
    }

    /**
     * Print table of exists databases.
     */
    public function listDatabases()
    {
        table(['Database'], $this->getDatabases());
    }

    /**
     * Return Mysql connection.
     *
     * @return bool|mysqli
     */
    public function getConnection($withoutRootPwd = false)
    {
        // if connection already exists return it early.
        if ($this->link) {
            return $this->link;
        }

        // Create connection
        $rootPwd    = ($withoutRootPwd ? null : $this->getConfigRootPassword());
        $this->link = new mysqli('127.0.0.1', 'root', $rootPwd);

        // Check connection
        if ($this->link->connect_error) {
            warning('Failed to connect to database');

            return false;
        }

        return $this->link;
    }

    /**
     * Drop current Mysql database & re-import it from file.
     *
     * @param $file
     * @param $database
     */
    public function reimportDatabase($file, $database)
    {
        $this->importDatabase($file, $database, true);
    }

    /**
     * Import Mysql database from file.
     *
     * @param string $file
     * @param string $database
     * @param bool $dropDatabase
     */
    public function importDatabase($file, $database, $dropDatabase = false)
    {
        $database = $this->getDatabaseName($database);

        // drop database first
        if ($dropDatabase) {
            $this->dropDatabase($database);
        }

        $this->createDatabase($database);

        $gzip = ' | ';
        if (\stristr($file, '.gz')) {
            $gzip = ' | gzip -cd | ';
        }
        $this->cli->passthru('pv ' . \escapeshellarg($file) . $gzip . 'mysql ' . \escapeshellarg($database));
    }

    /**
     * Get current dir name.
     *
     * @return string
     */
    public function getDirName()
    {
        $gitDir = $this->cli->runAsUser('git rev-parse --show-toplevel 2>/dev/null');

        if ($gitDir) {
            return \trim(\basename($gitDir));
        }

        return \trim(\basename(\getcwd()));
    }

    /**
     * Drop Mysql database.
     *
     * @param string $name
     *
     * @return bool|string
     */
    public function dropDatabase($name)
    {
        $name = $this->getDatabaseName($name);

        return $this->query('DROP DATABASE `' . $name . '`') ? $name : false;
    }

    /**
     * Create Mysql database.
     *
     * @param string $name
     *
     * @return bool|string
     */
    public function createDatabase($name)
    {
        $name = $this->getDatabaseName($name);

        return $this->query('CREATE DATABASE IF NOT EXISTS `' . $name . '`') ? $name : false;
    }

    /**
     * Check if database already exists.
     *
     * @param string $name
     *
     * @return bool|\mysqli_result
     */
    public function isDatabaseExists($name)
    {
        $name = $this->getDatabaseName($name);
        $sql = "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '" . $this->escape($name) . "'";
        $query = $this->query($sql, false);

        return (bool)$query->num_rows;
    }

    /**
     * Export Mysql database.
     *
     * @param $filename
     * @param $database
     *
     * @return array
     */
    public function exportDatabase($filename, $database)
    {
        $database = $this->getDatabaseName($database);

        if (!$filename || $filename === '-') {
            $filename = $database . '-' . \date('Y-m-d-His', \time());
        }

        if (!\stristr($filename, '.sql')) {
            $filename = $filename . '.sql.gz';
        }
        if (!\stristr($filename, '.gz')) {
            $filename = $filename . '.gz';
        }

        $this->cli->passthru(
            'mysqldump ' . \escapeshellarg($database) . ' | ' .
            'gzip > ' . \escapeshellarg($filename ?: $database)
        );

        return [
            'database' => $database,
            'filename' => $filename,
        ];
    }

    /**
     * Get exists databases.
     *
     * @return array|bool
     */
    protected function getDatabases()
    {
        $result = $this->query('SHOW DATABASES');

        if (!$result) {
            return false;
        }

        return collect($result->fetch_all(MYSQLI_ASSOC))->reject(function ($row) {
            return \in_array($row['Database'], $this->getSystemDatabase());
        })->map(function ($row) {
            return [$row['Database']];
        })->toArray();
    }

    /**
     * Run Mysql query.
     *
     * @param $query
     * @param bool $escape
     * @param bool $withoutRootPwd
     *
     * @return bool|\mysqli_result
     */
    protected function query($query, $escape = true, $withoutRootPwd = false)
    {
        $link = $this->getConnection($withoutRootPwd);
        if ($link === false) {
            return false;
        }

        $query = $escape ? $this->escape($query) : $query;

        return tap($link->query($query), function ($result) use ($link) {
            if (!$result) { // throw mysql error
                warning(\mysqli_error($link));
            }
        });
    }

    /**
     * escape string of query via myslqi.
     *
     * @param string $string
     *
     * @return string
     */
    protected function escape($string)
    {
        return \mysqli_real_escape_string($this->getConnection(), $string);
    }

    /**
     * Get default databases of mysql.
     *
     * @return array
     */
    protected function getSystemDatabase()
    {
        return $this->systemDatabase;
    }

    /**
     * Get database name via name or current dir.
     *
     * @param $database
     *
     * @return string
     */
    protected function getDatabaseName($database = '')
    {
        return $database ?: $this->getDirName();
    }

    /**
     * Remove current configuration before install new version.
     */
    protected function removeConfiguration()
    {
        $this->files->unlink(BREW_PREFIX . '/' . static::MYSQL_CONF);
        $this->files->unlink(BREW_PREFIX . '/' . static::MYSQL_CONF . '.default');
    }

    /**
     * Returns the stored password from the config. If not configured returns the default root password.
     */
    public function getConfigRootPassword()
    {
        $config = $this->configuration->read();
        if (isset($config['mysql']) && isset($config['mysql']['password'])) {
            return $config['mysql']['password'];
        }

        return static::MYSQL_ROOT_PASSWORD;
    }

    /**
     * @param $rootPwd
     * @throws \JsonException
     */
    protected function setConfigRootPassword($rootPwd)
    {
        $config = $this->configuration->read();
        if (!isset($config['mysql'])) {
            $config['mysql'] = [];
        }
        $config['mysql']['password'] = $rootPwd;
        $this->configuration->write($config);
    }

    /**
     * @throws \JsonException
     */
    protected function resetConfigRootPassword()
    {
        $config = $this->configuration->read();
        if (isset($config['mysql'])) {
            unset($config['mysql']);
        }
        $this->configuration->write($config);
    }
}
