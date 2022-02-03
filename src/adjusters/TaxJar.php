<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\taxjar\adjusters;

use Craft;
use craft\base\Component;
use craft\commerce\adjusters\Shipping;
use craft\commerce\base\AdjusterInterface;
use craft\commerce\elements\Order;
use craft\commerce\models\Address;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\Plugin;
use craft\commerce\taxjar\TaxJar as TaxJarPlugin;
use craft\commerce\taxjar\events\SetAddressForTaxEvent;
use yii\base\Event;
use DvK\Vat\Validator;
use TaxJar\Exception;

/**
 * Tax Adjustments
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 *
 * @property Validator $vatValidator
 */
class TaxJar extends Component implements AdjusterInterface
{
    // Constants
    // =========================================================================

    const ADJUSTMENT_TYPE = 'tax';
    const SET_ADDRESS_FOR_TAX_EVENT = 'setAddressForTaxEvent';

    // Properties
    // =========================================================================

    /**
     * @var Order
     */
    private $_order;

    /**
     * @var Address
     */
    private $_address;

    /**
     * @var Address
     */
    private $_fromAddress;

    /**
     * @var mixed
     */
    private $_taxesByOrderHash;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function adjust(Order $order): array
    {
        $this->_order = $order;

        $this->_address = $this->_order->getShippingAddress();

        if (Plugin::getInstance()->getSettings()->useBillingAddressForTax) {
            $this->_address = $this->_order->getBillingAddress();
        }

        if (!$this->_address) {
            $this->_address = $order->getEstimatedShippingAddress();
        }

        if (Plugin::getInstance()->getSettings()->useBillingAddressForTax) {
            if (!$this->_address) {
                $this->_address = $this->_order->getEstimatedBillingAddress();
            }
        }

        // NOTE: (Cody / Noihsaf Change) Make sure we allow the fromAddress to be set and updated
        $event = new SetAddressForTaxEvent([
            'order' => $this->_order,
            'address' => $this->_address,
            'fromAddress' => $this->_fromAddress
        ]);

        Event::trigger(static::class, self::SET_ADDRESS_FOR_TAX_EVENT, $event);

        $this->_address = $event->address;
        $this->_fromAddress = $event->fromAddress;

        if (!$this->_address || !$this->_order->getLineItems()) {
            return [];
        }

        try {
            $orderTaxes = $this->_getOrderTaxData();
        } catch (Exception $e) {
            $message = 'TaxJar API error code: ' . $e->getStatusCode() . ' Message: ' . $e->getMessage();
            Craft::error($message, 'commerce-taxjar');

            if (TaxJarPlugin::getInstance()->getSettings()->useSandbox) {
                $adjustment = new OrderAdjustment();
                $adjustment->type = self::ADJUSTMENT_TYPE;
                $adjustment->name = Craft::t('commerce', 'TaxJar Error');
                $adjustment->amount = 0;
                $adjustment->description = $message;
                $adjustment->setOrder($this->_order);
                $adjustment->sourceSnapshot = [];

                return [$adjustment];
            }
            return [];
        }

        $adjustment = new OrderAdjustment();
        $adjustment->type = self::ADJUSTMENT_TYPE;
        $adjustment->name = Craft::t('commerce', 'Sales Tax');
        $adjustment->amount = $orderTaxes->amount_to_collect;
        $adjustment->description = "Combined tax rate {$this->_getPercent($orderTaxes->rate)}";
        $adjustment->setOrder($this->_order);
        $adjustment->sourceSnapshot = json_decode(json_encode($orderTaxes), true);

        return [$adjustment];
    }

    /**
     * @param Order $order
     */
    private function _getOrderHash()
    {
        $number = $this->_order->number;
        $lineItems = '';
        $address = '';
        $count = 0;
        foreach ($this->_order->getLineItems() as $item) {
            $count++;
            // NOTE: (Cody / Noihsaf Change) Make sure $item->taxCategoryId is being accounted for here (when pulling in changes from upstream)
            $lineItems = $count . ':' . $item->getOptionsSignature() . ':' . $item->qty . ':' . $item->getSubtotal() . ':' . $item->taxCategoryId;
        }
        $price = $this->_order->getTotalPrice();

        if ($this->_address) {
            $address .= $this->_address->address1;
            $address .= $this->_address->address2;
            $address .= $this->_address->address3;
            $address .= $this->_address->zipCode;
            $address .= $this->_address->stateText;
            $address .= $this->_address->countryText;
        }

        return md5($number . ':' . $lineItems . ':' . $address . ':' . $price);
    }

    private function _getOrderTaxData()
    {
        $orderHash = $this->_getOrderHash();

        // Do we already have it on this request?
        if (isset($this->_taxesByOrderHash[$orderHash]) && $this->_taxesByOrderHash[$orderHash] != false) {
            return $this->_taxesByOrderHash[$orderHash];
        }

        $cacheKey = 'taxjar-' . $orderHash;
        // Is it in the cache? if not, get it from the api.
        $orderData = Craft::$app->getCache()->get($cacheKey);

        if (!$orderData) {
            $api = TaxJarPlugin::getInstance()->getApi();

            $orderParams = array_merge(
                $api->getFromParams($this->_fromAddress),
                $api->getToParams($this->_address),
                $api->getAmountsParams($this->_order, false)
            );

            $orderData = $api->getClient()->taxForOrder($orderParams);

            // Save data into cache
            Craft::$app->getCache()->set($cacheKey, $orderData);
        }

        $this->_taxesByOrderHash[$orderHash] = $orderData;

        return $this->_taxesByOrderHash[$orderHash];
    }

    /**
     * @param float $rate
     * @return string
     */
    private function _getPercent(float $rate): string
    {
        return ($rate * 100) . '%';
    }
}
