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

namespace Sulu\Bundle\ArticleViewDocumentBundle\Document\Index;

use ONGR\ElasticsearchBundle\Service\Manager;
use ONGR\ElasticsearchDSL\Query\Compound\BoolQuery;
use ONGR\ElasticsearchDSL\Query\MatchAllQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\TermQuery;
use Sulu\Article\Domain\Model\ArticleDimensionContentInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\ArticleViewDocumentInterface;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Index\Factory\ExcerptFactory;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Index\Factory\SeoFactory;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\LocalizationStateViewObject;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Resolver\WebspaceResolver;
use Sulu\Bundle\ArticleViewDocumentBundle\Event\IndexEvent;
use Sulu\Bundle\ArticleViewDocumentBundle\Metadata\ArticleViewDocumentIdTrait;
use Sulu\Bundle\ArticleViewDocumentBundle\Metadata\StructureTagTrait;
use Sulu\Bundle\ContactBundle\Entity\Contact;
use Sulu\Bundle\ContactBundle\Entity\ContactRepository;
use Sulu\Bundle\SecurityBundle\UserManager\UserManager;
use Sulu\Component\Content\Document\LocalizationState;
use Sulu\Component\Content\Metadata\Factory\StructureMetadataFactoryInterface;
use Sulu\Component\Content\Metadata\PropertyMetadata;
use Sulu\Component\Content\Metadata\StructureMetadata;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Provides methods to index articles.
 */
class ArticleIndexer implements IndexerInterface
{
    use ArticleViewDocumentIdTrait;
    use StructureTagTrait;

    /**
     * @var StructureMetadataFactoryInterface
     */
    protected $structureMetadataFactory;

    /**
     * @var UserManager
     */
    protected $userManager;

    /**
     * @var ContactRepository
     */
    protected $contactRepository;

    /**
     * @var DocumentFactoryInterface
     */
    protected $documentFactory;

    /**
     * @var Manager
     */
    protected $manager;

    /**
     * @var ExcerptFactory
     */
    protected $excerptFactory;

    /**
     * @var SeoFactory
     */
    protected $seoFactory;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * @var WebspaceResolver
     */
    protected $webspaceResolver;

    /**
     * @var array
     */
    protected $typeConfiguration;

    /**
     * @var ArticleRepositoryInterface
     */
    protected $articleRepository;

    /**
     * @var ContentManagerInterface
     */
    protected $contentManager;

    public function __construct(
        ?StructureMetadataFactoryInterface $structureMetadataFactory,
        UserManager $userManager,
        ContactRepository $contactRepository,
        DocumentFactoryInterface $documentFactory,
        Manager $manager,
        ExcerptFactory $excerptFactory,
        SeoFactory $seoFactory,
        EventDispatcherInterface $eventDispatcher,
        TranslatorInterface $translator,
        WebspaceResolver $webspaceResolver,
        ArticleRepositoryInterface $articleRepository,
        ContentManagerInterface $contentManager,
        array $typeConfiguration,
    ) {
        $this->structureMetadataFactory = $structureMetadataFactory;
        $this->userManager = $userManager;
        $this->contactRepository = $contactRepository;
        $this->documentFactory = $documentFactory;
        $this->manager = $manager;
        $this->excerptFactory = $excerptFactory;
        $this->seoFactory = $seoFactory;
        $this->eventDispatcher = $eventDispatcher;
        $this->translator = $translator;
        $this->webspaceResolver = $webspaceResolver;
        $this->articleRepository = $articleRepository;
        $this->contentManager = $contentManager;
        $this->typeConfiguration = $typeConfiguration;
    }

    /**
     * Returns translation for given article type.
     */
    private function getTypeTranslation(string $type): string
    {
        if (!\array_key_exists($type, $this->typeConfiguration)) {
            return \ucfirst($type);
        }

        $typeTranslationKey = $this->typeConfiguration[$type]['translation_key'];

        return $this->translator->trans($typeTranslationKey, [], 'admin');
    }

    protected function dispatchIndexEvent(ArticleDimensionContentInterface $document, ArticleViewDocumentInterface $article): void
    {
        $this->eventDispatcher->dispatch(new IndexEvent($document, $article), IndexEvent::NAME);
    }

