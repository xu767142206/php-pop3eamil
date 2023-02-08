<?php

include_once "PopException.php";

use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;
use Swoole\StringObject;

class Pop3
{
    protected Socket $socket;

    /** @var bool $connectStatus 连接状态 */
    protected bool $connectStatus = false;

    /** @var bool $loginStatus 登录状态 */
    protected bool $loginStatus = false;

    /**
     * @param string $host
     * @param int    $port
     * @param int    $timeOut
     */
    public function __construct(protected string $host, protected int $port, protected int $timeOut = 5)
    {
        $this->initSocket();
        $this->connect();

    }

    /**
     * 初始化
     *
     * @return void
     */
    protected function initSocket()
    {
        $socket = new Socket(AF_INET, SOCK_STREAM, 0);

        $socket->setProtocol([
                                 'open_ssl'       => true,
                                 'open_eof_check' => true,
                                 'package_eof'    => "\r\n",
                             ]);
        $this->socket = $socket;
    }

    /**
     * @return bool
     */
    public function isConnectStatus(): bool
    {
        return $this->connectStatus;
    }

    /**
     * @return bool
     */
    public function isLoginStatus(): bool
    {
        return $this->loginStatus;
    }


    /**
     * 关闭
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->connectStatus) {
            $this->socket->close();
            $this->connectStatus = false;
        }
        if ($this->loginStatus) {
            $this->loginStatus = false;
        }
    }

    /**
     * 退出
     *
     * @return void
     */
    public function logout(): void
    {
        if ($this->loginStatus) {
            [$ok, $err] = $this->command('QUIT');
            if ($ok) $this->loginStatus = false;
        }
    }


    /**
     * 链接
     *
     * @return void
     */
    protected function connect()
    {
        if (!$this->socket->connect($this->host, $this->port, $this->timeOut)) {
            throw new PopException("连接 {$this->host} 失败:" . $this->socket->errMsg, $this->socket->errCode);
        }

        $this->connectStatus = true;

        if (!$this->isResultSuccess($this->getRevData())) {
            $this->close();
            throw new PopException("服务器 {$this->host} 异常:" . $this->socket->errMsg, $this->socket->errCode);
        }

    }

    /**
     * 重新连接
     *
     * @return void
     */
    public function retryConnect(): void
    {
        $this->connect();

        $this->loginStatus = false;
    }

    /**
     * 登录
     *
     * @param string $email
     * @param string $password
     *
     * @return array [bool,err]
     */
    public function login(string $email, string $password): array
    {
        $this->checkConnectStatus();

        [$ok, $err] = $this->command("USER $email");
        if (!$ok) return [false, $err];

        [$ok, $err] = $this->command("PASS $password");
        if (!$ok) return [false, $err];

        $this->loginStatus = true;

        return [true, ""];
    }

    /**
     * 检查链接状态
     *
     * @return void
     */
    private function checkConnectStatus(): void
    {
        if (!$this->connectStatus) {
            throw new PopException("服务器 {$this->host} :" . "服务未连接");
        }
    }

    /**
     * 检查链接状态
     *
     * @return void
     */
    private function checkLoginStatus(): void
    {
        if (!$this->loginStatus) {
            throw new PopException("服务器 {$this->host} :" . "用户未登录");
        }
    }

    /**
     * 判断返回数据是否正常
     *
     * @param StringObject $resp
     *
     * @return bool
     */
    protected function isResultSuccess(StringObject $resp): bool
    {
        return $resp->substr(0, 3)->equals("+OK");
    }

    /**
     * 获取返回结果
     *
     * @return StringObject
     */
    protected function getRevData(): StringObject
    {
        $data = $this->socket->recvPacket($this->timeOut);

        //发生错误或对端关闭连接，本端也需要关闭
        if ($data === false) {
            //如果0的话 单纯是没错误 超时本应该也不能作为关闭的根据
            if (!in_array($this->socket->errCode, [0, 116])) {
                $this->close();
            }
            throw new PopException("接受信息失败:" . $this->socket->errMsg, $this->socket->errCode);
        }

        $resp = new StringObject($data);

        // 去掉\r\n
        return $resp->substr(0, -2);
    }


