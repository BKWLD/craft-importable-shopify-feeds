<?php

namespace Bkwld\ImportableShopifyFeeds\services;

use Craft;
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
        list($url, $token) = $this->getCreds();
        $this->client = new Client([
            'base_uri' => $url.'/admin/api/2022-04/',
            'headers' => [
                'X-Shopify-Access-Token' => $token,
            ],
        ]);
    }

    /**
     * Get the Shopify creds to use, supporting switching creds based on store
     * query param
     */
    private function getCreds()
    {
        $store = Craft::$app->request->getQueryParam('store');
        $suffix = $store ? '_'.$store : '';
        if (!$url = getenv('SHOPIFY_URL'.$suffix)) {
            throw new Exception('Missing SHOPIFY_URL');
        }
        if (!$token = (getenv('SHOPIFY_ADMIN_API_ACCESS_TOKEN'.$suffix) ?:
            getenv('SHOPIFY_API_PASSWORD'.$suffix))) {
            throw new Exception('Missing SHOPIFY_ADMIN_API_ACCESS_TOKEN');
        }
        return [$url, $token];
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
     * Execute a GQL query and paginate through the results
     */
    public function paginate($payload)
    {
        $results = [];
        do {

            // Fetch this page and add to the results
            $response = $this->execute($payload);
            $results = array_merge(
                $results,
                $this->flattenEdges($response)['results'],
            );

            // If there is another page, add the end cursor to the next
            // request
            $pageInfo = $response['results']['pageInfo'] ?? [];
            $hasNextPage = $pageInfo['hasNextPage'] ?? false;
            if ($hasNextPage) {
                $payload['variables'] = array_merge(
                    $payload['variables'] ?? [],
                    ['cursor' => $pageInfo['endCursor']],
                );
            }
        } while ($hasNextPage);

        // Return the final list of results, fixing string keys
        return $results;
    }

    /**
     * Get all products
     */
    public function getProducts()
    {
        return $this->paginate([
            'query' => 'query getProducts($cursor: String) {
                results: products(first:250, after:$cursor) {
                    edges {
                        node {
                            title
                            handle
                        }
                    }
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                }
            }'
        ]);
    }

    /**
     * Get all variants of all products
     */
    public function getVariants()
    {
        $variants = $this->paginate([
            'query' => 'query getVariants($cursor: String) {
                results: productVariants(first:250, after:$cursor) {
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
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                }
            }'
        ]);

        // Remove variants that are missing a sku
        $variants = array_filter($variants, function($variant) {
            return !empty($variant['sku']);
        });

        // Make a title that is more useful for displaying in the CMS.
        $variants = array_map(function($variant) {
            $variant['dashboardTitle'] = $variant['product']['title']
                .' - '.$variant['title']
                .(($sku = $variant['sku']) ? ' ('.$sku.')' : null);
            return $variant;
        }, $variants);

        // Convert string keys to integer
        return array_values($variants);
    }

    /**
     * Get all collections
     */
    public function getCollections()
    {
        return $this->paginate([
            'query' => 'collections($cursor: String) {
                results: collections(first:250, after:$cursor) {
                    edges {
                        node {
                            title
                            handle
                        }
                    }
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                }
            }'
        ]);
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
