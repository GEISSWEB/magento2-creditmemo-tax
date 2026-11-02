<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Test\Unit\Plugin;

use Geissweb\CreditmemoTax\Model\ResourceModel\AdjustmentTax as AdjustmentTaxResource;
use Geissweb\CreditmemoTax\Plugin\AddAdjustmentTaxToCalculatedTaxes;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Magento\Tax\Helper\Data as TaxHelper;
use PHPUnit\Framework\TestCase;

class AddAdjustmentTaxToCalculatedTaxesTest extends TestCase
{
    private const ITEM_TAXES = [
        ['title' => 'VAT 19%', 'percent' => '19.0000', 'tax_amount' => 19.0, 'base_tax_amount' => 19.0],
    ];

    public function testAddsTheRowsOfANewCreditmemoToTheMatchingRatesAndAppendsOthers(): void
    {
        $creditmemo = $this->creditmemo(null);
        $creditmemo->setData(AdjustmentTaxResource::ROWS, [
            $this->row('VAT 19%', 19.0, -1.9),
            $this->row('VAT 7%', 7.0, 0.7),
            $this->row('VAT 0%', 0.0, 0.0),
        ]);
        $resource = $this->createMock(AdjustmentTaxResource::class);
        $resource->expects(self::never())->method('getRows');

        self::assertSame(
            [
                ['title' => 'VAT 19%', 'percent' => '19.0000', 'tax_amount' => 17.1, 'base_tax_amount' => 17.1],
                ['title' => 'VAT 7%', 'percent' => '7.0000', 'tax_amount' => 0.7, 'base_tax_amount' => 0.7],
            ],
            $this->plugin($resource)->afterGetCalculatedTaxes(
                $this->createStub(TaxHelper::class),
                self::ITEM_TAXES,
                $creditmemo
            )
        );
    }

    public function testLoadsTheRowsOfAStoredCreditmemo(): void
    {
        $resource = $this->createMock(AdjustmentTaxResource::class);
        $resource->expects(self::once())->method('getRows')->with(12)->willReturn([$this->row('VAT 19%', 19.0, 1.6)]);

        self::assertSame(
            [['title' => 'VAT 19%', 'percent' => '19.0000', 'tax_amount' => 1.6, 'base_tax_amount' => 1.6]],
            $this->plugin($resource)->afterGetCalculatedTaxes(
                $this->createStub(TaxHelper::class),
                [],
                $this->creditmemo('12')
            )
        );
    }

    public function testRatesNettedToZeroAreRemoved(): void
    {
        $creditmemo = $this->creditmemo(null);
        $creditmemo->setData(AdjustmentTaxResource::ROWS, [$this->row('VAT 19%', 19.0, -19.0)]);

        self::assertSame(
            [],
            $this->plugin($this->createStub(AdjustmentTaxResource::class))->afterGetCalculatedTaxes(
                $this->createStub(TaxHelper::class),
                self::ITEM_TAXES,
                $creditmemo
            )
        );
    }

    public function testOtherSourcesAreUnchanged(): void
    {
        $resource = $this->createMock(AdjustmentTaxResource::class);
        $resource->expects(self::never())->method('getRows');

        self::assertSame(
            self::ITEM_TAXES,
            $this->plugin($resource)->afterGetCalculatedTaxes(
                $this->createStub(TaxHelper::class),
                self::ITEM_TAXES,
                $this->createStub(Invoice::class)
            )
        );
    }

    /**
     * @return array{type: string, code: string, title: string, percent: float, amount: float,
     *     base_amount: float, taxable_amount: float, base_taxable_amount: float}
     */
    private function row(string $title, float $percent, float $amount): array
    {
        return [
            'type' => 'refund',
            'code' => $title,
            'title' => $title,
            'percent' => $percent,
            'amount' => $amount,
            'base_amount' => $amount,
            'taxable_amount' => 0.0,
            'base_taxable_amount' => 0.0,
        ];
    }

    private function creditmemo(?string $id): Creditmemo
    {
        $creditmemo = $this->getMockBuilder(Creditmemo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $creditmemo->expects($id === null ? self::never() : self::atLeastOnce())->method('getId')->willReturn($id);
        return $creditmemo;
    }

    private function plugin(AdjustmentTaxResource $resource): AddAdjustmentTaxToCalculatedTaxes
    {
        $priceCurrency = $this->createStub(PriceCurrencyInterface::class);
        $priceCurrency->method('round')->willReturnCallback(static fn ($price): float => round((float)$price, 2));

        return new AddAdjustmentTaxToCalculatedTaxes($resource, $priceCurrency);
    }
}
