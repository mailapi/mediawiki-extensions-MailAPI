<?php

namespace MediaWiki\Extension\MailAPI;

use MailAddress;
use MediaWiki\Hook\AlternateUserMailerHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Throwable;

class Hooks implements AlternateUserMailerHook
{
    /** @var object|null */
    private $logger;

    /**
     * @param object|null $logger
     */
    public function __construct($logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Send MediaWiki mail through Mail API.
     *
     * @param array|string $headers
     * @param MailAddress[]|MailAddress $to
     * @param MailAddress $from
     * @param string $subject
     * @param string|array $body
     * @return bool|string False on acceptance; true when not configured; an error
     *   string on failure, preventing an unsafe second submission via SMTP.
     */
    public function onAlternateUserMailer($headers, $to, $from, $subject, $body)
    {
        global $wgMailAPIEndpoint, $wgMailAPIToken;
        $token = (string)($wgMailAPIToken ?? '');

        $endpoint = (string)$wgMailAPIEndpoint;
        if (class_exists(MediaWikiServices::class)) {
            $config = MediaWikiServices::getInstance()->getMainConfig();
            if ($token === '' && $config->has('MailAPIToken')) { $token = (string)$config->get('MailAPIToken'); }
            if ($endpoint === '' && $config->has('MailAPIEndpoint')) {
                $endpoint = (string)$config->get('MailAPIEndpoint');
            }
        }

        if ($endpoint === '') {
            $this->log(
                'error',
                'MailAPI endpoint is not configured; falling back to the default mailer.'
            );
            return true;
        }

        try {
            $client = new Client($endpoint, null, $token);
            $payload = $client->buildPayload($headers, $to, $from, $subject, $body);
            $response = $client->send($payload);
            $this->log(
                'info',
                'MailAPI accepted email for processing. Message ID: {message_id}',
                ['message_id' => $response['id'] ?? '(missing)']
            );

            return false;
        } catch (Throwable $e) {
            $this->log(
                'error',
                'MailAPI submission failed: {error}',
                [
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]
            );
            return 'Mail API submission failed: ' . $e->getMessage();
        }
    }

    /**
     * Log a MailAPI event when MediaWiki's logger is available.
     *
     * The standalone test suite does not bootstrap MediaWiki's logging services.
     *
     * @param string $level
     * @param string $message
     * @param array $context
     */
    private function log($level, $message, array $context = []): void
    {
        if ($this->logger === null && class_exists(LoggerFactory::class)) {
            $this->logger = LoggerFactory::getInstance('mailapi');
        }

        if ($this->logger !== null) {
            $this->logger->$level($message, $context);
        }
    }
}
