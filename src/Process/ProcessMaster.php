<?php
/**
 * ProcessMaster.php
 *
 * Creator:         chongyi
 * Create Datetime: 2017/1/23 22:04
 */

namespace Keeper\Process;

use Swoole\Process;
use Illuminate\Contracts\Container\Container;
use Illuminate\Container\Container as laravelContainer;

/**
 * Class ProcessManager
 *
 * 主进程管理器
 *
 * @package Keeper\Process
 */
class ProcessMaster
{
    /**
     * @var int
     */
    public $processId;

    /**
     * @var string
     */
    public $processName;

    /**
     * @var string
     */
    public $pidFile;

    /**
     * @var resource
     */
    protected $pidFileHandle;

    /**
     * @var Container
     */
    protected $container;

    /**
     * @var boolean
     */
    protected $daemon = false;

    /**
     * @var ProcessHandler[]
     */
    protected $childrenProcesses = [];

    /**
     * @var array|int[]
     */
    protected $childrenProcessPipes = [];

    /**
     * @var string[]
     */
    protected $bootstrapLoaders = [];

    /**
     * @var string
     */
    public $user;

    /**
     * @var int
     */
    public $userId;

    /**
     * @var string
     */
    public $group;

    /**
     * @var int
     */
    public $groupId;

    /**
     * @var int 最后一次收到的信号
     */
    protected $signal;

    /**
     * @var bool
     */
    public $runningInstance = false;

    /**
     * @var \Closure
     */
    protected $ioEventCallback;

    /**
     * ProcessManager constructor.
     *
     * @param Container $container
     */
    public function __construct(Container $container = null)
    {
        $this->container = is_null($container) ? new LaravelContainer : $container;
    }

    /**
     * 开启守护进程
     *
     * @param bool $daemon 默认为 false
     *
     * @return $this
     */
    public function daemon($daemon = false)
    {
        $this->daemon = $daemon;

        return $this;
    }

    /**
     * 设置 PID 文件路径
     *
     * @param string $path
     *
     * @return $this
     */
    public function pidFile($path)
    {
        $this->pidFile = $path;

        return $this;
    }

    /**
     * @param string $user
     *
     * @return $this
     */
    public function user($user)
    {
        $this->user   = $user;
        $this->userId = posix_getpwnam($user)['uid'];

        return $this;
    }

    /**
     * @param string $group
     *
     * @return $this
     */
    public function group($group)
    {
        $this->group   = $group;
        $this->groupId = posix_getgrnam($group)['gid'];

        return $this;
    }

    /**
     * @param string $processName
     *
     * @return $this
     */
    public function processName($processName)
    {
        $this->processName = $processName;

        return $this;
    }

    /**
     * 运行
     *
     * @param boolean $force
     *
     * @return int
     */
    public function run($force = false)
    {
        $this->touchPidFile();
        $this->pidFileHandle = $this->buildPidFileDescriptor();

        if ($pid = $this->getRunningInstancePid($this->pidFileHandle)) {
            if (!$force) {
                throw new \RuntimeException("Have running instance (PID: $pid). Nothing to do.", 2);
            }

            fclose($this->pidFileHandle);
            Process::kill($pid);
            sleep(3);

            return $this->run();
        }

        if ($this->daemon) {
            Process::daemon(true, true);
        }

        ftruncate($this->pidFileHandle, 0);
        fwrite($this->pidFileHandle, $this->processId = getmypid());

        $this->startManagerProcess();

        return $this->processId;
    }

    /**
     * 停止
     */
    public function stop()
    {
        if (is_file($this->pidFile) && $pid = $this->getRunningInstancePid(fopen($this->pidFile, 'r+'))) {
            Process::kill($pid);
        }
    }

    /**
     * 主进程管理器注册
     */
    protected function startManagerProcess()
    {
        if ($this->processName) {
            swoole_set_process_name($this->processName);
        }

        Process::signal(SIGTERM, $this->onTerminating());
        Process::signal(SIGCHLD, $this->onChildProcessShutdown());
        Process::signal(SIGUSR1, $this->onReopen());
        Process::signal(SIGUSR2, $this->onReload());

        $this->runningInstance = true;

        $this->bootstrap();
    }

    protected function bootstrap()
    {
        foreach ($this->bootstrapLoaders as $bootstrap) {
            /** @var ProcessHandler $processBootstrap */
            $processBootstrap                    = new $bootstrap($this, $this->container);
            $processId                           = $processBootstrap->run();
            $this->childrenProcesses[$processId] = $processBootstrap;

            $this->childrenProcessPipes[$pipe = $processBootstrap->getProcess()->pipe] = $processId;

            swoole_event_add($pipe, $this->ioEvent());
        }
    }

    protected function ioEvent(\Closure $callback = null)
    {
        if (is_null($callback)) {
            if (is_null($this->ioEventCallback)) {
                return $this->ioEventCallback = function () {
                    //
                };
            }

            return $this->ioEventCallback;
        }

        $this->ioEventCallback = $callback;

        return $this;
    }

