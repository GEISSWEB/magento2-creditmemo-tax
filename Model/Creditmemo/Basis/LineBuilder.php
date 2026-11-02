<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Model\Creditmemo\Basis;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Tax\Api\Data\OrderTaxDetailsAppliedTaxInterface;
use Magento\Tax\Api\OrderTaxManagementInterface;
use Magento\Tax\Model\Sales\Total\Quote\CommonTaxCollector;

/**
 * Builds the item and shipping lines of a credit memo, of the open order amounts or of the invoiced amounts.
 *
 * The tax of a line is split across its rates by the ratio of the line tax to the order item or order
 * shipping tax, as Magento\Tax\Helper\Data::getCalculatedTaxes() does for invoices and credit memos.
 */
class LineBuilder
{
    /**
     * @param OrderTaxManagementInterface $orderTaxManagement
     */
    public function __construct(
        private readonly OrderTaxManagementInterface $orderTaxManagement
    ) {
    }

    /**
     * Lines of the items and the shipping refunded by the credit memo.
     *
     * @param Creditmemo $creditmemo
     * @return Line[]
     */
    public function forCreditmemo(Creditmemo $creditmemo): array
    {
        $order = $creditmemo->getOrder();
        $appliedTaxes = $this->getAppliedTaxes($order);

        $lines = [];
        foreach ($creditmemo->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();
            if ($orderItem->isDummy() || (float)$item->getQty() <= 0.0) {
                continue;
            }
            $lines[] = $this->itemLine(
                $orderItem,
                $appliedTaxes,
                (float)$item->getRowTotal() - (float)$item->getDiscountAmount()
                    + (float)$item->getDiscountTaxCompensationAmount(),
                (float)$item->getBaseRowTotal() - (float)$item->getBaseDiscountAmount()
                    + (float)$item->getBaseDiscountTaxCompensationAmount(),
                (float)$item->getTaxAmount(),
                (float)$item->getBaseTaxAmount()
            );
        }

        if ((float)$creditmemo->getBaseShippingAmount() > 0.0) {
            array_push($lines, ...$this->shippingLines(
                $order,
                $appliedTaxes,
                (float)$creditmemo->getShippingAmount(),
                (float)$creditmemo->getBaseShippingAmount(),
                (float)$creditmemo->getShippingTaxAmount(),
                (float)$creditmemo->getBaseShippingTaxAmount()
            ));
        }

        return $this->withAmount($lines);
    }

    /**
     * Lines of the invoiced item and shipping amounts that are not refunded yet.
     *
     * A credit memo based on an invoice is limited to the items and the shipping of that invoice.
     *
     * @param Creditmemo $creditmemo
     * @return Line[]
     */
    public function forOpenAmounts(Creditmemo $creditmemo): array
    {
        return $this->orderLines($creditmemo, true);
    }

    /**
     * Lines of the invoice of the credit memo, or of all invoiced amounts of the order.
     *
     * @param Creditmemo $creditmemo
     * @return Line[]
     */
    public function forInvoiced(Creditmemo $creditmemo): array
    {
        $invoice = $creditmemo->getInvoice();
        if ($invoice === null) {
            return $this->orderLines($creditmemo, false);
        }

        $order = $creditmemo->getOrder();
        $appliedTaxes = $this->getAppliedTaxes($order);

        $lines = [];
        foreach ($invoice->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();
            if ($orderItem->isDummy() || (float)$item->getQty() <= 0.0) {
                continue;
            }
            $lines[] = $this->itemLine(
                $orderItem,
                $appliedTaxes,
                (float)$item->getRowTotal() - (float)$item->getDiscountAmount()
                    + (float)$item->getDiscountTaxCompensationAmount(),
                (float)$item->getBaseRowTotal() - (float)$item->getBaseDiscountAmount()
                    + (float)$item->getBaseDiscountTaxCompensationAmount(),
                (float)$item->getTaxAmount(),
                (float)$item->getBaseTaxAmount()
            );
        }

        if ((float)$invoice->getBaseShippingAmount() > 0.0) {
            array_push($lines, ...$this->shippingLines(
                $order,
                $appliedTaxes,
                (float)$invoice->getShippingAmount(),
                (float)$invoice->getBaseShippingAmount(),
                (float)$invoice->getShippingTaxAmount(),
                (float)$invoice->getBaseShippingTaxAmount()
            ));
        }

        return $this->withAmount($lines);
    }

