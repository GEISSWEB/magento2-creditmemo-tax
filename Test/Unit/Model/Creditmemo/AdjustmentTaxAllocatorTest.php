<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Test\Unit\Model\Creditmemo;

use Geissweb\CreditmemoTax\Model\Creditmemo\AdjustmentTaxAllocator;
use Geissweb\CreditmemoTax\Model\Creditmemo\Basis\Line;
use Geissweb\CreditmemoTax\Model\Creditmemo\Basis\Rate;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use PHPUnit\Framework\TestCase;

class AdjustmentTaxAllocatorTest extends TestCase
{
    public function testExtractsTheTaxFromAGrossRefundAtASingleRate(): void
    {
        $allocation = $this->allocator()->allocate([$this->line(119.0, 'DE-19', 19.0, 19.0)], 10.0, 10.0, 0.0, 0.0);

        self::assertSame(1.6, $allocation->positiveTax);
        self::assertSame(1.6, $allocation->basePositiveTax);
        self::assertSame(0.0, $allocation->negativeTax);
        self::assertSame(
            [
                [
                    'type' => AdjustmentTaxAllocator::TYPE_REFUND,
                    'code' => 'DE-19',
                    'title' => 'VAT 19%',
                    'percent' => 19.0,
                    'amount' => 1.6,
                    'base_amount' => 1.6,
                    'taxable_amount' => 8.4,
                    'base_taxable_amount' => 8.4,
                ],
            ],
            $allocation->rows
        );
    }

    public function testSplitsARefundByTheShareOfEachRateInTheBasis(): void
    {
        $allocation = $this->allocator()->allocate(
            [$this->line(107.0, 'DE-7', 7.0, 7.0), $this->line(119.0, 'DE-19', 19.0, 19.0)],
            22.6,
            22.6,
            0.0,
            0.0
        );

        self::assertSame(2.6, $allocation->positiveTax);
        self::assertSame(
            ['refund DE-7' => [0.7, 10.0], 'refund DE-19' => [1.9, 10.0]],
            $this->amounts($allocation->rows)
        );
    }

    public function testAFeeIsStoredAsNegativeFeeRows(): void
    {
        $allocation = $this->allocator()->allocate([$this->line(119.0, 'DE-19', 19.0, 19.0)], 0.0, 0.0, 11.9, 11.9);

        self::assertSame(0.0, $allocation->positiveTax);
        self::assertSame(1.9, $allocation->negativeTax);
        self::assertSame(1.9, $allocation->baseNegativeTax);
        self::assertSame(['fee DE-19' => [-1.9, -10.0]], $this->amounts($allocation->rows));
    }

    public function testRefundAndFeeOfOneCreditMemoAreStoredSeparately(): void
    {
        $allocation = $this->allocator()->allocate([$this->line(119.0, 'DE-19', 19.0, 19.0)], 11.9, 11.9, 5.95, 5.95);

        self::assertSame(1.9, $allocation->positiveTax);
        self::assertSame(0.95, $allocation->negativeTax);
        self::assertSame(
            ['refund DE-19' => [1.9, 10.0], 'fee DE-19' => [-0.95, -5.0]],
            $this->amounts($allocation->rows)
        );
    }

    public function testTheTaxOfAShareFollowsTheRateNotTheRoundedTaxOfTheBasis(): void
    {
        $allocation = $this->allocator()->allocate([$this->line(0.99, 'DE-19', 19.0, 0.16)], 50.0, 50.0, 0.0, 0.0);

        self::assertSame(['refund DE-19' => [7.98, 42.02]], $this->amounts($allocation->rows));
    }

    public function testRefundAndFeeOfOneRateAreRoundedAsOneAmount(): void
    {
        $allocation = $this->allocator()->allocate([$this->line(107.0, 'DE-7', 7.0, 7.0)], 4.33, 4.33, 1.3, 1.3);

        self::assertSame(0.28, $allocation->positiveTax);
        self::assertSame(0.08, $allocation->negativeTax);
        self::assertSame(
            ['refund DE-7' => [0.28, 4.05], 'fee DE-7' => [-0.08, -1.22]],
            $this->amounts($allocation->rows)
        );
    }

    public function testBaseCurrencyIsAllocatedFromItsOwnAmounts(): void
    {
        $line = new Line(119.0, 59.5, ['DE-19' => new Rate('DE-19', 'VAT 19%', 19.0, 19.0, 9.5)]);

        $allocation = $this->allocator()->allocate([$line], 11.9, 5.95, 0.0, 0.0);

        self::assertSame(1.9, $allocation->positiveTax);
        self::assertSame(0.95, $allocation->basePositiveTax);
        self::assertSame(0.95, $allocation->rows[0]['base_amount']);
        self::assertSame(5.0, $allocation->rows[0]['base_taxable_amount']);
    }

    public function testTheShareOfUntaxedLinesIsStoredAsAnUntaxedRow(): void
    {
        $allocation = $this->allocator()->allocate(
            [$this->line(119.0, 'DE-19', 19.0, 19.0), new Line(119.0, 119.0, [])],
            23.8,
            23.8,
            0.0,
            0.0
        );

        self::assertSame(1.9, $allocation->positiveTax);
        self::assertSame(
            ['refund DE-19' => [1.9, 10.0], 'refund ' => [0.0, 11.9]],
            $this->amounts($allocation->rows)
        );
    }

