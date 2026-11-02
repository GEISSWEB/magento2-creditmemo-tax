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
use Geissweb\CreditmemoTax\Plugin\ExcludeAdjustmentTaxFromInvoiceRefunded;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Model\ResourceModel\Order\Invoice as InvoiceResource;
use PHPUnit\Framework\TestCase;

class ExcludeAdjustmentTaxFromInvoiceRefundedTest extends TestCase
{
    public function testDeductsTheAdjustmentTaxFromTheRefundedTax(): void
    {
        $resource = $this->createMock(AdjustmentTaxResource::class);
        $resource->expects(self::exactly(2))->method('getInvoiceAdjustmentTax')->with(7)->willReturn([1.9, 0.95]);
        $plugin = new ExcludeAdjustmentTaxFromInvoiceRefunded($resource);
        $invoiceResource = $this->createStub(InvoiceResource::class);

        self::assertSame(
            17.1,
            round($plugin->afterCalculateRefundedAmount($invoiceResource, 19.0, 7, CreditmemoInterface::TAX_AMOUNT), 2)
        );
        self::assertSame(
            8.55,
            round(
                $plugin->afterCalculateRefundedAmount($invoiceResource, 9.5, 7, CreditmemoInterface::BASE_TAX_AMOUNT),
                2
            )
        );
    }

    public function testOtherRefundedAmountsAreUnchanged(): void
    {
        $resource = $this->createMock(AdjustmentTaxResource::class);
        $resource->expects(self::never())->method('getInvoiceAdjustmentTax');
        $plugin = new ExcludeAdjustmentTaxFromInvoiceRefunded($resource);

        self::assertSame(
            2.5,
            $plugin->afterCalculateRefundedAmount(
                $this->createStub(InvoiceResource::class),
                2.5,
                7,
                CreditmemoInterface::DISCOUNT_TAX_COMPENSATION_AMOUNT
            )
        );
    }
}
