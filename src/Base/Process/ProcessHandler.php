<?php
/**
 * ProcessHandler.php
 *
 * Creator:    chongyi
 * Created at: 2016/11/29 17:35
 */

namespace Keeper\Base\Process;

use Swoole\Process;
use Illuminate\Contracts\Container\Container;

/**
 * Class ProcessHandler
 *
 * 托管进程管理器
 *
 * @package Keeper\Base\Process
 */
abstract class ProcessHandler
{
    /**
     * @var ProcessMaster
     */
    protected $processMaster;

    /**
     * @var Process
     */
    protected $process;

    /**
     * @var int
     */
    protected $processId;

    /**
     * @var Container
     */
    private $container;

    /**
     * @var bool 是否开启异步 IO 事件
     */
    protected $async = false;

    /**
     * ProcessBuilder constructor.
     *
     * @param ProcessMaster $processMaster
     */
    public function __construct(ProcessMaster $processMaster)
    {
        $this->processMaster = $processMaster;
        $this->container     = $processMaster->getContainer();
    }

    /**
     * 获取容器
     *
     * @return Container|null
     */
    final public function getContainer()
    {
        return $this->container;
    }

    /**
     * 构建子进程
     *
     * @return \Closure
     */
    final public function buildProcess()
    {
        return function (Process $process) {
            if (!is_null($this->processMaster->userId)) {
                posix_setuid($this->processMaster->userId);
            }

            if (!is_null($this->processMaster->groupId)) {
                posix_setgid($this->processMaster->groupId);
            }

            if ($this->async) {
                // 若开启异步，则需要设置事件处理方法返回一个回调函数
                swoole_event_add($process->pipe, $this->ioEvent());
            }
            
            $this->runProcess($process);
        };
    }

    /**
     * @return \Closure
     */
    protected function ioEvent()
    {
        return function () {
            //
        };
    }

    /**
     * 执行子进程
     *
     * @return int
     */
    final public function run()
    {
        $this->process = new Process($this->buildProcess());

        return $this->processId = $this->process->start();
    }

    /**
     * @return int
     */
    final public function getProcessId()
    {
        return $this->processId;
    }

    /**
     * @return Process
     */
    final public function getProcess()
    {
        return $this->process;
    }

    /**
     * 运行进程
     *
     * @param Process $process
     *
     * @return void
     */
    abstract public function runProcess(Process $process);

    /**
     * 向进程发送信号
     *
     * @param int $signal
     */
    final public function kill($signal = SIGTERM)
    {
        Process::kill($this->processId, $signal);
    }

    /**
     * 停止
     */
    final public function stop()
    {
        $this->kill();
    }
}