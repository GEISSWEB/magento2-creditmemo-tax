# Changelog

## 0.1.0

- Tax contained in Adjustment Refund and Adjustment Fee of credit memos, split by the tax rates of the refunded
  items and shipping, otherwise of the items and shipping not refunded yet, otherwise of the invoiced ones. The tax of
  each share is taken at its rate as for prices including tax; refund and fee of a rate are rounded as one amount.
  The part of a shipping not covered by its tax counts as untaxed.
- Stored per credit memo, adjustment and rate, including the untaxed share; taxable amounts plus tax add up to the
  adjustment. Included in the credit memo tax and the tax summary.
- Adjustment rows excluding and/or including tax in admin, customer account, emails and PDF.
- Item and shipping tax of later credit memos unaffected by earlier adjustment tax.
- Setting *Calculate Tax on Adjustments* per website.
