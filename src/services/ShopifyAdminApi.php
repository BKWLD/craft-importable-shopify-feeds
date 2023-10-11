<?php

namespace Bkwld\ImportableShopifyFeeds\services;

use Bkwld\ImportableShopifyFeeds\Plugin;
use Craft;
use yii\base\Component;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;

/**
 * Make Guzzle client for querying Shopify Admin API
 */
class ShopifyAdminApi extends Component
{
    private $client;
    private $disablePublishedCheck;

    /**
     * Make Guzzle instance
     */
    public function __construct()
    {
        list($url, $token) = $this->getCreds();
        $this->client = new Client([
            'base_uri' => $url.'/admin/api/2023-10/',
            'headers' => [
                'X-Shopify-Access-Token' => $token,
            ],
        ]);
        $this->disablePublishedCheck = Plugin::getInstance()
            ->settings->disablePublishedCheck;
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
    public function execute($payload): array
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
    public function paginate($payload): Collection
    {
        $results = new Collection;
        do {

            // Fetch this page and add to the results
            $response = $this->execute($payload);
            $results = $results->concat(
                $this->flattenEdges($response)['results']
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
    public function getProducts(): Collection
    {
        return $this->paginate([
            'query' => 'query getProducts($cursor: String) {
                results: products(first:250, after:$cursor) {
                    edges {
                        node {
                            title
                            handle
                            publishedOnCurrentPublication
                        }
                    }
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                }
            }'
        ])

        // Remove products that weren't published to the Sales Channel of the
        // Custom App whose credentials are being used to query the API.
        ->filter(function($variant) {
            return $variant['publishedOnCurrentPublication'];
        })

        // Remove fields that we're used to pre-filter
        ->map(function($variant) {
            unset($variant['publishedOnCurrentPublication']);
            return $variant;
        })

        // Use integer keys
        ->values();
    }

    /**
     * Get all variants of all products
     */
    public function getVariants(): Collection
    {
        return $this->paginate([
            'query' => 'query getVariants($cursor: String) {
                results: productVariants(first:250, after:$cursor) {
                    edges {
                        node {
                            title
                            sku
                            product {
                                title
                                handle
                                status
                                publishedOnCurrentPublication
                            }
                        }
                    }
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                }
            }'
        ])

        // Remove variants that are missing a sku
        ->filter(function($variant) {
            return !empty($variant['sku']);
        })

        // Remove variants that weren't published to the Sales Channel of the
        // Custom App whose credentials are being used to query the API. This
        // can be used to filter out products not intended for the public store,
        // like wholesale only products.
        ->when(!$this->disablePublishedCheck, function($variants) {
            return $variants->filter(function($variant) {
                return $variant['product']['publishedOnCurrentPublication'];
            });
        })

        // Dedupe by SKU, prefering active variants. Shopify allows you to
        // re-use SKUs between multiple product variants but this is used in
        // Feed Me as the unique identifier for importing.
        ->reduce(function($variants, $variant) {

            // Check if this variant has already been added
            $existingIndex = $variants->search(
                function($existing) use ($variant) {
                    return $existing['sku'] == $variant['sku'];
                }
            );

            // If this sku is already in the list, replace it if the previous
            // instance was not an active product
            // https://shopify.dev/api/admin-graphql/2022-04/enums/ProductStatus
            if ($existingIndex !== false) {
                $existing = $variants->get($existingIndex);
                if ($existing['product']['status'] != 'ACTIVE') {
                    $variants = $variants->replace([
                        $existingIndex => $variant
                    ]);
                }

            // ... else the variant didn't exist, so add it
            } else $variants->push($variant);

            // Keep working...
            return $variants;
        }, new Collection)

        // Make a title that is more useful for displaying in the CMS.
        ->map(function($variant) {
            $variant['dashboardTitle'] = $variant['product']['title']
                .' - '.$variant['title']
                .(($sku = $variant['sku']) ? ' ('.$sku.')' : null);
            return $variant;
        })

        // Remove fields that we're used to pre-filter
        ->map(function($variant) {
            unset(
                $variant['product']['status'],
                $variant['product']['publishedOnCurrentPublication']
            );
            return $variant;
        })

        // Use integer keys
        ->values();
    }

    /**
     * Get all collections
     */
    public function getCollections(): Collection
    {
        return $this->paginate([
            'query' => 'query collections($cursor: String) {
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
        if (!$obj || is_scalar($obj)) return $obj;

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