    /**
     * 执行命令
     *
     * @param string $command 发送给服务器的命令
     *
     * @return array [bool,resp]
     */
    public function command(string $command): array
    {
        $this->checkConnectStatus();

        if ($this->socket->send("$command\r\n", $this->timeOut) === false) {
            $this->close();
            throw new PopException("发送息失败:" . $this->socket->errMsg, $this->socket->errCode);
        }

        $resp = $this->getRevData();

        return [$this->isResultSuccess($resp), $resp->toString()];

    }

    /**
     * 获取邮件总数
     *
     * @return array [total,bytes,err]
     */
    public function getEmailTotal(): array
    {
        $this->checkLoginStatus();

        [$ok, $resp] = $this->command("STAT");

        if ($ok) {

            $strings = (new StringObject($resp))->split(" ");

            /** @var string $total */
            $total = $strings->offsetGet(1);
            /** @var string $bytes */
            $bytes = $strings->offsetGet(2);

            return [intval($total ?? 0), intval($bytes ?? 0), null];
        }

        return [0, 0, $resp];
    }

    /**
     * 获取邮件列表
     *
     * @param Channel $channel
     * @param int     $no
     *
     * @return string
     */
    public function getListMail(Channel $channel, int $no = 0): string
    {
        $this->checkLoginStatus();

        $commad = "UIDL";
        $no && $commad .= ' ' . $no;
        [$ok, $resp] = $this->command($commad);

        if ($ok) {
            for (; ;) {
                $res = $this->getRevData();
                if ($res->equals('.')) {
                    break;
                }
                $channel->push($res);
            }
        }
        $channel->close();
        return $resp;

    }

    /**
     * 返回指定邮件的大小等
     *
     * @param int $no
     *
     * @return int
     */
    public function getMailSize(int $no): int|false
    {
        $this->checkLoginStatus();

        [$ok, $resp] = $this->command("LIST $no");
        if ($ok) {
            $strings = (new StringObject($resp))->split(" ");
            $total = $strings->offsetGet(1);
            return intval($total ?? 0);
        }
        return false;
    }

    /**
     * 获取邮件正文
     *
     * @param int $no
     * @param int $line
     *
     * @return array [$mail, $ok, $resp]
     */
    public function getMailBody(int $no, int $line = -1): array
    {
        $this->checkLoginStatus();

        if ($line < 0)
            $command = "RETR $no";
        else
            $command = "TOP $no $line";

        [$ok, $resp] = $this->command($command);

        $mail = [];
        $str = 'head';
        if ($ok) {
            for (; ;) {
                $res = $this->getRevData();
                if ($res->equals('.')) {
                    break;
                }
                if ($res->equals("")) {
                    $str = 'body';
                }
                $mail[$str][] = $res->toString();
            }
        }
        return [$mail, $ok, $resp];
    }


    /**
     * 删除
     *
     * @param $num
     *
     * @return array 【bool,err】
     */
    public function delete($num): array
    {
        $this->checkLoginStatus();
        if (!$num) {
            return [false, "num 必须大于0"];
        }
        return $this->command("DELE $num");

    }


    /**
     * @return array [bool,resp]
     */
    public function noop(): array
    {
        $this->checkConnectStatus();

        return $this->command("NOOP");
    }

    /**
     * 简单解析下
     *
     * @param array $body
     *
     * @return array
     */
    public function parse(array $body): array
    {
        $heads = [];
        $last = "";
        foreach ($body['head'] as $key => $item) {
            $str = new StringObject($item);
            if (!$str->substr(0, 1)->equals("	")) {
                $arrayObject = $str->split(':');
                $heads[$arrayObject->offsetGet(0)] = trim($arrayObject->offsetGet(1));
                $last = $arrayObject->offsetGet(0);
                continue;
            }
            $heads[$last] .= $str->ltrim()->toString();
        }
        $func = static function (string $str) {

            $strObjet = new StringObject($str);
            if ($strObjet->contains("?utf-8?B?")) {
                return base64_decode($strObjet->substr(10)->replace('?=', "=")->toString()) ?? $str;
            }
            return $str;
        };

        $heads['Subject'] = $func($heads['Subject']);
        $arr = explode(" ", $heads['From']);
        $heads['From'] = $func($arr[0]) . " " . $arr[1];

        $body['head'] = $heads;
        $body['body'] = base64_decode(implode("", $body['body']));

        return $body;
    }
}