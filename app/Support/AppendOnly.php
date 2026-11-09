<?php

namespace App\Support;

trait AppendOnly
{
    protected static function bootAppendOnly(): void
    {
        static::updating(function (): never {
            throw new \LogicException('This record is append-only.');
        });
        static::deleting(function (): never {
            throw new \LogicException('This record is append-only.');
        });
    }
}
