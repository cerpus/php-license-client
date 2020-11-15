<?php

namespace Cerpus\LicenseClient\Traits;


use Cerpus\LicenseClient\Contracts\LicenseContract;

trait LicenseHelper
{

    protected $validLicenses = [
        'BY',
        'BY-SA',
        'BY-ND',
        'BY-NC',
        'BY-NC-SA',
        'BY-NC-ND',
        'CC0',
        'PRIVATE',
        'PDM',
    ];

    protected $throwawayLicenseParts = [
        'CREATIVE COMMONS',
        'CREATIVE-COMMONS',
        'LICENSE',
        'CC-',
        'CC ',
        '-1.0',
        '1.0',
        '-2.0',
        '2.0',
        '-2.5',
        '2.5',
        '-3.0',
        '3.0',
        '-4.0',
        '4.0',
        'INTERNATIONAL',
    ];

    // This must be in sync with $licenseLongFormParts for replacement to happen correctly
    protected $licenseShortFormParts = [
        'BY',
        'SA',
        'ND',
        'NC',
        'C',
        'C',
        'CC0',
        'PDM',
    ];

    // This must be in sync with $licenseShortFormParts for replacement to happen correctly
    protected $licenseLongFormParts = [
        'en' => [
            'ATTRIBUTION',
            'SHAREALIKE',
            'NODERIVATIVES',
            'NONCOMMERCIAL',
            'PRIVATE',
            'COPYRIGHT',
            'ZERO',
            'PUBLIC DOMAIN MARK',
        ],
        'no' => [
            'NAVNGIVELSE',
            'DEL PA SAMME VILKAR', // Note: Norwegian characters are replaced in toEdLibLicenseString
            'INGEN BEARBEIDELSE',
            'IKKEKOMMERSIELL',
            'PRIVATE',
            'COPYRIGHT',
            'ZERO',
            'PUBLIC DOMAIN MARK',
        ]
    ];

    public function canImport($copyright)
    {
        $license = $this->toEdLibLicenseString($copyright->license->license ?? '');

        $result = mb_strstr($license, 'BY') || in_array($license, ['PDM', 'CC0']);

        if ($result === false) {
            return false;
        }

        return true;
    }

    public function makeAttributionString($copyright, $backLinkUri = 'unknown')
    {
        $license = $copyright->license->license ? ('License: ' . $copyright->license->license) : '';
        $creators = [];
        foreach ($copyright->creators ?? [] as $creator) {
            $creators[] = ($creator->type ?? '') . ': ' . ($creator->name ?? '');
        }
        $creators = implode(', ', $creators);

        $rightsholders = [];
        foreach (($copyright->rightsholders ?? []) as $rightsholder) {
            $rightsholders[] = ($rightsholder->type ?? '') . ': ' . ($rightsholder->name ?? '');
        }
        $rightsholders = implode(', ', $rightsholders);

        $attributionArray = [$creators, $rightsholders, $license];

        if ($backLinkUri !== 'unknown') {
            $attributionArray[] = "Source: " . $backLinkUri;
        }

        $attributionArray = array_filter($attributionArray, function ($part) {
            return !empty($part);
        });

        return implode('. ', $attributionArray);
    }

