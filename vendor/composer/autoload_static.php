<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit43590e14afe9554b2627ee0b1c8b4906
{
    public static $prefixLengthsPsr4 = array (
        'F' => 
        array (
            'Firebase\\JWT\\' => 13,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Firebase\\JWT\\' => 
        array (
            0 => __DIR__ . '/..' . '/firebase/php-jwt/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit43590e14afe9554b2627ee0b1c8b4906::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit43590e14afe9554b2627ee0b1c8b4906::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit43590e14afe9554b2627ee0b1c8b4906::$classMap;

        }, null, ClassLoader::class);
    }
}
