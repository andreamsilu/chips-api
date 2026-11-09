<?php

namespace App\Support;

trait ProtectsSnapshots
{
    protected static function bootProtectsSnapshots(): void
    {
        static::updating(function (self $model): void {
            foreach ($model->snapshotFields() as $field) {
                if ($model->isDirty($field)) {
                    throw new \LogicException('Financial and ownership snapshots cannot be changed.');
                }
            }
            if ($model->getOriginal('first_attempt_at') !== null && ($model->isDirty('first_attempt_at') || $model->isDirty('request_payload'))) {
                throw new \LogicException('A submitted provider operation cannot be rewritten.');
            }
        });
        static::deleting(function (): never {
            throw new \LogicException('Financial records must be retained.');
        });
    }

    abstract protected function snapshotFields(): array;
}
