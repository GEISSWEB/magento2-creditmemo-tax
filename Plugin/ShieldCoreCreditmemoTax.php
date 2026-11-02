<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Plugin;

use Geissweb\CreditmemoTax\Model\ResourceModel\AdjustmentTax as AdjustmentTaxResource;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Total\Tax;

/**
 * Lets the core tax collector cap the item and shipping tax by the refunded item and shipping tax only.
 *
 * The order tax refunded includes the adjustment tax of earlier credit memos. While the core collector runs,
 * that adjustment tax is deducted from the order tax refunded and restored afterwards.
 */
class ShieldCoreCreditmemoTax
{
    /**
     * @param AdjustmentTaxResource $adjustmentTaxResource
     */
    public function __construct(
        private readonly AdjustmentTaxResource $adjustmentTaxResource
    ) {
    }

    /**
     * @param Tax $subject
     * @param callable $proceed
     * @param Creditmemo $creditmemo
     * @return Tax
     */
    public function aroundCollect(Tax $subject, callable $proceed, Creditmemo $creditmemo): Tax
    {
        $order = $creditmemo->getOrder();
        [$adjustmentTax, $baseAdjustmentTax] = $this->adjustmentTaxResource->getOrderAdjustmentTax(
            (int)$order->getId()
        );
        if ($adjustmentTax === 0.0 && $baseAdjustmentTax === 0.0) {
            return $proceed($creditmemo);
        }

        $taxRefunded = $order->getData(OrderInterface::TAX_REFUNDED);
        $baseTaxRefunded = $order->getData(OrderInterface::BASE_TAX_REFUNDED);
        $order->setData(OrderInterface::TAX_REFUNDED, (float)$taxRefunded - $adjustmentTax);
        $order->setData(OrderInterface::BASE_TAX_REFUNDED, (float)$baseTaxRefunded - $baseAdjustmentTax);
        try {
            return $proceed($creditmemo);
        } finally {
            $order->setData(OrderInterface::TAX_REFUNDED, $taxRefunded);
            $order->setData(OrderInterface::BASE_TAX_REFUNDED, $baseTaxRefunded);
        }
    }
}
