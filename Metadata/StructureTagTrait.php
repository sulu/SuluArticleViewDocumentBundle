<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\ArticleViewDocumentBundle\Metadata;

use Sulu\Component\Content\Metadata\StructureMetadata;

/**
 * Encapsulates function to extract configuration from structure-metadata.
 */
trait StructureTagTrait
{
    /**
     * Returns type for given structure-metadata.
     */
    protected function getType(StructureMetadata $metadata, ?string $default = 'default')
    {
        return $this->getTagAttribute($metadata, 'sulu_article.type', 'type', $default);
    }

    /**
     * Returns attribute for given tag in metadata.
     */
    private function getTagAttribute(StructureMetadata $metadata, string $tag, string $attribute, $default)
    {
        if (!$metadata->hasTag($tag)) {
            return $default;
        }

        $tag = $metadata->getTag($tag);
        if (!\array_key_exists($attribute, $tag['attributes'])) {
            return $default;
        }

        return $tag['attributes'][$attribute];
    }
}
