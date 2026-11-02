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
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Model\ResourceModel\Order\Invoice as InvoiceResource;

/**
 * Limits the tax refunded on an invoice, which caps the core credit memo tax, to item and shipping tax.
 */
class ExcludeAdjustmentTaxFromInvoiceRefunded
{
    /**
     * @param AdjustmentTaxResource $adjustmentTaxResource
     */
    public function __construct(
        private readonly AdjustmentTaxResource $adjustmentTaxResource
    ) {
    }

    /**
     * @param InvoiceResource $subject
     * @param float $result
     * @param int $invoiceId
     * @param string $field
     * @return float
     */
    public function afterCalculateRefundedAmount(
        InvoiceResource $subject,
        float $result,
        int $invoiceId,
        string $field
    ): float {
        if ($field === CreditmemoInterface::TAX_AMOUNT) {
            return $result - $this->adjustmentTaxResource->getInvoiceAdjustmentTax($invoiceId)[0];
        }
        if ($field === CreditmemoInterface::BASE_TAX_AMOUNT) {
            return $result - $this->adjustmentTaxResource->getInvoiceAdjustmentTax($invoiceId)[1];
        }
        return $result;
    }
}
