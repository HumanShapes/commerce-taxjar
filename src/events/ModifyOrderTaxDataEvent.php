<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\taxjar\events;
use craft\commerce\elements\Order;

use yii\base\Event;

/**
 * ModifyOrderTaxDataEvent class.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.2
 */
class ModifyOrderTaxDataEvent extends Event
{
    /**
     * @var array The order tax data allowed to be modified
     */
    public $orderData;

    /**
     * @var Order Order
     */
    public $order;
}
