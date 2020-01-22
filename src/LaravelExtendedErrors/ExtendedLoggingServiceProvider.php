<?php

namespace LaravelExtendedErrors;

use Illuminate\Foundation\Application;
use Illuminate\Log\ParsesLogConfiguration;
use Illuminate\Mail\TransportManager;
use Illuminate\Support\ServiceProvider;
use LaravelExtendedErrors\Formatter\EmailFormatter;
use LaravelExtendedErrors\Handler\TelegramHandler;
use LaravelExtendedErrors\MailTransport\ExtendedLogTransport;
use Monolog\Handler\NativeMailerHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

class ExtendedLoggingServiceProvider extends ServiceProvider {

    use ParsesLogConfiguration;

    /**
     * Get fallback log channel name.
     *
     * @return string
     */
    protected function getFallbackChannelName() {
        return 'production';
    }

    public function boot() {
        $this->replaceMailLogTransport();
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register() {
        /** @var ExtendedLogManager $logManager */
        $logManager = $this->replaceLogManager();

        /*
            Channel Config:
            'telegram' => [
                'driver' => 'telegram',
                'token' => env('LOG_TELEGRAM_API_KEY'),
                'chat_id' => env('LOG_TELEGRAM_CHAT_ID'),
                'level' => 'debug',
                'bubble' => false',
            ],
        */
        $this->registerTelegramChannelDriver($logManager);

        /*
            Channel Config:
            'email' => [
                'driver' => 'email',
                'sender' => env('LOG_EMAIL_SENDER'),
                'subject' => env('LOG_EMAIL_SUBJECT'),
                'receiver' => preg_split('%\s*,\s*%', trim(env('LOG_EMAIL_RECEIVER'))),
                'level' => 'debug',
            ],
            Note: 'receiver' can be a string (single email) or array (several emails)
        */
        $this->registerEmailChannelDriver($logManager);
    }

    /**
     * @return ExtendedLogManager
     */
    protected function replaceLogManager() {
        $this->app->singleton('log', function () {
            return new ExtendedLogManager($this->app);
        });
        return $this->app['log'];
    }

    /**
     * @param ExtendedLogManager $logManager
     */
    protected function registerTelegramChannelDriver($logManager) {
        $logManager->extend('telegram', function ($app, array $config) {
            /** @var ExtendedLogManager $this */
            $handler = new TelegramHandler(
                $this->level($config),
                array_get($config, 'token'),
                array_get($config, 'chat_id'),
                array_get($config, 'bubble', false),
                array_get($config, 'proxy')
            );
            $handler->setFormatter(new EmailFormatter());
            return new Logger($this->parseChannel($config), [$handler]);
        });
    }

    /**
     * @param ExtendedLogManager $logManager
     */
    protected function registerEmailChannelDriver($logManager) {
        $logManager->extend('email', function ($app, array $config) {
            /** @var ExtendedLogManager $this */
            $senderEmail = array_get($config, 'sender');
            $ip = (empty($_SERVER['SERVER_ADDR']) ? 'undefined ip' : $_SERVER['HTTP_HOST']);
            $host = (empty($_SERVER['HTTP_HOST']) ? 'unknown.host' : $_SERVER['HTTP_HOST']);
            if (empty($senderEmail)) {
                /** @noinspection HostnameSubstitutionInspection */
                $senderEmail = 'errors@' . $host;
            }
            $handler = new NativeMailerHandler(
                $config['receiver'],
                array_get($config, 'subject', "Log from $host ($ip)"),
                $senderEmail,
                $this->level($config)
            );
            $handler
                ->setContentType('text/html')
                ->setFormatter(new EmailFormatter());
            return new Logger($this->parseChannel($config), [$handler]);
        });
    }

    protected function replaceMailLogTransport() {
        /** @var TransportManager $swiftTransport */
        $swiftTransport = $this->app->make('swift.transport');
        $swiftTransport->extend('log', function ($app) {
            /** @var Application $app */
            return new ExtendedLogTransport($app->make(LoggerInterface::class));
        });
    }

}