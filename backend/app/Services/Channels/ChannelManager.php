<?php

namespace App\Services\Channels;

use App\Contracts\ChannelProviderInterface;
use InvalidArgumentException;

class ChannelManager
{
    protected array $drivers = [];

    public function __construct()
    {
        $this->registerDriver('whatsapp', new \App\Services\Channels\Drivers\WhatsAppCloudApiDriver());
        $this->registerDriver('whatsapp_baileys', new \App\Services\Channels\Drivers\WhatsAppBaileysDriver());
        $this->registerDriver('instagram', new \App\Services\Channels\Drivers\InstagramDriver());
        $this->registerDriver('messenger', new \App\Services\Channels\Drivers\FacebookMessengerDriver());
        $this->registerDriver('telegram', new \App\Services\Channels\Drivers\TelegramDriver());
        $this->registerDriver('sms', new \App\Services\Channels\Drivers\SmsDriver());
        $this->registerDriver('email', new \App\Services\Channels\Drivers\EmailDriver());
    }

    /**
     * Register a custom channel driver.
     */
    public function registerDriver(string $name, ChannelProviderInterface $driver): void
    {
        $this->drivers[$name] = $driver;
    }

    /**
     * Resolve channel driver.
     */
    public function driver(string $name): ChannelProviderInterface
    {
        if (!isset($this->drivers[$name])) {
            throw new InvalidArgumentException("Channel driver [{$name}] is not registered.");
        }

        $configKeyMap = [
            'whatsapp' => 'whatsapp_cloud',
            'whatsapp_baileys' => 'whatsapp_baileys',
            'instagram' => 'instagram',
            'messenger' => 'messenger',
            'telegram' => 'telegram',
            'sms' => 'sms',
            'email' => 'email',
        ];

        $configKey = $configKeyMap[$name] ?? $name;

        $settings = \App\Models\PlatformSetting::where('key', 'channels_enabled')->first();
        $isTesting = app()->environment('testing');
        $channelsEnabled = $settings ? $settings->value : [
            'whatsapp_cloud' => true,
            'whatsapp_baileys' => true,
            'instagram' => $isTesting,
            'messenger' => $isTesting,
            'telegram' => true,
            'sms' => true,
            'email' => true,
        ];

        if (!($channelsEnabled[$configKey] ?? true)) {
            throw new InvalidArgumentException("Channel [{$name}] is globally disabled by platform administrator.");
        }

        return $this->drivers[$name];
    }
}
