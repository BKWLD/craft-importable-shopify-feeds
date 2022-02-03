<?php
namespace Bkwld\ImportableShopifyFeeds;

class Plugin extends \craft\base\Plugin
{
    public function init()
    {
        parent::init();

        $this->setComponents([
            'shopifyAdminApi' => services\ShopifyAdminApi::class,
        ]);
    }
}
