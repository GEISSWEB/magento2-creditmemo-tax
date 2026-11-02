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
use Geissweb\CreditmemoTax\Plugin\ShieldCoreCreditmemoTax;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Total\Tax;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ShieldCoreCreditmemoTaxTest extends TestCase
{
    public function testTheCoreCollectorSeesOnlyTheRefundedItemAndShippingTax(): void
    {
        $order = $this->order();
        $creditmemo = $this->creditmemo($order);
        $collector = $this->createStub(Tax::class);
        $seen = [];

        $result = $this->plugin([1.9, 0.95])->aroundCollect(
            $collector,
            static function (Creditmemo $subject) use ($order, $collector, &$seen): Tax {
                $seen = [round((float)$order->getTaxRefunded(), 2), round((float)$order->getBaseTaxRefunded(), 2)];
                return $collector;
            },
            $creditmemo
        );

        self::assertSame($collector, $result);
        self::assertSame([17.1, 8.55], $seen);
        self::assertSame(19.0, $order->getTaxRefunded());
        self::assertSame(9.5, $order->getBaseTaxRefunded());
    }

    public function testRestoresTheRefundedTaxWhenTheCoreCollectorFails(): void
    {
        $order = $this->order();

        try {
            $this->plugin([1.9, 0.95])->aroundCollect(
                $this->createStub(Tax::class),
                static function (): never {
                    throw new RuntimeException('Maximum shipping amount allowed to refund is: 0');
                },
                $this->creditmemo($order)
            );
            self::fail('The exception of the core collector must be passed on.');
        } catch (RuntimeException) {
            self::assertSame(19.0, $order->getTaxRefunded());
            self::assertSame(9.5, $order->getBaseTaxRefunded());
        }
    }

    public function testOrdersWithoutAdjustmentTaxAreNotTouched(): void
    {
        $order = $this->order();
        $collector = $this->createStub(Tax::class);
        $seen = [];

        $this->plugin([0.0, 0.0])->aroundCollect(
            $collector,
            static function () use ($order, $collector, &$seen): Tax {
                $seen = [$order->getTaxRefunded(), $order->getBaseTaxRefunded()];
                return $collector;
            },
            $this->creditmemo($order)
        );

        self::assertSame([19.0, 9.5], $seen);
    }

    /**
     * @param array{0: float, 1: float} $adjustmentTax
     */
    private function plugin(array $adjustmentTax): ShieldCoreCreditmemoTax
    {
        $resource = $this->createMock(AdjustmentTaxResource::class);
        $resource->expects(self::once())->method('getOrderAdjustmentTax')->with(42)->willReturn($adjustmentTax);

        return new ShieldCoreCreditmemoTax($resource);
    }

    private function order(): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $order->expects(self::atLeastOnce())->method('getId')->willReturn('42');
        $order->setData(['tax_refunded' => 19.0, 'base_tax_refunded' => 9.5]);
        return $order;
    }

    private function creditmemo(Order $order): Creditmemo
    {
        $creditmemo = $this->createStub(Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($order);
        return $creditmemo;
    }
}
