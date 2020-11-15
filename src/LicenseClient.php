<?php


namespace Cerpus\LicenseClient;


use Cerpus\LicenseClient\Contracts\LicenseContract;

/**
 * Class LicenseClient
 * @package Cerpus\LicenseClient
 */
class LicenseClient
{
    /**
     * @var string
     */
    static $alias = "license";

    /**
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return LicenseContract::class;
    }

    /**
     * @return string
     */
    public static function getBasePath()
    {
        return dirname(__DIR__);
    }

    /**
     * @return string
     */
    public static function getConfigPath()
    {
        return self::getBasePath() . '/src/Config/license.php';
    }
}
