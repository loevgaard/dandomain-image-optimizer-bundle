<?php
namespace Loevgaard\DandomainImageOptimizerBundle\Service;

use Loevgaard\DandomainImageOptimizerBundle\Console\OutputAwareInterface;
use Loevgaard\DandomainImageOptimizerBundle\Console\OutputAwareTrait;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Tinify\Source;

class Optimizer implements ContainerAwareInterface, OutputAwareInterface
{
    use ContainerAwareTrait, OutputAwareTrait;

    public function optimizeFtp($force = false, $dryRun = false, $limit = null, $image = null) {
        /**
         * PROCEDURE
         *
         * Iterate through all directories and add images to the queue has not been optimized before
         * If the force option is set we optimize all images in the directories
         * The way we determine whether the image has been optimized is that we check if there exist -o variation
         */
        $output         = $this->getOutput();
        $optionForce    = $force;
        $optionDryRun   = $dryRun;
        $optionLimit    = $limit;
        $optionImage    = $image;
        $baseUrl        = $this->container->getParameter('loevgaard_dandomain_image_optimizer.base_url');
        $host           = $this->container->getParameter('loevgaard_dandomain_image_optimizer.host');
        $username       = $this->container->getParameter('loevgaard_dandomain_image_optimizer.username');
        $password       = $this->container->getParameter('loevgaard_dandomain_image_optimizer.password');
        $directories    = $this->container->getParameter('loevgaard_dandomain_image_optimizer.directories');

        $output->writeln('Force: ' . ($optionForce ? 'true' : 'false'), OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Dry run: ' . ($optionDryRun ? 'true' : 'false'), OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Host: ' . $host, OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Base URL: ' . $baseUrl, OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln("Directories: ['" . join("', '", $directories) . "']", OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln("Image config", OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln(print_r($this->getImageConfig(), true), OutputInterface::VERBOSITY_VERBOSE);

        $ftp = new \Ftp();
        $ftp->connect($host);
        $ftp->login($username, $password);
        $ftp->pasv(true);

        $queue = [];

        if($optionImage) {
            $queue[] = trim($optionImage, '/');
        } else {
            $i = 0;
            $limitReached = false;
            foreach ($directories as $directory) {
                $directory = trim($directory, '/');
                $rawFileList = $ftp->rawList($directory);
                $fileList = $this->parseFtpRawlist($rawFileList, true); // dandomain is windows so we set $win = true

                if ($fileList === false) {
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
                foreach ($originalImages as $originalImage) {
                    $originalImageVariations = $this->getImageVariations($originalImage['filename']);
                    $optimizedImages[$originalImageVariations['popup']] = $originalImageVariations['original'];
                }

                foreach ($popupImages as $popupImage) {
                    if ($optionLimit && $i >= $optionLimit) {
                        $limitReached = true;
                        break;
                    }
                    $filename = $popupImage['filename'];
                    $add = true;
                    if (isset($optimizedImages[$popupImage['filename']])) {
                        $filename = $optimizedImages[$popupImage['filename']];
                        if (!$optionForce) {
                            $add = false;
                        }
                    }
                    if ($add) {
                        $queue[] = $directory . '/' . $filename;
                        $i++;
                    }
                }

                if ($limitReached) {
                    break;
                }
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

    /**
     * @param string $imageSource The path to the image
     * @param string $targetDir The directory on the FTP where the files should be uploaded
     */
    public function uploadAndOptimizeImage($imageSource, $targetDir) {
        $ftp = $this->getFtp();
        $imageConfig = $this->getImageConfig();
        $imageVariations = $this->getImageVariations($imageSource);
        $tmpFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/' . basename($imageSource);

        /** @var Source $source */
        $source = \Tinify\fromFile($imageSource);

        // create popup image
        $popup = $source->resize([
            'method'    => 'fit',
            'width'     => $imageConfig['popup']['width'],
            'height'    => $imageConfig['popup']['height'],
        ]);
        $popup->toFile($tmpFile);
        $ftp->put($targetDir . '/' . $imageVariations['popup'], $tmpFile, FTP_BINARY);

        // create related image
        $related = $source->resize([
            'method'    => 'fit',
            'width'     => $imageConfig['related']['width'],
            'height'    => $imageConfig['related']['height'],
        ]);
        $related->toFile($tmpFile);
        $ftp->put($targetDir . '/' . $imageVariations['related'], $tmpFile, FTP_BINARY);

        // create thumbnail image
        $thumbnail = $source->resize([
            'method'    => 'fit',
            'width'     => $imageConfig['thumbnail']['width'],
            'height'    => $imageConfig['thumbnail']['height'],
        ]);
        $thumbnail->toFile($tmpFile);
        $ftp->put($targetDir . '/' . $imageVariations['thumbnail'], $tmpFile, FTP_BINARY);

        // create product image
        $product = $source->resize([
            'method'    => 'fit',
            'width'     => $imageConfig['product']['width'],
            'height'    => $imageConfig['product']['height'],
        ]);
        $product->toFile($tmpFile);
        $ftp->put($targetDir . '/' . $imageVariations['product'], $tmpFile, FTP_BINARY);

        // upload original
        $ftp->put($targetDir . '/' . $imageVariations['original'], $imageSource, FTP_BINARY);

        // we are done
        $ftp->close();
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
        return $this->container->getParameter('loevgaard_dandomain_image_optimizer.image_settings');
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

    /**
     * @return \Ftp
     */
    protected function getFtp() {
        $host       = $this->container->getParameter('loevgaard_dandomain_image_optimizer.host');
        $username   = $this->container->getParameter('loevgaard_dandomain_image_optimizer.username');
        $password   = $this->container->getParameter('loevgaard_dandomain_image_optimizer.password');

        $ftp = new \Ftp();
        $ftp->connect($host);
        $ftp->login($username, $password);
        $ftp->pasv(true);

        return $ftp;
    }
}