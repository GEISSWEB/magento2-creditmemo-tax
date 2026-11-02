<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Model\Creditmemo\Basis;

/**
 * Item or shipping amount including tax that weights the adjustment tax per rate.
 */
class Line
{
    /**
     * @param float $gross
     * @param float $baseGross
     * @param array<string, Rate> $rates Keyed by tax code
     */
    public function __construct(
        public readonly float $gross,
        public readonly float $baseGross,
        public readonly array $rates
    ) {
    }

    /**
     * @return float
     */
    public function getNet(): float
    {
        return $this->gross - array_sum(array_map(static fn (Rate $rate): float => $rate->amount, $this->rates));
    }

    /**
     * @return float
     */
    public function getBaseNet(): float
    {
        return $this->baseGross
            - array_sum(array_map(static fn (Rate $rate): float => $rate->baseAmount, $this->rates));
    }
}
