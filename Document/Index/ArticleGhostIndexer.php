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
use Sulu\Article\Domain\Model\ArticleDimensionContentInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Index\Factory\ExcerptFactory;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Index\Factory\SeoFactory;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Resolver\WebspaceResolver;
use Sulu\Bundle\ContactBundle\Entity\ContactRepository;
use Sulu\Bundle\SecurityBundle\UserManager\UserManager;
use Sulu\Component\Content\Document\LocalizationState;
use Sulu\Component\Content\Metadata\Factory\StructureMetadataFactoryInterface;
use Sulu\Component\Localization\Localization;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Provides methods to index articles.
 */
class ArticleGhostIndexer extends ArticleIndexer
{
    /**
     * @var WebspaceManagerInterface
     */
    protected $webspaceManager;

    /**
     * @var ArticleRepositoryInterface
     */
    protected $articleRepository;

    /**
     * @var ContentManagerInterface
     */
    protected $contentManager;

    public function __construct(
        StructureMetadataFactoryInterface $structureMetadataFactory,
        UserManager $userManager,
        ContactRepository $contactRepository,
        DocumentFactoryInterface $documentFactory,
        Manager $manager,
        ExcerptFactory $excerptFactory,
        SeoFactory $seoFactory,
        EventDispatcherInterface $eventDispatcher,
        TranslatorInterface $translator,
        WebspaceResolver $webspaceResolver,
        array $typeConfiguration,
        WebspaceManagerInterface $webspaceManager,
        ArticleRepositoryInterface $articleRepository,
        ContentManagerInterface $contentManager,
    ) {
        parent::__construct(
            $structureMetadataFactory,
            $userManager,
            $contactRepository,
            $documentFactory,
            $manager,
            $excerptFactory,
            $seoFactory,
            $eventDispatcher,
            $translator,
            $webspaceResolver,
            $typeConfiguration,
        );

        $this->webspaceManager = $webspaceManager;
        $this->articleRepository = $articleRepository;
        $this->contentManager = $contentManager;
    }

    public function index(ArticleDimensionContentInterface $document, string $locale): void
    {
        if ($this->isShadowLocaleEnabled($document)) {
            $this->indexShadow($document);

            return;
        }

        $article = $this->createOrUpdateArticle($document, $locale);
        $this->updateShadows($document);
        $this->createOrUpdateGhosts($document);
        $this->dispatchIndexEvent($document, $article);
        $this->manager->persist($article);
    }

    private function createOrUpdateGhosts(ArticleDimensionContentInterface $document): void
    {
        $documentLocale = $document->getLocale();
        if ($documentLocale === null) {
            return;
        }

        /** @var Localization $localization */
        foreach ($this->webspaceManager->getAllLocalizations() as $localization) {
            $locale = $localization->getLocale();
            if ($documentLocale === $locale) {
                continue;
            }

            $ghostArticle = $this->articleRepository->findOneBy([
                'uuid' => $document->getResourceId(),
            ]);

            /** @var ArticleDimensionContentInterface $ghostDocument */
            $ghostDocument = $this->contentManager->resolve(
                $ghostArticle,
                [
                    'locale' => $locale,
                    'stage' => $document->getStage(),
                ],
            );

            // Only index ghosts
            if (null !== $ghostDocument->getGhostLocale() && $document->getLocale() !== $ghostDocument->getGhostLocale()) {
                continue;
            }

            // Try index the article ghosts.
            $article = $this->createOrUpdateArticle(
                $document,
                $locale,
                LocalizationState::GHOST,
            );

            if ($article) {
                $this->dispatchIndexEvent($ghostDocument, $article);
                $this->manager->persist($article);
            }
        }
    }
}
