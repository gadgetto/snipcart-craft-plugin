<?php
/**
 * Snipcart plugin for Craft CMS 3.x
 *
 * @link      https://workingconcept.com
 * @copyright Copyright (c) 2018 Working Concept Inc.
 */

namespace workingconcept\snipcart\services;

use workingconcept\snipcart\events\ShippingRateEvent;
use workingconcept\snipcart\Snipcart;
use workingconcept\snipcart\models\Order;
use workingconcept\snipcart\models\Notification;
use workingconcept\snipcart\models\Refund;
use workingconcept\snipcart\models\Package;
use workingconcept\snipcart\helpers\ModelHelper;
use Craft;

/**
 * The Orders service lets you interact with Snipcart orders as tidy,
 * documented models. The service can be accessed globally from
 * `Snipcart::$plugin->orders`.
 *
 * @package workingconcept\snipcart\services
 *
 * @todo clean up interfaces to be more Craft-y and obscure pagination concerns
 * @todo return null for invalid single-object requests, otherwise empty arrays
 */
class Orders extends \craft\base\Component
{
    // Constants
    // =========================================================================

    /**
     * @event ShippingRateEvent Triggered before shipping rates are requested
     *                          from any third parties.
     */
    const EVENT_BEFORE_REQUEST_SHIPPING_RATES = 'beforeRequestShippingRates';

    /**
     * @var string
     */
    const NOTIFICATION_TYPE_ADMIN = 'notifyAdmin';

    /**
     * @var string
     */
    const NOTIFICATION_TYPE_CUSTOMER = 'notifyCustomer';


    // Public Methods
    // =========================================================================

    /**
     * Gets a Snipcart order.
     *
     * @param string $orderId Snipcart order ID
     *
     * @return Order|null
     * @throws \Exception if our API key is missing.
     */
    public function getOrder($orderId)
    {
        if ($orderData = Snipcart::$plugin->api->get(sprintf(
            'orders/%s',
            $orderId
        )))
        {
            return new Order((array)$orderData);
        }

        return null;
    }

    /**
     * Gets multiple Snipcart orders.
     *
     * @param array $params Parameters to send with the request
     *
     * @return Order[]
     * @throws \Exception if our API key is missing.
     *
     * @todo support params similar to Craft Elements
     */
    public function getOrders($params = []): array
    {
        return ModelHelper::populateArrayWithModels(
            (array)$this->_fetchOrders($params)->items,
            Order::class
        );
    }

    /**
     * Gets Snipcart orders using multiple requests to overcome
     * pagination limits.
     *
     * @param array $params Parameters to send with the request
     *
     * @return Order[]
     * @throws
     */
    public function getAllOrders($params = []): array
    {
        $collection = [];
        $collected = 0;
        $offset = 0;
        $finished = false;

        while ($finished === false)
        {
            $params['offset'] = $offset;

            if ($result = $this->_fetchOrders($params))
            {
                $currentItems = (array)$result->items;
                $collected += count($currentItems);
                $collection[] = $currentItems;

                if ($result->totalItems > $collected)
                {
                    $offset++;
                }
                else
                {
                    $finished = true;
                }
            }
            else
            {
                $finished = true;
            }
        }

        $items = array_merge(...$collection);

        return ModelHelper::populateArrayWithModels(
            $items,
            Order::class
        );
    }

    /**
     * Gets the notifications Snipcart has sent regarding a specific order.
     *
     * @param string $orderId Snipcart order ID
     *
     * @return Notification[]
     * @throws \Exception if our API key is missing.
     */
    public function getOrderNotifications($orderId): array
    {
        return ModelHelper::populateArrayWithModels(
            (array)Snipcart::$plugin->api->get(sprintf(
                'orders/%s/notifications',
                $orderId
            )),
            Notification::class
        );
    }

    /**
     * Gets a Snipcart order's refunds.
     *
     * @param string $orderId Snipcart order ID
     *
     * @return Refund[]
     * @throws \Exception if our API key is missing.
     */
    public function getOrderRefunds($orderId): array
    {
        return ModelHelper::populateArrayWithModels(
            (array)Snipcart::$plugin->api->get(sprintf(
                'orders/%s/refunds',
                $orderId
            )),
            Refund::class
        );
    }

    /**
     * Gets Snipcart orders with pagination info.
     *
     * @param int    $page   Page of results
     * @param int    $limit  Number of results per page
     * @param array  $params Parameters to send with the request
     *
     * @return \stdClass
     *              ->items (Order[])
     *              ->totalItems (int)
     *              ->offset (int)
     *              ->limit (int)
     * @throws \Exception if our API key is missing.
     */
    public function listOrders($page = 1, $limit = 25, $params = []): \stdClass
    {
        /**
         * define offset and limit since that's pretty much all we're doing here
         */
        $params['offset'] = ($page - 1) * $limit;
        $params['limit']  = $limit;

        $response = $this->_fetchOrders($params);

        return (object) [
            'items' => ModelHelper::populateArrayWithModels(
                $response->items,
                Order::class
            ),
            'totalItems' => $response->totalItems,
            'offset' => $response->offset,
            'limit' => $limit
        ];
    }

    /**
     * Gets Craft Elements that relate to order items, updating quantities
     * and sending a notification if relevant.
     *
     * @param Order $order
     *
     * @return bool|array true if successful, or an array of notification errors
     * @throws
     */
    public function updateElementsFromOrder(Order $order)
    {
        if (Snipcart::$plugin->getSettings()->reduceQuantitiesOnOrder)
        {
            foreach ($order->items as $item)
            {
                if ($item->getRelatedElement())
                {
                    Snipcart::$plugin->products->reduceProductInventory(
                        $item->getRelatedElement(),
                        $item->quantity
                    );
                    // TODO: reduce product inventory in ShipStation if necessary
                }
            }
        }

        return true;
    }

