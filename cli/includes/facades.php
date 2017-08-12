<?php

use Illuminate\Container\Container;

class Facade
{
    /**
     * The key for the binding in the container.
     *
     * @return string
     */
    public static function containerKey()
    {
        return 'Squire\\'.basename(str_replace('\\', '/', get_called_class()));
    }

    /**
     * Call a non-static method on the facade.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public static function __callStatic($method, $parameters)
    {
        $resolvedInstance = Container::getInstance()->make(static::containerKey());

        return call_user_func_array([$resolvedInstance, $method], $parameters);
    }
}

class Brew extends Facade {}
class Nginx extends Facade {}
class Mysql extends Facade {}
class Redis extends Facade {}
class Elasticsearch extends Facade {}
class Mailhog extends Facade {}
class CommandLine extends Facade {}
class Configuration extends Facade {}
class DnsMasq extends Facade {}
class Filesystem extends Facade {}
class Ngrok extends Facade {}
class PhpFpm extends Facade {}
class DevTools extends Facade {}
class Site extends Facade {}
class Squire extends Facade {}
