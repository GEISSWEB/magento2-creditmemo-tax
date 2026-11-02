<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Model\Creditmemo;

use Geissweb\CreditmemoTax\Model\Creditmemo\Basis\Line;
use Geissweb\CreditmemoTax\Model\Creditmemo\Basis\Rate;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * Splits the gross adjustment refund and adjustment fee across the tax rates of the basis lines.
 *
 * Each adjustment is shared out by the gross amount per rate of the basis, the missing cents going to the largest
 * remainders, so the shares add up to the adjustment. Of each share the tax is taken first, as Magento does for
 * prices including tax: share × rate / (100 + rate), rounded. The taxable amount is the share minus the tax. The fee
 * of a rate takes the tax of the refund minus the tax of their balance, so refund and fee of a rate together carry
 * the tax of their balance, rounded once. The share of untaxed lines becomes a row without tax code.
 *
 * Rates splitting a line, as a proportionally taxed shipping, share the line by their taxable amount plus tax.
 * Rates added on the same amount, as state and county taxes, keep the line together, split its tax by their
 * amounts and each take the full taxable amount.
 */
class AdjustmentTaxAllocator
{
    public const TYPE_REFUND = 'refund';
    public const TYPE_FEE = 'fee';

    /**
     * @param PriceCurrencyInterface $priceCurrency
     */
    public function __construct(
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
    }

    /**
     * @param Line[] $lines
     * @param float $refund Adjustment refund
     * @param float $baseRefund
     * @param float $fee Adjustment fee
     * @param float $baseFee
     * @return Allocation
     */
    public function allocate(array $lines, float $refund, float $baseRefund, float $fee, float $baseFee): Allocation
    {
        $groups = $this->groups($lines);
        if (array_sum(array_column($groups, 'gross')) <= 0.0
            || array_sum(array_column($groups, 'base_gross')) <= 0.0
        ) {
            $groups = ['' => ['gross' => 1.0, 'base_gross' => 1.0, 'rates' => []]];
        }

        $refunds = $this->share($refund, array_column($groups, 'gross'));
        $baseRefunds = $this->share($baseRefund, array_column($groups, 'base_gross'));
        $fees = $this->share($fee, array_column($groups, 'gross'));
        $baseFees = $this->share($baseFee, array_column($groups, 'base_gross'));

        $refundRows = [];
        $feeRows = [];
        foreach (array_values($groups) as $index => $group) {
            $percent = array_sum(array_column($group['rates'], 'percent'));
            $refundTax = $this->taxOfShare($refunds[$index], $percent);
            $baseRefundTax = $this->taxOfShare($baseRefunds[$index], $percent);
            $feeTax = $this->priceCurrency->round(
                $refundTax - $this->taxOfShare($refunds[$index] - $fees[$index], $percent)
            );
            $baseFeeTax = $this->priceCurrency->round(
                $baseRefundTax - $this->taxOfShare($baseRefunds[$index] - $baseFees[$index], $percent)
            );

            $refundRows[] = $this->rows(
                self::TYPE_REFUND,
                $group,
                [$refunds[$index], $baseRefunds[$index], $refundTax, $baseRefundTax],
                1.0
            );
            $feeRows[] = $this->rows(
                self::TYPE_FEE,
                $group,
                [$fees[$index], $baseFees[$index], $feeTax, $baseFeeTax],
                -1.0
            );
        }
        $refundRows = array_merge(...$refundRows);
        $feeRows = array_merge(...$feeRows);

        return new Allocation(
            $this->sum($refundRows, 'amount'),
            $this->sum($refundRows, 'base_amount'),
            0.0 - $this->sum($feeRows, 'amount'),
            0.0 - $this->sum($feeRows, 'base_amount'),
            array_merge($refundRows, $feeRows)
        );
    }

