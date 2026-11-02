<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Model\Creditmemo;

/**
 * Tax contained in the adjustments of a credit memo, in total and per rate.
 *
 * Rows of the fee are negative. Per type the taxable amounts plus the tax amounts of the rows make up the
 * adjustment, the untaxed share being a row without tax code.
 */
class Allocation
{
    /**
     * @param float $positiveTax
     * @param float $basePositiveTax
     * @param float $negativeTax
     * @param float $baseNegativeTax
     * @param array<int, array{type: string, code: string, title: string, percent: float, amount: float,
     *     base_amount: float, taxable_amount: float, base_taxable_amount: float}> $rows
     */
    public function __construct(
        public readonly float $positiveTax,
        public readonly float $basePositiveTax,
        public readonly float $negativeTax,
        public readonly float $baseNegativeTax,
        public readonly array $rows
    ) {
    }
}
