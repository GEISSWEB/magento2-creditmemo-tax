<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;

/**
 * Adjustment tax of credit memos: totals in sales_creditmemo, rows per rate in sales_creditmemo_adjustment_tax.
 */
class AdjustmentTax
{
    public const TABLE = 'sales_creditmemo_adjustment_tax';

    public const POSITIVE_TAX = 'adjustment_positive_tax_amount';
    public const BASE_POSITIVE_TAX = 'base_adjustment_positive_tax_amount';
    public const NEGATIVE_TAX = 'adjustment_negative_tax_amount';
    public const BASE_NEGATIVE_TAX = 'base_adjustment_negative_tax_amount';

    /**
     * Credit memo data key of the rows per rate calculated for the credit memo.
     */
    public const ROWS = 'adjustment_tax_rows';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @param int $creditmemoId
     * @param array<int, array{type: string, code: string, title: string, percent: float, amount: float,
     *     base_amount: float, taxable_amount: float, base_taxable_amount: float}> $rows
     * @return void
     */
    public function replaceRows(int $creditmemoId, array $rows): void
    {
        $connection = $this->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE, 'sales');
        $connection->delete($table, ['creditmemo_id = ?' => $creditmemoId]);
        if ($rows === []) {
            return;
        }
        $connection->insertMultiple(
            $table,
            array_map(static fn (array $row): array => ['creditmemo_id' => $creditmemoId] + $row, $rows)
        );
    }

    /**
     * @param int $creditmemoId
     * @return array<int, array{type: string, code: string, title: string, percent: float, amount: float,
     *     base_amount: float, taxable_amount: float, base_taxable_amount: float}>
     */
    public function getRows(int $creditmemoId): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(self::TABLE, 'sales'),
                ['type', 'code', 'title', 'percent', 'amount', 'base_amount', 'taxable_amount', 'base_taxable_amount']
            )
            ->where('creditmemo_id = ?', $creditmemoId)
            ->order('entity_id');

        return array_map(
            static fn (array $row): array => [
                'type' => (string)$row['type'],
                'code' => (string)$row['code'],
                'title' => (string)$row['title'],
                'percent' => (float)$row['percent'],
                'amount' => (float)$row['amount'],
                'base_amount' => (float)$row['base_amount'],
                'taxable_amount' => (float)$row['taxable_amount'],
                'base_taxable_amount' => (float)$row['base_taxable_amount'],
            ],
            $connection->fetchAll($select)
        );
    }

    /**
     * Adjustment tax of the stored credit memos of an order, refund minus fee.
     *
     * @param int $orderId
     * @return array{0: float, 1: float} Amount and base amount
     */
    public function getOrderAdjustmentTax(int $orderId): array
    {
        return $this->sumAdjustmentTax('order_id', $orderId);
    }

    /**
     * Adjustment tax of the stored credit memos of an invoice, refund minus fee.
     *
     * @param int $invoiceId
     * @return array{0: float, 1: float} Amount and base amount
     */
    public function getInvoiceAdjustmentTax(int $invoiceId): array
    {
        return $this->sumAdjustmentTax('invoice_id', $invoiceId);
    }

    /**
     * @param string $field
     * @param int $id
     * @return array{0: float, 1: float}
     */
    private function sumAdjustmentTax(string $field, int $id): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('sales_creditmemo', 'sales'),
                [
                    'amount' => new Expression(
                        sprintf(
                            'SUM(%s - %s)',
                            $connection->getIfNullSql(self::POSITIVE_TAX, 0),
                            $connection->getIfNullSql(self::NEGATIVE_TAX, 0)
                        )
                    ),
                    'base_amount' => new Expression(
                        sprintf(
                            'SUM(%s - %s)',
                            $connection->getIfNullSql(self::BASE_POSITIVE_TAX, 0),
                            $connection->getIfNullSql(self::BASE_NEGATIVE_TAX, 0)
                        )
                    ),
                ]
            )
            ->where("{$field} = ?", $id);
        $sum = $connection->fetchRow($select);

        return [(float)($sum['amount'] ?? 0.0), (float)($sum['base_amount'] ?? 0.0)];
    }

    /**
     * @return AdapterInterface
     */
    private function getConnection(): AdapterInterface
    {
        return $this->resourceConnection->getConnection('sales');
    }
}