    protected function createOrUpdateArticle(
        ArticleDimensionContentInterface $document,
        string $locale,
        string $localizationState = LocalizationState::LOCALIZED,
    ): ?ArticleViewDocumentInterface {
        $article = $this->findOrCreateViewDocument($document, $locale, $localizationState);
        if (!$article) {
            return null;
        }

        // SULU 3.0 MIGRATION: StructureMetadataFactory is not available in Sulu 3.0
        // Article indexing will work with limited functionality until proper migration
        $structureMetadata = null;
        if ($this->structureMetadataFactory) {
            $structureMetadata = $this->structureMetadataFactory->getStructureMetadata(
                'article',
                $document->getTemplateKey(),
            );
        }

        $article->setTitle($document->getTitle());
        $article->setRoutePath($document->getTemplateData()['url']);
        $this->setParentPageUuid($document, $article);
        $article->setLastModified($document->getLastModified());
        $article->setAuthored($document->getAuthored());
        if ($document->getAuthor() && $author = $this->contactRepository->find($document->getAuthor())) {
            $article->setAuthorId($author->getId());

            if ($author instanceof Contact) {
                $article->setAuthorFullName($author->getFullName());
            }
        }

        $changer = $document->getChanger();
        $changed = $document->getChanged();
        $creator = $document->getCreator();
        $created = $document->getCreated();

        $article->setChanged($changed);
        $article->setChangerFullName($changer?->getFullName());
        $article->setChangerContactId($changer?->getContact()->getId());

        $article->setCreated($created);
        $article->setCreatorFullName($creator?->getFullName());
        $article->setCreatorContactId($creator?->getContact()->getId());

        // SULU 3.0 MIGRATION: Use fallback when structure metadata is not available
        $type = $structureMetadata ? $this->getType($structureMetadata) : 'default';
        $article->setType($type);
        $article->setStructureType($document->getTemplateKey());

        $isPublished = $this->isPublished($document, $localizationState);

        $article->setPublished($isPublished ? $document->getWorkflowPublished() : null);
        $article->setPublishedState($isPublished);
        $article->setTypeTranslation($this->getTypeTranslation($type));
        $article->setLocalizationState(
            new LocalizationStateViewObject(
                $localizationState,
                (LocalizationState::LOCALIZED === $localizationState) ? null : $document->getGhostLocale(),
            ),
        );

        $extensions = [
            'excerpt' => [
                'title' => $document->getExcerptTitle() ?? '',
                'description' => $document->getExcerptDescription() ?? '',
                'more' => $document->getExcerptMore() ?? '',
                'categories' => $document->getExcerptCategoryIds(),
                'tags' => $document->getExcerptTagNames(),
            ],
            'seo' => [
                'title' => $document->getSeoTitle() ?? '',
                'description' => $document->getSeoDescription() ?? '',
                'keywords' => $document->getSeoKeywords() ?? '',
                'canonicalUrl' => $document->getSeoCanonicalUrl() ?? '',
                'noIndex' => $document->getSeoNoIndex(),
                'noFollow' => $document->getSeoNoFollow(),
                'hideInSitemap' => $document->getSeoHideInSitemap(),
            ],
        ];
        $article->setExcerpt($this->excerptFactory->create($extensions['excerpt'], $locale));
        $article->setSeo($this->seoFactory->create($extensions['seo']));

        // SULU 3.0 MIGRATION: Skip teaser properties when structure metadata is not available
        if ($structureMetadata) {
            if ($structureMetadata->hasPropertyWithTagName('sulu.teaser.description')) {
                $descriptionProperty = $structureMetadata->getPropertyByTagName('sulu.teaser.description');
                $article->setTeaserDescription($document->getTemplateData()[$descriptionProperty->getName()]);
            }
            if ($structureMetadata->hasPropertyWithTagName('sulu.teaser.media')) {
                $mediaProperty = $structureMetadata->getPropertyByTagName('sulu.teaser.media');
                $mediaData = $document->getTemplateData()[$mediaProperty->getName()];
                if (null !== $mediaData && \array_key_exists('ids', $mediaData)) {
                    $article->setTeaserMediaId(\reset($mediaData['ids']) ?: null);
                }
            }
        }

        // SULU 3.0 MIGRATION: Pass null structure metadata to getContentFields
        $article->setContentFields($structureMetadata ? $this->getContentFields($structureMetadata, $document) : []);
        $article->setContentData(\json_encode($document->getTemplateData()));

        $article->setMainWebspace($this->webspaceResolver->resolveMainWebspace($document));
        $article->setAdditionalWebspaces($this->webspaceResolver->resolveAdditionalWebspaces($document));

        $this->mapPages($document, $article);

        return $article;
    }

