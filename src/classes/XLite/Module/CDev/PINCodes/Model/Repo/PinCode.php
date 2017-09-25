<?php
// vim: set ts=4 sw=4 sts=4 et:

/**
 * Copyright (c) 2011-present Qualiteam software Ltd. All rights reserved.
 * See https://www.x-cart.com/license-agreement.html for license details.
 */

namespace XLite\Module\CDev\PINCodes\Model\Repo;

/**
 * @Api\Operation\Create(modelClass="XLite\Module\CDev\PINCodes\Model\PinCode", summary="Add pincode")
 * @Api\Operation\Read(modelClass="XLite\Module\CDev\PINCodes\Model\PinCode", summary="Retrieve pincode by id")
 * @Api\Operation\ReadAll(modelClass="XLite\Module\CDev\PINCodes\Model\PinCode", summary="Retrieve pincodes by conditions")
 * @Api\Operation\Update(modelClass="XLite\Module\CDev\PINCodes\Model\PinCode", summary="Update pincode by id")
 * @Api\Operation\Delete(modelClass="XLite\Module\CDev\PINCodes\Model\PinCode", summary="Delete pincode by id")
 *
 * @SWG\Tag(
 *   name="CDev\PINCodes\PinCode",
 *   x={"display-name": "PinCode", "group": "CDev\PINCodes"},
 *   description="Pincode allows to attach some text information (e.g. CD key, product registration code) to the product and sell it within"
 * )
 */
class PinCode extends \XLite\Model\Repo\ARepo
{
    /**
     * Prepare certain search condition
     *
     * @Api\Condition(description="Filters pincodes by certain product id", type="integer")
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param string                     $value        Condition data
     *
     * @return void
     */
    protected function prepareCndProduct(\Doctrine\ORM\QueryBuilder $queryBuilder, $value)
    {
        $queryBuilder
            ->andWhere('p.product=:product')
            ->setParameter('product', $value);
    }

    /**
     * Counts sold pin codes by product
     *
     * @param \XLite\Model\Product $product Product
     *
     * @return integer
     */
    public function countSold(\XLite\Model\Product $product)
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.product = :product AND p.isSold = :true')
            ->setParameter('true', true)
            ->setParameter('product', $product)
            ->count();
    }

    /**
     * Counts blocked pin codes by product
     *
     * @param \XLite\Model\Product $product Product
     *
     * @return integer
     */
    public function countBlocked(\XLite\Model\Product $product)
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.product = :product AND (p.isSold = :true1 OR p.isBlocked = :true2)')
            ->setParameter('true1', true)
            ->setParameter('true2', true)
            ->setParameter('product', $product)
            ->count();
    }

    /**
     * Counts sold pin codes by product
     *
     * @param \XLite\Model\Product $product Product
     *
     * @return integer
     */
    public function countRemaining(\XLite\Model\Product $product)
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.product = :product AND p.isSold = :false1 AND p.isBlocked = :false2')
            ->setParameter('false1', false)
            ->setParameter('false2', false)
            ->setParameter('product', $product)
            ->count();
    }

    /**
     * Returns not sold pin code 
     *
     * @param \XLite\Model\Product $product Product
     * @param integer              $index   Index
     *
     * @return \XLite\Module\CDev\PINCodes\Model\PinCode
     */
    public function getAvailablePinCode(\XLite\Model\Product $product, $index)
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.product = :product AND p.isSold = :false1 AND p.isBlocked = :false2')
            ->addOrderBy('p.id')
            ->setParameter('false1', false)
            ->setParameter('false2', false)
            ->setParameter('product', $product)
            ->setFirstResult($index)
            ->setMaxResults(1)
            ->getSingleResult();
    }

    /**
     * Returns not sold pin code 
     *
     * @param \XLite\Model\Product $product Product
     * @param integer              $count   Count
     *
     * @return \XLite\Module\CDev\PINCodes\Model\PinCode
     */
    public function getAvailablePinCodes(\XLite\Model\Product $product, $count)
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.product = :product AND p.isSold = :false1 AND p.isBlocked = :false2')
            ->addOrderBy('p.id')
            ->setParameter('false1', false)
            ->setParameter('false2', false)
            ->setParameter('product', $product)
            ->setMaxResults($count)
            ->getResult();
    }
}
