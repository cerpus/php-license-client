<?php

namespace Cerpus\LicenseClient;

use Log;
use Cache;
use GuzzleHttp\Client;
use Illuminate\Http\Response;
use GuzzleHttp\Exception\ClientException;

class LicenseClient
{
    protected $licenseConfig;
    protected $oauthKey, $oauthSecret;
    protected $oauthToken = null;

    const LICENSE_PDM = 'PDM';
    const LICENSE_CC0 = 'CC0';

    const LICENSE_CC = 'CC';
    const LICENSE_BY = 'BY';
    const LICENSE_SA = 'SA';
    const LICENSE_ND = 'ND';
    const LICENSE_NC = 'NC';

    const LICENSE_PRIVATE = 'PRIVATE';
    const LICENSE_COPYRIGHT = 'COPYRIGHT';

    const LICENSE_EDLIB = "EDLL";

    public function __construct($licenseConfig = [], $oauthKey = null, $oauthSecret = null)
    {
        $this->licenseConfig = empty($licenseConfig) ? config('license') : $licenseConfig;
        $this->oauthKey = empty($oauthKey) ? config('cerpus-auth.key') : $oauthKey;
        $this->oauthSecret = empty($oauthSecret) ? config('cerpus-auth.secret') : $oauthSecret;
        $this->verifyConfig();
    }

    private function verifyConfig()
    {
        $hasSite = array_key_exists('site', $this->licenseConfig);
        $hasServer = array_key_exists('server', $this->licenseConfig);

        if (!($hasSite && $hasServer)) {
            throw new Exception('LicenseClient->licenseConfig is missing one or more config fields. Make sure both "site" and "server" keys exist.');
        }
    }

    public function setOauthKey($oauthKey)
    {
        $this->oauthKey = $oauthKey;
    }

    public function setOauthSecret($oauthSecret)
    {
        $this->oauthSecret = $oauthSecret;
    }

    public function getLicenses(): array
    {
        $licenseKey = __METHOD__ . '-licenses';
        $licenses = Cache::get($licenseKey);
        if (is_null($licenses)) {
            $endPoint = '/v1/licenses';

            $licenses = $this->doRequest($endPoint, []);

            if ($licenses === false) {
                return []; // Empty list
            }
            $licenses = json_decode($licenses);
            Cache::put($licenseKey, $licenses, 7);
        }

        return $licenses;
    }

    public function addContent($id, $name)
    {
        $params = [
            'content_id' => $id,
            'name' => $name
        ];

        $addContentResponse = $this->doRequest('/v1/site/' . $this->licenseConfig['site'] . '/content',
            $params,
            'POST');

        if ($addContentResponse === false) { // Could not add content
            return false;
        }

        $addContentJson = json_decode($addContentResponse);
        if (!property_exists($addContentJson, 'id')) {
            return false;
        }

        return $addContentJson;
    }

    public function getContent($id)
    {
        $endPoint = '/v1/site/' . $this->licenseConfig['site'] . '/content/' . $id;
        $cachedKey = "GET-" . $endPoint;
        $cached = Cache::get($cachedKey . '1');
        if (is_null($cached)) {
            try {
                $getContentResponse = $this->doRequest($endPoint, []);
            } catch (\Exception $e) {
                Log::error('Unable to get content for ' . $id . ': ' . $e->getMessage());
                return false;
            }

            if ($getContentResponse === false) {
                return false;
            }
            $cached = (object)json_decode($getContentResponse);
            Cache::put($cachedKey, $cached, 10);
        }

        return $cached;
    }

    public function deleteContent($id)
    {
        $endPoint = '/v1/site/' . $this->licenseConfig['site'] . '/content/' . $id;

        $deleteContentResponse = $this->doRequest($endPoint, [], 'DELETE');

        if ($deleteContentResponse === false) {
            return false;
        }

        return (object)json_decode($deleteContentResponse);
    }

    public function addLicense($id, $license_id)
    {
        $endPoint = '/v1/site/' . $this->licenseConfig['site'] . '/content/' . $id;

        $addLicenseResponse = $this->doRequest($endPoint, ['license_id' => $license_id], 'PUT');

        if ($addLicenseResponse === false) {
            return false;
        }

        $addContentJson = json_decode($addLicenseResponse);
        if (!property_exists($addContentJson, 'id')) {
            return false;
        }

        return $addContentJson;
    }

    /**
     * Remove all licenses and set a new license
     *
     * @param $id Content id
     * @param $license_id License ID
     * @return mixed
     */
    public function setLicense($id, $license_id)
    {
        $content = $this->getContent($id);
        $licenses = $content->licenses;
        foreach ($licenses as $license) {
            $this->removeLicense($id, $license);
        }

        $response = $this->addLicense($id, $license_id);

        $newLicense = $response->licenses[0];

        return $newLicense;
    }

    public function removeLicense($id, $license_id)
    {
        $endPoint = '/v1/site/' . $this->licenseConfig['site'] . '/content/' . $id;

        $removeLicenseResponse = $this->doRequest($endPoint, ['license_id' => $license_id], 'DELETE');

        if ($removeLicenseResponse === false) {
            return false;
        }

        $removeLicenseJson = json_decode($removeLicenseResponse);
        if (!property_exists($removeLicenseJson, 'id')) {
            return false;
        }

        return (object)$removeLicenseJson;
    }

