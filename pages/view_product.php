<?php 
$query = <<<GRAPHQL
query MyQuery {
  product(id: "") {
    createdAt
    description
    handle
    id
    media(first: 1) {
      nodes {
        preview {
          image {
            url
          }
        }
      }
    }
    tags
    title
    totalInventory
    vendor
  }
}
GRAPHQL;
?>