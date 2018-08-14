# OpenAPI Spec for Saferpay JSON API

This repository provides an OpenAPI 3.0 Specification File describing the Saferepay JSON API documented at https://saferpay.github.io/jsonapi .

The OpenAPI spec is hosted at https://app.swaggerhub.com/apis/soxyl/saferpay

## Regenerate spec

```
$ composer install --working-dir generator
$ generator/bin/console generate > openapi.yaml
```

## Generate a Client Locally

```
docker run --rm --user 1000:1000 -v ${PWD}:/local openapitools/openapi-generator-cli generate \
    -i /local/openapi.yaml \
    -g php \
    -o /local/client-php
```
