<?php

namespace MediaWiki\Extension\MailAPI\Tests;

use MailAddress;
use MediaWiki\Extension\MailAPI\Client;
use MediaWiki\Extension\MailAPI\Hooks;
use PHPUnit\Framework\TestCase;

class IntegrationTest extends TestCase
{
    private static $serverProcess = null;
    private static $serverPort = 0;
    private static $logFile = '';
    private static $serverScript = '';
    private static $lastRequestFile = '';

    private static function allocateEphemeralPort(): int
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$server) {
            return rand(10000, 60000);
        }
        $address = stream_socket_get_name($server, false);
        fclose($server);
        $port = (int)parse_url('tcp://' . $address, PHP_URL_PORT);
        return $port > 0 ? $port : rand(10000, 60000);
    }

    public static function setUpBeforeClass(): void
    {
        self::$serverPort = self::allocateEphemeralPort();
        $uid = uniqid('mailapi_test_', true);
        self::$logFile = sys_get_temp_dir() . '/' . $uid . '_server.log';
        self::$serverScript = sys_get_temp_dir() . '/' . $uid . '_router.php';
        self::$lastRequestFile = sys_get_temp_dir() . '/' . $uid . '_last_request.json';

        $reqFileEscaped = var_export(self::$lastRequestFile, true);

        $scriptContent = <<<PHP
<?php
\$uri = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);
\$method = \$_SERVER['REQUEST_METHOD'];

if (\$uri === '/v1/messages' && \$method === 'POST') {
    if ((\$_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer integration-token' || !preg_match('/^[a-f0-9]{32}$/', \$_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')) {
        http_response_code(401);
        echo json_encode(['type' => 'https://mailapi.github.io/problems/unauthenticated', 'title' => 'Unauthenticated', 'status' => 401]);
        exit;
    }
    \$body = file_get_contents('php://input');
    file_put_contents({$reqFileEscaped}, \$body);

    \$json = json_decode(\$body, true);
    if (!isset(\$json['from']) || !isset(\$json['to'])) {
        http_response_code(400);
        header('Content-Type: application/problem+json');
        echo json_encode([
            'type' => 'https://api.example.com/problems/invalid-payload',
            'title' => 'Invalid Payload',
            'status' => 400,
            'detail' => 'Missing required fields',
        ]);
        exit;
    }

    http_response_code(202);
    header('Content-Type: application/json');
    echo json_encode(['id' => 'msg_integration_test_ok']);
    exit;
}

http_response_code(404);
header('Content-Type: application/problem+json');
echo json_encode([
    'type' => 'https://api.example.com/problems/not-found',
    'title' => 'Not Found',
    'status' => 404,
    'detail' => 'Route not found',
]);
PHP;

        file_put_contents(self::$serverScript, $scriptContent);

        $cmd = sprintf(
            'php -S 127.0.0.1:%d %s > %s 2>&1 & echo $!',
            self::$serverPort,
            escapeshellarg(self::$serverScript),
            escapeshellarg(self::$logFile)
        );

        $pid = exec($cmd);
        self::$serverProcess = (int)$pid;
        usleep(200000); // 200ms wait for server to start
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess) {
            exec('kill ' . self::$serverProcess . ' 2>/dev/null');
        }
        @unlink(self::$lastRequestFile);
        @unlink(self::$serverScript);
        @unlink(self::$logFile);
    }

    public function testEndToEndSendWithMockServer(): void
    {
        $client = new Client('http://127.0.0.1:' . self::$serverPort, null, 'integration-token');
        $payload = $client->buildPayload(
            ['X-Test' => 'Integration'],
            new MailAddress('recipient@example.com', 'Recipient Name'),
            new MailAddress('sender@example.com', 'Sender Name'),
            'Test Integration Email',
            'This is the email content.'
        );

        $result = $client->send($payload);
        $this->assertSame(['id' => 'msg_integration_test_ok'], $result);

        $savedRequest = file_get_contents(self::$lastRequestFile);
        $this->assertNotEmpty($savedRequest);
        $decoded = json_decode($savedRequest, true);

        $this->assertSame('sender@example.com', $decoded['from']['email']);
        $this->assertSame('Sender Name', $decoded['from']['name']);
        $this->assertSame('recipient@example.com', $decoded['to'][0]['email']);
        $this->assertSame('Recipient Name', $decoded['to'][0]['name']);
        $this->assertSame('Test Integration Email', $decoded['subject']);
        $this->assertSame('This is the email content.', $decoded['text']);
        $this->assertSame([['name' => 'X-Test', 'value' => 'Integration']], $decoded['headers']);
    }

    public function testHooksEndToEnd(): void
    {
        global $wgMailAPIEndpoint, $wgMailAPIToken;
        $wgMailAPIToken = 'integration-token';
        $wgMailAPIEndpoint = 'http://127.0.0.1:' . self::$serverPort;

        $hooks = new Hooks();
        $ret = $hooks->onAlternateUserMailer(
            [],
            new MailAddress('user@example.com'),
            new MailAddress('wiki@example.com'),
            'MediaWiki Test',
            'Hello from Hook'
        );

        $subject = 'MediaWiki Test';
        $headers = [];
        $body = 'Hello from Hook';
        $error = null;
        $this->assertTrue($hooks->onUserMailerTransformMessage(
            new MailAddress('user@example.com'), new MailAddress('wiki@example.com'),
            $subject, $headers, $body, $error
        ));
        $this->assertNull($error);
        $this->assertSame('MediaWiki Test', json_decode(file_get_contents(self::$lastRequestFile), true)['subject']);

        // onAlternateUserMailer returns false when email delivery is successfully intercepted
        $this->assertFalse($ret);
    }

    public function testHooksErrorStopsDefaultMailer(): void
    {
        global $wgMailAPIEndpoint, $wgMailAPIToken;
        $wgMailAPIToken = 'integration-token';
        $wgMailAPIEndpoint = 'http://127.0.0.1:' . self::$serverPort . '/nonexistent';

        $hooks = new Hooks();
        $ret = $hooks->onAlternateUserMailer(
            [],
            new MailAddress('user@example.com'),
            new MailAddress('wiki@example.com'),
            'MediaWiki Test',
            'Hello from Hook'
        );

        $subject = 'MediaWiki Test';
        $headers = [];
        $body = 'Hello from Hook';
        $error = null;
        $result = $hooks->onUserMailerTransformMessage(
            new MailAddress('user@example.com'),
            new MailAddress('wiki@example.com'),
            $subject, $headers, $body, $error
        );
        $this->assertFalse($result);
        $this->assertFalse($ret);
        $this->assertSame('Mail API submission failed. Please contact the wiki administrator.', $error);
    }
}
