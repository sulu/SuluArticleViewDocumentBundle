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

namespace Sulu\Bundle\ArticleViewDocumentBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Article\Domain\Model\ArticleDimensionContentInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\ArticleViewDocumentBundle\Document\Index\IndexerInterface;
use Sulu\Component\HttpKernel\SuluKernel;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Reindixes articles.
 */
class ReindexCommand extends Command
{
    /**
     * @var string
     */
    protected static $defaultName = 'sulu:article:reindex';

    public function __construct(
        private WebspaceManagerInterface $webspaceManager,
        private ArticleRepositoryInterface $articleRepository,
        private ContentManagerInterface $contentManager,
        private EntityManagerInterface $entityManager,
        private IndexerInterface $draftIndexer,
        private IndexerInterface $liveIndexer,
        private string $suluContext,
    ) {
        parent::__construct(static::$defaultName);
    }

    public function configure(): void
    {
        $this->setDescription('Rebuild elastic-search index for articles');
        $this->setHelp('This command will load all articles and index them to elastic-search indexes.');
        $this->addOption('drop', null, InputOption::VALUE_NONE, 'Drop and recreate index before reindex');
        $this->addOption('clear', null, InputOption::VALUE_NONE, 'Clear all articles of index before reindex');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startTime = \microtime(true);

        $indexer = SuluKernel::CONTEXT_WEBSITE === $this->suluContext
            ? $this->liveIndexer
            : $this->draftIndexer;

        $output->writeln(
            \sprintf('Reindex articles for the <comment>`%s`</comment> context' . \PHP_EOL, $this->suluContext),
        );

        if (!$this->dropIndex($indexer, $input, $output)) {
            // Drop was canceled by user.

            return 0;
        }

        $indexer->createIndex();
        $this->clearIndex($indexer, $input, $output);

        $locales = $this->webspaceManager->getAllLocalizations();

        foreach ($locales as $locale) {
            $output->writeln(\sprintf('<info>Locale "</info>%s<info>"</info>' . \PHP_EOL, $locale->getLocale()));

            $this->indexDocuments($locale->getLocale(), $indexer, $output);

            $output->writeln(\PHP_EOL);
        }

        $output->writeln(
            \sprintf(
                '<info>Index rebuild completed (</info>%ss %s</info><info>)</info>',
                \number_format(\microtime(true) - $startTime, 2),
                $this->humanBytes(\memory_get_peak_usage()),
            ),
        );

        return 0;
    }

    /**
     * Drop index if requested.
     */
    protected function dropIndex(IndexerInterface $indexer, InputInterface $input, OutputInterface $output): bool
    {
        if (!$input->getOption('drop')) {
            return true;
        }

        if (!$input->getOption('no-interaction')) {
            $output->writeln(
                '<comment>ATTENTION</comment>: This operation drops and recreates the whole index and deletes the complete data.',
            );
            $output->writeln('');

            $question = new ConfirmationQuestion('Are you sure you want to drop the index? [Y/n] ');

            /** @var QuestionHelper $questionHelper */
            $questionHelper = $this->getHelper('question');
            if (!$questionHelper->ask($input, $output, $question)) {
                return false;
            }

            $output->writeln('');
        }

        $indexer->dropIndex();

        $output->writeln(
            \sprintf(
                'Dropped and recreated index for the <comment>`%s`</comment> context' . \PHP_EOL,
                $this->suluContext,
            ),
        );

        return true;
    }

    /**
     * Clear article-content of index.
     */
    protected function clearIndex(IndexerInterface $indexer, InputInterface $input, OutputInterface $output): void
    {
        if (!$input->getOption('clear')) {
            return;
        }

        $indexer->clear();
        $output->writeln(\sprintf('Cleared index for the <comment>`%s`</comment> context', $this->suluContext));
    }

    /**
     * Index documents for given locale.
     */
    protected function indexDocuments(string $locale, IndexerInterface $indexer, OutputInterface $output): void
    {
        $documents = $this->getDocuments($locale);
        $count = $this->countDocuments();
        if (0 === $count) {
            $output->writeln('  No documents found');

            return;
        }

        $progressBar = new ProgressBar($output, $count);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $progressBar->start();
        $count = 0;

        /** @var ArticleDimensionContentInterface $document */
        foreach ($documents as $document) {
            $indexer->index($document, $locale);
            $progressBar->advance();

            ++$count;

            if (0 === ($count % 100)) {
                $indexer->flush();
                $this->entityManager->clear();
            }

            if (0 === ($count % 500)) {
                \gc_collect_cycles();
            }
        }

        $indexer->flush();
        $progressBar->finish();
    }

    /**
     * @return iterable<ArticleDimensionContentInterface>
     */
    protected function getDocuments(string $locale): iterable
    {
        $stage = SuluKernel::CONTEXT_WEBSITE === $this->suluContext
            ? DimensionContentInterface::STAGE_LIVE
            : DimensionContentInterface::STAGE_DRAFT;

        foreach ($this->articleRepository->findIdentifiersBy() as $articleId) {
            $article = $this->articleRepository->findOneBy($articleId);

            $resolved = $this->contentManager->resolve($article, [
                'stage' => $stage,
                'locale' => $locale,
            ]);

            if (!\in_array($locale, $resolved->getAvailableLocales(), true)) {
                continue;
            }

            yield $resolved;
        }
    }

    protected function countDocuments(): int
    {
        return $this->articleRepository->countBy();
    }

    /**
     * Converts bytes into human readable.
     *
     * Inspired by http://jeffreysambells.com/2012/10/25/human-readable-filesize-php
     */
    protected function humanBytes(int $bytes, int $dec = 2): string
    {
        $size = ['b', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        $factor = (int) \floor((\strlen((string) $bytes) - 1) / 3);

        return \sprintf("%.{$dec}f", $bytes / 1024 ** $factor) . $size[$factor];
    }
}
