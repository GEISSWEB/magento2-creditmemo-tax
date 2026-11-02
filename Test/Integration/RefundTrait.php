<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Test\Integration;

use Geissweb\CreditmemoTax\Model\ResourceModel\AdjustmentTax as AdjustmentTaxResource;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Invoice;
use Magento\Tax\Helper\Data as TaxHelper;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Creates offline credit memos the way the admin does and reads back the stored adjustment tax.
 */
trait RefundTrait
{
    /**
     * @param array<int, float> $qtysByProductId Items not listed are not refunded
     * @param float $adjustmentRefund
     * @param float $adjustmentFee
     * @param bool $byInvoice
     * @param float $shipping Refund shipping as entered in the admin
     * @return Creditmemo
     */
    private function refund(
        array $qtysByProductId,
        float $adjustmentRefund,
        float $adjustmentFee,
        bool $byInvoice = false,
        float $shipping = 0.0
    ): Creditmemo {
        $objectManager = Bootstrap::getObjectManager();
        $order = $this->fixture('order');
        $qtys = [];
        foreach ($order->getAllItems() as $item) {
            $qtys[(int)$item->getId()] = $qtysByProductId[(int)$item->getProductId()] ?? 0.0;
        }
        $data = [
            'qtys' => $qtys,
            'shipping_amount' => $shipping,
            'adjustment_positive' => $adjustmentRefund,
            'adjustment_negative' => $adjustmentFee,
        ];

        $factory = $objectManager->get(CreditmemoFactory::class);
        $creditmemo = $byInvoice
            ? $factory->createByInvoice($this->invoice(), $data)
            : $factory->createByOrder($order, $data);
        $objectManager->get(CreditmemoManagementInterface::class)->refund($creditmemo, true);

        return $objectManager->get(CreditmemoRepositoryInterface::class)->get((int)$creditmemo->getEntityId());
    }

    /**
     * Stored adjustment tax rows keyed by type and percent: [tax amount, taxable amount].
     *
     * @param Creditmemo $creditmemo
     * @return array<string, array{0: float, 1: float}>
     */
    private function storedRows(Creditmemo $creditmemo): array
    {
        $rows = [];
        $resource = Bootstrap::getObjectManager()->get(AdjustmentTaxResource::class);
        foreach ($resource->getRows((int)$creditmemo->getEntityId()) as $row) {
            $rows[$row['type'] . ' ' . (int)$row['percent']] = [
                round($row['amount'], 2),
                round($row['taxable_amount'], 2),
            ];
        }
        ksort($rows);
        return $rows;
    }

    /**
     * Tax summary of the credit memo keyed by percent.
     *
     * @param Creditmemo $creditmemo
     * @return array<int, float>
     */
    private function taxSummary(Creditmemo $creditmemo): array
    {
        $summary = [];
        foreach (Bootstrap::getObjectManager()->get(TaxHelper::class)->getCalculatedTaxes($creditmemo) as $tax) {
            $summary[(int)$tax['percent']] = round((float)$tax['tax_amount'], 2);
        }
        ksort($summary);
        return $summary;
    }

    /**
     * @param string $name
     * @return Order
     */
    private function fixture(string $name): Order
    {
        $order = DataFixtureStorageManager::getStorage()->get($name);
        self::assertInstanceOf(Order::class, $order);
        return $order;
    }

    /**
     * @return Invoice
     */
    private function invoice(): Invoice
    {
        $invoice = Bootstrap::getObjectManager()->get(InvoiceRepositoryInterface::class)->get(
            (int)DataFixtureStorageManager::getStorage()->get('invoice')?->getId()
        );
        self::assertInstanceOf(Invoice::class, $invoice);
        return $invoice;
    }

    /**
     * @param float $expected
     * @param mixed $actual
     * @return void
     */
    private static function assertAmount(float $expected, mixed $actual): void
    {
        self::assertSame($expected, round((float)$actual, 2));
    }
}
