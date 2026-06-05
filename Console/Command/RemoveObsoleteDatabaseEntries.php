<?php

declare(strict_types=1);

namespace Baldwin\ImageCleanup\Console\Command;

use Baldwin\ImageCleanup\Console\UserInteraction;
use Baldwin\ImageCleanup\Deleter\DatabaseGalleryDeleter;
use Baldwin\ImageCleanup\Finder\ObsoleteDatabaseEntriesFinder;
use Magento\Catalog\Model\ResourceModel\Product\Gallery as GalleryResourceModel;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command as ConsoleCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveObsoleteDatabaseEntries extends ConsoleCommand
{
    private const OPTION_WRITE = 'write';

    private $userInteraction;
    private $dbGalleryDeleter;
    private $obsoleteDbEntriesFinder;
    private $directoryList;

    public function __construct(
        UserInteraction $userInteraction,
        DatabaseGalleryDeleter $dbGalleryDeleter,
        ObsoleteDatabaseEntriesFinder $obsoleteDbEntriesFinder,
        DirectoryList $directoryList
    ) {
        $this->userInteraction = $userInteraction;
        $this->dbGalleryDeleter = $dbGalleryDeleter;
        $this->obsoleteDbEntriesFinder = $obsoleteDbEntriesFinder;
        $this->directoryList = $directoryList;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('catalog:images:remove-obsolete-db-entries');
        $this->setDescription(
            sprintf(
                'Removes values from the %s db table which are no longer needed.',
                GalleryResourceModel::GALLERY_TABLE
            )
        );

        $this->addOption(
            self::OPTION_WRITE,
            'w',
            InputOption::VALUE_OPTIONAL,
            'Write the list of obsolete db entries to a .txt file instead of deleting them. '
            . 'Optionally provide a filename; defaults to var/obsolete_db_entries_<date>.txt.',
            false
        );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entries = $this->obsoleteDbEntriesFinder->find();

        $writeOption = $input->getOption(self::OPTION_WRITE);
        if ($writeOption !== false) {
            return $this->writeEntriesToTxt(array_map('strval', $entries), $writeOption, $output);
        }

        $accepted = $this->userInteraction->showDbValuesToDeleteAndAskForConfirmation(
            array_map('strval', $entries),
            GalleryResourceModel::GALLERY_TABLE,
            $input,
            $output
        );
        if ($accepted) {
            $this->dbGalleryDeleter->deleteGalleryValues($entries);
            $deletedValues = $this->dbGalleryDeleter->getDeletedValues();
            $numberOfValuesDeleted = $this->dbGalleryDeleter->getNumberOfValuesDeleted();

            $this->userInteraction->showFinalDbInfo(
                $deletedValues,
                $numberOfValuesDeleted,
                GalleryResourceModel::GALLERY_TABLE,
                $output
            );
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * @param array<string> $entries
     */
    private function writeEntriesToTxt(array $entries, ?string $filename, OutputInterface $output): int
    {
        if ($entries === []) {
            $output->writeln('<info>No obsolete db entries found, nothing to write.</info>');
            return Cli::RETURN_SUCCESS;
        }

        if ($filename === null || $filename === '') {
            $varDir = $this->directoryList->getPath(DirectoryList::VAR_DIR);
            $filename = $varDir . '/obsolete_db_entries_' . date('Y-m-d_H-i-s') . '.txt';
        }

        $content = implode(PHP_EOL, $entries) . PHP_EOL;
        $result = file_put_contents($filename, $content);

        if ($result === false) {
            $output->writeln(sprintf('<error>Could not write to file: %s</error>', $filename));
            return Cli::RETURN_FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Written %d obsolete db entries to: %s</info>',
            count($entries),
            $filename
        ));

        return Cli::RETURN_SUCCESS;
    }
}
