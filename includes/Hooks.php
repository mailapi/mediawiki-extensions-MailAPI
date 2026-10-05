<?php

namespace MediaWiki\Extension\MailAPI;

use MailAddress;
use MediaWiki\Hook\AlternateUserMailerHook;
use MediaWiki\Hook\UserMailerTransformMessageHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Throwable;

class Hooks implements AlternateUserMailerHook, UserMailerTransformMessageHook
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
     * Dispatch through the abortable transform hook, whose error argument is
     * converted to a fatal Status by UserMailer. Hook returns remain boolean.
     */
    public function onUserMailerTransformMessage($to, $from, &$subject, &$headers, &$body, &$error)
    {
        [$endpoint, $token, $waitTimeout] = $this->settings();
        if ($endpoint === '') {
            return true;
        }
        if ($token === '') {
            $this->log('warning', 'MailAPI endpoint is configured without a bearer token.');
        }
        try {
            $client = new Client($endpoint, null, $token, $waitTimeout);
            $payload = $client->buildPayload($headers, $to, $from, $subject, $body);
            $response = $client->send($payload);
            $this->log(
                'info',
                'MailAPI accepted email for processing. Message ID: {message_id}',
                ['message_id' => $response['id']]
            );
            return true;
        } catch (Throwable $e) {
            $error = $e instanceof OutcomeUnknownException
                ? 'Mail submission outcome is unknown. The message may still be sent; do not immediately resend.'
                : 'Mail API submission failed. Please contact the wiki administrator.';
            $this->log('error', 'MailAPI submission failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
            return false;
        }
    }

    /** @return bool Skip SMTP only when Mail API is configured. */
    public function onAlternateUserMailer($headers, $to, $from, $subject, $body)
    {
        [$endpoint] = $this->settings();
        return $endpoint === '';
    }

    private function settings(): array
    {
        global $wgMailAPIEndpoint, $wgMailAPIToken, $wgMailAPIWaitTimeout;
        $endpoint = (string)($wgMailAPIEndpoint ?? '');
        $token = (string)($wgMailAPIToken ?? '');
        $waitTimeout = (int)($wgMailAPIWaitTimeout ?? 0);
        if (class_exists(MediaWikiServices::class)) {
            $config = MediaWikiServices::getInstance()->getMainConfig();
            if ($endpoint === '' && $config->has('MailAPIEndpoint')) {
                $endpoint = (string)$config->get('MailAPIEndpoint');
            }
            if ($token === '' && $config->has('MailAPIToken')) {
                $token = (string)$config->get('MailAPIToken');
            }
            if (!isset($wgMailAPIWaitTimeout) && $config->has('MailAPIWaitTimeout')) {
                $waitTimeout = (int)$config->get('MailAPIWaitTimeout');
            }
        }
        if ($waitTimeout < 0 || $waitTimeout > 20) {
            $this->log('warning', 'Invalid MailAPIWaitTimeout {value}; waiting is disabled.',
                ['value' => $waitTimeout]);
            $waitTimeout = 0;
        }
        return [$endpoint, $token, $waitTimeout];
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
