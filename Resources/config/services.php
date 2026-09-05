<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Sulu\Bundle\ArticleViewDocumentBundle\Builder\ArticleIndexBuilder;
use Sulu\Bundle\ArticleViewDocumentBundle\Command\ReindexCommand;
use Sulu\Bundle\ArticleViewDocumentBundle\DependencyInjection\WebspaceSettingsConfigurationResolver;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Index\ArticleGhostIndexer;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Index\ArticleIndexer;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Index\DocumentFactory;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Index\Factory\CategoryCollectionFactory;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Index\Factory\ExcerptFactory;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Index\Factory\MediaCollectionFactory;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Index\Factory\MediaFactory;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Index\Factory\SegmentCollectionFactory;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Index\Factory\SeoFactory;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Index\Factory\TagCollectionFactory;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Repository\ArticleViewDocumentRepository;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Resolver\WebspaceResolver;
use Sulu\Bundle\ArticleViewDocumentBundle\Elasticsearch\AnnotationReader;
use Symfony\Component\DependencyInjection\Reference;

return static function(ContainerConfigurator $container) {
    $services = $container->services();

    // Document Factory & Repository
    $services->set('sulu_article_view_document.document.factory.document_factory', DocumentFactory::class)
        ->args([
            '%sulu_article.documents%',
        ]);

    $services->set('sulu_article_view_document.document.repository.article_view_document_repository', ArticleViewDocumentRepository::class)
        ->args([
            new Reference('es.manager'),
            new Reference('sulu_article_view_document.document.factory.document_factory'),
        ]);

    // builder
    $services->set('sulu_article_view_document.builder.index', ArticleIndexBuilder::class)
        ->tag('massive_build.builder');

    // annotation reader
    $services->set('sulu_article_view_document.annotations.cached_reader', AnnotationReader::class)
        ->decorate('es.annotations.cached_reader')
        ->args([
            new Reference('sulu_article_view_document.annotations.cached_reader.inner'),
            '%sulu_article.view_document.article.class%',
        ]);

    // Factory Services
    $services->set('sulu_article_view_document.document.index.factory.excerpt_factory', ExcerptFactory::class)
        ->args([
            new Reference('sulu_article_view_document.document.index.factory.category_collection_factory'),
            new Reference('sulu_article_view_document.document.index.factory.tag_collection_factory'),
            new Reference('sulu_article_view_document.document.index.factory.media_collection_factory'),
            new Reference('sulu_article_view_document.document.index.factory.segment_collection_factory'),
        ]);

    $services->set('sulu_article_view_document.document.index.factory.seo_factory', SeoFactory::class);

    $services->set('sulu_article_view_document.document.index.factory.category_collection_factory', CategoryCollectionFactory::class)
        ->args([
            new Reference('sulu.repository.category'),
            new Reference('sulu_category.category_manager'),
        ]);

    $services->set('sulu_article_view_document.document.index.factory.tag_collection_factory', TagCollectionFactory::class)
        ->args([
            new Reference('sulu_tag.tag_manager'),
        ]);

    $services->set('sulu_article_view_document.document.index.factory.media_factory', MediaFactory::class)
        ->args([
            new Reference('sulu_media.media_manager'),
        ]);

    $services->set('sulu_article_view_document.document.index.factory.media_collection_factory', MediaCollectionFactory::class)
        ->args([
            new Reference('sulu_media.media_manager'),
        ]);

    $services->set('sulu_article_view_document.document.index.factory.segment_collection_factory', SegmentCollectionFactory::class)
        ->args([
            new Reference('doctrine.orm.entity_manager'),
        ]);

    // Resolver Services
    $services->set('sulu_article_view_document.webspace_settings_configuration_resolver', WebspaceSettingsConfigurationResolver::class)
        ->args([
            '%sulu_article.default_main_webspace%',
            '%sulu_article.default_additional_webspaces%',
        ]);

    $services->set('sulu_article_view_document.document.resolver.webspace_resolver', WebspaceResolver::class)
        ->args([
            new Reference('sulu_core.webspace.webspace_manager'),
            new Reference('sulu_article_view_document.webspace_settings_configuration_resolver'),
        ]);

    // Indexer Services
    // Draft/Default indexer (used in admin context)
    $services->set('sulu_article_view_document.document.index.article_draft_indexer', ArticleGhostIndexer::class)
        ->args([
            new Reference('sulu_page.structure.factory'),
            new Reference('sulu_security.user_manager'),
            new Reference('sulu.repository.contact'),
            new Reference('sulu_article_view_document.document.factory.document_factory'),
            new Reference('es.manager.default'), // Use default manager for draft
            new Reference('sulu_article_view_document.document.index.factory.excerpt_factory'),
            new Reference('sulu_article_view_document.document.index.factory.seo_factory'),
            new Reference('event_dispatcher'),
            new Reference('translator'),
            new Reference('sulu_article_view_document.document.resolver.webspace_resolver'),
            new Reference('sulu_article.article_repository'),
            new Reference('sulu_content.content_manager'),
            [
                'default' => [
                    'translation_key' => 'sulu_article.type.default',
                ],
            ],
            new Reference('sulu_core.webspace.webspace_manager'),
        ]);

    // Live indexer (used in website context)
    $services->set('sulu_article_view_document.document.index.article_live_indexer', ArticleIndexer::class)
        ->args([
            new Reference('sulu_page.structure.factory'),
            new Reference('sulu_security.user_manager'),
            new Reference('sulu.repository.contact'),
            new Reference('sulu_article_view_document.document.factory.document_factory'),
            new Reference('es.manager.live'), // Use live manager for published content
            new Reference('sulu_article_view_document.document.index.factory.excerpt_factory'),
            new Reference('sulu_article_view_document.document.index.factory.seo_factory'),
            new Reference('event_dispatcher'),
            new Reference('translator'),
            new Reference('sulu_article_view_document.document.resolver.webspace_resolver'),
            new Reference('sulu_article.article_repository'),
            new Reference('sulu_content.content_manager'),
            [
                'default' => [
                    'translation_key' => 'sulu_article.type.default',
                ],
            ],
        ]);

    // Commands
    $services->set('sulu_article_view_document.command.reindex_command', ReindexCommand::class)
        ->args([
            new Reference('sulu_core.webspace.webspace_manager'),
            new Reference('sulu_article.article_repository'),
            new Reference('sulu_content.content_manager'),
            new Reference('doctrine.orm.entity_manager'),
            new Reference('sulu_article_view_document.document.index.article_draft_indexer'), // Draft indexer for admin context
            new Reference('sulu_article_view_document.document.index.article_live_indexer'), // Live indexer for website context
            '%sulu.context%',
        ])
        ->tag('console.command');
};
