<?php

namespace App\Providers;

use App\Services\EventBus\EventDispatcher;
use App\Services\EventBus\MqttPublisher;
use App\Services\EventBus\RabbitMqPublisher;
use Illuminate\Support\ServiceProvider;

class EventBusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RabbitMqPublisher::class, function ($app) {
            return new RabbitMqPublisher(config('rabbitmq'));
        });

        $this->app->singleton(MqttPublisher::class, function ($app) {
            return new MqttPublisher(config('mqtt'));
        });

        $this->app->singleton(EventDispatcher::class, function ($app) {
            return new EventDispatcher(
                $app->make(RabbitMqPublisher::class),
                $app->make(MqttPublisher::class),
            );
        });
    }
}
