<?php
namespace Loevgaard\DandomainImageOptimizerBundle\Console;

use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

trait OutputAwareTrait
{
    private $output;

    /**
     * @param OutputInterface $output
     * @return $this
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
        return $this;
    }

    /**
     * @return OutputInterface
     */
    public function getOutput() {
        if(!$this->output) {
            $this->output = new NullOutput();
        }

        return $this->output;
    }
}