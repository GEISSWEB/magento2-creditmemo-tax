<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Model\Creditmemo\Total;

use Geissweb\CreditmemoTax\Model\Creditmemo\AdjustmentTaxAllocator;
use Geissweb\CreditmemoTax\Model\Creditmemo\Basis\Line;
use Geissweb\CreditmemoTax\Model\Creditmemo\Basis\LineBuilder;
use Geissweb\CreditmemoTax\Model\ResourceModel\AdjustmentTax as AdjustmentTaxResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Total\AbstractTotal;
use Magento\Store\Model\ScopeInterface;

/**
 * Adds the tax contained in the gross adjustment refund and adjustment fee to the credit memo tax.
 *
 * Runs after the tax collector and before the grand total collector. The grand total stays unchanged because
 * the grand total collector adds the adjustments as entered, tax included.
 */
class AdjustmentTax extends AbstractTotal
{
    public const XML_PATH_ENABLED = 'tax/creditmemo_adjustments/calculate_tax';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param LineBuilder $lineBuilder
     * @param AdjustmentTaxAllocator $allocator
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LineBuilder $lineBuilder,
        private readonly AdjustmentTaxAllocator $allocator,
        array $data = []
    ) {
        parent::__construct($data);
    }

    /**
     * @param Creditmemo $creditmemo
     * @return $this
     */
    public function collect(Creditmemo $creditmemo)
    {
        $positive = (float)$creditmemo->getAdjustmentPositive();
        $negative = (float)$creditmemo->getAdjustmentNegative();
        if (($positive === 0.0 && $negative === 0.0)
            || !$this->scopeConfig->isSetFlag(
                self::XML_PATH_ENABLED,
                ScopeInterface::SCOPE_STORE,
                $creditmemo->getStoreId()
            )
        ) {
            return $this;
        }

        $allocation = $this->allocator->allocate(
            $this->getLines($creditmemo),
            $positive,
            (float)$creditmemo->getBaseAdjustmentPositive(),
            $negative,
            (float)$creditmemo->getBaseAdjustmentNegative()
        );

        $creditmemo->setData(AdjustmentTaxResource::POSITIVE_TAX, $allocation->positiveTax);
        $creditmemo->setData(AdjustmentTaxResource::BASE_POSITIVE_TAX, $allocation->basePositiveTax);
        $creditmemo->setData(AdjustmentTaxResource::NEGATIVE_TAX, $allocation->negativeTax);
        $creditmemo->setData(AdjustmentTaxResource::BASE_NEGATIVE_TAX, $allocation->baseNegativeTax);
        $creditmemo->setData(AdjustmentTaxResource::ROWS, $allocation->rows);

        $creditmemo->setTaxAmount(
            (float)$creditmemo->getTaxAmount() + $allocation->positiveTax - $allocation->negativeTax
        );
        $creditmemo->setBaseTaxAmount(
            (float)$creditmemo->getBaseTaxAmount() + $allocation->basePositiveTax - $allocation->baseNegativeTax
        );

        return $this;
    }

    /**
     * Lines of the credit memo, else the open order amounts, else the invoiced amounts.
     *
     * @param Creditmemo $creditmemo
     * @return Line[]
     */
    private function getLines(Creditmemo $creditmemo): array
    {
        return $this->lineBuilder->forCreditmemo($creditmemo)
            ?: $this->lineBuilder->forOpenAmounts($creditmemo)
            ?: $this->lineBuilder->forInvoiced($creditmemo);
    }
}