    /**
     * 设置启动器加载序列
     *
     * @param string[] $bootstrapLoaders
     *
     * @return $this
     */
    public function bootstrapLoader(array $bootstrapLoaders)
    {
        $this->bootstrapLoaders = $bootstrapLoaders;

        return $this;
    }

    /**
     * 获取应用核心
     *
     * @return Container
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * 进程终结
     */
    protected function terminate()
    {
        flock($this->pidFileHandle, LOCK_UN);
        fclose($this->pidFileHandle);

        @unlink($this->pidFile);
        exit;
    }

    /**
     * @return \Closure
     */
    protected function onTerminating()
    {
        return function ($signal) {
            if (count($this->childrenProcesses)) {
                $this->signal = $signal;

                foreach ($this->childrenProcesses as $process) {
                    $process->stop();
                }
            } else {
                $this->terminate();
            }
        };
    }

    /**
     * 获取收到 SIGKILL 时的处理回调
     *
     * @return \Closure
     */
    protected function onKill()
    {
        return $this->onTerminating();
    }

    /**
     * 获取收到 SIGCHLD 时的处理回调
     *
     * @return \Closure
     */
    protected function onChildProcessShutdown()
    {
        return function () {
            while ($ret = Process::wait(false)) {
                if (SIGUSR1 === $this->signal || SIGKILL === $ret['signal']) {
                    $bootstrap = get_class($this->childrenProcesses[$ret['pid']]);

                    /** @var ProcessHandler $processBootstrap */
                    $processBootstrap                    = new $bootstrap($this);
                    $processId                           = $processBootstrap->run();
                    $this->childrenProcesses[$processId] = $processBootstrap;

                    $this->childrenProcessPipes[$pipe = $processBootstrap->getProcess()->pipe] = $processId;
                    swoole_event_add($pipe, $this->ioEvent());
                }

                unset($this->childrenProcesses[$ret['pid']]);
                unset($this->childrenProcessPipes[array_search($ret['pid'], $this->childrenProcessPipes)]);
            }

            if (SIGTERM === $this->signal || SIGKILL === $this->signal) {
                $this->terminate();
            }
        };
    }

    /**
     * 获取收到 SIGUSR1 时的处理回调
     *
     * @return \Closure
     */
    protected function onReopen()
    {
        return function ($signal) {
            $this->signal = $signal;

            foreach ($this->childrenProcesses as $process) {
                $process->kill();
            }
        };
    }

    /**
     * 获取收到 SIGUSR2 时的处理回调
     *
     * @return \Closure
     */
    protected function onReload()
    {
        return function () {
            foreach ($this->childrenProcesses as $process) {
                $process->kill(SIGUSR1);
            }
        };
    }

    /**
     * 通过配置设置组件
     *
     * @param array $config
     *
     * @return $this
     */
    public function setConfig(array $config)
    {
        if (isset($config['pid_file'])) {
            $this->pidFile($config['pid_file']);
        }

        if (isset($config['daemon'])) {
            if (is_string($config['daemon']) && strtolower($config['daemon']) === 'true') {
                $this->daemon(true);
            } else {
                $this->daemon((bool)$config['daemon']);
            }
        }

        if (isset($config['process_name'])) {
            $this->processName($config['process_name']);
        }

        if (isset($config['group'])) {
            $this->group($config['group']);
        }

        if (isset($config['user'])) {
            $this->user($config['user']);
        }

        if (isset($config['bootstrap'])) {
            $this->bootstrapLoader($config['bootstrap']);
        }

        return $this;
    }

    /**
     * 获取 PID
     *
     * @return bool|int|string
     */
    public function getPid()
    {
        if ($this->runningInstance) {
            return $this->processId;
        }

        if ($this->pidFile && is_file($this->pidFile)) {
            $fd = fopen($this->pidFile, 'r');

            if (!$fd) {
                return false;
            }

            $pid = fread($fd, 64);
            fclose($fd);

            return $pid;
        }

        return false;
    }

    protected function touchPidFile()
    {
        if (!is_dir($path = dirname($this->pidFile))) {
            @mkdir($path, 0644, true);
        }

        if (!is_file($this->pidFile)) {
            touch($this->pidFile);
        }
    }

    protected function buildPidFileDescriptor()
    {
        $fd = fopen($this->pidFile, 'r+');

        if (!$fd) {
            throw new \RuntimeException('Create PID file failed.', 1);
        }

        return $fd;
    }

    protected function getRunningInstancePid($fd = null)
    {
        if ($this->processId) {
            return $this->processId;
        }

        if (is_null($fd)) {
            $fd = $this->pidFileHandle;
        }

        if (!flock($fd, LOCK_EX | LOCK_NB)) {
            return fread($fd, 64);
        }

        return false;
    }

}