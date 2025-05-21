<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\ArticleViewDocumentBundle\Document\Index;

use Sulu\Article\Domain\Model\ArticleDimensionContentInterface;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\ArticleViewDocumentInterface;

interface IndexerInterface
{
    /**
     * Clear index.
     */
    public function clear(): void;

    /**
     * Sets state of document to unpublished.
     * Clear published and sets published state to false.
     */
    public function setUnpublished(string $uuid, string $locale): ?ArticleViewDocumentInterface;

    /**
     * Indexes given document.
     */
    public function index(ArticleDimensionContentInterface $document, string $locale): void;

    /**
     * Removes document from index.
     */
    public function remove(ArticleDimensionContentInterface $document/* , ?string $locale = null */): void;

    /**
     * Reindexes the document in given locale with the data of the originalLocale.
     */
    public function replaceWithGhostData(ArticleDimensionContentInterface $document, string $locale): void;

    /**
     * Flushes index.
     */
    public function flush(): void;

    /**
     * Drop and recreate elastic-search index.
     */
    public function dropIndex(): void;

    /**
     * Drop and create elastic-search index.
     */
    public function createIndex(): void;
}
