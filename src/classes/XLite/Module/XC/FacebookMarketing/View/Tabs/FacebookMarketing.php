<?php
// vim: set ts=4 sw=4 sts=4 et:

/**
 * Copyright (c) 2011-present Qualiteam software Ltd. All rights reserved.
 * See https://www.x-cart.com/license-agreement.html for license details.
 */

namespace XLite\Module\XC\FacebookMarketing\View\Tabs;

/**
 * FacebookMarketing
 *
 * @ListChild (list="admin.center", zone="admin")
 */
class FacebookMarketing extends \XLite\View\Tabs\ATabs
{
    use \XLite\Core\Cache\ExecuteCachedTrait;

    /**
     * Return list of targets allowed for this widget
     *
     * @return array
     */
    public static function getAllowedTargets()
    {
        $result = parent::getAllowedTargets();
        $result[] = 'facebook_marketing';

        return $result;
    }

    /**
     * @inheritdoc
     */
    protected function defineTabs()
    {
        return [
            'facebook_marketing' => [
                'weight'     => 100,
                'title'      => static::t('Product feed'),
                'template'   => 'modules/XC/FacebookMarketing/general/body.twig',
            ],
        ];
    }

    /**
     * Return product feed
     *
     * @return \XLite\Module\XC\FacebookMarketing\Model\ProductFeed\IProductFeed
     */
    protected function getProductFeed()
    {
        return $this->executeCachedRuntime(function () {
            return new \XLite\Module\XC\FacebookMarketing\Model\ProductFeed\AllProductsFeed;
        });
    }

    /**
     * Check if product feed generated
     *
     * @return bool
     */
    protected function isProductFeedGenerated()
    {
        return file_exists($this->getProductFeed()->getStoragePath());
    }

    /**
     * Return product feed download url
     *
     * @return string
     */
    protected function getProductFeedUrl()
    {
        if (!$this->getFeedKey()) {
            $this->generateFeedKey();
        }

        return \XLite\Core\Converter::buildFullURL('facebook_product_feed', '', [
            'key' => $this->getFeedKey(),
        ], \XLite::CART_SELF);
    }

    /**
     * Return product feed key
     *
     * @return mixed
     */
    protected function getFeedKey()
    {
        return \XLite\Core\Config::getInstance()->XC->FacebookMarketing->product_feed_key;
    }

    /**
     * Generate & set product feed key
     */
    protected function generateFeedKey()
    {
        $key = function_exists('openssl_random_pseudo_bytes')
            ? bin2hex(openssl_random_pseudo_bytes(16))
            : md5(microtime(true) + mt_rand(0, 1000000));

        \XLite\Core\Database::getRepo('XLite\Model\Config')->createOption([
            'category' => 'XC\FacebookMarketing',
            'name' => 'product_feed_key',
            'value' => $key,
        ]);
    }

    /**
     * Return Facebook Pixel id
     *
     * @return boolean
     */
    protected function getPixelId()
    {
        return \XLite\Core\Config::getInstance()->XC->FacebookMarketing->pixel_id;
    }

    /**
     * Check if should include out of stock products
     *
     * @return boolean
     */
    protected function isIncludeOutOfStock()
    {
        return 'Y' === \XLite\Core\Config::getInstance()->XC->FacebookMarketing->include_out_of_stock;
    }

    /**
     * Check if widget is visible
     *
     * @return boolean
     */
    protected function getRenewalFrequency()
    {
        return \XLite\Module\XC\FacebookMarketing\Core\Task\GenerateProductFeed::getRenewalPeriod();
    }
}
