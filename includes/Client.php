<?php

namespace MediaWiki\Extension\MailAPI;

use MediaWiki\MediaWikiServices;
use MWException;

class Client
{
    private const IN_PROGRESS_TYPE = 'https://mailapi.github.io/problems/idempotency-key-in-progress';

    /** Seconds to wait for acceptance when no Prefer wait is configured. */
    private const HANDOFF_TIMEOUT = 5;

    /** Extra seconds allowed for retrying certain rejections. */
    private const RETRY_BUDGET = 5;

    /** @var string */
    private $endpoint;

    /** @var mixed|null */
    private $httpRequestFactory;

    /** @var string */
    private $token;

    private $waitTimeout;
    private $lastStatus = 0;
    private $retryAfter = null;
    private $lastProblemType = '';
    private $lastConnectFailed = false;

    /**
     * @param string $endpoint
     * @param mixed|null $httpRequestFactory
     * @throws MWException
     */
    public function __construct(string $endpoint, $httpRequestFactory = null, string $token = '', int $waitTimeout = 0)
    {
        $this->endpoint = self::normalizeEndpoint($endpoint);
        $this->httpRequestFactory = $httpRequestFactory;
        if ($token !== '' && !preg_match('/^[\x21-\x7E]+$/D', $token)) {
            throw new MWException('Mail API token must contain only visible ASCII characters.');
        }
        if ($waitTimeout < 0 || $waitTimeout > 20) {
            throw new MWException('Mail API wait timeout must be between 0 and 20 seconds.');
        }
        $this->token = $token;
        $this->waitTimeout = $waitTimeout;
    }

