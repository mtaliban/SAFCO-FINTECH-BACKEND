<?php

namespace App\Services\EventBus;

use App\Events\BaseEvent;

interface EventPublisher
{
    public function publish(BaseEvent $event): bool;
}
