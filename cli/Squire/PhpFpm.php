<?php

namespace Squire;

use DomainException;

class PhpFpm
{
    var $brew, $cli, $files;

    var $taps = [
        'homebrew/homebrew-php'
    ];

    /**
     * Create a new PHP FPM class instance.
     *
     * @param  Brew $brew
     * @param  CommandLine $cli
     * @param  Filesystem $files
     */
    function __construct(Brew $brew, CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->brew = $brew;
        $this->files = $files;
    }

    /**
     * Install and configure DnsMasq.
     *
     * @return void
     */
    function install()
    {
        if (! $this->brew->hasInstalledPhp()) {
            $this->brew->ensureInstalled('php70', [], $this->taps);
        }

        $this->files->ensureDirExists('/usr/local/var/log', user());
        $this->updateConfiguration();
        $this->installExtensions();
        $this->restart();
    }

    /**
     * Update the PHP FPM configuration.
     *
     * @return void
     */
    function updateConfiguration()
    {
        $contents = $this->files->get($this->fpmConfigPath());

        $contents = preg_replace('/^user = .+$/m', 'user = '.user(), $contents);
        $contents = preg_replace('/^group = .+$/m', 'group = staff', $contents);
        $contents = preg_replace('/^listen = .+$/m', 'listen = '.SQUIRE_HOME_PATH.'/squire.sock', $contents);
        $contents = preg_replace('/^;?listen\.owner = .+$/m', 'listen.owner = '.user(), $contents);
        $contents = preg_replace('/^;?listen\.group = .+$/m', 'listen.group = staff', $contents);
        $contents = preg_replace('/^;?listen\.mode = .+$/m', 'listen.mode = 0777', $contents);
        $contents = preg_replace('/^;?php_admin_value\[error_log\] = .+$/m', 'php_admin_value[error_log] = '.SQUIRE_HOME_PATH.'/Log/php.log', $contents);

        $this->files->put($this->fpmConfigPath(), $contents);

        $systemZoneName = readlink('/etc/localtime');
        $systemZoneName = str_replace('/usr/share/zoneinfo/', '', $systemZoneName);
        $contents = $this->files->get(__DIR__.'/../stubs/z-performance.ini');
        $contents = str_replace('TIMEZONE', $systemZoneName, $contents);

        $iniPath = $this->iniPath();
        $this->files->ensureDirExists($iniPath, user());
        $this->files->putAsUser($this->iniPath().'z-performance.ini', $contents);
    }

    function iniPath() {
        $destFile = dirname($this->fpmConfigPath());
        $destFile = str_replace('/php-fpm.d', '', $destFile);
        $destFile = $destFile . '/conf.d/';

        return $destFile;
    }

    /**
     * Restart the PHP FPM process.
     *
     * @return void
     */
    function restart()
    {
        $this->brew->restartLinkedPhp();
    }

    /**
     * Stop the PHP FPM process.
     *
     * @return void
     */
    function stop()
    {
        $this->brew->stopService('php56', 'php70', 'php71', 'php72');
    }

    /**
     * Get the path to the FPM configuration file for the current PHP version.
     *
     * @return string
     */
    function fpmConfigPath()
    {
        $confLookup = [
            'php72' => '/usr/local/etc/php/7.2/php-fpm.d/www.conf',
            'php71' => '/usr/local/etc/php/7.1/php-fpm.d/www.conf',
            'php70' => '/usr/local/etc/php/7.0/php-fpm.d/www.conf',
            'php56' => '/usr/local/etc/php/5.6/php-fpm.conf',
        ];

        return $confLookup[$this->brew->linkedPhp()];
    }

    function getExtensions() {
        return ['apcu', 'intl', 'mcrypt', 'opcache', 'geoip'];
    }

    function installExtensions() {
        $extensions = $this->getExtensions();
        $currentVersion = $this->brew->linkedPhp();
        info('['.$currentVersion.'] Installing extensions');

        foreach($extensions as $extension) {
            if($this->brew->installed($currentVersion.'-'.$extension)) {
                $this->cli->runAsUser('brew link '. $currentVersion . '-' . $extension);
                info('['.$currentVersion.'] '.$extension.' already installed');
            } else {
                $this->brew->ensureInstalled($currentVersion.'-'.$extension, [], $this->taps);
            }
        }
    }

    /**
     * Switch between versions of installed PHP
     *
     * @return bool
     */
    function switchTo($version)
    {
        $version = preg_replace('/[.]/','',$version);
        $versions = ['71', '70', '56'];
        $extensions = $this->getExtensions();
        $currentVersion = $this->brew->linkedPhp();

        if('php'.$version === $currentVersion) {
            return false;
        }

        if (!in_array($version, $versions)) {
            throw new DomainException("This version of PHP not available. The following versions are available: " . implode(' ', $versions));
        }

        $this->cli->passthru('brew unlink '. $currentVersion);
        $this->cli->passthru('sudo ln -s /usr/local/Cellar/jpeg/8d/lib/libjpeg.8.dylib /usr/local/opt/jpeg/lib/libjpeg.8.dylib');

        foreach($versions as $phpversion) {
            foreach($extensions as $extension) {
                $this->cli->runAsUser('brew unlink php'.$phpversion.'-'.$extension);
            }
        }

        if (!$this->brew->installed('php'.$version)) {
            $this->brew->ensureInstalled('php'.$version);
        }

        $this->cli->passthru('brew link php'.$version);
        $this->stop();
        $this->install();
        return true;
    }

    function enableExtension($extension) {
        $currentPhpVersion = $this->brew->linkedPhp();

        if(!$this->brew->installed($currentPhpVersion.'-'.$extension)) {
            $this->brew->ensureInstalled($currentPhpVersion.'-'.$extension);
        }

        $iniPath = $this->iniPath();

        if($this->files->exists($iniPath.'ext-'.$extension.'.ini')) {
            info($extension.' was already enabled.');
            $this->restart();
            return true;
        }

        if($this->files->exists($iniPath.'ext-'.$extension.'.ini.disabled')) {
            $this->files->move($iniPath.'ext-'.$extension.'.ini.disabled', $iniPath.'ext-'.$extension.'.ini');
        }

        $this->restart();

        info('Enabled '.$extension);
        return true;
    }

    function disableExtension($extension) {
        $iniPath = $this->iniPath();
        if($this->files->exists($iniPath.'ext-'.$extension.'.ini.disabled')) {
            info($extension.' was already disabled.');
            return true;
        }

        if($this->files->exists($iniPath.'ext-'.$extension.'.ini')) {
            $this->files->move($iniPath.'ext-'.$extension.'.ini', $iniPath.'ext-'.$extension.'.ini.disabled');
        }

        $this->restart();

        info('Disabled '.$extension);
        return true;
    }

    function isExtensionEnabled($extension) {

      $currentPhpVersion = $this->brew->linkedPhp();

      if(!$this->brew->installed($currentPhpVersion.'-'.$extension)) {
          $this->brew->ensureInstalled($currentPhpVersion.'-'.$extension);
      }

      $iniPath = $this->iniPath();

      if($this->files->exists($iniPath.'ext-'.$extension.'.ini')) {
          info($extension.' is enabled.');
      } else {
          info($extension.' is disabled.');
      }

      return true;
    }
}
