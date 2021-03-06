<?php
/**
 * Snipcart plugin for Craft CMS 3.x
 *
 * @link      https://workingconcept.com
 * @copyright Copyright (c) 2018 Working Concept Inc.
 */

namespace workingconcept\snipcart\base;

use craft\base\ComponentInterface;
use workingconcept\snipcart\models\Order as SnipcartOrder;
use workingconcept\snipcart\models\ShippingRate as SnipcartRate;
use workingconcept\snipcart\models\Package;
use GuzzleHttp\Client;

interface ShippingProviderInterface extends ComponentInterface
{
    // Static Methods
    // =========================================================================

    /**
     * Get the "camelCase" name of the provider.
     * @return string|null
     */
    public static function refHandle();

    /**
     * Get the base URL for the provider's REST API, used by client.
     * @return string
     */
    public static function apiBaseUrl(): string;


    // Public Methods
    // =========================================================================

    /**
     * Gets the provider settings model, null if it's not ready, false if there isn't one.
     * @return \craft\base\Model|bool|null
     */
    public function getSettings();

    /**
     * Sets the provider settings.
     * @param array $settings Stored plugin settings that should populate the settings model
     */
    public function setSettings(array $settings);

    /**
     * Whether the provider is ready to go.
     * @return bool
     */
    public function isConfigured(): bool;

    /**
     * Get shipping rates for the provided Snipcart order.
     *
     * @param SnipcartOrder $snipcartOrder
     * @param Package       $package
     * @return SnipcartRate[]
     */
    public function getRatesForOrder(SnipcartOrder $snipcartOrder, Package $package): array;

    /**
     * Create an equivalent order in the provider's system.
     *
     * @param SnipcartOrder $snipcartOrder
     * @return mixed|null The created order model.
     */
    public function createOrder(SnipcartOrder $snipcartOrder);

    /**
     * Get an instance of the Guzzle client.
     *
     * @return Client
     */
    public function getClient(): Client;

    /**
     * Get an order by the provider's unique ID.
     *
     * @param string|int $providerId
     * @return mixed provider order model or null
     */
    public function getOrderById($providerId);

    /**
     * Get an order by Snipcart invoice number.
     *
     * @param string $snipcartInvoice
     * @return mixed provider order model or null
     */
    public function getOrderBySnipcartInvoice(string $snipcartInvoice);

    /**
     * Create a shipping label for the provided order.
     *
     * @param SnipcartOrder $snipcartOrder
     * @return string|null URL to the label
     * @todo decide on sensible uniform return value
     */
    public function createShippingLabelForOrder(SnipcartOrder $snipcartOrder);

    /**
     * Perform get request with the provider's REST API, returning the response
     * as an object, array of objects, or null.
     *
     * @param $endpoint
     * @param array $params
     * @return mixed
     */
    public function get(string $endpoint, array $params = []);

    /**
     * Perform get request with the provider's REST API, returning the response
     * as an object, array of objects, or null.
     *
     * @param $endpoint
     * @param array $data
     * @return mixed
     */
    public function post(string $endpoint, array $data = []);

    /*

    public function updateOrder(SnipcartOrder $snipcartOrder);
    public function deleteOrder(SnipcartOrder $snipcartOrder);

    */

}