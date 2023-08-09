<?php
namespace Bkwld\ImportableShopifyFeeds;

use craft\base\Model;

class Plugin extends \craft\base\Plugin
{
    public function init()
    {
        parent::init();

        $this->setComponents([
            'shopifyAdminApi' => services\ShopifyAdminApi::class,
        ]);
    }

    protected function createSettingsModel(): ?Model
    {
        return new models\Settings();
    }
}