    /**
     * Triggers an Event that will allow another plugin or module to provide
     * packaging details and/or modify an order before shipping rates
     * are requested for that order.
     *
     * @param Order $order
     *
     * @return Package
     */
    public function getOrderPackaging(Order $order): Package
    {
        $package = new Package();

        if ($this->hasEventHandlers(self::EVENT_BEFORE_REQUEST_SHIPPING_RATES))
        {
            $event = new ShippingRateEvent([
                'order'   => $order,
                'package' => $package
            ]);
            $this->trigger(self::EVENT_BEFORE_REQUEST_SHIPPING_RATES, $event);
            $package = $event->package;
        }

        return $package;
    }

    /**
     * Sends email order notifications.
     *
     * @param Order  $order The relevant Snipcart order.
     * @param array  $extra Additional variables for email template.
     * @param string $type  Either `admin` or `customer`
     *
     * @return array|bool
     * @throws \Throwable if there's a template mode exception.
     */
    public function sendOrderEmailNotification($order, $extra = [], $type = self::NOTIFICATION_TYPE_ADMIN)
    {
        $templateSettings = $this->_selectNotificationTemplate($type);
        $emailVars = array_merge([
            'order'    => $order,
            'settings' => Snipcart::$plugin->getSettings()
        ], $extra);

        Snipcart::$plugin->notifications->setEmailTemplate($templateSettings['path']);
        Snipcart::$plugin->notifications->setNotificationVars($emailVars);

        $toEmails = [];
        $subject = $order->billingAddressName . ' just placed an order';

        if ($type === self::NOTIFICATION_TYPE_ADMIN)
        {
            $toEmails = Snipcart::$plugin->getSettings()->notificationEmails;
        }
        elseif ($type === self::NOTIFICATION_TYPE_CUSTOMER)
        {
            $toEmails = [ $order->email ];
            $subject = sprintf('%s Order #%s',
                Craft::$app->getSites()->getCurrentSite()->name,
                $order->invoiceNumber
            );
        }

        if ( ! Snipcart::$plugin->notifications->sendEmail($toEmails, $subject))
        {
            return Snipcart::$plugin->notifications->getErrors();
        }

        return true;
    }

    /**
     * Creates a refund for an order.
     *
     * @param string $orderId         The order ID
     * @param float  $amount          Amount to be refunded
     * @param string $comment         Reason for the refund
     * @param bool   $notifyCustomer  Whether Snipcart should send
     *                                a notification to the customer
     *
     * @return mixed
     * @throws \Exception if our API key is missing.
     */
    public function refundOrder($orderId, $amount, $comment = '', $notifyCustomer = false)
    {
        $refund = new Refund([
            'orderToken'     => $orderId,
            'amount'         => $amount,
            'comment'        => $comment,
            'notifyCustomer' => $notifyCustomer,
        ]);

        $response = Snipcart::$plugin->api->post(
            sprintf('orders/%s/refunds', $orderId),
            $refund->getPayloadForPost()
        );

        return $response;
    }

    // Private Methods
    // =========================================================================

    /**
     * Queries the API for orders with the provided parameters.
     * Invalid parameters are ignored and not sent to Snipcart.
     *
     * @param array $params
     *
     * @return \stdClass|array API response object or array of objects.
     * @throws \Exception if our API key is missing.
     */
    private function _fetchOrders($params = [])
    {
        $validParams = [
            'offset',
            'limit',
            'from',
            'to',
            'status',
            'invoiceNumber',
            'placedBy',
        ];

        $apiParams      = [];
        $hasCacheParam  = isset($params['cache']) && is_bool($params['cache']);
        $cacheSetting   = $hasCacheParam ? $params['cache'] : true;
        $dateTimeFormat = 'Y-m-d\TH:i:sP';

        if (isset($params['from']) && $params['from'] instanceof \DateTime)
        {
            $params['from'] = $params['from']->format($dateTimeFormat);
        }

        if (isset($params['to']) && $params['to'] instanceof \DateTime)
        {
            $params['to'] = $params['to']->format($dateTimeFormat);
        }

        foreach ($params as $key => $value)
        {
            if (in_array($key, $validParams, true))
            {
                $apiParams[$key] = $value;
            }
        }

        return Snipcart::$plugin->api->get(
            'orders',
            $apiParams,
            $cacheSetting
        );
    }

    /**
     * Selects whatever Twig template should be used for an order notification,
     * and if it's a custom template make sure it exists before relying on it.
     *
     * @param string $type `admin` or `customer`
     *
     * @return array       Returns an array with a `path` string property
     *                     and `user` bool which is true if the template exists
     *                     on the front end—false if it's scoped to the plugin
     */
    private function _selectNotificationTemplate($type): array
    {
        $settings = Snipcart::$plugin->getSettings();
        $defaultTemplatePath = '';
        $customTemplatePath = '';

        if ($type === self::NOTIFICATION_TYPE_ADMIN)
        {
            $defaultTemplatePath = 'snipcart/email/order';
            $customTemplatePath  = $settings->notificationEmailTemplate;
        }
        elseif ($type === self::NOTIFICATION_TYPE_CUSTOMER)
        {
            $defaultTemplatePath = 'snipcart/email/customer-order';
            $customTemplatePath  = $settings->notificationEmailTemplate;
        }

        $useCustom = ! empty($customTemplatePath);
        $templatePath = $useCustom ? $customTemplatePath : $defaultTemplatePath;

        return [
            'path' => $templatePath,
            'user' => $useCustom
        ];
    }

}
