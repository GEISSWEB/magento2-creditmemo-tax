<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Test\Unit\Model\Creditmemo\Total;

use Geissweb\CreditmemoTax\Model\Creditmemo\AdjustmentTaxAllocator;
use Geissweb\CreditmemoTax\Model\Creditmemo\Basis\Line;
use Geissweb\CreditmemoTax\Model\Creditmemo\Basis\LineBuilder;
use Geissweb\CreditmemoTax\Model\Creditmemo\Basis\Rate;
use Geissweb\CreditmemoTax\Model\Creditmemo\Total\AdjustmentTax;
use Geissweb\CreditmemoTax\Model\ResourceModel\AdjustmentTax as AdjustmentTaxResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;

class AdjustmentTaxTest extends TestCase
{
    private const STORE_ID = 3;

    public function testAddsTheTaxOfTheAdjustmentsToTheCreditmemoTax(): void
    {
        $creditmemo = $this->creditmemo(10.0, 0.0);
        $lineBuilder = $this->createStub(LineBuilder::class);
        $lineBuilder->method('forCreditmemo')->willReturn([$this->line()]);

        $this->collector(true, $lineBuilder)->collect($creditmemo);

        self::assertSame(20.6, $creditmemo->getTaxAmount());
        self::assertSame(20.6, $creditmemo->getBaseTaxAmount());
        self::assertSame(119.0, $creditmemo->getGrandTotal());
        self::assertSame(1.6, $creditmemo->getData(AdjustmentTaxResource::POSITIVE_TAX));
        self::assertSame(1.6, $creditmemo->getData(AdjustmentTaxResource::BASE_POSITIVE_TAX));
        self::assertSame(0.0, $creditmemo->getData(AdjustmentTaxResource::NEGATIVE_TAX));
        self::assertSame(0.0, $creditmemo->getData(AdjustmentTaxResource::BASE_NEGATIVE_TAX));
        self::assertSame(1.6, $creditmemo->getData(AdjustmentTaxResource::ROWS)[0]['amount']);
    }

    public function testTheTaxOfAFeeIsDeductedFromTheCreditmemoTax(): void
    {
        $creditmemo = $this->creditmemo(0.0, 11.9);
        $lineBuilder = $this->createStub(LineBuilder::class);
        $lineBuilder->method('forCreditmemo')->willReturn([$this->line()]);

        $this->collector(true, $lineBuilder)->collect($creditmemo);

        self::assertSame(17.1, $creditmemo->getTaxAmount());
        self::assertSame(1.9, $creditmemo->getData(AdjustmentTaxResource::NEGATIVE_TAX));
    }

    public function testFallsBackToTheOpenAmountsAndThenToTheInvoicedAmounts(): void
    {
        $creditmemo = $this->creditmemo(10.0, 0.0);
        $lineBuilder = $this->createMock(LineBuilder::class);
        $lineBuilder->expects(self::once())->method('forCreditmemo')->with($creditmemo)->willReturn([]);
        $lineBuilder->expects(self::once())->method('forOpenAmounts')->with($creditmemo)->willReturn([]);
        $lineBuilder->expects(self::once())->method('forInvoiced')->with($creditmemo)->willReturn([$this->line()]);

        $this->collector(true, $lineBuilder)->collect($creditmemo);

        self::assertSame(1.6, $creditmemo->getData(AdjustmentTaxResource::POSITIVE_TAX));
    }

    public function testOpenAmountsAreUsedWhenTheCreditmemoHasNoLines(): void
    {
        $creditmemo = $this->creditmemo(10.0, 0.0);
        $lineBuilder = $this->createMock(LineBuilder::class);
        $lineBuilder->expects(self::once())->method('forCreditmemo')->willReturn([]);
        $lineBuilder->expects(self::once())->method('forOpenAmounts')->willReturn([$this->line()]);
        $lineBuilder->expects(self::never())->method('forInvoiced');

        $this->collector(true, $lineBuilder)->collect($creditmemo);

        self::assertSame(1.6, $creditmemo->getData(AdjustmentTaxResource::POSITIVE_TAX));
    }

    public function testLeavesTheCreditmemoUnchangedWhenDisabled(): void
    {
        $creditmemo = $this->creditmemo(10.0, 0.0);
        $lineBuilder = $this->createMock(LineBuilder::class);
        $lineBuilder->expects(self::never())->method('forCreditmemo');

        $this->collector(false, $lineBuilder)->collect($creditmemo);

        self::assertSame(19.0, $creditmemo->getTaxAmount());
        self::assertFalse($creditmemo->hasData(AdjustmentTaxResource::POSITIVE_TAX));
        self::assertFalse($creditmemo->hasData(AdjustmentTaxResource::ROWS));
    }

    public function testLeavesTheCreditmemoUnchangedWithoutAdjustments(): void
    {
        $creditmemo = $this->creditmemo(0.0, 0.0);
        $lineBuilder = $this->createMock(LineBuilder::class);
        $lineBuilder->expects(self::never())->method('forCreditmemo');

        $this->collector(true, $lineBuilder)->collect($creditmemo);

        self::assertSame(19.0, $creditmemo->getTaxAmount());
        self::assertFalse($creditmemo->hasData(AdjustmentTaxResource::POSITIVE_TAX));
    }

    private function line(): Line
    {
        return new Line(119.0, 119.0, ['DE-19' => new Rate('DE-19', 'VAT 19%', 19.0, 19.0, 19.0)]);
    }

    private function creditmemo(float $positive, float $negative): Creditmemo
    {
        $creditmemo = $this->getMockBuilder(Creditmemo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrder'])
            ->getMock();
        $creditmemo->expects(self::never())->method('getOrder');
        $creditmemo->setData([
            'store_id' => self::STORE_ID,
            'adjustment_positive' => $positive,
            'base_adjustment_positive' => $positive,
            'adjustment_negative' => $negative,
            'base_adjustment_negative' => $negative,
            'tax_amount' => 19.0,
            'base_tax_amount' => 19.0,
            'grand_total' => 119.0,
            'base_grand_total' => 119.0,
        ]);
        return $creditmemo;
    }

    private function collector(bool $enabled, LineBuilder $lineBuilder): AdjustmentTax
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static function (string $path, string $scope, $storeId) use ($enabled): bool {
                self::assertSame([AdjustmentTax::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, self::STORE_ID], [
                    $path,
                    $scope,
                    $storeId,
                ]);
                return $enabled;
            }
        );
        $priceCurrency = $this->createStub(PriceCurrencyInterface::class);
        $priceCurrency->method('round')->willReturnCallback(static fn ($price): float => round((float)$price, 2));

        return new AdjustmentTax($scopeConfig, $lineBuilder, new AdjustmentTaxAllocator($priceCurrency));
    }
}
