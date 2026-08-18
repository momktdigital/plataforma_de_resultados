<?php

namespace App\Services\Backup;

interface DatabaseDumperContract
{
    public function dumpToFile(string $destino): void;
}