    public function testAnUntaxedBasisYieldsAnUntaxedRow(): void
    {
        $allocation = $this->allocator()->allocate([new Line(100.0, 100.0, [])], 10.0, 10.0, 0.0, 0.0);

        self::assertSame(0.0, $allocation->positiveTax);
        self::assertSame(['refund ' => [0.0, 10.0]], $this->amounts($allocation->rows));
        self::assertSame(['', '', 0.0], [
            $allocation->rows[0]['code'],
            $allocation->rows[0]['title'],
            $allocation->rows[0]['percent'],
        ]);
    }

    public function testAZeroRateKeepsItsTaxableAmount(): void
    {
        $allocation = $this->allocator()->allocate([$this->line(100.0, 'EU-0', 0.0, 0.0)], 10.0, 10.0, 0.0, 0.0);

        self::assertSame(0.0, $allocation->positiveTax);
        self::assertSame(['refund EU-0' => [0.0, 10.0]], $this->amounts($allocation->rows));
    }

    public function testWithoutBasisTheAdjustmentIsStoredUntaxed(): void
    {
        $allocation = $this->allocator()->allocate([], 10.0, 10.0, 0.0, 0.0);

        self::assertSame(0.0, $allocation->positiveTax);
        self::assertSame(['refund ' => [0.0, 10.0]], $this->amounts($allocation->rows));
    }

    public function testALineSplitAcrossRatesIsSharedByTheAmountOfEachRate(): void
    {
        $shipping = new Line(5.65, 5.65, [
            'DE-19' => new Rate('DE-19', 'VAT 19%', 19.0, 0.58, 0.58),
            'DE-7' => new Rate('DE-7', 'VAT 7%', 7.0, 0.13, 0.13),
        ]);

        $allocation = $this->allocator()->allocate([$shipping], 5.65, 5.65, 0.0, 0.0);

        self::assertSame(0.71, $allocation->positiveTax);
        self::assertSame(
            ['refund DE-19' => [0.58, 3.07], 'refund DE-7' => [0.13, 1.87]],
            $this->amounts($allocation->rows)
        );
    }

    public function testRatesAddedOnTheSameAmountEachTakeTheFullTaxableAmount(): void
    {
        $line = new Line(110.0, 110.0, [
            'STATE' => new Rate('STATE', 'State', 6.0, 6.0, 6.0),
            'COUNTY' => new Rate('COUNTY', 'County', 4.0, 4.0, 4.0),
        ]);

        $allocation = $this->allocator()->allocate([$line], 11.0, 11.0, 0.0, 0.0);

        self::assertSame(1.0, $allocation->positiveTax);
        self::assertSame(
            ['refund STATE' => [0.6, 10.0], 'refund COUNTY' => [0.4, 10.0]],
            $this->amounts($allocation->rows)
        );
    }

    /**
     * Shares of 10.00 by 119.00 / 107.00 / 50.00 are 4.31159 / 3.87681 / 1.81159 and do not add up when rounded.
     * The missing cent goes to the largest remainder; of 3.00 the first of two equal remainders gets it.
     */
    public function testRowsAddUpToTheAdjustmentExactly(): void
    {
        $allocation = $this->allocator()->allocate(
            [
                $this->line(119.0, 'DE-19', 19.0, 19.0),
                $this->line(107.0, 'DE-7', 7.0, 7.0),
                new Line(50.0, 50.0, []),
            ],
            10.0,
            10.0,
            3.0,
            3.0
        );

        self::assertSame(
            [
                'refund DE-19' => [0.69, 3.62],
                'refund DE-7' => [0.25, 3.63],
                'refund ' => [0.0, 1.81],
                'fee DE-19' => [-0.21, -1.09],
                'fee DE-7' => [-0.07, -1.09],
                'fee ' => [0.0, -0.54],
            ],
            $this->amounts($allocation->rows)
        );
        self::assertSame([10.0, -3.0], $this->grossBySign($allocation->rows));
    }

    /**
     * @param array<int, array<string, string|float>> $rows
     * @return array<string, array{0: float, 1: float}>
     */
    private function amounts(array $rows): array
    {
        $amounts = [];
        foreach ($rows as $row) {
            $amounts[$row['type'] . ' ' . $row['code']] = [(float)$row['amount'], (float)$row['taxable_amount']];
        }
        return $amounts;
    }

    /**
     * @param array<int, array<string, string|float>> $rows
     * @return array{0: float, 1: float}
     */
    private function grossBySign(array $rows): array
    {
        $gross = [AdjustmentTaxAllocator::TYPE_REFUND => 0.0, AdjustmentTaxAllocator::TYPE_FEE => 0.0];
        foreach ($rows as $row) {
            $gross[(string)$row['type']] += (float)$row['amount'] + (float)$row['taxable_amount'];
        }
        return [
            round($gross[AdjustmentTaxAllocator::TYPE_REFUND], 2),
            round($gross[AdjustmentTaxAllocator::TYPE_FEE], 2),
        ];
    }

    private function line(float $gross, string $code, float $percent, float $tax): Line
    {
        return new Line($gross, $gross, [$code => new Rate($code, "VAT {$percent}%", $percent, $tax, $tax)]);
    }

    private function allocator(): AdjustmentTaxAllocator
    {
        $priceCurrency = $this->createStub(PriceCurrencyInterface::class);
        $priceCurrency->method('round')->willReturnCallback(static fn ($price): float => round((float)$price, 2));

        return new AdjustmentTaxAllocator($priceCurrency);
    }
}
