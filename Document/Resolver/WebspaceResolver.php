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

namespace Sulu\Bundle\ArticleViewDocumentBundle\Document\Resolver;

use Sulu\Article\Domain\Model\ArticleDimensionContentInterface;
use Sulu\Bundle\ArticleViewDocumentBundle\DependencyInjection\WebspaceSettingsConfigurationResolver;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;

class WebspaceResolver
{
    /**
     * @var WebspaceManagerInterface
     */
    private $webspaceManager;

    /**
     * @var WebspaceSettingsConfigurationResolver
     */
    private $webspaceSettingsConfigurationResolver;

    public function __construct(
        WebspaceManagerInterface $webspaceManager,
        WebspaceSettingsConfigurationResolver $webspaceSettingsConfigurationResolver,
    ) {
        $this->webspaceManager = $webspaceManager;
        $this->webspaceSettingsConfigurationResolver = $webspaceSettingsConfigurationResolver;
    }

    public function resolveMainWebspace(ArticleDimensionContentInterface $document): ?string
    {
        if (!$this->hasMoreThanOneWebspace()) {
            $webspaces = $this->webspaceManager->getWebspaceCollection()->getWebspaces();

            return \reset($webspaces)->getKey();
        }

        $hasCustomizedWebspaceSettings = $this->hasCustomizedWebspaceSettings($document);

        if ($hasCustomizedWebspaceSettings) {
            return $document->getMainWebspace();
        }

        return $this->webspaceSettingsConfigurationResolver->getDefaultMainWebspaceForLocale($document->getLocale());
    }

    /**
     * @return string[]|null
     */
    public function resolveAdditionalWebspaces(ArticleDimensionContentInterface $document): ?array
    {
        if (!$this->hasMoreThanOneWebspace()) {
            return [];
        }

        $hasCustomizedWebspaceSettings = $this->hasCustomizedWebspaceSettings($document);

        if ($hasCustomizedWebspaceSettings) {
            // TODO get the additional webspaces from the document
            $additionalWebspaces = [];

            return $additionalWebspaces;
        }

        return $this->webspaceSettingsConfigurationResolver->getDefaultAdditionalWebspacesForLocale($document->getOriginalLocale());
    }

    public function hasCustomizedWebspaceSettings(ArticleDimensionContentInterface $document): bool
    {
        return null !== $document->getMainWebspace();
    }

    /**
     * Check if system has more than one webspace.
     */
    private function hasMoreThanOneWebspace(): bool
    {
        return \count($this->webspaceManager->getWebspaceCollection()->getWebspaces()) > 1;
    }
}
