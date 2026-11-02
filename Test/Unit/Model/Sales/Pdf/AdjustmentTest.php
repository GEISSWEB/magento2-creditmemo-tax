<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Test\Unit\Model\Sales\Pdf;

use Geissweb\CreditmemoTax\Model\ResourceModel\AdjustmentTax as AdjustmentTaxResource;
use Geissweb\CreditmemoTax\Model\Sales\Pdf\Adjustment;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Store\Model\Store;
use Magento\Tax\Helper\Data as TaxHelper;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\ResourceModel\Sales\Order\Tax\CollectionFactory;
use PHPUnit\Framework\TestCase;

class AdjustmentTest extends TestCase
{
    public function testShowsTheRefundExcludingAndIncludingTax(): void
    {
        self::assertSame(
            [
                ['amount' => '8.40', 'label' => 'Adjustment Refund (Excl. Tax):', 'font_size' => 7],
                ['amount' => '10.00', 'label' => 'Adjustment Refund (Incl. Tax):', 'font_size' => 7],
            ],
            $this->total('adjustment_positive', 'Adjustment Refund', true, false)->getTotalsForDisplay()
        );
    }

    public function testShowsTheFeeExcludingAndIncludingTax(): void
    {
        self::assertSame(
            [
                ['amount' => '5.00', 'label' => 'Adjustment Fee (Excl. Tax):', 'font_size' => 7],
                ['amount' => '5.95', 'label' => 'Adjustment Fee (Incl. Tax):', 'font_size' => 7],
            ],
            $this->total('adjustment_negative', 'Adjustment Fee', true, false)->getTotalsForDisplay()
        );
    }

    public function testShowsTheRefundExcludingTax(): void
    {
        self::assertSame(
            [['amount' => '8.40', 'label' => 'Adjustment Refund:', 'font_size' => 7]],
            $this->total('adjustment_positive', 'Adjustment Refund', false, false)->getTotalsForDisplay()
        );
    }

    public function testShowsTheRefundIncludingTax(): void
    {
        self::assertSame(
            [['amount' => '10.00', 'label' => 'Adjustment Refund:', 'font_size' => 7]],
            $this->total('adjustment_positive', 'Adjustment Refund', false, true)->getTotalsForDisplay()
        );
    }

    public function testAdjustmentsWithoutTaxAreShownAsEntered(): void
    {
        $total = $this->total('adjustment_positive', 'Adjustment Refund', true, false);
        $total->getSource()->setData(AdjustmentTaxResource::POSITIVE_TAX, null);

        self::assertSame(
            [['amount' => '10.00', 'label' => 'Adjustment Refund:', 'font_size' => 7]],
            $total->getTotalsForDisplay()
        );
    }

    private function total(string $sourceField, string $title, bool $both, bool $inclTax): Adjustment
    {
        $creditmemo = $this->getMockBuilder(Creditmemo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrder'])
            ->getMock();
        $creditmemo->expects(self::never())->method('getOrder');
        $creditmemo->setData([
            'adjustment_positive' => 10.0,
            'adjustment_negative' => 5.95,
            AdjustmentTaxResource::POSITIVE_TAX => 1.6,
            AdjustmentTaxResource::NEGATIVE_TAX => 0.95,
        ]);

        $order = $this->createStub(Order::class);
        $order->method('getStore')->willReturn($this->createStub(Store::class));
        $order->method('formatPriceTxt')->willReturnCallback(
            static fn ($price): string => number_format((float)$price, 2, '.', '')
        );

        $taxHelper = $this->createStub(TaxHelper::class);
        $taxHelper->method('displaySalesSubtotalBoth')->willReturn($both);
        $taxHelper->method('displaySalesSubtotalInclTax')->willReturn($inclTax);

        return new Adjustment(
            $taxHelper,
            $this->createStub(Calculation::class),
            $this->createStub(CollectionFactory::class),
            [
                'source' => $creditmemo,
                'order' => $order,
                'source_field' => $sourceField,
                'title' => $title,
                'font_size' => 7,
            ]
        );
    }
}
