<?php
namespace Altax\Module\Task\Process;

use Symfony\Component\Process\Process as SymfonyProcess;
use Altax\Module\Server\Facade\Server;

class Process
{
    protected $runtimeTask;
    protected $commandline;
    protected $timeout;
    protected $isLocal;
    protected $nodes = array();
    protected $isAlreadyCalledOn = false;
    protected $isAlreadyCalledTo = false;
    protected $childPids = array();

    public function __construct($runtimeTask)
    {
        $this->runtimeTask = $runtimeTask;
        $this->commandline = null;
        $this->timeout = null;
        $this->isLocal = true;
    }

    public function setCommandline($commandline)
    {
        $this->commandline = $commandline;
    }

    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;
    }

    /**
     * Set roles or nodes to run process remotely.
     * 
     * @return [type] [description]
     */
    public function on()
    {
        if ($this->isAlreadyCalledTo) {
            throw new \RuntimeException("You have already called 'to' method. Couldn't call 'on' after 'to'.");
        }

        $nodes = call_user_func_array(array($this, "loadNodes"), func_get_args());

        $this->setNodes($nodes);

        // Output info
        if ($this->runtimeTask->getOutput()->isVerbose()) {

            $this->runtimeTask->getOutput()
                ->writeln("<info>Process#on set</info> <comment>"
                    .count($nodes)
                    ."</comment> nodes: "
                    ."".trim(implode(", ", array_keys($nodes))));
        }

        // 'on' means to run command remotely.
        $this->isLocal = false;
        $this->isAlreadyCalledOn = true;

        return $this;
    }

    /**
     * Set roles or nodes to run process locally.
     * 
     * @return [type] [description]
     */
    public function to()
    {
        if ($this->isAlreadyCalledOn) {
            throw new \RuntimeException("You have already called 'on' method. Couldn't call 'to' after 'on'.");
        }

        $nodes = call_user_func_array(array($this, "loadNodes"), func_get_args());

        $this->setNodes($nodes);

        // Output info
        if ($this->runtimeTask->getOutput()->isVerbose()) {

            $this->runtimeTask->getOutput()
                ->writeln("<info>Process#to set</info> <comment>"
                    .count($nodes)
                    ."</comment> nodes: "
                    ."".trim(implode(", ", array_keys($nodes))));
        }

        // 'on' means to run command remotely.
        $this->isLocal = true;
        $this->isAlreadyCalledTo = true;
        
        return $this;
    }



    /**
     * Load nods from variable length argument lists same 'on' and 'for' method.
     * @return array Array of Altax\Module\Server\Resource\Node
     */
    protected function loadNodes()
    {
        $candidateNodeNames = array();
        $concreteNodes = array();

        $args = func_get_args();

        if (count($args) === 0) {
            throw new \RuntimeException("Missing argument. Must 1 argument at minimum.");
        }

        foreach ($args as $arg) {
            if (is_string($arg)) {
                $candidateNodeNames[] = array(
                    "type" => null,
                    "name" => $arg
                    );
            }

            if (is_array($arg)) {

                if (isset($arg["nodes"])) {
                    $nodes = array();
                    if (is_string($arg["nodes"])) {
                        $nodes[] = $arg["nodes"];
                    } elseif (is_array($arg["nodes"])) {
                        $nodes = $arg["nodes"];
                    }

                    foreach ($nodes as $node) {
                        $candidateNodeNames[] = array(
                            "type" => "node",
                            "name" => $node,
                        );
                    } 
                }

                if (isset($arg["roles"])) {
                    $roles = array();
                    if (is_string($arg["roles"])) {
                        $roles[] = $arg["roles"];
                    } elseif (is_array($arg["roles"])) {
                        $roles = $arg["roles"];
                    }

                    foreach ($roles as $role) {
                        $candidateNodeNames[] = array(
                            "type" => "role",
                            "name" => $role,
                        );
                    } 
                }
            }
        }

        foreach ($candidateNodeNames as $candidateNodeName) {
            
            $node = null;
            $role = null;

            if ($candidateNodeName["type"] === null || $candidateNodeName["type"] == "node") {
                $node = Server::getNode($candidateNodeName["name"]);
            }

            if ($candidateNodeName["type"] === null || $candidateNodeName["type"] == "role") {
                $role = Server::getRole($candidateNodeName["name"]);
            }
            
            if ($node && $role) {
                throw new \RuntimeException("The key '$candidateNodeName' was found in both nodes and roles. So It couldn't identify to unique node.");
            }

            if ($node) {
                $concreteNodes[$node->getName()] = $node;
            }

            if ($role) {
                foreach($role as $nodeName) {
                    $concreteNodes[$nodeName] = Server::getNode($nodeName);
                }
            }
        }

        return $concreteNodes;
    }


    public function run()
    {
        if ($this->isLocal()) {
            // Runs process locally.

            // Output info
            if ($this->runtimeTask->getOutput()->isVerbose()) {
                $this->runtimeTask->getOutput()->writeln("<info>Process#run </info>Running process <comment>locally</comment>.");
            }

            $self = $this;
            $symfonyProcess = new SymfonyProcess($this->commandline);
            $symfonyProcess->setTimeout($this->timeout);
            $symfonyProcess->run(function ($type, $buffer) use ($self) {
                $self->runtimeTask->getOutput()->write($buffer);
            });

        } else {
            // Runs process remotely.
            
            // Output info
            if ($this->runtimeTask->getOutput()->isVerbose()) {
                $this->runtimeTask->getOutput()->writeln("<info>Process#run </info>Running process <comment>remotely</comment>.");
            }

            // Fork process
            declare(ticks = 1);
            pcntl_signal(SIGTERM, array($this, "signalHander"));
            pcntl_signal(SIGINT, array($this, "signalHander"));

            $nodes = $this->getNodes();
            foreach ($nodes as $node) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    // Error
                    throw new \RuntimeException("Fork Error.");
                } else if ($pid) {
                    // Parent process
                    $this->childPids[$pid] = $node;
                } else {
                    // Child process
                    if ($this->runtimeTask->getOutput()->isVerbose()) {
                        $this->runtimeTask->getOutput()->writeln("<info>Forked process for node: </info>".$node->getName()." (pid:<comment>".posix_getpid()."</comment>)");
                    }

                    exit(0);
                }
            }

            // At the following code, only parent precess runs.
            while (count($this->childPids) > 0) {
                // Keep to wait until to finish all child processes.
                $status = null;
                $pid = pcntl_wait($status);
                if (!$pid) {
                    throw new \RuntimeException("pcntl_wait error.");
                }

                if (!array_key_exists($pid, $this->childPids)) {
                    throw new \RuntimeException("pcntl_wait error.".$pid);
                }

                // When a child process is done, removes managed child pid.
                $node = $this->childPids[$pid];
                unset($this->childPids[$pid]);
            }
        }
    }

    public function setNodes($nodes)
    {
        $this->nodes = $nodes;
    }

    public function getNodes()
    {
        return $this->nodes;
    }

    public function isLocal()
    {
        return $this->isLocal;
    }

    public function isRemote()
    {
        return !$this->isLocal();
    }

    public function signalHander($signo)
    {
        // TODO: Impliment.
        switch ($signo) {
            case SIGTERM:
                $this->runtimeTask->getOutput()->writeln("Got SIGTERM.");
                $this->killAllChildren();
                exit;

            case SIGINT:
                $this->runtimeTask->getOutput()->writeln("Got SIGINT.");
                $this->killAllChildren();
                exit;
        }
    }

    public function killAllChildren()
    {
        foreach ($this->childPids as $pid => $host) {
            $this->runtimeTask->getOutput()->writeln("Sending sigint to child (pid:<comment>$pid</comment>)");
            posix_kill($pid, SIGINT);
        }
    }

}