    /**
     * Normalize the endpoint URL to ensure it targets the Mail API /v1/messages route.
     *
     * @param string $endpoint
     * @return string
     * @throws MWException
     */
    public static function normalizeEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            throw new MWException('Please set $wgMailAPIEndpoint in LocalSettings.php.');
        }

        $endpoint = rtrim($endpoint, '/');
        if (!preg_match('#/v1/messages$#', $endpoint)) {
            $endpoint .= '/v1/messages';
        }

        return $endpoint;
    }

    /**
     * Decode RFC 2047 MIME encoded-words (e.g. =?UTF-8?B?...?= or =?UTF-8?Q?...?=).
     *
     * @param string $value
     * @return string
     */
    public static function decodeMimeHeader(string $value): string
    {
        if (strpos($value, '=?') === false) {
            return $value;
        }

        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decoded !== false && strpos($decoded, '=?') === false) {
                return $decoded;
            }
        }

        if (function_exists('mb_decode_mimeheader')) {
            $origEnc = mb_internal_encoding();
            mb_internal_encoding('UTF-8');
            $decoded = mb_decode_mimeheader($value);
            mb_internal_encoding($origEnc);
            return $decoded;
        }

        // Fallback regex decoder
        return (string)preg_replace_callback('/=\?([^?]+)\?([BQbq])\?([^?]+)\?=/i', function ($matches) {
            $charset = strtoupper($matches[1]);
            $encoding = strtoupper($matches[2]);
            $text = $matches[3];

            if ($encoding === 'B') {
                $decoded = base64_decode($text, true);
            } else {
                $decoded = quoted_printable_decode(str_replace('_', ' ', $text));
            }

            if ($decoded === false) {
                return $matches[0];
            }

            if ($charset !== 'UTF-8' && function_exists('mb_convert_encoding')) {
                return @mb_convert_encoding($decoded, 'UTF-8', $charset);
            }

            return $decoded;
        }, $value);
    }

    /**
     * Parse and validate a single email address into Mail API EmailAddress schema.
     *
     * @param mixed $address
     * @param string $role
     * @return array
     * @throws MWException
     */
    public static function parseAddress($address, string $role = 'address'): array
    {
        $email = '';
        $name = '';

        if (is_object($address)) {
            if (isset($address->address)) {
                $email = (string)$address->address;
            } elseif (method_exists($address, 'getEmail')) {
                $email = (string)$address->getEmail();
            }

            if (isset($address->name) && (string)$address->name !== '') {
                $name = (string)$address->name;
            } elseif (method_exists($address, 'getRealName') && (string)$address->getRealName() !== '') {
                $name = (string)$address->getRealName();
            } elseif (method_exists($address, 'getName') && (string)$address->getName() !== '') {
                $name = (string)$address->getName();
            }
        } elseif (is_array($address)) {
            $email = $address['email'] ?? $address['address'] ?? '';
            $name = $address['name'] ?? '';
        } elseif (is_string($address)) {
            $trimmed = trim($address);
            if (preg_match('/^(.*?)\s*<([^>]+)>$/u', $trimmed, $matches)) {
                $name = trim($matches[1], " \t\n\r\0\x0B\"'");
                $email = trim($matches[2]);
            } else {
                $email = $trimmed;
            }
        }

        $email = trim((string)$email);
        $name = trim((string)$name);

        if ($name !== '') {
            $name = self::decodeMimeHeader($name);
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MWException("Invalid {$role} email address: " . ($email !== '' ? $email : '(empty)'));
        }

        $result = ['email' => $email];
        if ($name !== '') {
            $result['name'] = $name;
        }

        return $result;
    }

    /**
     * Parse a list of email addresses.
     *
     * @param mixed $input
     * @param string $role
     * @return array
     * @throws MWException
     */
    public static function parseAddressList($input, string $role = 'recipient'): array
    {
        if ($input === null || $input === '') {
            return [];
        }

        if (is_object($input)) {
            return [self::parseAddress($input, $role)];
        }

        if (is_array($input)) {
            if (isset($input['email']) || isset($input['address'])) {
                return [self::parseAddress($input, $role)];
            }
            $list = [];
            foreach ($input as $item) {
                $list[] = self::parseAddress($item, $role);
            }
            return $list;
        }

        if (is_string($input)) {
            $list = [];
            // Split comma-separated addresses while respecting quoted strings and angled brackets
            $addresses = preg_split('/,(?=(?:[^\"]*\"[^\"]*\")*[^\"]*$)(?=(?:[^<]*<[^>]*>)*[^>]*$)/', $input);
            if (is_array($addresses)) {
                foreach ($addresses as $addr) {
                    $addr = trim($addr);
                    if ($addr !== '') {
                        $list[] = self::parseAddress($addr, $role);
                    }
                }
            }
            return $list;
        }

        return [self::parseAddress($input, $role)];
    }

    /**
     * Extract text and/or HTML body parts from raw body or structured array.
     * Handles MIME multipart/alternative structures created by MediaWiki.
     *
     * @param mixed $body
     * @param array $rawHeaders Array of [headerName, headerValue]
     * @return array ['text' => ?string, 'html' => ?string]
     */
    public static function parseBodyParts($body, array $rawHeaders = []): array
    {
        $text = null;
        $html = null;

        if (is_array($body)) {
            if (isset($body['text'])) {
                $text = (string)$body['text'];
            }
            if (isset($body['html'])) {
                $html = (string)$body['html'];
            }
            if ($text === null && $html === null) {
                $text = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            return ['text' => $text, 'html' => $html];
        }

        $bodyStr = (string)$body;

        // Detect boundary and Content-Type from headers
        $boundary = null;
        $contentTypeHeader = '';
        foreach ($rawHeaders as [$hName, $hValue]) {
            if (strtolower($hName) === 'content-type') {
                $contentTypeHeader = $hValue;
                if (preg_match('/boundary\s*=\s*(?:"([^"]+)"|([^\s;]+))/i', $hValue, $m)) {
                    $boundary = $m[1] !== '' ? $m[1] : $m[2];
                }
                break;
            }
        }

        // If no boundary in header, check if body itself starts with MIME boundary
        if ($boundary === null) {
            if (preg_match('/^--([^\r\n]+)/m', $bodyStr, $m)) {
                $candidate = trim($m[1]);
                if (substr($candidate, -2) === '--') {
                    $candidate = substr($candidate, 0, -2);
                }
                if ($candidate !== '') {
                    $boundary = $candidate;
                }
            } elseif (preg_match('/boundary\s*=\s*(?:"([^"]+)"|([^\s;]+))/i', $bodyStr, $m)) {
                $boundary = $m[1] !== '' ? $m[1] : $m[2];
            }
        }

        if ($boundary !== null && strpos($bodyStr, '--' . $boundary) !== false) {
            $parts = explode('--' . $boundary, $bodyStr);
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '' || $part === '--') {
                    continue;
                }

                // Split part headers and part body
                $headerAndBody = preg_split("/\r?\n\r?\n/", $part, 2);
                if (count($headerAndBody) < 2) {
                    continue;
                }

                $partHeadersRaw = $headerAndBody[0];
                $partBody = $headerAndBody[1];

                $partContentType = 'text/plain';
                $partEncoding = '';

                if (preg_match('/content-type:\s*([^\s;]+)/i', $partHeadersRaw, $m)) {
                    $partContentType = strtolower(trim($m[1]));
                }
                if (preg_match('/content-transfer-encoding:\s*([^\s;]+)/i', $partHeadersRaw, $m)) {
                    $partEncoding = strtolower(trim($m[1]));
                }

                // Decode transfer encoding
                if ($partEncoding === 'quoted-printable') {
                    $partBody = quoted_printable_decode($partBody);
                } elseif ($partEncoding === 'base64') {
                    $partBody = (string)base64_decode($partBody);
                }

                $partBody = rtrim($partBody, "\r\n");

                if ($partContentType === 'text/plain') {
                    $text = $partBody;
                } elseif ($partContentType === 'text/html') {
                    $html = $partBody;
                }
            }
        } elseif (stripos($contentTypeHeader, 'text/html') !== false) {
            $html = $bodyStr;
        } else {
            $text = $bodyStr;
        }

        return ['text' => $text, 'html' => $html];
    }

    /**
     * Build OutboundMessageRequest payload complying with Mail API OpenAPI specification.
     *
     * @param mixed $headers
     * @param mixed $to
     * @param mixed $from
     * @param string $subject
     * @param mixed $body
     * @return array
     * @throws MWException
     */
    public function buildPayload($headers, $to, $from, $subject, $body): array
    {
        $fromAddress = self::parseAddress($from, 'sender');
        $toAddresses = self::parseAddressList($to, 'recipient');

        if (empty($toAddresses)) {
            throw new MWException('No recipient address resolved from MediaWiki mail payload.');
        }

        $decodedSubject = self::decodeMimeHeader((string)$subject);

        $payload = [
            'from' => $fromAddress,
            'to' => $toAddresses,
            'subject' => $decodedSubject,
        ];

        // Process headers into raw [name, value] pairs
        $rawHeaders = [];
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (is_int($k) && is_string($v)) {
                    $parts = explode(':', $v, 2);
                    if (count($parts) === 2) {
                        $rawHeaders[] = [trim($parts[0]), trim($parts[1])];
                    }
                } elseif (is_string($k)) {
                    if (is_array($v)) {
                        foreach ($v as $subv) {
                            $rawHeaders[] = [trim($k), trim((string)$subv)];
                        }
                    } else {
                        $rawHeaders[] = [trim($k), trim((string)$v)];
                    }
                }
            }
        } elseif (is_string($headers) && trim($headers) !== '') {
            $lines = preg_split("/\r\n|\n|\r/", trim($headers));
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $rawHeaders[] = [trim($parts[0]), trim($parts[1])];
                    }
                }
            }
        }

        // Handle body content (including multipart/alternative MIME parsing)
        $bodyParts = self::parseBodyParts($body, $rawHeaders);
        if ($bodyParts['text'] !== null) {
            $payload['text'] = $bodyParts['text'];
        }
        if ($bodyParts['html'] !== null) {
            $payload['html'] = $bodyParts['html'];
        }
        if (!isset($payload['text']) && !isset($payload['html'])) {
            $payload['text'] = '';
        }

        // Process supplemental headers (excluding standard fields & MIME transport headers)
        $supplementalHeaders = [];
        $excludedHeaderNames = [
            'from' => true,
            'to' => true,
            'subject' => true,
            'reply-to' => true,
            'cc' => true,
            'bcc' => true,
            'content-type' => true,
            'content-transfer-encoding' => true,
            'mime-version' => true,
        ];

        foreach ($rawHeaders as [$headerName, $headerValue]) {
            $lower = strtolower($headerName);
            if (isset($excludedHeaderNames[$lower])) {
                if ($lower === 'reply-to') {
                    $replyTo = self::parseAddressList($headerValue, 'replyTo');
                    if (!empty($replyTo)) {
                        $payload['replyTo'] = array_merge($payload['replyTo'] ?? [], $replyTo);
                    }
                } elseif ($lower === 'cc') {
                    $cc = self::parseAddressList($headerValue, 'cc');
                    if (!empty($cc)) {
                        $payload['cc'] = array_merge($payload['cc'] ?? [], $cc);
                    }
                } elseif ($lower === 'bcc') {
                    $bcc = self::parseAddressList($headerValue, 'bcc');
                    if (!empty($bcc)) {
                        $payload['bcc'] = array_merge($payload['bcc'] ?? [], $bcc);
                    }
                }
                continue;
            }

            $supplementalHeaders[] = [
                'name' => $headerName,
                'value' => self::decodeMimeHeader($headerValue),
            ];
        }

        if (!empty($supplementalHeaders)) {
            $payload['headers'] = $supplementalHeaders;
        }

        return $payload;
    }

    /**
     * Validate and decode the Mail API HTTP 200 or 202 response according to OpenAPI schema.
     *
     * @param int $statusCode
     * @param string $responseBody
     * @return array
     * @throws MWException
     */
    private function parseSuccessResponse(int $statusCode, string $responseBody): array
    {
        $this->lastStatus = $statusCode;
        if ($statusCode !== 200 && $statusCode !== 202) {
            $this->handleErrorResponse($statusCode, $responseBody);
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded) || !isset($decoded['id']) || !is_string($decoded['id']) || trim($decoded['id']) === '') {
            throw new MWException(
                "Mail API response malformed (HTTP {$statusCode}): expected JSON object with non-empty string 'id'. Response: " .
                substr($responseBody, 0, 200)
            );
        }

        return $decoded;
    }

    /**
     * Send OutboundMessageRequest to Mail API endpoint.
     *
     * @param array $payload
     * @param string|null $idempotencyKey Stable key for retries of the same logical message
     * @return array
     * @throws MWException
     */
    public function send(array $payload, ?string $idempotencyKey = null): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new MWException('Failed to encode message payload as JSON: ' . json_last_error_msg());
        }

        $key = $idempotencyKey ?? bin2hex(random_bytes(16));
        if (strlen($key) < 1 || strlen($key) > 256 || !preg_match('/^[\x21-\x7E]+$/D', $key)) {
            throw new MWException('Invalid Mail API Idempotency-Key.');
        }
        $headers = ['Idempotency-Key' => $key];
        if ($this->token !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }
        if ($this->waitTimeout > 0) {
            $headers['Prefer'] = 'wait=' . $this->waitTimeout;
        }
        // Hand off quickly, like an MTA accepting a message. Only rejections that
        // certainly did not start the submission are retried, briefly.
        $attemptTimeout = $this->waitTimeout > 0 ? $this->waitTimeout + 2 : self::HANDOFF_TIMEOUT;
        $deadline = microtime(true) + $attemptTimeout + self::RETRY_BUDGET;
        for ($attempt = 0; ; $attempt++) {
            $this->lastStatus = 0;
            $this->retryAfter = null;
            $this->lastProblemType = '';
            $this->lastConnectFailed = false;
            $timeout = min($attemptTimeout, $deadline - microtime(true));
            try {
                return $this->sendOnce($json, $headers, $timeout);
            } catch (MWException $e) {
                $retryable = in_array($this->lastStatus, [429, 503], true) ||
                    ($this->lastStatus === 0 && $this->lastConnectFailed);
                $delay = $this->retryDelay();
                if (!$retryable || $attempt >= 2 || $delay === null ||
                    microtime(true) + $delay + 1 >= $deadline) {
                    // Each retry was certainly rejected, so only the last attempt decides.
                    if ($this->isInProgress() || ($this->lastStatus === 0 && !$this->lastConnectFailed)) {
                        throw new OutcomeUnknownException(
                            'Mail API submission outcome is unknown. The message may still be sent; do not immediately resubmit.',
                            0,
                            $e
                        );
                    }
                    throw $e;
                }
                usleep((int)($delay * 1000000));
            }
        }
    }

    private function isInProgress(): bool
    {
        return $this->lastStatus === 409 && $this->lastProblemType === self::IN_PROGRESS_TYPE;
    }

    /**
     * Recognize failures where no connection was established, so the request
     * cannot have reached the provider. Unrecognized errors stay ambiguous.
     */
    private static function isConnectFailure(string $error): bool
    {
        return (bool)preg_match(
            '/Could not resolve (host|proxy)|Failed to connect to|Couldn\'t connect to server|' .
                'Connection refused|getaddrinfo .*failed/i',
            $error
        );
    }

    private function retryDelay(): ?float
    {
        if ($this->retryAfter === null || $this->retryAfter === '') {
            return 0.1;
        }
        if (ctype_digit($this->retryAfter)) {
            $delay = (float)$this->retryAfter;
        } else {
            $timestamp = strtotime($this->retryAfter);
            if ($timestamp === false) {
                return null;
            }
            $delay = max(0, $timestamp - time());
        }
        // A long Retry-After is reported rather than retried prematurely.
        if ($delay > 2) {
            return null;
        }
        return $delay;
    }

    private function sendOnce(string $json, array $requestHeaders, float $timeout): array
    {
        $httpFactory = $this->httpRequestFactory;
        if ($httpFactory === null && class_exists(MediaWikiServices::class)) {
            $httpFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
        }

        if ($httpFactory !== null && method_exists($httpFactory, 'create')) {
            $req = $httpFactory->create(
                $this->endpoint,
                [
                    'method' => 'POST',
                    'postData' => $json,
                    'timeout' => $timeout,
                ],
                __METHOD__
            );
            $req->setHeader('Content-Type', 'application/json');
            $req->setHeader('Accept', 'application/json, application/problem+json');
            foreach ($requestHeaders as $name => $value) {
                $req->setHeader($name, $value);
            }

            $status = $req->execute();
            $statusCode = (int)$req->getStatus();
            $responseBody = (string)$req->getContent();
            $this->lastStatus = $statusCode;
            if (method_exists($req, 'getResponseHeader')) {
                $this->retryAfter = $req->getResponseHeader('Retry-After') ?: null;
            }

            if ($status->isOK() && ($statusCode === 200 || $statusCode === 202)) {
                return $this->parseSuccessResponse($statusCode, $responseBody);
            }

            $wikiText = method_exists($status, 'getWikiText') ? $status->getWikiText() : '';
            $this->lastConnectFailed = $statusCode === 0 && self::isConnectFailure($wikiText);
            $this->handleErrorResponse($statusCode, $responseBody, $wikiText);
        }

        return $this->sendViaNativeHttp($json, $requestHeaders, $timeout);
    }

    /**
     * @param int $statusCode
     * @param string $responseBody
     * @param string $fallbackError
     * @return void
     * @throws MWException
     */
    private function handleErrorResponse(int $statusCode, string $responseBody, string $fallbackError = ''): void
    {
        $this->lastStatus = $statusCode;
        $problem = json_decode($responseBody, true);
        $this->lastProblemType = is_array($problem) ? (string)($problem['type'] ?? '') : '';
        if (is_array($problem)) {
            $title = $problem['title'] ?? '';
            $detail = $problem['detail'] ?? '';
            if ($detail !== '' && $title !== '') {
                throw new MWException("Mail API error [HTTP {$statusCode}] ({$title}): {$detail}");
            }
            if ($detail !== '') {
                throw new MWException("Mail API error [HTTP {$statusCode}]: {$detail}");
            }
            if ($title !== '') {
                throw new MWException("Mail API error [HTTP {$statusCode}]: {$title}");
            }
        }

        if ($responseBody !== '') {
            throw new MWException("Mail API error [HTTP {$statusCode}]: {$responseBody}");
        }

        if ($fallbackError !== '') {
            throw new MWException("Mail API error [HTTP {$statusCode}]: {$fallbackError}");
        }

        throw new MWException("Mail API request failed with HTTP status {$statusCode}.");
    }

    /**
     * Native HTTP fallback when MediaWikiServices is unavailable.
     *
     * @param string $json
     * @return array
     * @throws MWException
     */
    private function sendViaNativeHttp(string $json, array $requestHeaders, float $timeout): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json, application/problem+json'];
        foreach ($requestHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($this->endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => max(1, (int)($timeout * 1000)),
                CURLOPT_HEADERFUNCTION => function ($ch, $line) {
                    if (stripos($line, 'Retry-After:') === 0) {
                        $this->retryAfter = trim(substr($line, 12));
                    }
                    return strlen($line);
                },
            ]);

            $responseBody = curl_exec($ch);
            $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            if ($curlError !== '') {
                $this->lastConnectFailed = in_array(
                    $curlErrno,
                    [CURLE_COULDNT_RESOLVE_PROXY, CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT],
                    true
                );
                throw new MWException("Mail API cURL error: {$curlError}");
            }

            return $this->parseSuccessResponse($statusCode, (string)$responseBody);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $json,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        error_clear_last();
        $responseBody = @file_get_contents($this->endpoint, false, $context);
        if ($responseBody === false) {
            $cause = error_get_last()['message'] ?? 'Unknown stream error';
            $this->lastConnectFailed = self::isConnectFailure($cause);
            throw new MWException('Mail API stream error: ' . $cause);
        }
        $statusCode = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (stripos($line, 'Retry-After:') === 0) {
                    $this->retryAfter = trim(substr($line, 12));
                }
            }
            if (preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $matches)) {
                $statusCode = (int)$matches[1];
            }
        }

        return $this->parseSuccessResponse($statusCode, (string)$responseBody);
    }

    /**
     * @return string
     */
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }
}
