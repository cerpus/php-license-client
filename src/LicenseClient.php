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
        if (!property_exists($addContentJson, 'id')) {
            return false;
        }

        return $addContentJson;
    }

    public function removeLicense($id, $license_id)
    {
        $endPoint = '/v1/content/' . $id . '/licenses/' . $license_id;

        $removeLicenseResponse = $this->doRequest($endPoint, [], 'DELETE');
        $addContentJson = json_decode($removeLicenseResponse);
        if (!property_exists($addContentJson, 'id')) {
            return false;
        }

        return (object)$addContentJson;
    }

    private function doRequest($endPoint, $params = [], $method = 'GET')
    {
        $token = $this->getToken();
        $responseClient = new Client(['base_uri' => $this->licenseConfig['server']]);
        $params = array_merge(['token' => $token], $params);
        $finalParams = ['form_params' => $params];
        $response = $responseClient->request($method, $endPoint, $finalParams);

        return $response->getBody();
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
}