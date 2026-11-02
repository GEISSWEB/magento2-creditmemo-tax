# Geissweb_CreditmemoTax

This module calculates the adjustment tax for Magento 2 credit memos. The adjustment tax is the tax that the Adjustment
Refund and the Adjustment Fee contain. The module adds it to the tax of the credit memo, split by tax rate.

Magento calculates no tax for the two adjustments. It adds them to the grand total of the credit memo without tax. The
tax of the credit memo then does not match the refunded amount:

- A credit memo that refunds only an Adjustment Refund, for example a goodwill refund, shows a tax of 0.
- A credit memo with an Adjustment Fee still refunds the full tax of the returned items. But the merchant keeps a part
  of the price.
- The tax summary per tax rate in the admin panel, the customer account, the emails, and the PDF does not include the
  adjustments. The `tax_refunded` of the order does not include them either.

Adobe closed the issue [magento/magento2#23938](https://github.com/magento/magento2/issues/23938) as a non-issue. The
module corrects the tax without a change to Magento core files. It takes the tax rates from the tax data that Magento
stored for the order and its invoices as its calculation base.

## How the module calculates the tax

The module treats each adjustment as an amount *including tax*. This amount is the money that the merchant pays back or
keeps. The grand total and the amount that the payment gateway refunds stay as entered. The fee is a negative
adjustment, so its tax decreases the refunded tax.

The module splits each adjustment across the tax rates of a basis. The basis is the first of these sets of lines that
is not empty:

1. The items and the shipping that the credit memo refunds.
2. The items and the shipping that are not refunded yet. If the credit memo is based on an invoice, only the lines of
   that invoice count.
3. All invoiced items and the invoiced shipping.

Each tax rate gets a share of the adjustment in proportion to its amount including tax in the basis. Lines without tax
get an untaxed share in the same way. The module rounds the shares to cents and gives the missing cents to the largest
remainders. The shares therefore add up to the adjustment exactly. If a line of the basis has more than one tax rate,
the module splits the line as `Magento\Tax\Helper\Data::getCalculatedTaxes()` does.

The module calculates the tax of each share at its rate, as Magento does for prices including tax:
share × rate / (100 + rate). The taxable amount (the amount without tax) is the share minus the tax.

The module rounds the refund and the fee of one tax rate as one amount. The tax of the fee is the tax of the refund
minus the tax of the difference between refund and fee. The tax of a rate is therefore the tax that this difference
contains.

If the tax of a shipping covers only a part of the shipping amount, the module adds the rest to the untaxed share. This
occurs, for example, when the shipping tax follows the ratio of taxed and untaxed items. The taxed part of a rate is
its tax × (100 + rate) / rate. If the order has no tax, the full adjustment is untaxed.

## What the module changes

- `sales_creditmemo.tax_amount` includes the adjustment tax. The `tax_refunded` of the order therefore includes it
  too.
- The module adds four columns to `sales_creditmemo`: `adjustment_positive_tax_amount`,
  `base_adjustment_positive_tax_amount`, `adjustment_negative_tax_amount`, and `base_adjustment_negative_tax_amount`.
- The module adds the table `sales_creditmemo_adjustment_tax`. The next section describes it.
- The tax summary per tax rate includes the adjustment tax. This applies to the admin panel, the customer account, the
  emails, the PDF, and the GraphQL field `taxes`.
- The adjustment rows follow the configuration field *Display Subtotal* in *Orders, Invoices, Credit Memos Display
  Settings*. They show the amount excluding tax, including tax, or both.
- Magento limits the item tax and the shipping tax of a credit memo to the tax that is not refunded yet. The module
  leaves the adjustment tax of earlier credit memos out of this limit. A later return therefore still refunds the full
  item tax.

## Stored tax rows

The table `sales_creditmemo_adjustment_tax` has one row for each credit memo, adjustment, and tax rate. Other modules
can read the table, for example a module for e-invoices. They can then write the adjustments with the values that this
module calculated.

| Column | Content |
|---|---|
| `creditmemo_id` | Credit memo |
| `type` | `refund` (Adjustment Refund) or `fee` (Adjustment Fee) |
| `code`, `title`, `percent` | Tax rate as stored for the order. The untaxed share has an empty code, an empty title, and the percent 0. |
| `amount`, `base_amount` | Tax |
| `taxable_amount`, `base_taxable_amount` | Taxable amount |

For each credit memo and adjustment, the taxable amounts plus the tax amounts are equal to the adjustment. The rows of
the fee have negative amounts, so their sum is the negative fee. Some tax rates apply to the same amount, for example a
state tax and a county tax. Each row of such a rate has the full taxable amount. The sum then counts this taxable
amount once.

## Configuration

The configuration field is *Stores > Configuration > Sales > Tax > Credit Memo Adjustments > Calculate Tax on
Adjustments*. The default is *Yes*, and the scope is website. A change applies only to credit memos that you create
after the change. Credit memos with stored adjustment tax keep it.

## Compatibility

The module requires PHP 8.1 or later. It supports Magento Open Source and Adobe Commerce 2.4.6 to 2.4.9, and Mage-OS.

The module uses a total collector for credit memos, plugins, layout updates, and PDF total models. A total collector is
a class that calculates one part of the credit memo totals. The module uses no preferences (class replacements in
`di.xml`). It replaces the PDF subtotal model of `Magento_Tax`.

## Limitations

- If a fee is compensation for damages and has no tax, the module still calculates tax on it. The module always treats
  a fee as part of the price that the merchant keeps.
- Fixed product taxes (WEEE) and payment method fees are not part of the basis.
- For some amounts including tax, no taxable amount and tax in cents match the rate exactly. For these amounts, the
  taxable amount × rate, rounded to cents, differs from the tax by one cent.
- If a line has tax rates of different priority (compound taxes), the module treats them as rates on the same amount.

## Installation

Run these commands in the Magento root directory:

```bash
composer require geissweb/module-creditmemo-tax
bin/magento module:enable Geissweb_CreditmemoTax
bin/magento setup:upgrade
```

The last command adds the database columns and the table. The module does not change credit memos that exist before
the installation.

## License

The license for the code is [OSL-3.0](LICENSE.txt). The license for the layout files is [AFL-3.0](LICENSE_AFL.txt).
