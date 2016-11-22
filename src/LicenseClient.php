<?php

namespace Cerpus\LicenseClient;

use Log;
use Cache;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class LicenseClient
{
    protected $licenseConfig;
    protected $oauthKey, $oauthSecret;
    protected $oauthToken = null;

    public function __construct($licenseConfig = [], $oauthKey = null, $oauthSecret = null)
    {
        $this->licenseConfig = empty($licenseConfig) ? config('license') : $licenseConfig;
        $this->oauthKey = empty($oauthKey) ? config('license.key') : $oauthKey;
	    $this->oauthSecret = empty($oauthSecret) ? config('license.secret') : $oauthSecret;
        $this->oauthKey = $oauthKey;
        $this->oauthSecret = $oauthSecret;
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

    public function getLicenses()
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
/*
 *         try{
            return (object)json_decode($this->doRequest($endPoint, []));
        } catch (\Exception $e){
            Log::error('Unable to get content for ' . $id . ': ' . $e->getMessage());
            return false;
        }

 */

        $getContentResponse = $this->doRequest($endPoint, []);

        if ($getContentResponse === false) {
            return false;
        }


        return (object)json_decode($getContentResponse);
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
                Log::error(__METHOD__. ': Unable to get token: URL: '.$authUrl.'. Wrong key/secret?');
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

		try{
			$responseBody = $this->doRequest($endpoint);
			$responseJson = json_decode($responseBody);
		} catch (\Exception $e){
			return false;
		}


		if( empty($responseJson->copyable) ){
			return false;
		}
		return true;
	}
}