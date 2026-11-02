<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Test\Unit\Block\Sales\Creditmemo;

use Geissweb\CreditmemoTax\Block\Sales\Creditmemo\AdjustmentTotals;
use Geissweb\CreditmemoTax\Model\ResourceModel\AdjustmentTax as AdjustmentTaxResource;
use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Context;
use Magento\Framework\View\LayoutInterface;
use Magento\Sales\Block\Order\Totals;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Store\Model\Store;
use Magento\Tax\Model\Config as TaxConfig;
use PHPUnit\Framework\TestCase;

class AdjustmentTotalsTest extends TestCase
{
    private const DISPLAY_EXCL = 'excl';
    private const DISPLAY_INCL = 'incl';
    private const DISPLAY_BOTH = 'both';

    public function testShowsTheAdjustmentsExcludingAndIncludingTax(): void
    {
        $parent = $this->parent($this->creditmemo());

        $this->block($parent, self::DISPLAY_BOTH)->initTotals();

        self::assertSame(
            [
                'subtotal' => [100.0, 100.0, 'Subtotal'],
                'adjustment_positive' => [8.4, 8.4, 'Adjustment Refund (Excl. Tax)'],
                'adjustment_positive_incl' => [10.0, 10.0, 'Adjustment Refund (Incl. Tax)'],
                'adjustment_negative' => [5.0, 5.0, 'Adjustment Fee (Excl. Tax)'],
                'adjustment_negative_incl' => [5.95, 5.95, 'Adjustment Fee (Incl. Tax)'],
                'grand_total' => [123.05, 123.05, 'Grand Total'],
            ],
            $this->describe($parent)
        );
    }

    public function testShowsTheAdjustmentsExcludingTax(): void
    {
        $parent = $this->parent($this->creditmemo());

        $this->block($parent, self::DISPLAY_EXCL)->initTotals();

        self::assertSame([8.4, 8.4, 'Adjustment Refund'], $this->describe($parent)['adjustment_positive']);
        self::assertSame([5.0, 5.0, 'Adjustment Fee'], $this->describe($parent)['adjustment_negative']);
    }

    public function testShowsTheAdjustmentsIncludingTaxAsEntered(): void
    {
        $parent = $this->parent($this->creditmemo());

        $this->block($parent, self::DISPLAY_INCL)->initTotals();

        self::assertSame([10.0, 10.0, 'Adjustment Refund'], $this->describe($parent)['adjustment_positive']);
        self::assertSame([5.95, 5.95, 'Adjustment Fee'], $this->describe($parent)['adjustment_negative']);
    }

    public function testTotalsWithoutBaseValueKeepIt(): void
    {
        $parent = $this->parent($this->creditmemo(), false);

        $this->block($parent, self::DISPLAY_BOTH)->initTotals();

        self::assertNull($parent->getTotal('adjustment_positive')->getBaseValue());
        self::assertNull($parent->getTotal('adjustment_positive_incl')->getBaseValue());
    }

    public function testDeductsTheAdjustmentTaxFromAnEstimatedSubtotalIncludingTax(): void
    {
        $parent = $this->parent($this->adjustmentOnlyCreditmemo());
        $parent->getTotal('subtotal')->setValue(1.6)->setBaseValue(1.6);

        $this->block($parent, self::DISPLAY_INCL)->initTotals();

        self::assertSame([0.0, 0.0, 'Subtotal'], $this->describe($parent)['subtotal']);
    }

    public function testDeductsTheAdjustmentTaxFromAnEstimatedSubtotalIncludingTaxShownBesideExcludingTax(): void
    {
        $parent = $this->parent($this->adjustmentOnlyCreditmemo());
        $parent->addTotal(new DataObject(['code' => 'subtotal_incl', 'value' => 1.6, 'base_value' => 1.6]), 'subtotal');

        $this->block($parent, self::DISPLAY_BOTH)->initTotals();

        self::assertSame([0.0, 0.0, null], $this->describe($parent)['subtotal_incl']);
    }

    public function testAStoredSubtotalIncludingTaxIsKept(): void
    {
        $parent = $this->parent($this->creditmemo());
        $parent->getTotal('subtotal')->setValue(119.0)->setBaseValue(119.0);

        $this->block($parent, self::DISPLAY_INCL)->initTotals();

        self::assertSame([119.0, 119.0, 'Subtotal'], $this->describe($parent)['subtotal']);
    }

