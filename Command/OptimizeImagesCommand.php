<?php
namespace Loevgaard\DandomainImageOptimizerBundle\Command;

use Loevgaard\LockableCommandBundle\Command\LockableCommandInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OptimizeImagesCommand extends ContainerAwareCommand implements LockableCommandInterface
{
    protected function configure()
    {
        $this
            ->setName('dandomain:optimize-images')
            ->setDescription('This command optimizes images on the FTP')
            ->addOption('force', null, InputOption::VALUE_NONE, 'If set, the command will force optimization of all images')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'If set, the command will not optimize any images, but output the names of the images that would have been optimized')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'If set, the command will optimize <limit> images')
            ->addOption('image', null, InputOption::VALUE_REQUIRED, 'If set, the command will optimize only this image. This should be a absolute path, i.e. /images/image.jpg')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /**
         * PROCEDURE
         *
         * Iterate through all directories and add images to the queue has not been optimized before
         * If the force option is set we optimize all images in the directories
         * The way we determine whether the image has been optimized is that we check if there exist -o variation
         */
        $optionForce    = $input->getOption('force');
        $optionDryRun   = $input->getOption('dry-run');
        $optionLimit    = $input->getOption('limit');
        $optionImage    = $input->getOption('image');

        $optimizer      = $this->getContainer()->get('loevgaard_dandomain_image_optimizer.optimizer');
        $optimizer
            ->setOutput($output)
            ->optimizeFtp($optionForce ? true : false, $optionDryRun ? true : false, $optionLimit, $optionImage)
        ;

    }
}