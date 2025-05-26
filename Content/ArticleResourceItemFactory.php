<?php

declare(strict_types=1);

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\ArticleViewDocumentBundle\Content;

use ProxyManager\Factory\LazyLoadingValueHolderFactory;
use ProxyManager\Proxy\LazyLoadingInterface;
use Sulu\Article\Domain\Model\ArticleDimensionContentInterface;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\ArticleViewDocumentInterface;

/**
 * Creates article resource items for given article view document.
 */
class ArticleResourceItemFactory
{
    /**
     * @var LazyLoadingValueHolderFactory
     */
    protected $proxyFactory;

    public function __construct(
        LazyLoadingValueHolderFactory $proxyFactory,
    ) {
        $this->proxyFactory = $proxyFactory;
    }

    /**
     * Creates and returns article source item with proxy document.
     */
    public function createResourceItem(ArticleViewDocumentInterface $articleViewDocument): ArticleResourceItem
    {
        return new ArticleResourceItem(
            $articleViewDocument,
            $this->getResource($articleViewDocument->getUuid(), $articleViewDocument->getLocale()),
        );
    }

    /**
     * Returns Proxy document for uuid.
     */
    private function getResource(string $uuid, string $locale): object
    {
        return $this->proxyFactory->createProxy(
            ArticleDimensionContentInterface::class,
            function(
                &$wrappedObject,
                LazyLoadingInterface $proxy,
                $method,
                array $parameters,
                &$initializer,
            ) {
                $initializer = null;

                // TODO load the dimension for article
                $wrappedObject = null;

                return true;
            },
        );
    }
}
