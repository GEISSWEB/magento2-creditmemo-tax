<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Test\Integration\Model\Creditmemo;

use Geissweb\CreditmemoTax\Model\ResourceModel\AdjustmentTax as AdjustmentTaxResource;
use Geissweb\CreditmemoTax\Test\Integration\RefundTrait;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Checkout\Test\Fixture\PlaceOrder as PlaceOrderFixture;
use Magento\Checkout\Test\Fixture\SetBillingAddress as SetBillingAddressFixture;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod as SetDeliveryMethodFixture;
use Magento\Checkout\Test\Fixture\SetGuestEmail as SetGuestEmailFixture;
use Magento\Checkout\Test\Fixture\SetPaymentMethod as SetPaymentMethodFixture;
use Magento\Checkout\Test\Fixture\SetShippingAddress as SetShippingAddressFixture;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\GuestCart as GuestCartFixture;
use Magento\Sales\Test\Fixture\Invoice as InvoiceFixture;
use Magento\Tax\Test\Fixture\TaxRate as TaxRateFixture;
use Magento\Tax\Test\Fixture\TaxRule as TaxRuleFixture;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Order of one item at 100.00 plus 19 % tax, invoiced. Shipping is free unless a test configures it.
 */
#[
    DbIsolation(true),
    DataFixture(
        TaxRateFixture::class,
        ['code' => 'de_19_%uniqid%', 'tax_country_id' => 'DE', 'rate' => 19.0],
        'rate19'
    ),
    DataFixture(
        TaxRuleFixture::class,
        [
            'code' => 'rule_19_%uniqid%',
            'tax_rate_ids' => ['$rate19.id$'],
            'customer_tax_class_ids' => [3],
            'product_tax_class_ids' => [2],
        ]
    ),
    DataFixture(ProductFixture::class, ['price' => 100.0, 'custom_attributes' => ['tax_class_id' => 2]], 'product'),
    DataFixture(GuestCartFixture::class, as: 'cart'),
    DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddressFixture::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS]),
    DataFixture(SetShippingAddressFixture::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS]),
    DataFixture(SetGuestEmailFixture::class, ['cart_id' => '$cart.id$', 'email' => 'guest@example.com']),
    DataFixture(SetDeliveryMethodFixture::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetPaymentMethodFixture::class, ['cart_id' => '$cart.id$']),
    DataFixture(PlaceOrderFixture::class, ['cart_id' => '$cart.id$'], 'order'),
    DataFixture(InvoiceFixture::class, ['order_id' => '$order.id$'], 'invoice'),
]
class SingleRateOrderTest extends TestCase
{
    use RefundTrait;

    private const ADDRESS = [
        'firstname' => 'Erika',
        'lastname' => 'Musterfrau',
        'street' => ['Musterstraße 1'],
        'city' => 'Berlin',
        'postcode' => '10115',
        'country_id' => 'DE',
        'region_id' => 82,
        'telephone' => '0301234567',
    ];

    #[Config('carriers/flatrate/price', '0', 'store', 'default')]
    public function testAGoodwillRefundContainsTheTaxOfTheOrder(): void
    {
        $creditmemo = $this->refund([], 11.9, 0.0);

        self::assertAmount(11.9, $creditmemo->getGrandTotal());
        self::assertAmount(1.9, $creditmemo->getTaxAmount());
        self::assertAmount(1.9, $creditmemo->getData(AdjustmentTaxResource::POSITIVE_TAX));
        self::assertAmount(1.9, $creditmemo->getData(AdjustmentTaxResource::BASE_POSITIVE_TAX));
        self::assertSame(['refund 19' => [1.9, 10.0]], $this->storedRows($creditmemo));
        self::assertSame([19 => 1.9], $this->taxSummary($creditmemo));
        self::assertAmount(1.9, $this->fixture('order')->getTaxRefunded());
    }

    /**
     * A full refund reduced by a fee to the amount actually paid back (magento/magento2#23938).
     */
    #[Config('carriers/flatrate/price', '0', 'store', 'default')]
    public function testAFeeReducingAFullRefundReducesTheTaxProportionally(): void
    {
        $product = (int)$this->fixture('order')->getAllItems()[0]->getProductId();

        $creditmemo = $this->refund([$product => 1.0], 0.0, 107.1);

        self::assertAmount(11.9, $creditmemo->getGrandTotal());
        self::assertAmount(1.9, $creditmemo->getTaxAmount());
        self::assertAmount(17.1, $creditmemo->getData(AdjustmentTaxResource::NEGATIVE_TAX));
        self::assertSame(['fee 19' => [-17.1, -90.0]], $this->storedRows($creditmemo));
        self::assertSame([19 => 1.9], $this->taxSummary($creditmemo));
    }

    #[Config('carriers/flatrate/price', '0', 'store', 'default')]
    public function testAReturnAfterAGoodwillRefundRefundsTheRemainingTax(): void
    {
        $product = (int)$this->fixture('order')->getAllItems()[0]->getProductId();

        $goodwill = $this->refund([], 11.9, 0.0);
        $return = $this->refund([$product => 1.0], 0.0, 11.9);

        self::assertAmount(107.1, $return->getGrandTotal());
        self::assertAmount(17.1, $return->getTaxAmount());
        self::assertAmount(19.0, (float)$goodwill->getTaxAmount() + (float)$return->getTaxAmount());
        self::assertAmount(19.0, $this->fixture('order')->getTaxRefunded());
    }

    #[Config('carriers/flatrate/price', '0', 'store', 'default')]
    public function testAnInvoiceBasedReturnAfterAGoodwillRefundRefundsTheRemainingTax(): void
    {
        $product = (int)$this->fixture('order')->getAllItems()[0]->getProductId();

        $goodwill = $this->refund([], 11.9, 0.0, true);
        $return = $this->refund([$product => 1.0], 0.0, 11.9, true);

        self::assertAmount(1.9, $goodwill->getTaxAmount());
        self::assertAmount(107.1, $return->getGrandTotal());
        self::assertAmount(17.1, $return->getTaxAmount());
    }

    #[
        Config('carriers/flatrate/price', '10', 'store', 'default'),
        Config('tax/classes/shipping_tax_class', '2', 'store', 'default'),
    ]
    public function testTaxedShippingCountsTowardsTheTaxOfAGoodwillRefund(): void
    {
        $creditmemo = $this->refund([], 13.09, 0.0);

        self::assertAmount(2.09, $creditmemo->getTaxAmount());
        self::assertSame(['refund 19' => [2.09, 11.0]], $this->storedRows($creditmemo));
    }

    #[
        Config('carriers/flatrate/price', '0', 'store', 'default'),
        Config('tax/creditmemo_adjustments/calculate_tax', '0', 'store', 'default'),
    ]
    public function testNoAdjustmentTaxIsCalculatedWhenDisabled(): void
    {
        $creditmemo = $this->refund([], 11.9, 0.0);

        self::assertAmount(11.9, $creditmemo->getGrandTotal());
        self::assertAmount(0.0, $creditmemo->getTaxAmount());
        self::assertNull($creditmemo->getData(AdjustmentTaxResource::POSITIVE_TAX));
        self::assertSame([], $this->storedRows($creditmemo));
    }
}
