<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Block\Sales\Creditmemo;

use Geissweb\CreditmemoTax\Model\ResourceModel\AdjustmentTax as AdjustmentTaxResource;
use Magento\Framework\DataObject;
use Magento\Framework\Phrase;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Element\Context;
use Magento\Sales\Block\Order\Totals;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Store\Model\Store;
use Magento\Tax\Model\Config as TaxConfig;

/**
 * Shows the credit memo adjustments excluding and/or including tax like the subtotal.
 *
 * Child of the credit memo totals block, placed after the tax totals block.
 */
class AdjustmentTotals extends AbstractBlock
{
    /**
     * @param Context $context
     * @param TaxConfig $taxConfig
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly TaxConfig $taxConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return $this
     */
    public function initTotals(): self
    {
        $parent = $this->getParentBlock();
        if (!$parent instanceof Totals) {
            return $this;
        }
        $source = $parent->getSource();
        if (!$source instanceof Creditmemo) {
            return $this;
        }

        $positiveTax = (float)$source->getData(AdjustmentTaxResource::POSITIVE_TAX);
        $basePositiveTax = (float)$source->getData(AdjustmentTaxResource::BASE_POSITIVE_TAX);
        $negativeTax = (float)$source->getData(AdjustmentTaxResource::NEGATIVE_TAX);
        $baseNegativeTax = (float)$source->getData(AdjustmentTaxResource::BASE_NEGATIVE_TAX);
        if ($positiveTax === 0.0 && $basePositiveTax === 0.0 && $negativeTax === 0.0 && $baseNegativeTax === 0.0) {
            return $this;
        }

        $store = $parent->getOrder()->getStore();
        $this->correctEstimatedSubtotalInclTax(
            $parent,
            $source,
            $store,
            $positiveTax - $negativeTax,
            $basePositiveTax - $baseNegativeTax
        );
        $this->showAdjustment(
            $parent,
            $store,
            'adjustment_positive',
            $positiveTax,
            $basePositiveTax,
            __('Adjustment Refund (Excl. Tax)'),
            __('Adjustment Refund (Incl. Tax)')
        );
        $this->showAdjustment(
            $parent,
            $store,
            'adjustment_negative',
            $negativeTax,
            $baseNegativeTax,
            __('Adjustment Fee (Excl. Tax)'),
            __('Adjustment Fee (Incl. Tax)')
        );

        return $this;
    }

    /**
     * Removes the adjustment tax from the subtotal including tax that the tax totals block estimates.
     *
     * Without a stored subtotal including tax, as on credit memos without items, the tax totals block
     * estimates it as subtotal + tax - shipping tax.
     *
     * @param Totals $parent
     * @param Creditmemo $source
     * @param Store $store
     * @param float $adjustmentTax
     * @param float $baseAdjustmentTax
     * @return void
     */
    private function correctEstimatedSubtotalInclTax(
        Totals $parent,
        Creditmemo $source,
        Store $store,
        float $adjustmentTax,
        float $baseAdjustmentTax
    ): void {
        $estimated = !(float)$source->getSubtotalInclTax();
        $baseEstimated = !(float)$source->getBaseSubtotalInclTax();
        if ($this->taxConfig->displaySalesSubtotalBoth($store)) {
            $code = 'subtotal_incl';
            $estimated = $baseEstimated = $estimated || $baseEstimated;
        } elseif ($this->taxConfig->displaySalesSubtotalInclTax($store)) {
            $code = 'subtotal';
        } else {
            return;
        }

        $total = $parent->getTotal($code);
        if (!$total) {
            return;
        }
        if ($estimated) {
            $total->setValue(max(
                0.0,
                (float)$source->getSubtotal() + (float)$source->getTaxAmount()
                - (float)$source->getShippingTaxAmount() - $adjustmentTax
            ));
        }
        if ($baseEstimated && $total->getBaseValue() !== null) {
            $total->setBaseValue(max(
                0.0,
                (float)$source->getBaseSubtotal() + (float)$source->getBaseTaxAmount()
                - (float)$source->getBaseShippingTaxAmount() - $baseAdjustmentTax
            ));
        }
    }

    /**
     * @param Totals $parent
     * @param Store $store
     * @param string $code
     * @param float $tax
     * @param float $baseTax
     * @param Phrase $exclTaxLabel
     * @param Phrase $inclTaxLabel
     * @return void
     */
    private function showAdjustment(
        Totals $parent,
        Store $store,
        string $code,
        float $tax,
        float $baseTax,
        Phrase $exclTaxLabel,
        Phrase $inclTaxLabel
    ): void {
        $total = $parent->getTotal($code);
        if (!$total || ($tax === 0.0 && $baseTax === 0.0)) {
            return;
        }

        if ($this->taxConfig->displaySalesSubtotalBoth($store)) {
            $inclTax = new DataObject([
                'code' => "{$code}_incl",
                'value' => $total->getValue(),
                'label' => $inclTaxLabel,
            ]);
            if ($total->getBaseValue() !== null) {
                $inclTax->setBaseValue($total->getBaseValue());
            }
            $this->deductTax($total, $tax, $baseTax);
            $total->setLabel($exclTaxLabel);
            $parent->addTotal($inclTax, $code);
            return;
        }
        if (!$this->taxConfig->displaySalesSubtotalInclTax($store)) {
            $this->deductTax($total, $tax, $baseTax);
        }
    }

    /**
     * @param DataObject $total
     * @param float $tax
     * @param float $baseTax
     * @return void
     */
    private function deductTax(DataObject $total, float $tax, float $baseTax): void
    {
        $total->setValue((float)$total->getValue() - $tax);
        if ($total->getBaseValue() !== null) {
            $total->setBaseValue((float)$total->getBaseValue() - $baseTax);
        }
    }
}
