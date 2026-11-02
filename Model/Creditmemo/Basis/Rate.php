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
 * Tax of one rate carried by a basis line.
 */
class Rate
{
    /**
     * @param string $code
     * @param string $title
     * @param float $percent
     * @param float $amount
     * @param float $baseAmount
     */
    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly float $percent,
        public readonly float $amount,
        public readonly float $baseAmount
    ) {
    }
}
