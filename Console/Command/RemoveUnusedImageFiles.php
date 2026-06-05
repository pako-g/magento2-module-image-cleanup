<?php

declare(strict_types=1);

namespace Baldwin\ImageCleanup\Console\Command;

use Baldwin\ImageCleanup\Console\ProgressIndicator;
use Baldwin\ImageCleanup\Console\UserInteraction;
use Baldwin\ImageCleanup\Deleter\MediaDeleter;
use Baldwin\ImageCleanup\Finder\UnusedFilesFinder;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command as ConsoleCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\Filesystem\DirectoryList;

class RemoveUnusedImageFiles extends ConsoleCommand
{
    private const OPTION_WRITE = 'write';

    private $userInteraction;
    private $progressIndicator;
    private $mediaDeleter;
    private $unusedFilesFinder;
    private $directoryList;

    public function __construct(
        UserInteraction $userInteraction,
        ProgressIndicator $progressIndicator,
        MediaDeleter $mediaDeleter,
        UnusedFilesFinder $unusedFilesFinder,
        DirectoryList $directoryList
    ) {
        $this->userInteraction = $userInteraction;
        $this->progressIndicator = $progressIndicator;
        $this->mediaDeleter = $mediaDeleter;
        $this->unusedFilesFinder = $unusedFilesFinder;
        $this->directoryList = $directoryList;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('catalog:images:remove-unused-files');
        $this->setDescription(
            'Remove unused product image files from the filesystem. '
            . 'We compare the data that\'s in the database with the files on disk and remove the ones that don\'t match'
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
            'Write the list of unused files to a .txt file instead of deleting them. '
            . 'Optionally provide a filename; defaults to var/unused_product_images_<date>.txt.',
            false
        );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->progressIndicator->init($output);

        $files = $this->unusedFilesFinder->find();

        $writeOption = $input->getOption(self::OPTION_WRITE);
        if ($writeOption !== false) {
            return $this->writeFilesToTxt($files, $writeOption, $output);
        }

        $accepted = $this->userInteraction->showPathsToDeleteAndAskForConfirmation($files, $input, $output);
        if ($accepted) {
            $this->mediaDeleter->deletePaths($files);
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
            $output->writeln('<info>No unused files found, nothing to write.</info>');
            return Cli::RETURN_SUCCESS;
        }

        if ($filename === null || $filename === '') {
            $varDir = $this->directoryList->getPath(DirectoryList::VAR_DIR);
            $filename = $varDir . '/unused_product_images_' . date('Y-m-d_H-i-s') . '.txt';
        }

        $content = implode(PHP_EOL, $files) . PHP_EOL;
        $result = file_put_contents($filename, $content);

        if ($result === false) {
            $output->writeln(sprintf('<error>Could not write to file: %s</error>', $filename));
            return Cli::RETURN_FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Written %d unused file paths to: %s</info>',
            count($files),
            $filename
        ));

        return Cli::RETURN_SUCCESS;
    }
}