    /**
     * Basis amounts grouped by the rates charged on them; untaxed amounts form the group without rates.
     *
     * @param Line[] $lines
     * @return array<string, array{gross: float, base_gross: float,
     *     rates: array<string, array{title: string, percent: float, tax: float, base_tax: float}>}>
     */
    private function groups(array $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            foreach ($this->portions($line) as $key => $portion) {
                $group = $groups[$key] ?? ['gross' => 0.0, 'base_gross' => 0.0, 'rates' => []];
                $group['gross'] += $portion['gross'];
                $group['base_gross'] += $portion['base_gross'];
                foreach ($portion['rates'] as $code => $rate) {
                    $group['rates'][$code] ??= [
                        'title' => $rate->title,
                        'percent' => $rate->percent,
                        'tax' => 0.0,
                        'base_tax' => 0.0,
                    ];
                    $group['rates'][$code]['tax'] += $rate->amount;
                    $group['rates'][$code]['base_tax'] += $rate->baseAmount;
                }
                $groups[$key] = $group;
            }
        }
        return $groups;
    }

    /**
     * Parts of a line keyed by the codes of the rates charged on them.
     *
     * @param Line $line
     * @return array<string, array{gross: float, base_gross: float, rates: array<string, Rate>}>
     */
    private function portions(Line $line): array
    {
        $weights = [];
        $baseWeights = [];
        foreach ($line->rates as $code => $rate) {
            $weights[$code] = $rate->percent > 0.0 ? $rate->amount * 100 / $rate->percent + $rate->amount : 0.0;
            $baseWeights[$code] = $rate->percent > 0.0
                ? $rate->baseAmount * 100 / $rate->percent + $rate->baseAmount
                : 0.0;
        }
        $totalWeight = array_sum($weights);
        $totalBaseWeight = array_sum($baseWeights);
        $isSplit = count($line->rates) > 1
            && $totalWeight > 0.0
            && $totalBaseWeight > 0.0
            && $totalWeight - array_sum(array_column($line->rates, 'amount')) <= $line->getNet() * 1.5;

        if (!$isSplit) {
            return [
                implode('|', array_keys($line->rates)) => [
                    'gross' => $line->gross,
                    'base_gross' => $line->baseGross,
                    'rates' => $line->rates,
                ],
            ];
        }

        $portions = [];
        foreach ($line->rates as $code => $rate) {
            $portions[$code] = [
                'gross' => $line->gross * $weights[$code] / $totalWeight,
                'base_gross' => $line->baseGross * $baseWeights[$code] / $totalBaseWeight,
                'rates' => [$code => $rate],
            ];
        }
        return $portions;
    }

    /**
     * Rows of one adjustment share, one per rate of the group.
     *
     * @param string $type
     * @param array{gross: float, base_gross: float,
     *     rates: array<string, array{title: string, percent: float, tax: float, base_tax: float}>} $group
     * @param array{0: float, 1: float, 2: float, 3: float} $amounts Share, base share, tax and base tax
     * @param float $sign 1 for the refund, -1 for the fee
     * @return array<int, array{type: string, code: string, title: string, percent: float, amount: float,
     *     base_amount: float, taxable_amount: float, base_taxable_amount: float}>
     */
    private function rows(string $type, array $group, array $amounts, float $sign): array
    {
        [$share, $baseShare, $tax, $baseTax] = $amounts;
        $taxable = $this->priceCurrency->round($share - $tax);
        $baseTaxable = $this->priceCurrency->round($baseShare - $baseTax);
        if ($taxable === 0.0 && $baseTaxable === 0.0 && $tax === 0.0 && $baseTax === 0.0) {
            return [];
        }

        $rates = $group['rates'] ?: ['' => ['title' => '', 'percent' => 0.0, 'tax' => 0.0, 'base_tax' => 0.0]];
        $taxes = $this->share($tax, array_column($rates, 'tax'));
        $baseTaxes = $this->share($baseTax, array_column($rates, 'base_tax'));
        $rows = [];
        foreach (array_keys($rates) as $rateIndex => $code) {
            $rows[] = [
                'type' => $type,
                'code' => (string)$code,
                'title' => $rates[$code]['title'],
                'percent' => $rates[$code]['percent'],
                'amount' => $sign * $taxes[$rateIndex],
                'base_amount' => $sign * $baseTaxes[$rateIndex],
                'taxable_amount' => $sign * $taxable,
                'base_taxable_amount' => $sign * $baseTaxable,
            ];
        }
        return $rows;
    }

    /**
     * Tax contained in a share at the sum of the rates, as Magento calculates it for prices including tax.
     *
     * @param float $share
     * @param float $percent
     * @return float
     */
    private function taxOfShare(float $share, float $percent): float
    {
        return $this->priceCurrency->round($share * (1 - 1 / (1 + $percent / 100)));
    }

    /**
     * Shares of an amount by weight in whole cents; the missing cents go to the largest remainders.
     *
     * Without weight the first share takes the whole amount.
     *
     * @param float $amount
     * @param float[] $weights
     * @return array<int, float>
     */
    private function share(float $amount, array $weights): array
    {
        $weights = array_values($weights);
        $totalWeight = array_sum($weights);
        $cents = (int)round($amount * 100);
        if ($totalWeight <= 0.0) {
            return array_map(static fn (int $index): float => $index === 0 ? $cents / 100 : 0.0, array_keys($weights));
        }

        $shares = [];
        $remainders = [];
        foreach ($weights as $index => $weight) {
            $exact = $cents * $weight / $totalWeight;
            $shares[$index] = (int)floor($exact);
            $remainders[$index] = round($exact - $shares[$index], 9);
        }
        arsort($remainders);
        foreach (array_slice(array_keys($remainders), 0, $cents - array_sum($shares)) as $index) {
            $shares[$index]++;
        }

        return array_map(static fn (int $share): float => $share / 100, $shares);
    }

    /**
     * @param array<int, array{amount: float, base_amount: float}> $rows
     * @param string $field
     * @return float
     */
    private function sum(array $rows, string $field): float
    {
        return $this->priceCurrency->round(array_sum(array_column($rows, $field)));
    }
}
