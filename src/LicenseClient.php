<?php

namespace Cerpus\LicenseClient;

use GuzzleHttp\Client;

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
        $endPoint = '/v1/licenses';

        return json_decode($this->doRequest($endPoint, []));
    }

    public function addContent($id, $name)
    {
        $params = [
            'site' => $this->licenseConfig['site'],
            'content_id' => $id,
            'name' => $name
        ];

        $addContentResponse = $this->doRequest('/v1/content', $params, 'POST');
        $addContentJson = json_decode($addContentResponse);
        if (!property_exists($addContentJson, 'id')) {
            return false;
        }

        return $addContentJson;
    }

    public function getContent($id)
    {
        $endPoint = '/v1/content/' . $id;

        return (object)json_decode($this->doRequest($endPoint, []));
    }

    public function deleteContent($id)
    {
        $endPoint = '/v1/content/' . $id;

        return (object)json_decode($this->doRequest($endPoint, [], 'DELETE'));
    }

    public function addLicense($id, $license_id)
    {
        $endPoint = '/v1/content/' . $id . '/licenses/' . $license_id;

        $addLicenseResponse = $this->doRequest($endPoint, [], 'PUT');
        $addContentJson = json_decode($addLicenseResponse);
        if (!array_key_exists($addContentJson['id'])) {
            return false;
        }

        return $addContentJson;
    }

    public function removeLicense($id, $license_id)
    {
        $endPoint = '/v1/content/' . $id . '/licenses/' . $license_id;

        $removeLicenseResponse = $this->doRequest($endPoint, [], 'DELETE');
        $addContentJson = json_decode($removeLicenseResponse);
        if (!array_key_exists($addContentJson['id'])) {
            return false;
        }

        return (object)$addContentJson;
    }

    private function doRequest($endPoint, $params = [], $method = 'GET')
    {
        $token = $this->getToken();
        $responseClient = new Client(['base_uri' => $this->licenseConfig['server']]);
	    $finalParams = [
		    'form_params' => $params,
		    'headers'     => [
			    'Authorization' => 'Bearer ' . $token
		    ],
	    ];
        $response = $responseClient->request($method, $endPoint, $finalParams);

        return $response->getBody()->getContents();
    }

    protected function getToken()
    {
        if (is_null($this->oauthToken)) {
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
        }

        return $this->oauthToken;

    }

	public function isContentCopyable($license)
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