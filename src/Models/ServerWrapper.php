<?php

namespace Azuriom\Plugin\Vote\Models;

use Azuriom\Models\Server;
use Illuminate\Support\Facades\Crypt;

class ServerWrapper
{
    private static array $publicIds = [];

    private Server $server;

    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    public function getServer(): Server
    {
        return $this->server;
    }

    public function getPublicId(): string
    {
        return self::$publicIds[$this->getServer()->id] ?? self::$publicIds[$this->getServer()->id] = Crypt::encryptString($this->getServer()->id);
    }

}