    /**
     * @param Creditmemo $creditmemo
     * @param bool $openOnly Deduct the refunded amounts and limit the lines to the invoice of the credit memo
     * @return Line[]
     */
    private function orderLines(Creditmemo $creditmemo, bool $openOnly): array
    {
        $order = $creditmemo->getOrder();
        $appliedTaxes = $this->getAppliedTaxes($order);
        $invoice = $openOnly ? $creditmemo->getInvoice() : null;
        $invoiceItemIds = $invoice === null
            ? null
            : array_map(static fn ($item): int => (int)$item->getOrderItemId(), $invoice->getAllItems());

        $lines = [];
        foreach ($order->getAllItems() as $orderItem) {
            if ($orderItem->isDummy() || (float)$orderItem->getQtyInvoiced() <= 0.0) {
                continue;
            }
            if ($invoiceItemIds !== null && !in_array((int)$orderItem->getId(), $invoiceItemIds, true)) {
                continue;
            }
            $net = (float)$orderItem->getRowInvoiced() - (float)$orderItem->getDiscountInvoiced()
                + (float)$orderItem->getDiscountTaxCompensationInvoiced();
            $baseNet = (float)$orderItem->getBaseRowInvoiced() - (float)$orderItem->getBaseDiscountInvoiced()
                + (float)$orderItem->getBaseDiscountTaxCompensationInvoiced();
            $tax = (float)$orderItem->getTaxInvoiced();
            $baseTax = (float)$orderItem->getBaseTaxInvoiced();
            if ($openOnly) {
                $net -= (float)$orderItem->getAmountRefunded() - (float)$orderItem->getDiscountRefunded()
                    + (float)$orderItem->getDiscountTaxCompensationRefunded();
                $baseNet -= (float)$orderItem->getBaseAmountRefunded() - (float)$orderItem->getBaseDiscountRefunded()
                    + (float)$orderItem->getBaseDiscountTaxCompensationRefunded();
                $tax -= (float)$orderItem->getTaxRefunded();
                $baseTax -= (float)$orderItem->getBaseTaxRefunded();
            }
            $lines[] = $this->itemLine($orderItem, $appliedTaxes, $net, $baseNet, $tax, $baseTax);
        }

        $shipping = (float)$order->getShippingInvoiced();
        $baseShipping = (float)$order->getBaseShippingInvoiced();
        $shippingTax = $shipping > 0.0 ? (float)$order->getShippingTaxAmount() : 0.0;
        $baseShippingTax = $baseShipping > 0.0 ? (float)$order->getBaseShippingTaxAmount() : 0.0;
        if ($openOnly) {
            $shipping -= (float)$order->getShippingRefunded();
            $baseShipping -= (float)$order->getBaseShippingRefunded();
            $shippingTax -= (float)$order->getShippingTaxRefunded();
            $baseShippingTax -= (float)$order->getBaseShippingTaxRefunded();
        }
        if ($baseShipping > 0.0 && ($invoice === null || (float)$invoice->getBaseShippingAmount() > 0.0)) {
            array_push($lines, ...$this->shippingLines(
                $order,
                $appliedTaxes,
                $shipping,
                $baseShipping,
                $shippingTax,
                $baseShippingTax
            ));
        }

        return $this->withAmount($lines);
    }

    /**
     * @param OrderItem $orderItem
     * @param array{items: array<int, OrderTaxDetailsAppliedTaxInterface[]>,
     *     shipping: OrderTaxDetailsAppliedTaxInterface[]} $appliedTaxes
     * @param float $net Row total less discount, plus discount tax compensation
     * @param float $baseNet
     * @param float $tax
     * @param float $baseTax
     * @return Line
     */
    private function itemLine(
        OrderItem $orderItem,
        array $appliedTaxes,
        float $net,
        float $baseNet,
        float $tax,
        float $baseTax
    ): Line {
        $orderItemTax = (float)$orderItem->getTaxAmount();

        return new Line(
            $net + $tax,
            $baseNet + $baseTax,
            $this->rates(
                $appliedTaxes['items'][(int)$orderItem->getId()] ?? [],
                $orderItemTax !== 0.0 ? $tax / $orderItemTax : 0.0
            )
        );
    }

