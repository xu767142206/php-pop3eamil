<?php

ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');
ini_set('memory_limit', '1G');
error_reporting(E_ALL);

require './src/Pop3.php';

use function Swoole\Coroutine\run;

run(function () {
    $pop3 = new Pop3('pop.qq.com', 995, 5);
    [$ok] = $pop3->login("1368332201@qq.com", "xxxxxxxxxxxxxxxxxxx");
    if ($ok) {
        [$total] = $pop3->getEmailTotal();
        [$body] = $pop3->getMailBody($total);


        var_dump($pop3->parse($body));
    }

});