    /**
     * Take almost any half way sensible Creative Commons or EdLib license string and normalize it to the license format used by EdLib.
     *
     * @param $licenseString The string you want normalized.
     * @return string|null The normalized license string. Null if the resulting license is not supported by EdLib or makes no sense
     */
    public function toEdLibLicenseString($licenseString)
    {
        $normalizingCopyrightString = strtoupper(trim($licenseString));

        $normalizingCopyrightString = str_replace(['Æ', 'Ø', 'Å'], ['A', 'O', 'A'], $normalizingCopyrightString);
        $normalizingCopyrightString = str_replace(['æ', 'ø', 'å'], ['A', 'O', 'A'], $normalizingCopyrightString);

        // Replace long form parts of a license with short form. Ex Attribution -> BY
        // Supports English and Norwegian longform parts
        foreach ($this->licenseLongFormParts as $licenseLongFormPart) {
            $normalizingCopyrightString = str_replace($licenseLongFormPart, $this->licenseShortFormParts, $normalizingCopyrightString);
        }

        // Some massaging of the string to get it to a point where we can
        $normalizingCopyrightString = str_replace($this->throwawayLicenseParts, '', $normalizingCopyrightString);
        $normalizingCopyrightString = preg_replace("/(\s)\\1+/", '-', $normalizingCopyrightString);
        $normalizingCopyrightString = str_replace(' ', '-', $normalizingCopyrightString);

        $licenseParts = explode('-', $normalizingCopyrightString);
        $licenseParts = array_unique($licenseParts);
        $licenseParts = array_values(array_filter($licenseParts, 'strlen')); // Remove empty array fields

        if (sizeof($licenseParts) === 1) {
            if ($licenseParts[0] === 'C') {
                $licenseParts[0] = 'PRIVATE';
            } elseif ($licenseParts[0] === 'PD') {
                $licenseParts[0] = 'PDM';
            }
        }

        $licenseParts = $this->rearrangeLicenseTerms($licenseParts);

        $normalizedCopyrightString = implode('-', $licenseParts);

        if (!in_array($normalizedCopyrightString, $this->validLicenses)) {
            return null;
        }

        return $normalizedCopyrightString;
    }

    /**
     * Make sure the order of the cc license terms is correct.
     *
     * @param array $licenseParts The array to check.
     * @return array The license with the correct order of the license parts.
     */
    protected function rearrangeLicenseTerms($licenseParts = [])
    {
        if (empty($licenseParts)) {
            return [];
        }

        $rearrangedLicenseParts = [];

        $orderedLicenseParts = [
            'BY',
            'NC',
            'SA',
            'ND',
            'CC0',
            'PRIVATE',
            'PDM',
        ];

        // Create a new array containing the license parts in the correct order
        foreach ($orderedLicenseParts as $licensePart) {
            if (in_array($licensePart, $licenseParts)) {
                $rearrangedLicenseParts[] = $licensePart;
            }
        }

        if (sizeof($rearrangedLicenseParts) !== sizeof($licenseParts)) {
            return [];
        }

        return $rearrangedLicenseParts;
    }

    /**
     * Normalizes a license string and returns the H5P equivalent license string. If EdLib does not support the resulting license null is returned.
     *
     * @param $licenseString The string to get H5P equivalent license for
     * @return string|null The H5P licens string, or null if license is unsupported in EdLib.
     */
    public function toH5PLicenseString($licenseString)
    {
        if (!$normalizedString = $this->toEdLibLicenseString($licenseString)) {
            return null;
        }

        $normalizedLicenseToH5PLicenseMap = [
            'CC0' => 'CC0 1.0',
            'BY' => 'CC BY',
            'BY-SA' => 'CC BY-SA',
            'BY-ND' => 'CC BY-ND',
            'BY-NC' => 'CC BY-NC',
            'BY-NC-SA' => 'CC BY-NC-SA',
            'BY-NC-ND' => 'CC BY-NC-ND',
            'PRIVATE' => 'C',
            'PDM' => 'CC PDM',
        ];

        $h5pLicense = $normalizedLicenseToH5PLicenseMap[$normalizedString];

        return $h5pLicense;
    }

    /**
     * @param string $license
     * @return array
     */
    public static function splitCode($license)
    {
        $parts = explode('-', strtoupper($license));
        sort($parts, SORT_STRING);

        return $parts;
    }

