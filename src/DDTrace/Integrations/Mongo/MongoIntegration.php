<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Span;
use DDTrace\Tags;
use DDTrace\Types;
use DDTrace\Integrations\Integration;

final class MongoIntegration
{
    public static function load()
    {
        if (!extension_loaded('mongo')) {
            return;
        }
        MongoClientIntegration::load();
        MongoDBIntegration::load();
        MongoCollectionIntegration::load();
    }

    public static function setDefaultTags(Span $span, $method)
    {
        Integration::setDefaultTags($span, $method);
        $span->setTag(Tags\SPAN_TYPE, Types\MONGO);
        $span->setTag(Tags\SERVICE_NAME, 'mongo');
    }
}
