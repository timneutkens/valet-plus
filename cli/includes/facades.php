<?php

use Illuminate\Container\Container;

class ValetPlusFacade
{
    /**
     * The key for the binding in the container.
     */
    public static function containerKey(): string
    {
        return 'WeProvide\\ValetPlus\\'.basename(str_replace('\\', '/', get_called_class()));
    }

    /**
     * Call a non-static method on the facade.
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        $resolvedInstance = Container::getInstance()->make(static::containerKey());

        return call_user_func_array([$resolvedInstance, $method], $parameters);
    }
}

/**
 * Valet+ classes
 */
class PhpExtension extends ValetPlusFacade
{
}
class Mysql extends ValetPlusFacade
{
}
class Mailhog extends ValetPlusFacade
{
}
class Elasticsearch extends ValetPlusFacade
{
}
class Varnish extends ValetPlusFacade
{
}
class RedisService extends ValetPlusFacade
{
}
class Rabbitmq extends ValetPlusFacade
{
}

class Memcache extends ValetPlusFacade
{
}
class Xdebug extends ValetPlusFacade
{
}

class Binary extends ValetPlusFacade
{
}

class DriverConfigurator extends ValetPlusFacade
{
}
class Docker extends ValetPlusFacade
{
}