    private function doRequest($endPoint, $params = [], $method = 'GET')
    {
        $token = $this->getToken();
        try {
            $finalParams = [];
            $responseClient = new Client(['base_uri' => $this->licenseConfig['server']]);

            if ($token) {
                $headers = [
                    'Authorization' => 'Bearer ' . $token
                ];
                $finalParams = [
                    'form_params' => $params,
                    'headers' => $headers,
                ];

                $response = $responseClient->request($method, $endPoint, $finalParams);

                return $response->getBody();
            } else {
                Log::error(__METHOD__ . ' Missing token.');

                return false;
            }
        } catch (ClientException $e) {
            if ($e->getCode() !== Response::HTTP_NOT_FOUND) {
                throw $e;
            }

            return false;
        } catch (\Exception $e) {
            Log::error(__METHOD__ . " $method request to " . $this->licenseConfig['server'] . $endPoint . " failed. " . $e->getCode() . ' ' . $e->getMessage(),
                $finalParams);

            return false;
        }

    }

    /**
     * Get token to talk to license server
     * @return bool|string false on failure, token otherwise
     */
    protected function getToken()
    {
        $tokenName = __METHOD__ . '-licenseToken';
        $this->oauthToken = Cache::get($tokenName);
        if (is_null($this->oauthToken)) {
            try {
                $licenseServer = $this->licenseConfig['server'];
                $licenseClient = new Client(['base_uri' => $licenseServer]);
                $authResponse = $licenseClient->get('/v1/oauth2/service');
                $authJson = json_decode($authResponse->getBody());
                $authUrl = $authJson->url;

                $authClient = new Client(['base_uri' => $authUrl]);
                $authResponse = $authClient->request('POST', '/oauth/token', [
                    'auth' => [
                        $this->oauthKey,
                        $this->oauthSecret
                    ],
                    'form_params' => [
                        'grant_type' => 'client_credentials'
                    ],
                ]);
                $oauthJson = json_decode($authResponse->getBody());
                $this->oauthToken = $oauthJson->access_token;
                Cache::put($tokenName, $this->oauthToken, 3);
            } catch (\Exception $e) {
                Log::error(__METHOD__ . ': Unable to get token: URL: ' . $authUrl . '. Wrong key/secret?');
                return false;
            }
        }

        return $this->oauthToken;
    }

    public function isContentCopyable($id)
    {
        $licenseContent = $this->getContent($id);
        if (empty($licenseContent)) {
            return false;
        }

        $license = $licenseContent->licenses[0];
        return $this->isLicenseCopyable($license);
    }

    public function isLicenseCopyable($license)
    {
        $endpoint = sprintf('v1/licenses/%s/copyable', $license);

        try {
            $responseBody = $this->doRequest($endpoint);
            $responseJson = json_decode($responseBody);
        } catch (\Exception $e) {
            return false;
        }


        if (empty($responseJson->copyable)) {
            return false;
        }

        return true;
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
        $translations = [];
        $licensePart = strtoupper($licensePart);

        switch (strtolower($langCode)) {
            case 'nb-no':
                $translations = [
                    self::LICENSE_CC => 'Creative Commons',
                    self::LICENSE_BY => 'Navngivelse',
                    self::LICENSE_SA => 'Del på samme vilkår',
                    self::LICENSE_ND => 'Ingen bearbeidelse',
                    self::LICENSE_NC => 'Ikkekommersiell',
                    self::LICENSE_CC0 => 'Zero',
                    self::LICENSE_PRIVATE => 'Copyright',
                    self::LICENSE_COPYRIGHT => 'Copyright',
                    self::LICENSE_PDM => 'Public Domain Mark',
                    self::LICENSE_EDLIB =>" EdLib lisens",
                ];
                break;
            case 'sv-se':
                $translations = [
                    self::LICENSE_CC => 'Creative Commons',
                    self::LICENSE_BY => 'Erkännande',
                    self::LICENSE_SA => 'Dela lika',
                    self::LICENSE_ND => 'Inga bearbetningar',
                    self::LICENSE_NC => 'Icke kommersiel',
                    self::LICENSE_CC0 => 'Zero',
                    self::LICENSE_PRIVATE => 'Copyright',
                    self::LICENSE_COPYRIGHT => 'Copyright',
                    self::LICENSE_PDM => 'Public Domain Mark',
                    self::LICENSE_EDLIB =>" EdLib license",
                ];
                break;
            case 'en-gb':
                // No break;
            default:
                $translations = [
                    self::LICENSE_CC => 'Creative Commons',
                    self::LICENSE_BY => 'Attribution',
                    self::LICENSE_SA => 'Share alike',
                    self::LICENSE_ND => 'No derivatives',
                    self::LICENSE_NC => 'Non commercial',
                    self::LICENSE_CC0 => 'Zero',
                    self::LICENSE_PRIVATE => 'Copyright',
                    self::LICENSE_COPYRIGHT => 'Copyright',
                    self::LICENSE_PDM => 'Public Domain Mark',
                    self::LICENSE_EDLIB =>" EdLib license",
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
            case self::LICENSE_PDM:
                return 'https://creativecommons.org/share-your-work/public-domain/pdm';
                break;

            case self::LICENSE_CC0:
                $licenseUrl = 'https://creativecommons.org/publicdomain/zero/1.0/';
                break;

            case 'CC-BY':
                $licenseUrl = 'https://creativecommons.org/licenses/by/4.0/';
                break;

            case 'CC-BY-SA':
                $licenseUrl = 'https://creativecommons.org/licenses/by-sa/4.0/';
                break;

            case 'CC-BY-ND':
                $licenseUrl = 'https://creativecommons.org/licenses/by-nd/4.0/';
                break;

            case 'CC-BY-NC':
                $licenseUrl = 'https://creativecommons.org/licenses/by-nc/4.0/';
                break;

            case 'CC-BY-NC-SA':
                $licenseUrl = 'https://creativecommons.org/licenses/by-nc-sa/4.0/';
                break;

            case 'CC-BY-NC-ND':
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
