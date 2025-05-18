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

namespace Sulu\Bundle\ArticleViewDocumentBundle\DependencyInjection;

use Sulu\Bundle\ArticleViewDocumentBundle\Document\ArticleViewDocument;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Initializes configuration tree for article-bundle.
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('sulu_article_view_document');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('index_name')->isRequired()->end()
                ->arrayNode('hosts')
                    ->prototype('scalar')->end()
                ->end()
                ->arrayNode('default_main_webspace')
                    ->useAttributeAsKey('locale')
                    ->beforeNormalization()
                        ->ifString()
                        ->then(function ($v) {
                            return ['default' => $v];
                        })
                    ->end()
                    ->prototype('scalar')->end()
                ->end()
                ->arrayNode('default_additional_webspaces')
                    ->beforeNormalization()
                        ->ifTrue(function ($v) {
                            return \count(\array_filter(\array_keys($v), 'is_string')) <= 0;
                        })
                        ->then(function ($v) {
                            return ['default' => $v];
                        })
                    ->end()
                    ->prototype('array')->useAttributeAsKey('locale')->prototype('scalar')->end()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('documents')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('article')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('view')->defaultValue(ArticleViewDocument::class)->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('types')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->prototype('array')
                        ->children()
                            ->scalarNode('translation_key')->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('search_fields')
                    ->prototype('scalar')->end()->defaultValue([
                        'title',
                        'excerpt.title',
                        'excerpt.description',
                        'excerpt.seo.title',
                        'excerpt.seo.description',
                        'excerpt.seo.keywords',
                        'teaser_description',
                    ])
                ->end()
            ->end();

        return $treeBuilder;
    }
}
