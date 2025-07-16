<?php

namespace CatFerq\ReactPHPDNS\Enums;

use InvalidArgumentException;

class ClassEnum
{
    public const INTERNET = 1;
    public const CSNET    = 2;
    public const CHAOS    = 3;
    public const HESIOD   = 4;

    public static array $classes = [
        self::INTERNET => 'IN',
        self::CSNET    => 'CS',
        self::CHAOS    => 'CHAOS',
        self::HESIOD   => 'HS',
    ];

    public static function getName(int $class): string
    {
        if (!static::isValid($class)) {
            throw new InvalidArgumentException(sprintf('No class matching integer "%s"', $class));
        }

        return self::$classes[$class];
    }

    public static function isValid(string $class): bool
    {
        return array_key_exists($class, self::$classes);
    }

    public static function getClassFromName(string $name): int
    {
        $class = array_search(strtoupper($name), self::$classes, true);

        if (false === $class || !is_int($class)) {
            throw new InvalidArgumentException(sprintf('Class: "%s" is not defined.', $name));
        }

        return $class;
    }
}