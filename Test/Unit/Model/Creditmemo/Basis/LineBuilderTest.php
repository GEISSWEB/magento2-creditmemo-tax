<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Test\Unit\Model\Creditmemo\Basis;

use Geissweb\CreditmemoTax\Model\Creditmemo\Basis\Line;
use Geissweb\CreditmemoTax\Model\Creditmemo\Basis\LineBuilder;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item as CreditmemoItem;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Invoice\Item as InvoiceItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Tax\Api\Data\OrderTaxDetailsAppliedTaxInterface;
use Magento\Tax\Api\Data\OrderTaxDetailsInterface;
use Magento\Tax\Api\Data\OrderTaxDetailsItemInterface;
use Magento\Tax\Api\OrderTaxManagementInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class LineBuilderTest extends TestCase
{
    private const ORDER_ID = 5;

    public function testCreditmemoLinesUseTheRefundedItemAndShippingAmounts(): void
    {
        $orderItem = $this->orderItem(2, ['tax_amount' => 19.0]);
        $creditmemo = $this->creditmemo(
            $this->order([$orderItem], ['shipping_amount' => 10.0, 'shipping_tax_amount' => 1.9]),
            [
                $this->creditmemoItem($orderItem, 1.0, 100.0, 10.0, 0.0, 17.1),
                $this->creditmemoItem($this->orderItem(1, ['tax_amount' => 7.0], true), 1.0, 0.0, 0.0, 0.0, 0.0),
                $this->creditmemoItem($this->orderItem(3, ['tax_amount' => 19.0]), 0.0, 0.0, 0.0, 0.0, 0.0),
            ],
            ['shipping_amount' => 10.0, 'shipping_tax_amount' => 1.9]
        );

        self::assertSame(
            [[107.1, ['DE-19' => 17.1]], [11.9, ['DE-19' => 1.9]]],
            $this->describe($this->builder()->forCreditmemo($creditmemo))
        );
    }

    public function testShippingLinesDeductTheirShareOfTheShippingDiscount(): void
    {
        $creditmemo = $this->creditmemo(
            $this->order(
                [],
                [
                    'shipping_amount' => 10.0,
                    'shipping_tax_amount' => 1.9,
                    'shipping_discount_amount' => 1.19,
                    'shipping_discount_tax_compensation_amount' => 0.19,
                ]
            ),
            [],
            ['shipping_amount' => 5.0, 'shipping_tax_amount' => 0.95]
        );

        self::assertSame(
            [[5.45, ['DE-19' => 0.95]]],
            $this->describe($this->builder()->forCreditmemo($creditmemo))
        );
    }

    public function testTheShippingPartNotCoveredByItsTaxIsAnUntaxedLine(): void
    {
        $creditmemo = $this->creditmemo(
            $this->order([], ['shipping_amount' => 9.93, 'shipping_tax_amount' => 1.37]),
            [],
            ['shipping_amount' => 9.93, 'shipping_tax_amount' => 1.37]
        );

        self::assertSame(
            [[8.58, ['DE-19' => 1.37]], [2.72, []]],
            $this->describe($this->builder(1.37)->forCreditmemo($creditmemo))
        );
    }

    public function testARoundedShippingTaxKeepsTheShippingTogether(): void
    {
        $creditmemo = $this->creditmemo(
            $this->order([], ['shipping_amount' => 4.75, 'shipping_tax_amount' => 0.9]),
            [],
            ['shipping_amount' => 4.75, 'shipping_tax_amount' => 0.9]
        );

        self::assertSame(
            [[5.65, ['DE-19' => 0.9]]],
            $this->describe($this->builder(0.9)->forCreditmemo($creditmemo))
        );
    }

    public function testOpenLinesAreInvoicedMinusRefundedAmounts(): void
    {
        $order = $this->order(
            [
                $this->orderItem(1, [
                    'tax_amount' => 7.0,
                    'qty_invoiced' => 1.0,
                    'row_invoiced' => 100.0,
                    'tax_invoiced' => 7.0,
                ]),
                $this->orderItem(2, [
                    'tax_amount' => 19.0,
                    'qty_invoiced' => 1.0,
                    'row_invoiced' => 100.0,
                    'amount_refunded' => 100.0,
                    'tax_invoiced' => 19.0,
                    'tax_refunded' => 19.0,
                ]),
                $this->orderItem(3, ['tax_amount' => 19.0]),
            ],
            [
                'shipping_amount' => 10.0,
                'shipping_tax_amount' => 1.9,
                'shipping_invoiced' => 10.0,
                'shipping_refunded' => 4.0,
                'shipping_tax_refunded' => 0.76,
            ]
        );

        self::assertSame(
            [[107.0, ['DE-7' => 7.0]], [7.14, ['DE-19' => 1.14]]],
            $this->describe($this->builder()->forOpenAmounts($this->creditmemo($order, [], [])))
        );
    }

    public function testOpenLinesOfAnInvoiceBasedCreditmemoAreLimitedToTheInvoice(): void
    {
        $first = $this->orderItem(1, [
            'tax_amount' => 7.0,
            'qty_invoiced' => 1.0,
            'row_invoiced' => 100.0,
            'tax_invoiced' => 7.0,
        ]);
        $second = $this->orderItem(2, [
            'tax_amount' => 19.0,
            'qty_invoiced' => 1.0,
            'row_invoiced' => 100.0,
            'tax_invoiced' => 19.0,
        ]);
        $order = $this->order(
            [$first, $second],
            ['shipping_amount' => 10.0, 'shipping_tax_amount' => 1.9, 'shipping_invoiced' => 10.0]
        );
        $invoice = $this->invoice([$this->invoiceItem($second, 1.0, 100.0, 0.0, 0.0, 19.0)], 0.0, 0.0);

        self::assertSame(
            [[119.0, ['DE-19' => 19.0]]],
            $this->describe($this->builder()->forOpenAmounts($this->creditmemo($order, [], [], $invoice)))
        );
    }

    public function testInvoicedLinesOfAnOrderBasedCreditmemoIgnoreRefunds(): void
    {
        $order = $this->order(
            [
                $this->orderItem(2, [
                    'tax_amount' => 19.0,
                    'qty_invoiced' => 1.0,
                    'row_invoiced' => 100.0,
                    'amount_refunded' => 100.0,
                    'discount_invoiced' => 11.9,
                    'discount_tax_compensation_invoiced' => 1.9,
                    'tax_invoiced' => 17.1,
                    'tax_refunded' => 17.1,
                ]),
            ],
            [
                'shipping_amount' => 10.0,
                'shipping_tax_amount' => 1.9,
                'shipping_invoiced' => 10.0,
                'shipping_refunded' => 10.0,
            ]
        );

        self::assertSame(
            [[107.1, ['DE-19' => 17.1]], [11.9, ['DE-19' => 1.9]]],
            $this->describe($this->builder()->forInvoiced($this->creditmemo($order, [], [])))
        );
    }

    public function testInvoicedLinesOfAnInvoiceBasedCreditmemoAreTheInvoiceLines(): void
    {
        $orderItem = $this->orderItem(2, ['tax_amount' => 19.0]);
        $invoice = $this->invoice([$this->invoiceItem($orderItem, 1.0, 100.0, 0.0, 0.0, 19.0)], 10.0, 1.9);
        $order = $this->order([$orderItem], ['shipping_amount' => 10.0, 'shipping_tax_amount' => 1.9]);

        self::assertSame(
            [[119.0, ['DE-19' => 19.0]], [11.9, ['DE-19' => 1.9]]],
            $this->describe($this->builder()->forInvoiced($this->creditmemo($order, [], [], $invoice)))
        );
    }

    /**
     * @param Line[] $lines
     * @return array<int, array{0: float, 1: array<string, float>}>
     */
    private function describe(array $lines): array
    {
        $described = [];
        foreach ($lines as $line) {
            self::assertSame($line->gross, $line->baseGross);
            $rates = [];
            foreach ($line->rates as $code => $rate) {
                self::assertSame($rate->amount, $rate->baseAmount);
                $rates[$code] = round($rate->amount, 2);
            }
            $described[] = [round($line->gross, 2), $rates];
        }
        return $described;
    }

    private function builder(float $shippingTax = 1.9): LineBuilder
    {
        $taxManagement = $this->createStub(OrderTaxManagementInterface::class);
        $taxManagement->method('getOrderTaxDetails')->willReturnCallback(function (int $orderId) use ($shippingTax) {
            self::assertSame(self::ORDER_ID, $orderId);
            $details = $this->createStub(OrderTaxDetailsInterface::class);
            $details->method('getItems')->willReturn([
                $this->taxDetailsItem('product', 1, 'DE-7', 7.0, 7.0),
                $this->taxDetailsItem('product', 2, 'DE-19', 19.0, 19.0),
                $this->taxDetailsItem('product', 3, 'DE-19', 19.0, 19.0),
                $this->taxDetailsItem('shipping', null, 'DE-19', 19.0, $shippingTax),
            ]);
            return $details;
        });

        return new LineBuilder($taxManagement);
    }

    private function taxDetailsItem(
        string $type,
        ?int $itemId,
        string $code,
        float $percent,
        float $amount
    ): OrderTaxDetailsItemInterface {
        $appliedTax = $this->createStub(OrderTaxDetailsAppliedTaxInterface::class);
        $appliedTax->method('getCode')->willReturn($code);
        $appliedTax->method('getTitle')->willReturn($code);
        $appliedTax->method('getPercent')->willReturn($percent);
        $appliedTax->method('getAmount')->willReturn($amount);
        $appliedTax->method('getBaseAmount')->willReturn($amount);

        $item = $this->createStub(OrderTaxDetailsItemInterface::class);
        $item->method('getType')->willReturn($type);
        $item->method('getItemId')->willReturn($itemId);
        $item->method('getAppliedTaxes')->willReturn([$code => $appliedTax]);
        return $item;
    }

    /**
     * @param OrderItem[] $items
     * @param array<string, float> $data
     */
    private function order(array $items, array $data): Order
    {
        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn((string)self::ORDER_ID);
        $order->method('getAllItems')->willReturn($items);
        $this->stubAmounts($order, $data, [
            'getShippingAmount' => 'shipping_amount',
            'getShippingTaxAmount' => 'shipping_tax_amount',
            'getShippingDiscountAmount' => 'shipping_discount_amount',
            'getShippingDiscountTaxCompensationAmount' => 'shipping_discount_tax_compensation_amount',
            'getShippingInvoiced' => 'shipping_invoiced',
            'getShippingRefunded' => 'shipping_refunded',
            'getShippingTaxRefunded' => 'shipping_tax_refunded',
        ]);
        return $order;
    }

    /**
     * @param array<string, float> $data
     */
    private function orderItem(int $id, array $data, bool $dummy = false): OrderItem
    {
        $item = $this->createStub(OrderItem::class);
        $item->method('getId')->willReturn((string)$id);
        $item->method('isDummy')->willReturn($dummy);
        $item->method('getQtyInvoiced')->willReturn($data['qty_invoiced'] ?? null);
        $this->stubAmounts($item, $data, [
            'getTaxAmount' => 'tax_amount',
            'getRowInvoiced' => 'row_invoiced',
            'getAmountRefunded' => 'amount_refunded',
            'getDiscountInvoiced' => 'discount_invoiced',
            'getDiscountRefunded' => 'discount_refunded',
            'getDiscountTaxCompensationInvoiced' => 'discount_tax_compensation_invoiced',
            'getDiscountTaxCompensationRefunded' => 'discount_tax_compensation_refunded',
            'getTaxInvoiced' => 'tax_invoiced',
            'getTaxRefunded' => 'tax_refunded',
        ]);
        return $item;
    }

    private function creditmemoItem(
        OrderItem $orderItem,
        float $qty,
        float $rowTotal,
        float $discount,
        float $discountTaxCompensation,
        float $tax
    ): CreditmemoItem {
        $item = $this->createStub(CreditmemoItem::class);
        $item->method('getOrderItem')->willReturn($orderItem);
        $item->method('getQty')->willReturn($qty);
        $this->stubLineAmounts($item, $rowTotal, $discount, $discountTaxCompensation, $tax);
        return $item;
    }

    private function invoiceItem(
        OrderItem $orderItem,
        float $qty,
        float $rowTotal,
        float $discount,
        float $discountTaxCompensation,
        float $tax
    ): InvoiceItem {
        $item = $this->createStub(InvoiceItem::class);
        $item->method('getOrderItem')->willReturn($orderItem);
        $item->method('getOrderItemId')->willReturn($orderItem->getId());
        $item->method('getQty')->willReturn($qty);
        $this->stubLineAmounts($item, $rowTotal, $discount, $discountTaxCompensation, $tax);
        return $item;
    }

    /**
     * @param InvoiceItem[] $items
     */
    private function invoice(array $items, float $shipping, float $shippingTax): Invoice
    {
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getAllItems')->willReturn($items);
        $this->stubAmounts($invoice, ['shipping' => $shipping, 'shipping_tax' => $shippingTax], [
            'getShippingAmount' => 'shipping',
            'getShippingTaxAmount' => 'shipping_tax',
        ]);
        return $invoice;
    }

    /**
     * @param CreditmemoItem[] $items
     * @param array<string, float> $data
     */
    private function creditmemo(Order $order, array $items, array $data, ?Invoice $invoice = null): Creditmemo
    {
        $creditmemo = $this->createStub(Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getInvoice')->willReturn($invoice);
        $creditmemo->method('getAllItems')->willReturn($items);
        $this->stubAmounts($creditmemo, $data, [
            'getShippingAmount' => 'shipping_amount',
            'getShippingTaxAmount' => 'shipping_tax_amount',
        ]);
        return $creditmemo;
    }

    private function stubLineAmounts(
        Stub $item,
        float $rowTotal,
        float $discount,
        float $discountTaxCompensation,
        float $tax
    ): void {
        $this->stubAmounts(
            $item,
            ['row' => $rowTotal, 'discount' => $discount, 'dtc' => $discountTaxCompensation, 'tax' => $tax],
            [
                'getRowTotal' => 'row',
                'getDiscountAmount' => 'discount',
                'getDiscountTaxCompensationAmount' => 'dtc',
                'getTaxAmount' => 'tax',
            ]
        );
    }

    /**
     * Stubs each getter and its base currency twin with the same amount.
     *
     * @param array<string, float> $data
     * @param array<string, string> $getters
     */
    private function stubAmounts(Stub $stub, array $data, array $getters): void
    {
        foreach ($getters as $getter => $key) {
            $value = $data[$key] ?? null;
            $stub->method($getter)->willReturn($value);
            $stub->method($this->baseGetter($getter))->willReturn($value);
        }
    }

    private function baseGetter(string $getter): string
    {
        $baseGetter = 'getBase' . substr($getter, 3);
        return $baseGetter === 'getBaseShippingDiscountTaxCompensationAmount'
            ? 'getBaseShippingDiscountTaxCompensationAmnt'
            : $baseGetter;
    }
}
