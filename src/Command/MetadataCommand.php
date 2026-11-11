<?php

namespace cweagans\Composer\Command;

use Composer\Command\BaseCommand;
use Composer\Json\JsonFile;
use cweagans\Composer\MetadataPatches;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MetadataCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('patches-metadata');
        $this->setDescription('Export the unpatched dependency manifest used to create metapackage metadata patches.');
        $this->addArgument('package', InputArgument::REQUIRED);
        $this->addArgument('version', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $metadata = new MetadataPatches($this->requireComposer(), $this->getIO());
        $package = $metadata->findMetapackage($input->getArgument('package'), $input->getArgument('version'));
        $output->writeln(JsonFile::encode(MetadataPatches::manifest($package)));
        return 0;
    }
}
