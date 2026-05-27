<?php
$query = <<<GRAPHQL
mutation MyMutation {
  productCreate(
    product: {handle: "", tags: "", title: "", vendor: "", status: ACTIVE}
    media: {originalSource: "", mediaContentType: IMAGE}
  ) {
    product {
      createdAt
      description
      id
      handle
      media(first: 1) {
        edges {
          cursor
        }
      }
      totalInventory
      title
      tags
      vendor
      status
    }
  }
}
GRAPHQL;
?>