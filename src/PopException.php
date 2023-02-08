<?php

/**
 *
 * 异常
 * Class    PopException
 *
 * @package Xycdd
 * auther   MyPC
 */
class PopException extends \RuntimeException
{
    protected int    $errCode;
    protected string $errMessage;

    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        $this->errCode = $code;
        $this->errMessage = $message;

        parent::__construct(sprintf("pop3发生错误:错误码: [%d] 错误信息: [%s] !", $code, $message), 0, $previous);
    }


}