    protected function getContentFields(StructureMetadata $structure, ArticleDimensionContentInterface $document)
    {
        $tag = 'sulu.search.field';
        $contentFields = [];
        foreach ($structure->getProperties() as $property) {
            if (\method_exists($property, 'getComponents') && \count($property->getComponents()) > 0) {
                $blocks = $document->getTemplateData()[$property->getName()] ?? [];
                if (isset($blocks['hotspots'])) {
                    $blocks = $blocks['hotspots'];
                }
                $contentFields = \array_merge($contentFields, $this->getBlockContentFieldsRecursive($blocks, $document, $property, $tag));
            } elseif ($property->hasTag($tag)) {
                $value = $document->getTemplateData()[$property->getName()];
                if (\is_string($value) && '' !== $value) {
                    $contentFields[] = \strip_tags($value);
                }
            }
        }

        return $contentFields;
    }

    /**
     * @return string[]
     */
    private function getBlockContentFieldsRecursive(array $blocks, ArticleDimensionContentInterface $document, $blockMetaData, $tag)
    {
        $contentFields = [];
        foreach ($blockMetaData->getComponents() as $component) {
            /** @var PropertyMetadata $componentProperty */
            foreach ($component->getChildren() as $componentProperty) {
                if (\method_exists($componentProperty, 'getComponents') && \count($componentProperty->getComponents()) > 0) {
                    $filteredBlocks = \array_filter($blocks, function ($block) use ($component) {
                        return $block['type'] === $component->getName();
                    });

                    foreach ($filteredBlocks as $filteredBlock) {
                        if (isset($filteredBlock['hotspots'])) {
                            $filteredBlock = $filteredBlock['hotspots'];
                        }
                        $contentFields = \array_merge(
                            $contentFields,
                            $this->getBlockContentFieldsRecursive(
                                $filteredBlock[$componentProperty->getName()],
                                $document,
                                $componentProperty,
                                $tag,
                            ),
                        );
                    }
                }

                if (false === $componentProperty->hasTag($tag)) {
                    continue;
                }

                foreach ($blocks as $block) {
                    if ($block['type'] === $component->getName()) {
                        $blockValue = $block[$componentProperty->getName()];
                        if (\is_string($blockValue) && '' !== $blockValue) {
                            $contentFields[] = \strip_tags($blockValue);
                        }
                    }
                }
            }
        }

        return $contentFields;
    }

    protected function findViewDocument(ArticleDimensionContentInterface $document, string $locale): ?ArticleViewDocumentInterface
    {
        $articleId = $this->getViewDocumentId($document->getResourceId(), $locale);
        /** @var ArticleViewDocumentInterface $article */
        $article = $this->manager->find($this->documentFactory->getClass('article'), $articleId);

        return $article;
    }

    /**
     * Returns view-document from index or create a new one.
     */
    protected function findOrCreateViewDocument(
        ArticleDimensionContentInterface $document,
        string $locale,
        string $localizationState,
    ): ?ArticleViewDocumentInterface {
        $article = $this->findViewDocument($document, $locale);

        if ($article) {
            // Only index ghosts when the article isn't a ghost himself.
            if (LocalizationState::GHOST === $localizationState
                && LocalizationState::GHOST !== $article->getLocalizationState()->state
            ) {
                return null;
            }

            return $article;
        }

        $article = $this->documentFactory->create('article');
        $article->setId($this->getViewDocumentId($document->getResourceId(), $locale));
        $article->setUuid($document->getResourceId());
        $article->setLocale($locale);

        return $article;
    }

    /**
     * Maps pages from document to view-document.
     */
    private function mapPages(ArticleDimensionContentInterface $document, ArticleViewDocumentInterface $article): void
    {
        // There are no pages anymore
    }

    /**
     * Set parent-page-uuid to view-document.
     */
    private function setParentPageUuid(ArticleDimensionContentInterface $document, ArticleViewDocumentInterface $article): void
    {
        $parentPageUuid = $document->getRoute()?->getParentRoute()?->getResourceId();
        if (!$parentPageUuid) {
            return;
        }

        $article->setParentPageUuid($parentPageUuid);
    }

    public function remove(ArticleDimensionContentInterface $document/* , ?string $locale = null */): void
    {
        $locale = \func_num_args() >= 2 ? \func_get_arg(1) : null;

        $repository = $this->manager->getRepository($this->documentFactory->getClass('article'));
        $search = $repository->createSearch()
            ->addQuery(new TermQuery('uuid', $document->getResourceId()))
            ->setSize(1000);

        if ($locale) {
            $search->addQuery(new TermQuery('locale', $locale));
        }

        foreach ($repository->findDocuments($search) as $viewDocument) {
            $this->manager->remove($viewDocument);
        }
    }

