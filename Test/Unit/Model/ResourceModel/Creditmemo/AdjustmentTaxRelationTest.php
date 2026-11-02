<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Test\Unit\Model\ResourceModel\Creditmemo;

use Geissweb\CreditmemoTax\Model\ResourceModel\AdjustmentTax as AdjustmentTaxResource;
use Geissweb\CreditmemoTax\Model\ResourceModel\Creditmemo\AdjustmentTaxRelation;
use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\TestCase;

class AdjustmentTaxRelationTest extends TestCase
{
    public function testStoresTheRowsCalculatedForTheCreditmemo(): void
    {
        $rows = [[
            'type' => 'refund',
            'code' => 'DE-19',
            'title' => 'VAT 19%',
            'percent' => 19.0,
            'amount' => 1.6,
            'base_amount' => 1.6,
            'taxable_amount' => 8.4,
            'base_taxable_amount' => 8.4,
        ]];
        $resource = $this->createMock(AdjustmentTaxResource::class);
        $resource->expects(self::once())->method('replaceRows')->with(12, $rows);

        (new AdjustmentTaxRelation($resource))->processRelation($this->creditmemo([AdjustmentTaxResource::ROWS => $rows]));
    }

    public function testLeavesStoredRowsAloneWhenNothingWasCalculated(): void
    {
        $resource = $this->createMock(AdjustmentTaxResource::class);
        $resource->expects(self::never())->method('replaceRows');

        (new AdjustmentTaxRelation($resource))->processRelation($this->creditmemo([]));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function creditmemo(array $data): Creditmemo
    {
        $creditmemo = $this->getMockBuilder(Creditmemo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $creditmemo->expects($data === [] ? self::never() : self::once())->method('getId')->willReturn('12');
        $creditmemo->setData($data);
        return $creditmemo;
    }
}