    /**
     * Shipping line; the part of the shipping that its tax does not cover, as with a shipping taxed in proportion to
     * taxed and untaxed items, becomes a line without rates.
     *
     * The taxed part of each rate is its tax × (100 + rate) / rate. A rest within the rounding of the tax amounts,
     * one cent per rate, stays with the taxed part.
     *
     * @param Order $order
     * @param array{items: array<int, OrderTaxDetailsAppliedTaxInterface[]>,
     *     shipping: OrderTaxDetailsAppliedTaxInterface[]} $appliedTaxes
     * @param float $shipping
     * @param float $baseShipping
     * @param float $tax
     * @param float $baseTax
     * @return Line[]
     */
    private function shippingLines(
        Order $order,
        array $appliedTaxes,
        float $shipping,
        float $baseShipping,
        float $tax,
        float $baseTax
    ): array {
        $orderShipping = (float)$order->getShippingAmount();
        $baseOrderShipping = (float)$order->getBaseShippingAmount();
        $discount = $orderShipping > 0.0
            ? $shipping / $orderShipping * ((float)$order->getShippingDiscountAmount()
                - (float)$order->getShippingDiscountTaxCompensationAmount())
            : 0.0;
        $baseDiscount = $baseOrderShipping > 0.0
            ? $baseShipping / $baseOrderShipping * ((float)$order->getBaseShippingDiscountAmount()
                - (float)$order->getBaseShippingDiscountTaxCompensationAmnt())
            : 0.0;
        $orderShippingTax = (float)$order->getShippingTaxAmount();

        $gross = $shipping - $discount + $tax;
        $baseGross = $baseShipping - $baseDiscount + $baseTax;
        $rates = $this->rates($appliedTaxes['shipping'], $orderShippingTax !== 0.0 ? $tax / $orderShippingTax : 0.0);

        $taxedGross = 0.0;
        $baseTaxedGross = 0.0;
        $tolerance = 0.0;
        foreach ($rates as $rate) {
            if ($rate->percent <= 0.0) {
                return [new Line($gross, $baseGross, $rates)];
            }
            $factor = (100 + $rate->percent) / $rate->percent;
            $taxedGross += $rate->amount * $factor;
            $baseTaxedGross += $rate->baseAmount * $factor;
            $tolerance += 0.01 * $factor;
        }
        if ($rates === [] || $gross - $taxedGross <= $tolerance) {
            return [new Line($gross, $baseGross, $rates)];
        }

        return [
            new Line($taxedGross, $baseTaxedGross, $rates),
            new Line($gross - $taxedGross, $baseGross - $baseTaxedGross, []),
        ];
    }

    /**
     * @param OrderTaxDetailsAppliedTaxInterface[] $appliedTaxes
     * @param float $ratio
     * @return array<string, Rate>
     */
    private function rates(array $appliedTaxes, float $ratio): array
    {
        $rates = [];
        foreach ($appliedTaxes as $appliedTax) {
            $code = (string)$appliedTax->getCode();
            $rates[$code] = new Rate(
                $code,
                (string)$appliedTax->getTitle(),
                (float)$appliedTax->getPercent(),
                (float)$appliedTax->getAmount() * $ratio,
                (float)$appliedTax->getBaseAmount() * $ratio
            );
        }
        return $rates;
    }

    /**
     * Applied taxes of the order items and the shipping.
     *
     * @param Order $order
     * @return array{items: array<int, OrderTaxDetailsAppliedTaxInterface[]>,
     *     shipping: OrderTaxDetailsAppliedTaxInterface[]}
     */
    private function getAppliedTaxes(Order $order): array
    {
        $appliedTaxes = ['items' => [], 'shipping' => []];
        foreach ($this->orderTaxManagement->getOrderTaxDetails((int)$order->getId())->getItems() ?? [] as $item) {
            if ($item->getItemId() !== null) {
                $appliedTaxes['items'][(int)$item->getItemId()] = $item->getAppliedTaxes() ?? [];
            } elseif ($item->getType() === CommonTaxCollector::ITEM_TYPE_SHIPPING) {
                $appliedTaxes['shipping'] = $item->getAppliedTaxes() ?? [];
            }
        }
        return $appliedTaxes;
    }

    /**
     * @param Line[] $lines
     * @return Line[]
     */
    private function withAmount(array $lines): array
    {
        return array_values(array_filter($lines, static fn (Line $line): bool => $line->baseGross > 0.0));
    }
}
