<?php

declare(strict_types=1);

namespace WeProvide\ValetPlus;

use Illuminate\Container\Container;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Valet\Brew;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use WeProvide\ValetPlus\Event\DataEvent;
use WeProvide\ValetPlus\Extended\Site;

use function Valet\info;

class Mailhog extends AbstractService
{
    /** @var string */
    protected const SERVICE_NAME = 'mailhog';

    /** @var string */
    protected const PHP_CONFIGURATION_STUB = __DIR__ . '/../stubs/mailhog.ini';
    /** @var string */
    protected const NGINX_CONFIGURATION_PATH = VALET_HOME_PATH . '/Nginx/mailhog.conf';

    /** @var EventDispatcher */
    protected $eventDispatcher;
    /** @var Site */
    protected $site;

    /**
     * @param Configuration $configuration
     * @param Brew $brew
     * @param Filesystem $files
     * @param CommandLine $cli
     * @param Site $site
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(
        Configuration $configuration,
        Brew $brew,
        Filesystem $files,
        CommandLine $cli,
        Site $site
    ) {
        parent::__construct($configuration, $brew, $files, $cli);

        $container             = Container::getInstance();
        $this->eventDispatcher = $container->get('event_dispatcher');
        $this->site            = $site;
    }

    /**
     * Register events to listen to.
     */
    public function register()
    {
        $this->eventDispatcher->addListener('after_create_php_config', [$this, 'createPhpConfiguration']);
    }

    /**
     * Install mailhog and configuration the TLD to listen to.
     *
     * @param string $tld
     * @throws \JsonException
     */
    public function install(string $tld = 'test'): void
    {
        $this->brew->ensureInstalled(static::SERVICE_NAME);
        $this->site->proxyCreate('mailhog', 'http://127.0.0.1:8025', true);
        $this->setEnabled(static::STATE_ENABLED);
        $this->restart();
    }

    /**
     * @inheritdoc
     */
    public function installed(): mixed
    {
        return $this->brew->installed(static::SERVICE_NAME);
    }

    /**
     * @inheritdoc
     */
    public function stop(): void
    {
        if (!$this->installed()) {
            return;
        }

        $this->brew->stopService(static::SERVICE_NAME);
        $this->cli->quietlyAsUser('brew services stop ' . static::SERVICE_NAME);
    }

    /**
     * @inheritdoc
     */
    public function restart(): void
    {
        if (!$this->installed() || !$this->isEnabled()) {
            info("Mailhog not installed or not enabled");

            return;
        }

        $this->brew->restartService(static::SERVICE_NAME);
    }

    /**
     * @inheritdoc
     */
    public function uninstall(): void
    {
        $this->stop();
        $this->removeEnabled();
        $this->site->proxyDelete('mailhog');
        $this->brew->uninstallFormula(static::SERVICE_NAME);
        $this->files->unlink(BREW_PREFIX . '/var/log/mailhog.log');
        // Remove nginx domain listen file.
        $this->files->unlink(static::NGINX_CONFIGURATION_PATH);
    }

    /**
     * Create php ini files.
     *
     * @param DataEvent $event
     */
    public function createPhpConfiguration(DataEvent $event)
    {
        $this->files->putAsUser(
            $event->get('php_dir') . 'mailhog.ini',
            str_replace(
                ['BREW_PATH'],
                [BREW_PREFIX],
                $this->files->get(static::PHP_CONFIGURATION_STUB)
            )
        );
    }
}
