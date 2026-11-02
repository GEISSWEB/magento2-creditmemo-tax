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
use Magento\Sales\Model\Order\Pdf\Total\DefaultTotal;

/**
 * PDF total of the adjustment refund or the adjustment fee, excluding and/or including tax like the subtotal.
 */
class Adjustment extends DefaultTotal
{
    /**
     * @return array<int, array{amount: string, label: string, font_size: int}>
     */
    public function getTotalsForDisplay()
    {
        $isFee = $this->getSourceField() === 'adjustment_negative';
        $tax = (float)$this->getSource()->getData(
            $isFee ? AdjustmentTaxResource::NEGATIVE_TAX : AdjustmentTaxResource::POSITIVE_TAX
        );
        if ($tax === 0.0) {
            return parent::getTotalsForDisplay();
        }

        $store = $this->getOrder()->getStore();
        $amount = (float)$this->getAmount();
        if ($this->_taxHelper->displaySalesSubtotalBoth($store)) {
            return [
                $this->row(
                    $amount - $tax,
                    $isFee ? __('Adjustment Fee (Excl. Tax)') : __('Adjustment Refund (Excl. Tax)')
                ),
                $this->row($amount, $isFee ? __('Adjustment Fee (Incl. Tax)') : __('Adjustment Refund (Incl. Tax)')),
            ];
        }

        return [
            $this->row(
                $this->_taxHelper->displaySalesSubtotalInclTax($store) ? $amount : $amount - $tax,
                __($this->getTitle())
            ),
        ];
    }

    /**
     * @param float $amount
     * @param \Stringable|string $label
     * @return array{amount: string, label: string, font_size: int}
     */
    private function row(float $amount, \Stringable|string $label): array
    {
        return [
            'amount' => $this->getAmountPrefix() . $this->getOrder()->formatPriceTxt($amount),
            'label' => $label . ':',
            'font_size' => (int)($this->getFontSize() ?: 7),
        ];
    }
}
