<?php


namespace Cerpus\LicenseClient\Contracts;


interface LicenseContract
{
    const LICENSE_PDM = 'PDM';
    const LICENSE_CC0 = 'CC0';

    const LICENSE_CC = 'CC';
    const LICENSE_BY = 'BY';
    const LICENSE_SA = 'SA';
    const LICENSE_ND = 'ND';
    const LICENSE_NC = 'NC';
    const LICENSE_CC_BY = self::LICENSE_CC . "-" . self::LICENSE_BY;
    const LICENSE_CC_BY_SA = self::LICENSE_CC_BY . "-" . self::LICENSE_SA;
    const LICENSE_CC_BY_ND = self::LICENSE_CC_BY . "-" . self::LICENSE_ND;
    const LICENSE_CC_BY_NC = self::LICENSE_CC_BY . "-" . self::LICENSE_NC;
    const LICENSE_CC_BY_NC_SA = self::LICENSE_CC_BY_NC . "-" . self::LICENSE_SA;
    const LICENSE_CC_BY_NC_ND = self::LICENSE_CC_BY_NC . "-" . self::LICENSE_ND;

    const LICENSE_PRIVATE = 'PRIVATE';
    const LICENSE_COPYRIGHT = 'COPYRIGHT';

    const LICENSE_EDLIB = "EDLL";

    public function getLicenses(): array;

    public function addContent($id, $name);

    public function getContent($id);

    public function getContents($ids);

    public function deleteContent($id);

    public function addLicense($id, $licenseId);

    public function removeLicense($id, $licenseId);

    public function isContentCopyable($id);

    public function isLicenseCopyable($license);

    public function isLicenseSupported($license);
}
