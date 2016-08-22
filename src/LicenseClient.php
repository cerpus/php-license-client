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
        $this->oauthKey = $oauthKey;
        $this->oauthSecret = $oauthSecret;
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
            'content_id' => $id,
            'name' => $name
        ];

        $addContentResponse = $this->doRequest('/v1/site/' . $this->licenseConfig['site'] . '/content', $params,
            'POST');
        $addContentJson = json_decode($addContentResponse);
        if (!property_exists($addContentJson, 'id')) {
            return false;
        }

        return $addContentJson;
    }

    public function getContent($id)
    {
        $endPoint = '/v1/site/' . $this->licenseConfig['site'] . '/content/' . $id;

        return (object)json_decode($this->doRequest($endPoint, []));
    }

    public function deleteContent($id)
    {
        $endPoint = '/v1/site/' . $this->licenseConfig['site'] . '/content/' . $id;

        return (object)json_decode($this->doRequest($endPoint, [], 'DELETE'));
    }

    public function addLicense($id, $license_id)
    {
        $endPoint = '/v1/site/' . $this->licenseConfig['site'] . '/content/' . $id;

        $addLicenseResponse = $this->doRequest($endPoint, ['license_id' => $license_id], 'PUT');
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
        $addContentJson = json_decode($removeLicenseResponse);
        if (!property_exists($addContentJson, 'id')) {
            return false;
        }

        return (object)$addContentJson;
    }

    private function doRequest($endPoint, $params = [], $method = 'GET')
    {
        $responseClient = new Client(['base_uri' => $this->licenseConfig['server']]);
        $headers = [
            'Authorization' => 'Bearer ' . $this->getToken()
        ];
        $finalParams = [
            'form_params' => $params,
            'headers' => $headers,
        ];

        $response = $responseClient->request($method, $endPoint, $finalParams);

        return $response->getBody();
    }

    protected function getToken()
    {
        //TODO: Cache this...
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
}