    public function replaceWithGhostData(ArticleDimensionContentInterface $document, string $locale): void
    {
        // overwrite removed locale with properties from original locale
        $article = $this->createOrUpdateArticle($document, $locale);
        $article->setLocalizationState(new LocalizationStateViewObject(LocalizationState::GHOST, $locale));

        $repository = $this->manager->getRepository($this->documentFactory->getClass('article'));
        $search = $repository->createSearch();
        $search->addQuery(new TermQuery('localization_state.state', 'ghost'), BoolQuery::MUST);
        $search->addQuery(new TermQuery('localization_state.locale', $locale), BoolQuery::MUST);
        $search->addQuery(new TermQuery('locale', $locale), BoolQuery::MUST_NOT);
        $search->addQuery(new TermQuery('uuid', $document->getResourceId()), BoolQuery::MUST);

        /** @var array<array{locale: string}> $searchResult */
        $searchResult = $repository->findArray($search);
        foreach ($searchResult as $result) {
            $this->replaceWithGhostData($document, $result['locale']);
        }

        $this->manager->persist($article);
    }

    public function flush(): void
    {
        $this->manager->commit();
    }

    public function clear(): void
    {
        $pageSize = 500;
        $repository = $this->manager->getRepository($this->documentFactory->getClass('article'));
        $search = $repository->createSearch()
            ->addQuery(new MatchAllQuery())
            ->setSize($pageSize);

        do {
            $result = $repository->findDocuments($search);
            foreach ($result as $document) {
                $this->manager->remove($document);
            }

            $this->manager->commit();
        } while (0 !== $result->count());

        $this->manager->clearCache();
        $this->manager->flush();
    }

    public function setUnpublished(string $uuid, string $locale): ?ArticleViewDocumentInterface
    {
        $articleId = $this->getViewDocumentId($uuid, $locale);
        /** @var ArticleViewDocumentInterface|null $article */
        $article = $this->manager->find($this->documentFactory->getClass('article'), $articleId);
        if (!$article) {
            return null;
        }

        $article->setPublished(null);
        $article->setPublishedState(false);

        $this->manager->persist($article);

        return $article;
    }

    protected function isShadowLocaleEnabled(ArticleDimensionContentInterface $document): bool
    {
        return null !== $document->getShadowLocale();
    }

    protected function isPublished(ArticleDimensionContentInterface $document, string $localizationState): bool
    {
        if (LocalizationState::GHOST === $localizationState) {
            return false;
        }

        return WorkflowInterface::WORKFLOW_PLACE_PUBLISHED === $document->getWorkflowPlace()
            || ArticleDimensionContentInterface::STAGE_LIVE === $document->getStage();
    }

    public function index(ArticleDimensionContentInterface $document, string $locale): void
    {
        if ($this->isShadowLocaleEnabled($document)) {
            $this->indexShadow($document);

            return;
        }

        $article = $this->createOrUpdateArticle($document, $locale);

        $this->dispatchIndexEvent($document, $article);
        $this->manager->persist($article);

        $this->updateShadows($document);
    }

    protected function indexShadow(ArticleDimensionContentInterface $document): void
    {
        $shadowLocale = $document->getShadowLocale();
        if (null === $shadowLocale) {
            return;
        }

        $shadowDocument = $this->findArticleDimension(
            $document->getResourceId(),
            $shadowLocale,
            $document->getStage(),
        );

        $article = $this->createOrUpdateArticle($shadowDocument, $document->getLocale(), LocalizationState::SHADOW);
        $this->dispatchIndexEvent($shadowDocument, $article);
        $this->manager->persist($article);
    }

    protected function updateShadows(ArticleDimensionContentInterface $document): void
    {
        if ($document->getShadowLocale()) {
            return;
        }

        foreach ($document->getShadowLocales() ?? [] as $shadowLocale) {
            try {
                $shadowDocument = $this->findArticleDimension(
                    $document->getResourceId(),
                    $shadowLocale,
                    $document->getStage(),
                );

                // update shadow only if original document exists
                if (!$this->findViewDocument($shadowDocument, $document->getLocale())) {
                    continue;
                }

                $this->indexShadow($shadowDocument);
            } catch (\Exception $exception) {
                // @ignoreException
                // do nothing
            }
        }
    }

    public function dropIndex(): void
    {
        if (!$this->manager->indexExists()) {
            return;
        }

        $this->manager->dropIndex();
    }

    public function createIndex(): void
    {
        if ($this->manager->indexExists()) {
            return;
        }

        $this->manager->createIndex();
    }

    protected function findArticleDimension(string $uuid, string $locale, string $stage = 'draft'): ArticleDimensionContentInterface
    {
        $article = $this->articleRepository->findOneBy([
            'uuid' => $uuid,
        ]);

        /** @var ArticleDimensionContentInterface $dimension */
        $dimension = $this->contentManager->resolve(
            $article,
            [
                'locale' => $locale,
                'stage' => $stage,
            ],
        );

        return $dimension;
    }
}
