<?php

namespace NotificationChannels\Discord\Commands;

use Exception;
use WebSocket\Client;
use Illuminate\Support\Arr;
use Illuminate\Console\Command;
use GuzzleHttp\Client as HttpClient;

class SetupCommand extends Command
{
    protected $signature = 'discord:setup';

    protected $description = "Add the bot to your server(s) and identify it with Discord's gateway.";

    /**
     * @var \GuzzleHttp\Client
     */
    protected $guzzle;

    /**
     * @var string
     */
    protected $token = null;

    /**
     * @var string
     */
    protected $gateway = 'wss://gateway.discord.gg';

    public function __construct(HttpClient $guzzle, $token = null)
    {
        parent::__construct();

        $this->guzzle = $guzzle;
        $this->token = $token;
    }

    public function handle()
    {
        if (! $this->token) {
            $this->error('You must paste your Discord token (App Bot User token) into your `services.php` config file.');
            $this->error('View the README for more info: https://github.com/laravel-notification-channels/discord#installation');

            return -1;
        }

        if (! $this->confirm('Is the bot already added to your server?')) {
            $clientId = $this->ask('What is your Discord app client ID?');

            $this->warn('Add the bot to your server by visiting this link: https://discordapp.com/oauth2/authorize?&client_id='.$clientId.'&scope=bot&permissions=0');

            if (! $this->confirm('Continue?', true)) {
                return -1;
            }
        }

        $this->warn("Attempting to identify the bot with Discord's websocket gateway...");

        $this->gateway = $this->getGateway();

        $this->warn("Connecting to '$this->gateway'...");

        $client = $this->getSocket($this->gateway);

        // Discord requires all bots to connect via a websocket connection and
        // identify at least once before any HTTP API requests are allowed.
        // https://discordapp.com/developers/docs/topics/gateway#gateway-identify
        $client->send(json_encode([
            'op' => 2,
            'd' => [
                'token' => $this->token,
                'v' => 3,
                'compress' => false,
                'properties' => [
                    '$os' => PHP_OS,
                    '$browser' => 'laravel-notification-channels-discord',
                    '$device' => 'laravel-notification-channels-discord',
                    '$referrer' => '',
                    '$referring_domain' => '',
                ],
            ],
        ]));

        $response = $client->receive();
        $identified = Arr::get(json_decode($response, true), 't') === 'READY';

        if (! $identified) {
            $this->error("Discord responded with an error while trying to identify the bot: $response");

            return -1;
        }

        $this->info('Your bot has been identified by Discord and can now send API requests!');
    }

    public function getSocket($gateway)
    {
        return new Client($gateway);
    }

    public function getGateway()
    {
        $gateway = $this->gateway;

        try {
            $response = $this->guzzle->get('https://discordapp.com/api/gateway', [
                'headers' => [
                    'Authorization' => 'Bot '.$this->token,
                ],
            ]);

            $gateway = Arr::get(json_decode($response->getBody(), true), 'url', $gateway);
        } catch (Exception $e) {
            $this->warn("Could not get a websocket gateway address, defaulting to '{$gateway}'.");
        }

        return $gateway;
    }
}
