<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Model\Sales\Pdf;

use Geissweb\CreditmemoTax\Model\ResourceModel\AdjustmentTax as AdjustmentTaxResource;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Tax\Model\Sales\Pdf\Subtotal as TaxSubtotal;

/**
 * PDF subtotal whose estimated amount including tax leaves out the credit memo adjustment tax.
 *
 * Without a stored subtotal including tax, as on credit memos without items, the amount including tax is
 * estimated as subtotal + tax - shipping tax.
 */
class Subtotal extends TaxSubtotal
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTotalsForDisplay()
    {
        $totals = parent::getTotalsForDisplay();
        $source = $this->getSource();
        if (!$source instanceof Creditmemo || (float)$source->getSubtotalInclTax()) {
            return $totals;
        }
        $adjustmentTax = (float)$source->getData(AdjustmentTaxResource::POSITIVE_TAX)
            - (float)$source->getData(AdjustmentTaxResource::NEGATIVE_TAX);
        if ($adjustmentTax === 0.0) {
            return $totals;
        }

        $store = $this->getOrder()->getStore();
        if ($this->_taxHelper->displaySalesSubtotalBoth($store)) {
            $index = 1;
        } elseif ($this->_taxHelper->displaySalesSubtotalInclTax($store)) {
            $index = 0;
        } else {
            return $totals;
        }

        $amountInclTax = (float)$this->getAmount() + (float)$source->getTaxAmount()
            - (float)$source->getShippingTaxAmount() - $adjustmentTax;
        $totals[$index]['amount'] = $this->getAmountPrefix() . $this->getOrder()->formatPriceTxt($amountInclTax);

        return $totals;
    }
}
