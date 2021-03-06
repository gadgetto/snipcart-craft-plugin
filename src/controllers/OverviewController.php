<?php
/**
 * Snipcart plugin for Craft CMS 3.x
 *
 * @link      https://workingconcept.com
 * @copyright Copyright (c) 2018 Working Concept Inc.
 */

namespace workingconcept\snipcart\controllers;

use workingconcept\snipcart\Snipcart;
use workingconcept\snipcart\helpers\FormatHelper;
use craft\helpers\DateTimeHelper;
use DateTimeZone;
use DateTime;
use Craft;

class OverviewController extends \craft\web\Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Display store overview.
     * @return \yii\web\Response
     * @throws
     */
    public function actionIndex(): \yii\web\Response
    {
        if ( ! Snipcart::$plugin->getSettings()->isConfigured())
        {
            return $this->renderTemplate('snipcart/cp/welcome');
        }

        return $this->renderTemplate(
            'snipcart/cp/index',
            $this->_getOrderAndCustomerSummary()
        );
    }

    /**
     * Get the stats for the top panels.
     * @return \yii\web\Response
     * @throws
     */
    public function actionGetStats(): \yii\web\Response
    {
        return $this->asJson(
            $this->_getOverviewStats(true)
        );
    }

    /**
     * Get the data for the recent order and top customer summary tables.
     * @return \yii\web\Response
     * @throws \yii\base\InvalidConfigException
     */
    public function actionGetOrdersCustomers(): \yii\web\Response
    {
        return $this->asJson(
            $this->_getOrderAndCustomerSummary(true)
        );
    }


    // Private Methods
    // =========================================================================

    /**
     * Get store statistics for the Snipcart landing/overview.
     *
     * @param bool $preformat
     * @return array
     * @throws \yii\base\InvalidConfigException
     */
    private function _getOverviewStats($preformat = false): array
    {
        $startDate = $this->_getStartDate();
        $endDate   = $this->_getEndDate();
        $stats     = Snipcart::$plugin->data->getPerformance($startDate, $endDate);

        if ($preformat)
        {
            $defaultCurrency = Snipcart::$plugin->getSettings()->defaultCurrency;

            $stats->ordersCount = number_format($stats->ordersCount);
            $stats->ordersSales = FormatHelper::formatCurrency(
                $stats->ordersSales,
                $defaultCurrency
            );
            $stats->averageOrdersValue = FormatHelper::formatCurrency(
                $stats->averageOrdersValue,
                $defaultCurrency
            );
            $stats->averageCustomerValue = FormatHelper::formatCurrency(
                $stats->averageCustomerValue,
                $defaultCurrency
            );
        }
        
        return [ 'stats' => $stats ];
    }

    /**
     * Get recent order and top customer statistics.
     *
     * @param bool $preformat
     * @return array
     * @throws \yii\base\InvalidConfigException
     */
    private function _getOrderAndCustomerSummary($preformat = false): array
    {
        $startDate = $this->_getStartDate();
        $endDate   = $this->_getEndDate();
        $orders    = Snipcart::$plugin->orders->listOrders(1, 10);
        $customers = Snipcart::$plugin->customers->listCustomers(1, 10, [
            'orderBy' => 'ordersValue'
        ]);

        if ($preformat)
        {
            foreach ($orders->items as &$item)
            {
                // TODO: see if there's a better way to attach dynamic fields
                $item = $item->toArray(
                    ['id', 'invoiceNumber', 'creationDate', 'finalGrandTotal'],
                    ['cpUrl', 'billingAddressName']
                );

                $item['creationDate'] = DateTimeHelper::toDateTime($item['creationDate'])->format('n/j');
                $item['finalGrandTotal'] = FormatHelper::formatCurrency($item['finalGrandTotal']);
            }

            foreach ($customers->items as &$item)
            {
                // TODO: see if there's a better way to attach dynamic fields
                $item = $item->toArray(
                    ['id', 'billingAddressName', 'statistics'],
                    ['cpUrl']
                );

                $item['statistics']['ordersCount'] = number_format($item['statistics']['ordersCount']);
                $item['statistics']['ordersAmount'] = FormatHelper::formatCurrency($item['statistics']['ordersAmount']);
            }
        }
        
        return [
            'startDate' => $startDate,
            'endDate'   => $endDate,
            'orders'    => $orders,
            'customers' => $customers,
        ];
    }

    /**
     * Get the beginning of the range used for visualizing stats.
     * @return DateTime
     * @throws
     */
    private function _getStartDate(): DateTime
    {
        $startDateParam = Craft::$app->getRequest()->getParam('startDate');

        if ($startDateParam && is_string($startDateParam))
        {
            return new DateTime($startDateParam);
        }

        return (new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone())))
            ->modify('-1 month');
    }

    /**
     * Get the end of the range used for visualizing stats.
     * @return DateTime
     * @throws
     */
    private function _getEndDate(): DateTime
    {
        $endDateParam = Craft::$app->getRequest()->getParam('endDate');

        if ($endDateParam && is_string($endDateParam))
        {
            return new DateTime($endDateParam);
        }

        return new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone()));
    }
}