<?php

namespace Bkwld\ImportableShopifyFeeds\controllers;

use Bkwld\ImportableShopifyFeeds\Plugin;
use craft\web\Controller;
use yii\web\Response;

class FeedsController extends Controller
{
    // Allow public requests from FeedMe
    protected $allowAnonymous = true;

    /**
     * Get all products
     */
    public function actionProducts(): Response
    {
        $products = Plugin::getInstance()->shopifyAdminApi->getProducts();
        return $this->asJson($products);
    }

    /**
     * Get all variants
     */
    public function actionVariants(): Response
    {
        $variants = Plugin::getInstance()->shopifyAdminApi->getVariants();
        return $this->asJson($variants);
    }


}
