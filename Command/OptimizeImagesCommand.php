<?php
namespace Loevgaard\DandomainImageOptimizerBundle\Command;

use Loevgaard\LockableCommandBundle\Command\LockableCommandInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tinify\Source;

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
        $baseUrl        = $this->getContainer()->getParameter('loevgaard_dandomain_image_optimizer.base_url');
        $host           = $this->getContainer()->getParameter('loevgaard_dandomain_image_optimizer.host');
        $username       = $this->getContainer()->getParameter('loevgaard_dandomain_image_optimizer.username');
        $password       = $this->getContainer()->getParameter('loevgaard_dandomain_image_optimizer.password');
        $directories    = $this->getContainer()->getParameter('loevgaard_dandomain_image_optimizer.directories');

        $output->writeln('Force: ' . ($optionForce ? 'true' : 'false'), OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Dry run: ' . ($optionDryRun ? 'true' : 'false'), OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Host: ' . $host, OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Base URL: ' . $baseUrl, OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln("Directories: ['" . join("', '", $directories) . "']", OutputInterface::VERBOSITY_VERBOSE);

        $ftp = new \Ftp();
        $ftp->connect($host);
        $ftp->login($username, $password);
        $ftp->pasv(true);

        $queue = [];
        $i = 0;
        $limitReached = false;
        foreach($directories as $directory) {
            $directory      = trim($directory, '/');
            $rawFileList    = $ftp->rawList($directory);
            $fileList       = $this->parseFtpRawlist($rawFileList, true); // dandomain is windows so we set $win = true

            if($fileList === false) {
                $output->writeln('parseFtpRawlist returned false', OutputInterface::VERBOSITY_VERBOSE);
                continue;
            }

            $popupImages = array_filter($fileList, function ($val) {
                return preg_match('/\-p\.(jpg|png|gif)$/i', $val['filename']) === 1;
            });

            $originalImages = array_filter($fileList, function ($val) {
                return preg_match('/\-o\.(jpg|png|gif)$/i', $val['filename']) === 1;
            });
            $optimizedImages = [];
            foreach($originalImages as $originalImage) {
                $originalImageVariations = $this->getImageVariations($originalImage['filename']);
                $optimizedImages[$originalImageVariations['popup']] = $originalImageVariations['original'];
            }

            foreach($popupImages as $popupImage) {
                if($optionLimit && $i >= $optionLimit) {
                    $limitReached = true;
                    break;
                }
                $filename = $popupImage['filename'];
                $add = true;
                if(isset($optimizedImages[$popupImage['filename']])) {
                    $filename = $optimizedImages[$popupImage['filename']];
                    if(!$optionForce) {
                        $add = false;
                    }
                }
                if($add) {
                    $queue[] = $directory . '/' . $filename;
                    $i++;
                }
            }

            if($limitReached) {
                break;
            }
        }

        $c = count($queue);
        $output->writeln('<info>Optimizing ' . count($queue) . ' images</info>', OutputInterface::VERBOSITY_VERBOSE);

        if($c) {
            $imageConfig = $this->getImageConfig();

            $i = 1;
            $startTime = time();
            foreach ($queue as $image) {
                $dir                = dirname($image);
                $imageVariations    = $this->getImageVariations($image);

                if ($optionDryRun) {
                    $output->writeln('Optimizing ' . $imageVariations['product'] . '...', OutputInterface::VERBOSITY_VERBOSE);
                }

                try {
                    if(!$optionDryRun) {
                        $output->writeln("Copying $image to $dir/{$imageVariations['original']}", OutputInterface::VERBOSITY_VERBOSE);

                        // save existing image as the original
                        $res = $this->ftpCopy($ftp, $image, $dir . '/' . $imageVariations['original']);

                        if($res === false) {
                            $output->writeln('There was an error copying ' . $image, OutputInterface::VERBOSITY_VERBOSE);
                            continue;
                        }
                    }
                } catch(\FtpException $e) {
                    $output->writeln($e->getMessage(), OutputInterface::VERBOSITY_VERBOSE);
                    continue;
                }

                $tmpFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/' . basename($image);

                try {
                    try {
                        try {
                            if(!$optionDryRun) {
                                /** @var Source $source */
                                $source = \Tinify\fromUrl($baseUrl . '/' . $image);

                                // popup
                                $source->toFile($tmpFile);
                                $ftp->put($dir . '/' . $imageVariations['popup'], $tmpFile, FTP_BINARY);

                                // thumbnail (-t)
                                $resized = $source->resize([
                                    'method' => 'fit',
                                    'width' => $imageConfig['thumbnail']['width'],
                                    'height' => $imageConfig['thumbnail']['height'],
                                ]);
                                $resized->toFile($tmpFile);
                                $ftp->put($dir . '/' . $imageVariations['thumbnail'], $tmpFile, FTP_BINARY);

                                // related (-r)
                                $resized = $source->resize([
                                    'method' => 'fit',
                                    'width' => $imageConfig['related']['width'],
                                    'height' => $imageConfig['related']['height'],
                                ]);
                                $resized->toFile($tmpFile);
                                $ftp->put($dir . '/' . $imageVariations['related'], $tmpFile, FTP_BINARY);

                                // product (no extension)
                                $resized = $source->resize([
                                    'method' => 'fit',
                                    'width' => $imageConfig['product']['width'],
                                    'height' => $imageConfig['product']['height'],
                                ]);
                                $resized->toFile($tmpFile);
                                $ftp->put($dir . '/' . $imageVariations['product'], $tmpFile, FTP_BINARY);
                            }
                        } catch (\Exception $e) {
                            $output->writeln($e->getMessage(), OutputInterface::VERBOSITY_VERBOSE);

                            $ftp->delete($dir . '/' . $imageVariations['original']);
                        }
                    } catch (\Exception $e) {
                        $output->writeln($e->getMessage(), OutputInterface::VERBOSITY_VERBOSE);
                        $ftp->reconnect();
                        $ftp->delete($dir . '/' . $imageVariations['original']);
                    }
                } catch(\Exception $e) {

                }

                // delete tmp file
                @unlink($tmpFile);

                $output->writeln('- Optimized ', OutputInterface::VERBOSITY_VERBOSE);

                if($i % 20 == 0) {
                    $elapsed    = ceil((time() - $startTime) / 60);
                    $timeLeft   = round((($c / $i) * $elapsed) / 60, 2);
                    $output->writeln("<info>$i/$c | Elapsed time: $elapsed minutes | Estimated time left: $timeLeft hours</info>", OutputInterface::VERBOSITY_VERBOSE);
                }

                $i++;
            }
        }
    }

    protected function getImageVariations($image) {
        // trims the image to its basename
        $image = basename($image);

        // if the image matches this pattern we have to strip out the image variation
        if(preg_match('/\-(p|r|o|t)\.(jpg|png|gif)$/i', $image)) {
            $image = preg_replace('/\-(p|r|o|t)\.(jpg|png|gif)$/i', '.$2', $image);
        }
        $pathinfo = pathinfo($image);
        return [
            'product'   => $image,
            'related'   => $pathinfo['filename'] . '-r.' . $pathinfo['extension'],
            'thumbnail' => $pathinfo['filename'] . '-t.' . $pathinfo['extension'],
            'popup'     => $pathinfo['filename'] . '-p.' . $pathinfo['extension'],
            'original'  => $pathinfo['filename'] . '-o.' . $pathinfo['extension'],
        ];
    }

    protected function getImageConfig() {
        return [
            'product' => [
                'width'  => 400,
                'height' => 400,
            ],
            'related' => [
                'width'  => 240,
                'height' => 240,
            ],
            'thumbnail' => [
                'width'  => 240,
                'height' => 240,
            ],
            'popup' => [
                'width'  => 850,
                'height' => 850,
            ],
        ];
    }

    protected function parseFtpRawlist($list, $win = false) {
        if(!is_array($list)) {
            return false;
        }

        $output = array();
        if ($win) {
            foreach ($list as $file) {
                preg_match('#([0-9]{2})-([0-9]{2})-([0-9]{2}) +([0-9]{2}):([0-9]{2})(AM|PM) +(<DIR>)? +([0-9]+|) +(.+)#', $file, $matches);
                if (is_array($matches)) {
                    $output[] = [
                        'dir'       => isset($matches[7]) && !empty($matches[7]),
                        'size'      => $matches[8],
                        'filename'  => $matches[9],
                    ];
                }
            }
            return !empty($output) ? $output : false;
        } else {
            foreach ($list as $file) {
                $matches = preg_split('[ ]', $file, 9, PREG_SPLIT_NO_EMPTY);
                if ($matches[0] != 'total') {
                    $output[] = [
                        'dir'       => $matches[0] {0} === 'd',
                        'size'      => $matches[4],
                        'filename'  => $matches[8],
                    ];
                }
            }
            return !empty($output) ? $output : false;
        }
    }

    /**
     * @param \Ftp $ftp
     * @param string $remoteSource
     * @param string $remoteTarget
     * @return bool
     */
    protected function ftpCopy(\Ftp $ftp, $remoteSource , $remoteTarget) {
        $ext = pathinfo($remoteSource, PATHINFO_EXTENSION);
        $tmpFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/' . md5(uniqid()) . '.' . $ext;

        $res = $ftp->get($tmpFile, $remoteSource, FTP_BINARY);
        if($res === false) {
            return false;
        }

        $res = $ftp->put($remoteTarget, $tmpFile, FTP_BINARY);
        if($res === false) {
            return false;
        }

        @unlink($tmpFile);

        return true;
    }
}