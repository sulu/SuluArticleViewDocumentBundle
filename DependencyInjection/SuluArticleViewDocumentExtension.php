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

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class SuluArticleViewDocumentExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('massive_build')) {
            $container->prependExtensionConfig(
                'massive_build',
                [
                    'targets' => [
                        'prod' => [
                            'dependencies' => [
                                'article_index' => [],
                            ],
                        ],
                        'dev' => [
                            'dependencies' => [
                                'article_index' => [],
                            ],
                        ],
                        'maintain' => [
                            'dependencies' => [
                                'article_index' => [],
                            ],
                        ],
                    ],
                ],
            );
        }

        if ($container->hasExtension('ongr_elasticsearch')) {
            $configs = $container->getExtensionConfig($this->getAlias());
            $config = $this->processConfiguration(new Configuration(), $configs);

            $indexName = $config['index_name'];
            $hosts = $config['hosts'];

            $ongrElasticSearchConfig = [
                'managers' => [
                    'default' => [
                        'index' => [
                            'index_name' => $indexName,
                        ],
                        'mappings' => ['SuluArticleViewDocumentBundle'],
                    ],
                    'live' => [
                        'index' => [
                            'index_name' => $indexName . '_live',
                        ],
                        'mappings' => ['SuluArticleViewDocumentBundle'],
                    ],
                ],
            ];

            if (\count($hosts) > 0) {
                $ongrElasticSearchConfig['managers']['default']['index']['hosts'] = $hosts;
                $ongrElasticSearchConfig['managers']['live']['index']['hosts'] = $hosts;
            }

            $container->prependExtensionConfig(
                'ongr_elasticsearch',
                $ongrElasticSearchConfig,
            );
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        $container->setParameter('sulu_article.default_main_webspace', $config['default_main_webspace']);
        $container->setParameter('sulu_article.default_additional_webspaces', $config['default_additional_webspaces']);
        $container->setParameter('sulu_article.documents', $config['documents']);
        $container->setParameter('sulu_article.view_document.article.class', $config['documents']['article']['view']);
        $container->setParameter('sulu_article.search_fields', $config['search_fields']);
        $container->setParameter('sulu_article.types', $config['types']);

        $loader = new Loader\PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.php');
    }
}
