<?php


namespace Cerpus\LicenseClient;


use Cerpus\LicenseClient\Contracts\LicenseContract;
use Illuminate\Support\Facades\Facade;

/**
 * Class LicenseClient
 * @package Cerpus\LicenseClient
 */
class LicenseClient extends Facade
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
