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
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Tax\Helper\Data as TaxHelper;

/**
 * Adds the adjustment tax per rate to the tax summary of a credit memo (admin, storefront, email, PDF, GraphQL).
 *
 * Rates without item or shipping tax are appended with the percent formatted like the stored order tax items.
 */
class AddAdjustmentTaxToCalculatedTaxes
{
    /**
     * @param AdjustmentTaxResource $adjustmentTaxResource
     * @param PriceCurrencyInterface $priceCurrency
     */
    public function __construct(
        private readonly AdjustmentTaxResource $adjustmentTaxResource,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
    }

    /**
     * @param TaxHelper $subject
     * @param array<int, array<string, mixed>> $result
     * @param mixed $source
     * @return array<int, array<string, mixed>>
     */
    public function afterGetCalculatedTaxes(TaxHelper $subject, array $result, mixed $source): array
    {
        if (!$source instanceof Creditmemo) {
            return $result;
        }

        foreach ($this->getRows($source) as $row) {
            if ($row['amount'] === 0.0 && $row['base_amount'] === 0.0) {
                continue;
            }
            $index = $this->findRate($result, $row['title'], $row['percent']);
            if ($index === null) {
                $result[] = [
                    'title' => $row['title'],
                    'percent' => sprintf('%.4F', $row['percent']),
                    'tax_amount' => $row['amount'],
                    'base_tax_amount' => $row['base_amount'],
                ];
                continue;
            }
            $result[$index]['tax_amount'] = $this->priceCurrency->round(
                (float)$result[$index]['tax_amount'] + $row['amount']
            );
            $result[$index]['base_tax_amount'] = $this->priceCurrency->round(
                (float)$result[$index]['base_tax_amount'] + $row['base_amount']
            );
        }

        return array_values(array_filter(
            $result,
            static fn (array $tax): bool => (float)$tax['tax_amount'] !== 0.0 || (float)$tax['base_tax_amount'] !== 0.0
        ));
    }

    /**
     * @param Creditmemo $creditmemo
     * @return array<int, array{type: string, code: string, title: string, percent: float, amount: float,
     *     base_amount: float, taxable_amount: float, base_taxable_amount: float}>
     */
    private function getRows(Creditmemo $creditmemo): array
    {
        if ($creditmemo->hasData(AdjustmentTaxResource::ROWS)) {
            return $creditmemo->getData(AdjustmentTaxResource::ROWS);
        }
        return $creditmemo->getId() ? $this->adjustmentTaxResource->getRows((int)$creditmemo->getId()) : [];
    }

    /**
     * @param array<int, array<string, mixed>> $taxes
     * @param string $title
     * @param float $percent
     * @return int|null
     */
    private function findRate(array $taxes, string $title, float $percent): ?int
    {
        foreach ($taxes as $index => $tax) {
            if ($tax['title'] === $title && (float)$tax['percent'] === $percent) {
                return $index;
            }
        }
        return null;
    }
}
