<?php
/**
 * Snipcart plugin for Craft CMS 3.x
 *
 * @link      https://workingconcept.com
 * @copyright Copyright (c) 2018 Working Concept Inc.
 */

namespace workingconcept\snipcart\services;

use workingconcept\snipcart\helpers\VersionHelper;
use workingconcept\snipcart\Snipcart;

use Craft;
use craft\base\Component;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use yii\caching\TagDependency;
use yii\base\Exception;

/**
 * Class Api
 * 
 * The API service is for interacting with Snipcart's REST API. It provides a
 * configured Guzzle client, can optionally cache GET requests, and validates
 * tokens.
 *
 * @package workingconcept\snipcart\services
 */
class Api extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var string The tag we'll attach to our caches here so they can be
     *             neatly invalidated with a reference to it.
     */
    const CACHE_TAG = 'snipcart-api-cache';

    /**
     * @var string Characters to prepend to any cache keys that are used.
     */
    const CACHE_KEY_PREFIX = 'snipcart_';

    // Properties
    // =========================================================================

    /**
     * @var string Snipcart's base API URL used for all interactions.
     */
    protected static $apiBaseUrl = 'https://app.snipcart.com/api/';

    /**
     * @var bool Whether we have credentials for talking with the Snipcart API.
     */
    protected $isLinked;

    /**
     * @var Client Instantiated REST client.
     */
    protected $client;


    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        $this->isLinked = $this->_getSecretApiKey() !== null;
    }

    /**
     * Returns a configured Guzzle client.
     *
     * @return Client
     * @throws \Exception if our API key is missing.
     */
    public function getClient(): Client
    {
        if ( ! $this->isLinked)
        {
            throw new Exception('Snipcart plugin not configured.');
        }

        if ($this->client !== null)
        {
            return $this->client;
        }

        return $this->client = new Client([
            'base_uri' => self::$apiBaseUrl,
            'auth' => [ $this->_getSecretApiKey(), 'password' ],
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'Accept'       => 'application/json',
            ],
            'verify' => false,
            'debug'  => false
        ]);
    }

    /**
     * Sends a GET request to the Snipcart REST API.
     *
     * @param  string $endpoint    Snipcart API endpoint to be queried
     * @param  array  $parameters  Array of parameters to be URL formatted
     * @param  bool   $useCache    Whether or not to cache responses
     *
     * @return \stdClass|array|null  Response as single object, array
     *                               of objects or null for an invalid response.
     * @throws \Exception if our API key is missing.
     */
    public function get(string $endpoint, array $parameters = [], bool $useCache = true)
    {
        if ( ! empty($parameters))
        {
            $endpoint .= '?' . http_build_query($parameters);
        }

        $cacheService = Craft::$app->getCache();
        $cacheKey     = self::CACHE_KEY_PREFIX . $endpoint;

        /**
         * Make sure plugin settings *and* local parameter both allow caching.
         */
        $useCache = $useCache && Snipcart::$plugin->getSettings()->cacheResponses;
        
        if ($useCache && $cachedResponseData = $cacheService->get($cacheKey))
        {
            return $cachedResponseData;
        }

        $responseData = $this->_getRequest($endpoint);

        if ($responseData && $useCache)
        {
            $cacheService->set(
                $cacheKey,
                $responseData,
                Snipcart::$plugin->getSettings()->cacheDurationLimit,
                new TagDependency([ 'tags' => [ self::CACHE_TAG ] ])
            );
        }

        return $responseData;
    }

    /**
     * Sends a POST request to the Snipcart REST API.
     *
     * @param  string $endpoint    Desired endpoint
     * @param  array  $data        Parameters to be formatted and sent
     *
     * @return \stdClass|array     Response object or array of objects
     * @throws \Exception if our API key is missing.
     */
    public function post(string $endpoint, array $data = [])
    {
        return $this->_postRequest($endpoint, $data);
    }

    /**
     * Sends a PUT request to the Snipcart REST API.
     *
     * @param  string $endpoint    Desired endpoint
     * @param  array  $data        Parameters to be formatted and sent
     *
     * @return \stdClass|array     Response object or array of objects
     * @throws \Exception if our API key is missing.
     */
    public function put(string $endpoint, array $data = [])
    {
        return $this->_putRequest($endpoint, $data);
    }

    /**
     * Sends a DELETE request to the Snipcart REST API.
     *
     * @param  string $endpoint    Desired endpoint
     * @param  array  $data        Parameters to be formatted and sent
     *
     * @return \stdClass|array     Response object or array of objects
     * @throws \Exception if our API key is missing.
     */
    public function delete(string $endpoint, array $data = [])
    {
        return $this->_deleteRequest($endpoint, $data);
    }

    /**
     * Ask Snipcart whether its provided token is genuine.
     * (Used for webhook posts to be sure they came from Snipcart.)
     *
     * Tokens are deleted after this call, so it can only be used once to verify
     * and tokens expire in one hour. Expect a 404 if the token is deleted
     * or expired.
     *
     * @param string  $token  token to be validated, probably
     *                        from $_POST['HTTP_X_SNIPCART_REQUESTTOKEN']
     *
     * @return bool
     * @throws \Exception if our API key is missing.
     */
    public function tokenIsValid($token): bool
    {
        $response = $this->_getRequest(sprintf(
            'requestvalidation/%s',
            $token
        ));

        return isset($response->token) && $response->token === $token;
    }

    /**
     * Invalidate any cached GET requests we may have accumulated.
     */
    public static function invalidateCache()
    {
        TagDependency::invalidate(
            Craft::$app->getCache(),
            self::CACHE_TAG
        );

        Craft::info('API caches cleared.', 'snipcart');
    }

    // Private Methods
    // =========================================================================

    private function _getSecretApiKey()
    {
        $keyValue = Snipcart::$plugin->getSettings()->secretApiKey;

        return VersionHelper::isCraft31() ?
            Craft::parseEnv($keyValue) :
            $keyValue;
    }

    /**
     * Send a get request to the Snipcart REST API.
     * 
     * @param string $endpoint The desired endpoint
     *
     * @return mixed
     * @throws \Exception if our API key is missing.
     */
    private function _getRequest(string $endpoint)
    {
        try
        {
            $response = $this->getClient()->get($endpoint);
            return $this->_prepResponseData($response->getBody());
        }
        catch(RequestException $exception)
        {
            return $this->_handleRequestException($exception, $endpoint);
        }
    }

    /**
     * Send a post request to the Snipcart REST API.
     * 
     * @param string $endpoint The desired endpoint
     * @param array  $data     Parameters to be sent with the request
     *
     * @return mixed
     * @throws \Exception if our API key is missing.
     */
    private function _postRequest(string $endpoint, array $data = [])
    {
        try
        {
            $response = $this->getClient()->post($endpoint, [
                \GuzzleHttp\RequestOptions::JSON => $data
            ]);

            return $this->_prepResponseData($response->getBody());
        }
        catch (RequestException $exception)
        {
            return $this->_handleRequestException($exception, $endpoint);
        }
    }

    /**
     * Send a put request to the Snipcart API.
     *
     * @param string $endpoint The desired endpoint
     * @param array  $data     Parameters to be sent with the request
     *
     * @return mixed
     * @throws \Exception if our API key is missing.
     */
    private function _putRequest(string $endpoint, array $data = [])
    {
        try
        {
            $response = $this->getClient()->put($endpoint, [
                \GuzzleHttp\RequestOptions::JSON => $data
            ]);

            return $this->_prepResponseData($response->getBody());
        }
        catch (RequestException $exception)
        {
            return $this->_handleRequestException($exception, $endpoint);
        }
    }

    /**
     * Send a delete request to the Snipcart API.
     *
     * @param string $endpoint The desired endpoint
     * @param array  $data     Parameters to be sent with the request
     *
     * @return mixed
     * @throws \Exception if our API key is missing.
     */
    private function _deleteRequest(string $endpoint, array $data = [])
    {
        try
        {
            $response = $this->getClient()->delete($endpoint, [
                \GuzzleHttp\RequestOptions::JSON => $data
            ]);

            return $this->_prepResponseData($response->getBody());
        }
        catch (RequestException $exception)
        {
            return $this->_handleRequestException($exception, $endpoint);
        }
    }

    /**
     * Take the raw response body and give it back as data that's ready to use.
     *
     * @param $body
     *
     * @return mixed Appropriate PHP type, or null if json cannot be decoded
     *               or encoded data is deeper than the recursion limit.
     */
    private function _prepResponseData($body)
    {
        /**
         * Get the response data as an object, not an associative array.
         */
        return json_decode($body, false);
    }

    /**
     * Handle a failed request.
     *
     * @param RequestException $exception  Exception that was thrown
     * @param string           $endpoint   Endpoint that was queried
     *
     * @return null
     * @throws \Exception
     */
    private function _handleRequestException(
        RequestException $exception,
        string $endpoint
    )
    {
        /**
         * Get the status code, which should be 200 or 201 if things went well.
         */
        $statusCode = $exception->getResponse()->getStatusCode() ?? null;

        /**
         * If there's a response we'll use its body, otherwise default
         * to the request URI.
         */
        $reason = $exception->getResponse()->getBody() ?? null;

        if ($statusCode !== null && $reason !== null)
        {
            if ($statusCode === 401)
            {
                // unauthorized, meaning invalid API credentials
                throw new Exception('Unauthorized; make sure Snipcart API credentials are valid.');
            }

            // return code and message
            Craft::warning(sprintf(
                'Snipcart API responded with %d: %s',
                $statusCode,
                $reason
            ), 'snipcart');
        }
        else
        {
            // report mystery
            Craft::warning(sprintf(
                'Snipcart API request to %s failed.',
                $endpoint
            ), 'snipcart');
        }

        return null;
    }

}