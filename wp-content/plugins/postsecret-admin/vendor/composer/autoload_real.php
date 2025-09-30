<?php

class ComposerAutoloaderInit
{
    private static $loader;

    public static function getLoader()
    {
        if (null !== self::$loader) {
            return self::$loader;
        }

        spl_autoload_register(array('ComposerAutoloaderInit', 'loadClassLoader'), true, true);
        self::$loader = $loader = new \Composer\Autoload\ClassLoader();
        spl_autoload_unregister(array('ComposerAutoloaderInit', 'loadClassLoader'));

        $loader->setPsr4('PostSecret\\Admin\\', array(__DIR__ . '/../../src'));
        $loader->register(true);

        return $loader;
    }

    public static function loadClassLoader($class)
    {
        if ('Composer\Autoload\ClassLoader' === $class) {
            require __DIR__ . '/ClassLoader.php';
        }
    }
}
