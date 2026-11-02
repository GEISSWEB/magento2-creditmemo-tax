<?php
/**
 * ||GEISSWEB| Credit Memo Tax
 *
 * @copyright   Copyright (c) 2026 GEISS Weblösungen (https://www.geissweb.de)
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Geissweb\CreditmemoTax\Model\ResourceModel\Creditmemo;

use Geissweb\CreditmemoTax\Model\ResourceModel\AdjustmentTax as AdjustmentTaxResource;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\VersionControl\RelationInterface;

/**
 * Stores the adjustment tax rows calculated for a credit memo when the credit memo is saved.
 */
class AdjustmentTaxRelation implements RelationInterface
{
    /**
     * @param AdjustmentTaxResource $adjustmentTaxResource
     */
    public function __construct(
        private readonly AdjustmentTaxResource $adjustmentTaxResource
    ) {
    }

    /**
     * @param AbstractModel $object
     * @return void
     */
    public function processRelation(AbstractModel $object): void
    {
        if (!$object->hasData(AdjustmentTaxResource::ROWS)) {
            return;
        }
        $this->adjustmentTaxResource->replaceRows(
            (int)$object->getId(),
            $object->getData(AdjustmentTaxResource::ROWS)
        );
    }
}
