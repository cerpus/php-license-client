<?php

namespace Cerpus\LicenseClient\Adapter;

use Cerpus\LicenseClient\Contracts\LicenseContract;
use Cerpus\LicenseClient\Traits\LicenseHelper;
use Exception;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class LicenseApiAdapter implements LicenseContract
{
    use LicenseHelper;

    const LICENSES_ENDPOINT = 'v1/licenses';
    const LICENSES_COPYABLE_ENDPOINT = 'v1/licenses/%s/copyable';
    const LICENSES_SITE_ENDPOINT = 'v1/site/%s/content';
    const LICENSES_SITE_ENDPOINT_MULTIPLE = 'v1/site/%s/content-by-id';
    const LICENSES_CONTENT_ENDPOINT = self::LICENSES_SITE_ENDPOINT . '/%s';

    /** @var Client */
    private $client;

    protected $site;

    public function __construct(Client $client, $site)
    {
        $this->client = $client;
        $this->site = $site;
    }

    public function getLicenses(): array
    {
        $licenseKey = $this->getCacheKey('licenses');
        $licenses = Cache::get($licenseKey);
        if (is_null($licenses)) {
            $licenses = $this->doRequest(self::LICENSES_ENDPOINT);
            if ($licenses === false) {
                return []; // Empty list
            }
            $licenses = json_decode($licenses);
            Cache::put($licenseKey, $licenses, config('license.cacheTTL', 3600));
        }

        return $licenses;
    }

    public function addContent($id, $name)
    {
        $params = [
            'content_id' => $id,
            'name' => $name
        ];

        $addContentResponse = $this->doRequest(sprintf(self::LICENSES_SITE_ENDPOINT, $this->site), $params, 'POST');
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
        try {
            $getContentResponse = $this->doRequest(sprintf(self::LICENSES_CONTENT_ENDPOINT, $this->site, $id), []);
        } catch (Exception $e) {
            Log::error('Unable to get content for ' . $id . ': ' . $e->getMessage());
            return false;
        }

        if ($getContentResponse === false) {
            return false;
        }
        return (object)json_decode($getContentResponse);
    }

    public function getContents($ids)
    {
        try {
            $getContentResponse = $this->doRequest(sprintf(self::LICENSES_SITE_ENDPOINT_MULTIPLE, $this->site), [
                "content_ids" => $ids
            ], "POST");
        } catch (Exception $e) {
            Log::error('Unable to get content for ' . $ids . ': ' . $e->getMessage());
            return false;
        }

        if ($getContentResponse === false) {
            return false;
        }

        return (object)json_decode($getContentResponse);
    }

    public function deleteContent($id)
    {
        $deleteContentResponse = $this->doRequest(sprintf(self::LICENSES_CONTENT_ENDPOINT, $this->site, $id), [], 'DELETE');

        if ($deleteContentResponse === false) {
            return false;
        }

        return (object)json_decode($deleteContentResponse);
    }

    public function addLicense($id, $license_id)
    {
        $endPoint = sprintf(self::LICENSES_CONTENT_ENDPOINT, $this->site, $id);
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
     * @param string $id
     * @param string $license_id
     * @return mixed
     */
    public function setLicense(string $id, string $license_id)
    {
        $content = $this->getContent($id);
        $licenses = $content->licenses;
        foreach ($licenses as $license) {
            $this->removeLicense($id, $license);
        }

        $response = $this->addLicense($id, $license_id);

        return $response->licenses[0];
    }

    public function removeLicense($id, $license_id)
    {
        $endPoint = sprintf(self::LICENSES_CONTENT_ENDPOINT, $this->site, $id);
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
        try {
            $finalParams = [
                'form_params' => $params,
            ];
            $response = $this->client->request($method, $endPoint, $finalParams);
            return $response->getBody();
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
            return false;
        } catch (Exception $e) {
            Log::error(__METHOD__ . " $method request to " . $this->getClientUrl($endPoint). $endPoint . " failed. " . $e->getCode() . ' ' . $e->getMessage(), $finalParams);
            return false;
        }
    }

    private function getClientUrl($path)
    {
        /** @var Uri $baseUri */
        $baseUri = $this->client->getConfig('base_uri');
        $url = [$baseUri->getScheme() . "://" . $baseUri->getHost()];
        if( $baseUri->getPort() ){
            $url[] = ":" . $baseUri->getPort();
        }
        $url[] = $baseUri->getPath();
        $url[] = $path;
        if( $baseUri->getQuery() ){
            $url[] = "?" . $baseUri->getQuery();
        }
        return implode("", $url);
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
        $endpoint = sprintf(self::LICENSES_COPYABLE_ENDPOINT, $license);

        try {
            $responseBody = $this->doRequest($endpoint);
            $responseJson = json_decode($responseBody);
        } catch (Exception $e) {
            return false;
        }


        if (empty($responseJson->copyable)) {
            return false;
        }

        return true;
    }

    public function isLicenseSupported($providedLicense)
    {
        return collect($this->getLicenses())
            ->filter(function ($license) use ($providedLicense) {
                return strtolower($license->id) === strtolower($providedLicense);
            })
            ->isNotEmpty();
    }

    private function getCacheKey($key)
    {
        return config('license.cacheKey') . '-' . $key;
    }
}
