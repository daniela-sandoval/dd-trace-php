<?php

namespace DDTrace\Tests\Sapi\PhpCgi;

use DDTrace\Tests\Integrations\CLI\EnvSerializer;
use DDTrace\Tests\Integrations\CLI\IniSerializer;
use DDTrace\Tests\Sapi\Sapi;
use Symfony\Component\Process\Process;

final class PhpCgi implements Sapi
{
    /**
     * @var Process
     */
    private $process;

    /**
     * @var string
     */
    private $host;

    /**
     * @var int
     */
    private $port;

    /**
     * @var array
     */
    private $envs;

    /**
     * @var array
     */
    private $inis;

    /**
     * @param string $host
     * @param int $port
     * @param array $envs
     * @param array $inis
     */
    public function __construct($host, $port, array $envs = [], array $inis = [])
    {
        $this->host = $host;
        $this->port = $port;
        $this->envs = $envs;
        $this->inis = $inis;
    }

    public function start()
    {
        $cmd = sprintf(
            'php-cgi %s -b %s:%d',
            new IniSerializer($this->inis),
            $this->host,
            $this->port
        );
        $envs = new EnvSerializer($this->envs);
        $processCmd = "$envs exec $cmd";
        $this->process = new Process($processCmd);
        $this->process->start();
    }

    public function stop()
    {
        $this->process->stop(0);
    }

    public function isFastCgi()
    {
        return true;
    }
}
