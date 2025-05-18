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
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Index\Factory\ExcerptFactory;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Index\Factory\SeoFactory;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Resolver\WebspaceResolver;
use Sulu\Bundle\ContactBundle\Entity\ContactRepository;
use Sulu\Bundle\SecurityBundle\UserManager\UserManager;
use Sulu\Component\Content\Document\LocalizationState;
use Sulu\Component\Content\Metadata\Factory\StructureMetadataFactoryInterface;
use Sulu\Component\Localization\Localization;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
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
    }

    public function index(ArticleDimensionContentInterface $document): void
    {
        if ($this->isShadowLocaleEnabled($document)) {
            $this->indexShadow($document);

            return;
        }

        $article = $this->createOrUpdateArticle($document, $document->getLocale());
        $this->updateShadows($document);
        $this->createOrUpdateGhosts($document);
        $this->dispatchIndexEvent($document, $article);
        $this->manager->persist($article);
    }

    private function createOrUpdateGhosts(ArticleDimensionContentInterface $document): void
    {
        $documentLocale = $document->getLocale();
        /** @var Localization $localization */
        foreach ($this->webspaceManager->getAllLocalizations() as $localization) {
            $locale = $localization->getLocale();
            if ($documentLocale === $locale) {
                continue;
            }

            // TODO load ghost document
            /** @var ArticleDimensionContentInterface $ghostDocument */
            $ghostDocument = null;

            // Only index ghosts
            if (null !== $ghostDocument->getGhostLocale()) {
                continue;
            }

            // Try index the article ghosts.
            $article = $this->createOrUpdateArticle(
                $ghostDocument,
                $localization->getLocale(),
                LocalizationState::GHOST,
            );

            if ($article) {
                $this->dispatchIndexEvent($ghostDocument, $article);
                $this->manager->persist($article);
            }
        }
    }
}
