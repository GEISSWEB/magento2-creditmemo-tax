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
use Geissweb\CreditmemoTax\Model\Sales\Pdf\Subtotal;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Magento\Store\Model\Store;
use Magento\Tax\Helper\Data as TaxHelper;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\ResourceModel\Sales\Order\Tax\CollectionFactory;
use PHPUnit\Framework\TestCase;

class SubtotalTest extends TestCase
{
    private const ADJUSTMENT_ONLY = [
        'subtotal' => 0.0,
        'subtotal_incl_tax' => 0.0,
        'tax_amount' => 1.6,
        'shipping_tax_amount' => 0.0,
        AdjustmentTaxResource::POSITIVE_TAX => 1.6,
        AdjustmentTaxResource::NEGATIVE_TAX => 0.0,
    ];

    public function testDeductsTheAdjustmentTaxFromAnEstimatedSubtotalIncludingTax(): void
    {
        self::assertSame(
            [['amount' => '0.00', 'label' => 'Subtotal:', 'font_size' => 7]],
            $this->total($this->creditmemo(self::ADJUSTMENT_ONLY), false, true)->getTotalsForDisplay()
        );
    }

    public function testDeductsTheAdjustmentTaxFromAnEstimatedSubtotalIncludingTaxShownBesideExcludingTax(): void
    {
        self::assertSame(
            [
                ['amount' => '0.00', 'label' => 'Subtotal (Excl. Tax):', 'font_size' => 7],
                ['amount' => '0.00', 'label' => 'Subtotal (Incl. Tax):', 'font_size' => 7],
            ],
            $this->total($this->creditmemo(self::ADJUSTMENT_ONLY), true, false)->getTotalsForDisplay()
        );
    }

    public function testAStoredSubtotalIncludingTaxIsKept(): void
    {
        $creditmemo = $this->creditmemo(['subtotal' => 100.0, 'subtotal_incl_tax' => 119.0, 'tax_amount' => 20.6]
            + self::ADJUSTMENT_ONLY);

        self::assertSame(
            [['amount' => '119.00', 'label' => 'Subtotal:', 'font_size' => 7]],
            $this->total($creditmemo, false, true)->getTotalsForDisplay()
        );
    }

    public function testOtherDocumentsAreUnchanged(): void
    {
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getSubtotalInclTax')->willReturn(null);
        $invoice->method('getTaxAmount')->willReturn(1.6);
        $invoice->method('getShippingTaxAmount')->willReturn(0.0);
        $invoice->method('getDataUsingMethod')->willReturn(0.0);

        self::assertSame(
            [['amount' => '1.60', 'label' => 'Subtotal:', 'font_size' => 7]],
            $this->total($invoice, false, true)->getTotalsForDisplay()
        );
    }

    /**
     * @param array<string, float> $data
     */
    private function creditmemo(array $data): Creditmemo
    {
        $creditmemo = $this->getMockBuilder(Creditmemo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrder'])
            ->getMock();
        $creditmemo->expects(self::never())->method('getOrder');
        $creditmemo->setData($data);
        return $creditmemo;
    }

    private function total(Creditmemo|Invoice $source, bool $both, bool $inclTax): Subtotal
    {
        $order = $this->createStub(Order::class);
        $order->method('getStore')->willReturn($this->createStub(Store::class));
        $order->method('formatPriceTxt')->willReturnCallback(
            static fn ($price): string => number_format((float)$price, 2, '.', '')
        );

        $taxHelper = $this->createStub(TaxHelper::class);
        $taxHelper->method('displaySalesSubtotalBoth')->willReturn($both);
        $taxHelper->method('displaySalesSubtotalInclTax')->willReturn($inclTax);

        return new Subtotal(
            $taxHelper,
            $this->createStub(Calculation::class),
            $this->createStub(CollectionFactory::class),
            ['source' => $source, 'order' => $order, 'source_field' => 'subtotal', 'title' => 'Subtotal', 'font_size' => 7]
        );
    }
}
