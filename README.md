# Importable Shopify Feeds

A Craft plugin that maps Shopify data into feeds that are more easily consumed by FeedMe.

## Setup

This expects that your Craft .env file has:

```env
SHOPIFY_URL=https://your-store.myshopify.com
SHOPIFY_ADMIN_API_ACCESS_TOKEN=xxxxxxxxxxx
```

In addition, you'll need to give your Custom App the following permissions:

- Product listings
  - `read_product_listings`
- Products
  - `read_products`

## Usage

Set up FeedMe to query either of the following routes.  We're only exposing a minimum set of data that can be used to create matching Craft products.  It's assumed that Shopify is the source of truth for all other fields and their data shouldn't be cached in Craft.

#### Products

`https://cms-domain.com/actions/importable-shopify-feeds/feeds/products`

```json
[
  {
    "title": "Product Title",
    "handle": "product-title"
  },
]
```

#### Variants

`https://cms-domain.com/actions/importable-shopify-feeds/feeds/variants`

```json
[
  {
    "title": "Variant Title",
    "sku": "413071",
    "product":{
      "title": "Product Title",
      "handle": "product-title"
    },
    "dashboardTitle": "Product Title - Variant Title (413071)"
  },
]
```
