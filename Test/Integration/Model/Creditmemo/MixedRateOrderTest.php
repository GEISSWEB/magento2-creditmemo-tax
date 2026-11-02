<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Test\Integration\Model\Creditmemo;

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
use Magento\Tax\Test\Fixture\ProductTaxClass as ProductTaxClassFixture;
use Magento\Tax\Test\Fixture\TaxRate as TaxRateFixture;
use Magento\Tax\Test\Fixture\TaxRule as TaxRuleFixture;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Order of one item at 100.00 plus 19 % tax and one at 100.00 plus 7 % tax, invoiced, free shipping.
 */
#[
    DbIsolation(true),
    DataFixture(
        TaxRateFixture::class,
        ['code' => 'de_19_%uniqid%', 'tax_country_id' => 'DE', 'rate' => 19.0],
        'rate19'
    ),
    DataFixture(
        TaxRateFixture::class,
        ['code' => 'de_7_%uniqid%', 'tax_country_id' => 'DE', 'rate' => 7.0],
        'rate7'
    ),
    DataFixture(ProductTaxClassFixture::class, ['class_name' => 'reduced_%uniqid%'], 'reducedClass'),
    DataFixture(
        TaxRuleFixture::class,
        [
            'code' => 'rule_19_%uniqid%',
            'tax_rate_ids' => ['$rate19.id$'],
            'customer_tax_class_ids' => [3],
            'product_tax_class_ids' => [2],
        ]
    ),
    DataFixture(
        TaxRuleFixture::class,
        [
            'code' => 'rule_7_%uniqid%',
            'tax_rate_ids' => ['$rate7.id$'],
            'customer_tax_class_ids' => [3],
            'product_tax_class_ids' => ['$reducedClass.id$'],
        ]
    ),
    DataFixture(ProductFixture::class, ['price' => 100.0, 'custom_attributes' => ['tax_class_id' => 2]], 'standard'),
    DataFixture(
        ProductFixture::class,
        ['price' => 100.0, 'custom_attributes' => ['tax_class_id' => '$reducedClass.id$']],
        'reduced'
    ),
    DataFixture(GuestCartFixture::class, as: 'cart'),
    DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$standard.id$', 'qty' => 1]),
    DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$reduced.id$', 'qty' => 1]),
    DataFixture(SetBillingAddressFixture::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS]),
    DataFixture(SetShippingAddressFixture::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS]),
    DataFixture(SetGuestEmailFixture::class, ['cart_id' => '$cart.id$', 'email' => 'guest@example.com']),
    DataFixture(SetDeliveryMethodFixture::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetPaymentMethodFixture::class, ['cart_id' => '$cart.id$']),
    DataFixture(PlaceOrderFixture::class, ['cart_id' => '$cart.id$'], 'order'),
    DataFixture(InvoiceFixture::class, ['order_id' => '$order.id$'], 'invoice'),
]
class MixedRateOrderTest extends TestCase
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
    public function testAGoodwillRefundIsSplitByTheRatesOfTheOpenItems(): void
    {
        $creditmemo = $this->refund([], 22.6, 0.0);

        self::assertAmount(22.6, $creditmemo->getGrandTotal());
        self::assertAmount(2.6, $creditmemo->getTaxAmount());
        self::assertSame(['refund 19' => [1.9, 10.0], 'refund 7' => [0.7, 10.0]], $this->storedRows($creditmemo));
        self::assertSame([7 => 0.7, 19 => 1.9], $this->taxSummary($creditmemo));
    }

    #[Config('carriers/flatrate/price', '0', 'store', 'default')]
    public function testAGoodwillRefundAfterTheReturnOfOneItemOnlyCarriesTheRateOfTheOtherItem(): void
    {
        $this->refund([$this->productId('reduced') => 1.0], 0.0, 0.0);

        $creditmemo = $this->refund([], 11.9, 0.0);

        self::assertAmount(1.9, $creditmemo->getTaxAmount());
        self::assertSame(['refund 19' => [1.9, 10.0]], $this->storedRows($creditmemo));
    }

    #[Config('carriers/flatrate/price', '0', 'store', 'default')]
    public function testAFeeOnAReturnCarriesTheRateOfTheReturnedItem(): void
    {
        $creditmemo = $this->refund([$this->productId('reduced') => 1.0], 0.0, 5.35);

        self::assertAmount(101.65, $creditmemo->getGrandTotal());
        self::assertAmount(6.65, $creditmemo->getTaxAmount());
        self::assertSame(['fee 7' => [-0.35, -5.0]], $this->storedRows($creditmemo));
        self::assertSame([7 => 6.65], $this->taxSummary($creditmemo));
    }

    #[Config('carriers/flatrate/price', '0', 'store', 'default')]
    public function testTheLastReturnAfterAReturnWithFeeRefundsOnlyItsOwnTax(): void
    {
        $this->refund([$this->productId('reduced') => 1.0], 0.0, 5.35);

        $creditmemo = $this->refund([$this->productId('standard') => 1.0], 0.0, 0.0);

        self::assertAmount(119.0, $creditmemo->getGrandTotal());
        self::assertAmount(19.0, $creditmemo->getTaxAmount());
        self::assertAmount(25.65, $this->fixture('order')->getTaxRefunded());
    }

    /**
     * @param string $fixture
     * @return int
     */
    private function productId(string $fixture): int
    {
        return (int)DataFixtureStorageManager::getStorage()->get($fixture)?->getId();
    }
}
