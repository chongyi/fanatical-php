# Keeper

[![Build Status](https://travis-ci.org/chongyi/keeper.svg?branch=master)](https://travis-ci.org/chongyi/keeper)
[![Latest Stable Version](https://poser.pugx.org/chongyi/keeper/v/stable)](https://packagist.org/packages/chongyi/keeper)
[![License](https://poser.pugx.org/chongyi/keeper/license)](https://packagist.org/packages/chongyi/keeper)

基于 Swoole 的后台多进程程序脚手架，提供了基本的控制功能。在此基础你将有更多可能使用 PHP 完成一些在 FPM 环境下无法实现的功能。

## 环境要求

* PHP >= 5.6
* Swoole >= 1.8.2

## 使用方法

### 一个简单的 HTTP 服务

1. 先定义一个用作实现 HTTP 服务子进程

```php
<?php
use Dybasedev\Keeper\Process\Process;
use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

class Http extends Process
{
    public function process()
    {
        $server = new Server('0.0.0.0', 19730);
        $server->on('request', $this->onRequest());
        $server->start();
    }
    
    public function onRequest()
    {
        return function (Request $request, Response $response) {
            if ($request->server['request_uri'] === '/favicon.ico') {
                $response->status(404);
                $response->end('Not found.');
                return;
            }
            
            $response->end('<html><head><title>Demo</title></head><body><h1>Hello, world</h1></body></html>');
        };
    }
}
```

2. 创建主进程

```php
<?php
use Dybasedev\Keeper\Process\ProcessManager;

class Master extends ProcessManager
{
    protected function onPreparing() 
    {
        // 注册子进程
        $this->registerChildProcess(new Http(['auto_reload' => false]));
    }
    
}
```

3. 启动/重启/停止

```php
<?php
// 确保引入了 autoload.php
// require 'vendor/autoload.php'

$master = (new Master())->setProcessIdFile('./pid')->setDaemon(true);

// 启动
$master->run();

// 重启
$master->restart();

// 停止
$master->stop();
```

## License

MIT License