<?php

declare(strict_types=1);

namespace Baldwin\ImageCleanup\Console\Command;

use Baldwin\ImageCleanup\Console\UserInteraction;
use Baldwin\ImageCleanup\Deleter\MediaDeleter;
use Baldwin\ImageCleanup\Finder\UnusedCacheHashDirectoriesFinder;
use Baldwin\ImageCleanup\Service\HyvaThemeFallbackStoreThemeResolver;
use Magento\Framework\App\Area as AppArea;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command as ConsoleCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveUnusedCacheHashDirectories extends ConsoleCommand
{
    private const OPTION_WRITE = 'write';

    private $appState;
    private $userInteraction;
    private $mediaDeleter;
    private $unusedCacheHashDirFinder;
    private $hyvaThemeFallbackStoreThemeResolver;
    private $directoryList;

    public function __construct(
        AppState $appState,
        UserInteraction $userInteraction,
        MediaDeleter $mediaDeleter,
        UnusedCacheHashDirectoriesFinder $unusedCacheHashDirFinder,
        HyvaThemeFallbackStoreThemeResolver $hyvaThemeFallbackStoreThemeResolver,
        DirectoryList $directoryList
    ) {
        $this->appState = $appState;
        $this->userInteraction = $userInteraction;
        $this->mediaDeleter = $mediaDeleter;
        $this->unusedCacheHashDirFinder = $unusedCacheHashDirFinder;
        $this->hyvaThemeFallbackStoreThemeResolver = $hyvaThemeFallbackStoreThemeResolver;
        $this->directoryList = $directoryList;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('catalog:images:remove-unused-hash-directories');
        $this->setDescription(
            'Remove unused resized hash directories (like: pub/media/catalog/product/cache/xxyyzz). '
            . 'These directories can be a leftover from older Magento versions, or from image definitions that got '
            . 'removed from the etc/view.xml file of a custom theme for example.'
        );
        $this->addOption(
            UserInteraction::CONSOLE_OPTION_TO_SKIP_GENERATING_STATS,
            null,
            InputOption::VALUE_NONE,
            'Skip calculating and outputting stats (filesizes, number of files, ...), '
            . 'this can speed up the command in case it runs slowly.'
        );
        $this->addOption(
            self::OPTION_WRITE,
            'w',
            InputOption::VALUE_OPTIONAL,
            'Write the list of unused hash directories to a .txt file instead of deleting them. '
            . 'Optionally provide a filename; defaults to var/unused_cache_hash_directories_<date>.txt.',
            false
        );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // needed to avoid 'Area code is not set'
        // mimicking same area as core magento (global) from the catalog:images:resize command
        $this->appState->setAreaCode(AppArea::AREA_GLOBAL);

        $this->hyvaThemeFallbackStoreThemeResolver->setIsActive(true);

        $directories = $this->unusedCacheHashDirFinder->find();

        $this->hyvaThemeFallbackStoreThemeResolver->setIsActive(false);

        $writeOption = $input->getOption(self::OPTION_WRITE);
        if ($writeOption !== false) {
            return $this->writeFilesToTxt($directories, $writeOption, $output);
        }

        $accepted = $this->userInteraction->showPathsToDeleteAndAskForConfirmation($directories, $input, $output);
        if ($accepted) {
            $this->mediaDeleter->deletePaths($directories);
            $deletedPaths = $this->mediaDeleter->getDeletedPaths();
            $skippedPaths = $this->mediaDeleter->getSkippedPaths();
            $numberOfFilesRemoved = $this->mediaDeleter->getNumberOfFilesDeleted();
            $bytesRemoved = $this->mediaDeleter->getBytesDeleted();

            $this->userInteraction->showFinalInfo(
                $deletedPaths,
                $skippedPaths,
                $numberOfFilesRemoved,
                $bytesRemoved,
                $output
            );
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * @param array<string> $files
     */
    private function writeFilesToTxt(array $files, ?string $filename, OutputInterface $output): int
    {
        if ($files === []) {
            $output->writeln('<info>No unused hash directories found, nothing to write.</info>');
            return Cli::RETURN_SUCCESS;
        }

        if ($filename === null || $filename === '') {
            $varDir = $this->directoryList->getPath(DirectoryList::VAR_DIR);
            $filename = $varDir . '/unused_cache_hash_directories_' . date('Y-m-d_H-i-s') . '.txt';
        }

        $content = implode(PHP_EOL, $files) . PHP_EOL;
        $result = file_put_contents($filename, $content);

        if ($result === false) {
            $output->writeln(sprintf('<error>Could not write to file: %s</error>', $filename));
            return Cli::RETURN_FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Written %d unused hash directory paths to: %s</info>',
            count($files),
            $filename
        ));

        return Cli::RETURN_SUCCESS;
    }
}
