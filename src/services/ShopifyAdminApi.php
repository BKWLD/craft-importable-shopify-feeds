<?php

namespace Bkwld\ImportableShopifyFeeds\services;

use yii\base\Component;
use Exception;
use GuzzleHttp\Client;

/**
 * Make Guzzle client for querying Shopify Admin API
 */
class ShopifyAdminApi extends Component
{
    private $client;

    /**
     * Make Guzzle instance
     */
    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => getenv('SHOPIFY_URL').'/admin/api/2022-01/',
            'headers' => [
                'X-Shopify-Access-Token' =>
                    getenv('SHOPIFY_ADMIN_API_ACCESS_TOKEN') ?:
                    getenv('SHOPIFY_API_PASSWORD'),
            ],
        ]);
    }

    /**
     * Execute a GQL query
     */
    public function execute($payload)
    {
        $response = $this->client->post('graphql.json', [
            'json' => $payload,
        ]);
        $json = json_decode($response->getBody(), true);
        if (isset($json['data'])) return $json['data'];
        else throw new Exception($response->getBody());
    }

    /**
     * Get all products
     */
    public function getProducts()
    {
        $response = $this->execute([
            'query' => '{
                products(first:250) {
                    edges {
                        node {
                            title
                            handle
                        }
                    }
                }
            }'
        ]);
        return $this->flattenEdges($response)['products'];
    }

    /**
     * Get all variants of all products
     */
    public function getVariants()
    {
        $response = $this->execute([
            'query' => '{
                productVariants(first:250) {
                    edges {
                        node {
                            title
                            sku
                            product {
                                title
                                handle
                            }
                        }
                    }
                }
            }'
        ]);

        // Get array of variants
        $variants = $this->flattenEdges($response)['productVariants'];

        // Remove variants that are missing a sku
        $variants = array_filter($variants, function($variant) {
            return !empty($variant['sku']);
        });

        // Make a title that is more useful for displaying in the CMS.
        return array_map(function($variant) {
            $variant['dashboardTitle'] = $variant['product']['title']
                .' - '.$variant['title']
                .(($sku = $variant['sku']) ? ' ('.$sku.')' : null);
            return $variant;
        }, $variants);
    }

    /**
     * Get all products
     */
    public function getCollections()
    {
        $response = $this->execute([
            'query' => '{
                collections(first:250) {
                    edges {
                        node {
                            title
                            handle
                        }
                    }
                }
            }'
        ]);
        return $this->flattenEdges($response)['collections'];
    }

    /**
     * Flatten edges arrays
     */
    private function flattenEdges($obj) {

        // If a simple value, return
        if (is_scalar($obj)) return $obj;

        // Loop through object properties
        return array_map(function($val) {

            // If there is an "edges" child, flatten it's contents
            if (isset($val['edges'])) {
                $val = array_map(function($edge) {
                    return $edge['node'];
                }, $val['edges']);
            }

            // Recurse deeper
            return $this->flattenEdges($val);

        }, $obj);
    }
}