    public function testCreditmemosWithoutAdjustmentTaxAreUnchanged(): void
    {
        $creditmemo = $this->creditmemo([
            AdjustmentTaxResource::POSITIVE_TAX => null,
            AdjustmentTaxResource::BASE_POSITIVE_TAX => null,
            AdjustmentTaxResource::NEGATIVE_TAX => null,
            AdjustmentTaxResource::BASE_NEGATIVE_TAX => null,
        ]);
        $parent = $this->parent($creditmemo);

        $this->block($parent, self::DISPLAY_BOTH)->initTotals();

        self::assertSame([10.0, 10.0, 'Adjustment Refund'], $this->describe($parent)['adjustment_positive']);
        self::assertArrayNotHasKey('adjustment_positive_incl', $this->describe($parent));
    }

    /**
     * @return array<string, array{0: float|null, 1: float|null, 2: string|null}>
     */
    private function describe(Totals $parent): array
    {
        $described = [];
        foreach ($parent->getTotals() as $code => $total) {
            $value = $total->getValue();
            $baseValue = $total->getBaseValue();
            $described[$code] = [
                $value === null ? null : round((float)$value, 2),
                $baseValue === null ? null : round((float)$baseValue, 2),
                $total->getLabel() === null ? null : (string)$total->getLabel(),
            ];
        }
        return $described;
    }

    private function adjustmentOnlyCreditmemo(): Creditmemo
    {
        return $this->creditmemo([
            'subtotal' => 0.0,
            'base_subtotal' => 0.0,
            'subtotal_incl_tax' => 0.0,
            'base_subtotal_incl_tax' => 0.0,
            'tax_amount' => 1.6,
            'base_tax_amount' => 1.6,
            AdjustmentTaxResource::NEGATIVE_TAX => 0.0,
            AdjustmentTaxResource::BASE_NEGATIVE_TAX => 0.0,
        ]);
    }

    /**
     * @param array<string, float|null> $data
     */
    private function creditmemo(array $data = []): Creditmemo
    {
        $creditmemo = $this->getMockBuilder(Creditmemo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrder'])
            ->getMock();
        $creditmemo->expects(self::never())->method('getOrder');
        $creditmemo->setData($data + [
            'subtotal' => 100.0,
            'base_subtotal' => 100.0,
            'subtotal_incl_tax' => 119.0,
            'base_subtotal_incl_tax' => 119.0,
            'tax_amount' => 19.65,
            'base_tax_amount' => 19.65,
            'shipping_tax_amount' => 0.0,
            'base_shipping_tax_amount' => 0.0,
            AdjustmentTaxResource::POSITIVE_TAX => 1.6,
            AdjustmentTaxResource::BASE_POSITIVE_TAX => 1.6,
            AdjustmentTaxResource::NEGATIVE_TAX => 0.95,
            AdjustmentTaxResource::BASE_NEGATIVE_TAX => 0.95,
        ]);
        return $creditmemo;
    }

    private function parent(Creditmemo $creditmemo, bool $withBaseValues = true): Totals
    {
        $store = $this->createStub(Store::class);
        $order = $this->createStub(Order::class);
        $order->method('getStore')->willReturn($store);

        $parent = $this->getMockBuilder(Totals::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSource', 'getOrder'])
            ->getMock();
        $parent->expects(self::atLeastOnce())->method('getSource')->willReturn($creditmemo);
        $parent->expects(self::any())->method('getOrder')->willReturn($order);

        foreach ([
            ['subtotal', 100.0, 'Subtotal'],
            ['adjustment_positive', 10.0, 'Adjustment Refund'],
            ['adjustment_negative', 5.95, 'Adjustment Fee'],
            ['grand_total', 123.05, 'Grand Total'],
        ] as [$code, $value, $label]) {
            $total = new DataObject(['code' => $code, 'value' => $value, 'label' => __($label)]);
            if ($withBaseValues) {
                $total->setBaseValue($value);
            }
            $parent->addTotal($total, 'last');
        }
        return $parent;
    }

    private function block(Totals $parent, string $display): AdjustmentTotals
    {
        $layout = $this->createStub(LayoutInterface::class);
        $layout->method('getParentName')->willReturn('creditmemo_totals');
        $layout->method('getBlock')->willReturn($parent);
        $context = $this->createStub(Context::class);
        $context->method('getLayout')->willReturn($layout);

        $taxConfig = $this->createStub(TaxConfig::class);
        $taxConfig->method('displaySalesSubtotalBoth')->willReturn($display === self::DISPLAY_BOTH);
        $taxConfig->method('displaySalesSubtotalInclTax')->willReturn($display === self::DISPLAY_INCL);

        return new AdjustmentTotals($context, $taxConfig);
    }
}