    /**
     * @param string $licensePart Part of license to get name of, e.g. 'ND'
     * @param string $langCode Currently supported 'en-gb', 'nb-no', 'sv-se'
     * @return string
     */
    public static function getLicensePartName($licensePart, $langCode = 'en-gb')
    {
        $licensePart = strtoupper($licensePart);

        switch (strtolower($langCode)) {
            case 'nb-no':
                $translations = [
                    LicenseContract::LICENSE_CC => 'Creative Commons',
                    LicenseContract::LICENSE_BY => 'Navngivelse',
                    LicenseContract::LICENSE_SA => 'Del på samme vilkår',
                    LicenseContract::LICENSE_ND => 'Ingen bearbeidelse',
                    LicenseContract::LICENSE_NC => 'Ikkekommersiell',
                    LicenseContract::LICENSE_CC0 => 'Zero',
                    LicenseContract::LICENSE_PRIVATE => 'Copyright',
                    LicenseContract::LICENSE_COPYRIGHT => 'Copyright',
                    LicenseContract::LICENSE_PDM => 'Public Domain Mark',
                    LicenseContract::LICENSE_EDLIB =>" EdLib lisens",
                ];
                break;
            case 'sv-se':
                $translations = [
                    LicenseContract::LICENSE_CC => 'Creative Commons',
                    LicenseContract::LICENSE_BY => 'Erkännande',
                    LicenseContract::LICENSE_SA => 'Dela lika',
                    LicenseContract::LICENSE_ND => 'Inga bearbetningar',
                    LicenseContract::LICENSE_NC => 'Icke kommersiel',
                    LicenseContract::LICENSE_CC0 => 'Zero',
                    LicenseContract::LICENSE_PRIVATE => 'Copyright',
                    LicenseContract::LICENSE_COPYRIGHT => 'Copyright',
                    LicenseContract::LICENSE_PDM => 'Public Domain Mark',
                    LicenseContract::LICENSE_EDLIB =>" EdLib license",
                ];
                break;
            case 'en-gb':
                // No break;
            default:
                $translations = [
                    LicenseContract::LICENSE_CC => 'Creative Commons',
                    LicenseContract::LICENSE_BY => 'Attribution',
                    LicenseContract::LICENSE_SA => 'Share alike',
                    LicenseContract::LICENSE_ND => 'No derivatives',
                    LicenseContract::LICENSE_NC => 'Non commercial',
                    LicenseContract::LICENSE_CC0 => 'Zero',
                    LicenseContract::LICENSE_PRIVATE => 'Copyright',
                    LicenseContract::LICENSE_COPYRIGHT => 'Copyright',
                    LicenseContract::LICENSE_PDM => 'Public Domain Mark',
                    LicenseContract::LICENSE_EDLIB =>" EdLib license",
                ];
                break;
        }

        return (array_key_exists($licensePart, $translations) ? $translations[$licensePart] : '');
    }

    /**
     * @param string $license The full license string e.g. 'CC-BY-ND'
     * @param string $langCode Currently supported 'en-gb', 'nb-no', 'sv-se'
     * @return string
     */
    public static function getCreativeCommonsLink($license, $langCode = 'en-gb')
    {
        $licenseUrl = '';
        switch (strtoupper($license)) {
            case LicenseContract::LICENSE_PDM:
                return 'https://creativecommons.org/share-your-work/public-domain/pdm';
                break;

            case LicenseContract::LICENSE_CC0:
                $licenseUrl = 'https://creativecommons.org/publicdomain/zero/1.0/';
                break;

            case LicenseContract::LICENSE_CC_BY:
                $licenseUrl = 'https://creativecommons.org/licenses/by/4.0/';
                break;

            case LicenseContract::LICENSE_CC_BY_SA:
                $licenseUrl = 'https://creativecommons.org/licenses/by-sa/4.0/';
                break;

            case LicenseContract::LICENSE_CC_BY_ND:
                $licenseUrl = 'https://creativecommons.org/licenses/by-nd/4.0/';
                break;

            case LicenseContract::LICENSE_CC_BY_NC:
                $licenseUrl = 'https://creativecommons.org/licenses/by-nc/4.0/';
                break;

            case LicenseContract::LICENSE_CC_BY_NC_SA:
                $licenseUrl = 'https://creativecommons.org/licenses/by-nc-sa/4.0/';
                break;

            case LicenseContract::LICENSE_CC_BY_NC_ND:
                $licenseUrl = 'https://creativecommons.org/licenses/by-nc-nd/4.0/';
                break;
        }

        if ($licenseUrl !== '') {
            switch (strtolower($langCode)) {
                case 'en-gb':
                    return $licenseUrl;
                    break;
                case 'nb-no':
                    return $licenseUrl . 'deed.no';
                    break;
                case 'sv-se':
                    return $licenseUrl . 'deed.sv';
                    break;
            }
        }

        return $licenseUrl;
    }
}
