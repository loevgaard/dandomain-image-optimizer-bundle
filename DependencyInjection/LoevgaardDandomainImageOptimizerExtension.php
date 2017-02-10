<?php
namespace Loevgaard\DandomainImageOptimizerBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class LoevgaardDandomainImageOptimizerExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('loevgaard_dandomain_image_optimizer.base_url',        $config['base_url']);
        $container->setParameter('loevgaard_dandomain_image_optimizer.host',            $config['host']);
        $container->setParameter('loevgaard_dandomain_image_optimizer.username',        $config['username']);
        $container->setParameter('loevgaard_dandomain_image_optimizer.password',        $config['password']);
        $container->setParameter('loevgaard_dandomain_image_optimizer.directories',     $config['directories']);
        $container->setParameter('loevgaard_dandomain_image_optimizer.image_settings',  $config['image_settings']);
    }
}
