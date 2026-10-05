<?php

namespace MediaWiki\Extension\MailAPI\Tests;

use MailAddress;
use MediaWiki\Extension\MailAPI\Client;
use MWException;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    public function testNormalizeEndpoint(): void
    {
        $this->assertSame(
            'http://localhost:8080/v1/messages',
            Client::normalizeEndpoint('http://localhost:8080')
        );

        $this->assertSame(
            'http://localhost:8080/v1/messages',
            Client::normalizeEndpoint('http://localhost:8080/')
        );

        $this->assertSame(
            'http://localhost:8080/v1/messages',
            Client::normalizeEndpoint('http://localhost:8080/v1/messages')
        );

        $this->assertSame(
            'http://localhost:8080/v1/messages',
            Client::normalizeEndpoint('http://localhost:8080/v1/messages/')
        );

        $this->assertSame(
            'https://api.example.com/subpath/v1/messages',
            Client::normalizeEndpoint('https://api.example.com/subpath')
        );
    }

    public function testNormalizeEmptyEndpointThrowsException(): void
    {
        $this->expectException(MWException::class);
        $this->expectExceptionMessage('Please set $wgMailAPIEndpoint in LocalSettings.php.');
        Client::normalizeEndpoint('');
    }

    public function testParseAddressFromMailAddress(): void
    {
        $addr = new MailAddress('admin@example.com', 'Admin User');
        $parsed = Client::parseAddress($addr, 'sender');
        $this->assertSame([
            'email' => 'admin@example.com',
            'name' => 'Admin User',
        ], $parsed);

        $addrNoName = new MailAddress('admin@example.com');
        $parsedNoName = Client::parseAddress($addrNoName, 'sender');
        $this->assertSame([
            'email' => 'admin@example.com',
        ], $parsedNoName);
    }

    public function testParseAddressFromString(): void
    {
        $parsedWithName = Client::parseAddress('Jane Doe <jane@example.com>');
        $this->assertSame([
            'email' => 'jane@example.com',
            'name' => 'Jane Doe',
        ], $parsedWithName);

        $parsedWithQuotes = Client::parseAddress('"Jane Doe" <jane@example.com>');
        $this->assertSame([
            'email' => 'jane@example.com',
            'name' => 'Jane Doe',
        ], $parsedWithQuotes);

        $parsedPlain = Client::parseAddress('jane@example.com');
        $this->assertSame([
            'email' => 'jane@example.com',
        ], $parsedPlain);
    }

    public function testParseAddressFromArray(): void
    {
        $parsed = Client::parseAddress(['email' => 'user@example.com', 'name' => 'User']);
        $this->assertSame([
            'email' => 'user@example.com',
            'name' => 'User',
        ], $parsed);
    }

    public function testParseInvalidAddressThrowsException(): void
    {
        $this->expectException(MWException::class);
        $this->expectExceptionMessage('Invalid sender email address: invalid-email');
        Client::parseAddress('invalid-email', 'sender');
    }

    public function testParseAddressList(): void
    {
        $list = Client::parseAddressList('a@example.com, "B User" <b@example.com>');
        $this->assertCount(2, $list);
        $this->assertSame(['email' => 'a@example.com'], $list[0]);
        $this->assertSame(['email' => 'b@example.com', 'name' => 'B User'], $list[1]);

        $addrArray = [
            new MailAddress('first@example.com', 'First'),
            new MailAddress('second@example.com', 'Second'),
        ];
        $listFromArray = Client::parseAddressList($addrArray);
        $this->assertCount(2, $listFromArray);
        $this->assertSame(['email' => 'first@example.com', 'name' => 'First'], $listFromArray[0]);
        $this->assertSame(['email' => 'second@example.com', 'name' => 'Second'], $listFromArray[1]);
    }

    public function testBuildPayloadBasic(): void
    {
        $client = new Client('http://localhost:8080');
        $from = new MailAddress('from@example.com', 'Sender');
        $to = new MailAddress('to@example.com', 'Recipient');
        $subject = 'Hello World';
        $body = 'Test Body Content';

        $payload = $client->buildPayload([], $to, $from, $subject, $body);

        $this->assertSame([
            'from' => ['email' => 'from@example.com', 'name' => 'Sender'],
            'to' => [['email' => 'to@example.com', 'name' => 'Recipient']],
            'subject' => 'Hello World',
            'text' => 'Test Body Content',
        ], $payload);
    }

    public function testBuildPayloadWithArrayBody(): void
    {
        $client = new Client('http://localhost:8080');
        $from = new MailAddress('from@example.com');
        $to = new MailAddress('to@example.com');
        $subject = 'HTML Email';
        $body = [
            'text' => 'Plain text version',
            'html' => '<p>HTML version</p>',
        ];

        $payload = $client->buildPayload([], $to, $from, $subject, $body);

        $this->assertSame('Plain text version', $payload['text']);
        $this->assertSame('<p>HTML version</p>', $payload['html']);
    }

    public function testBuildPayloadWithHeadersAndSpecialFields(): void
    {
        $client = new Client('http://localhost:8080');
        $from = new MailAddress('from@example.com');
        $to = [new MailAddress('to1@example.com'), new MailAddress('to2@example.com')];
        $headers = [
            'Reply-To' => 'Support <support@example.com>',
            'Cc' => 'cc@example.com',
            'Bcc' => 'bcc@example.com',
            'X-MediaWiki-Mailer' => 'MailAPI',
            'List-Unsubscribe' => '<mailto:unsub@example.com>',
        ];

        $payload = $client->buildPayload($headers, $to, $from, 'Notification', 'Message body');

        $this->assertCount(2, $payload['to']);
        $this->assertSame([['email' => 'support@example.com', 'name' => 'Support']], $payload['replyTo']);
        $this->assertSame([['email' => 'cc@example.com']], $payload['cc']);
        $this->assertSame([['email' => 'bcc@example.com']], $payload['bcc']);
        $this->assertSame([
            ['name' => 'X-MediaWiki-Mailer', 'value' => 'MailAPI'],
            ['name' => 'List-Unsubscribe', 'value' => '<mailto:unsub@example.com>'],
        ], $payload['headers']);
    }

    public function testBuildPayloadNoRecipientThrowsException(): void
    {
        $client = new Client('http://localhost:8080');
        $from = new MailAddress('from@example.com');

        $this->expectException(MWException::class);
        $this->expectExceptionMessage('No recipient address resolved from MediaWiki mail payload.');
        $client->buildPayload([], [], $from, 'Subject', 'Body');
    }

    public function testSendWithMockHttpRequestSuccess(): void
    {
        $reqMock = new class {
            public array $headers = [];
            public function setHeader($name, $value) { $this->headers[$name] = $value; }
            public function execute() {
                return new class {
                    public function isOK() { return true; }
                    public function getWikiText() { return ''; }
                };
            }
            public function getStatus() { return 200; }
            public function getContent() { return '{"id":"msg_test123"}'; }
        };

        $factoryMock = new class($reqMock) {
            private $req;
            public function __construct($req) { $this->req = $req; }
            public function create($url, $options, $caller) { return $this->req; }
        };

        $client = new Client('http://localhost:8080', $factoryMock);
        $payload = [
            'from' => ['email' => 'sender@example.com'],
            'to' => [['email' => 'recipient@example.com']],
            'subject' => 'Test',
            'text' => 'Hello',
        ];

        $res = $client->send($payload);
        $this->assertSame(['id' => 'msg_test123'], $res);
        $this->assertSame('application/json', $reqMock->headers['Content-Type']);
        $this->assertSame('application/json, application/problem+json', $reqMock->headers['Accept']);
    }

    public function testSendWithProblemDetailsError(): void
    {
        $reqMock = new class {
            public function setHeader($name, $value) {}
            public function execute() {
                return new class {
                    public function isOK() { return false; }
                    public function getWikiText() { return 'HTTP 400'; }
                };
            }
            public function getStatus() { return 400; }
            public function getContent() {
                return json_encode([
                    'type' => 'https://api.example.com/problems/invalid-recipient',
                    'title' => 'Invalid Recipient',
                    'status' => 400,
                    'detail' => 'Recipient domain is rejected.',
                ]);
            }
        };

        $factoryMock = new class($reqMock) {
            private $req;
            public function __construct($req) { $this->req = $req; }
            public function create($url, $options, $caller) { return $this->req; }
        };

        $client = new Client('http://localhost:8080', $factoryMock);
        $payload = [
            'from' => ['email' => 'sender@example.com'],
            'to' => [['email' => 'invalid@example.com']],
            'subject' => 'Test',
            'text' => 'Hello',
        ];

        $this->expectException(MWException::class);
        $this->expectExceptionMessage('Mail API error [HTTP 400] (Invalid Recipient): Recipient domain is rejected.');
        $client->send($payload);
    }

    public function testDecodeMimeHeader(): void
    {
        // "홍길동" in standard Base64: 7ZmN6ri464+Z
        $encodedB = '=?UTF-8?B?7ZmN6ri464+Z?=';
        $this->assertSame('홍길동', Client::decodeMimeHeader($encodedB));

        // Quoted-Printable
        $encodedQ = '=?UTF-8?Q?=ED=99=8D=EA=B8=B8=EB=8F=99?=';
        $this->assertSame('홍길동', Client::decodeMimeHeader($encodedQ));

        // Plain string
        $this->assertSame('Plain Name', Client::decodeMimeHeader('Plain Name'));
    }

    public function testParseAddressWithMimeEncodedName(): void
    {
        $parsed = Client::parseAddress('=?UTF-8?B?7ZmN6ri464+Z?= <hong@example.com>');
        $this->assertSame('hong@example.com', $parsed['email']);
        $this->assertSame('홍길동', $parsed['name']);
    }

    public function testBuildPayloadWithMimeEncodedHeaders(): void
    {
        $client = new Client('http://localhost:8080');
        $from = new MailAddress('admin@example.com', '=?UTF-8?B?6rSA66as7J6Q?=');
        $to = new MailAddress('user@example.com', '=?UTF-8?B?7ZmN6ri464+Z?=');
        $headers = [
            'Reply-To' => '=?UTF-8?B?7KeA7JuQ?= <support@example.com>',
            'X-Custom' => '=?UTF-8?B?7YWM7Iqk7Yq4?=',
        ];

        $payload = $client->buildPayload($headers, $to, $from, '=?UTF-8?B?7KCc66qp?=', 'Body');

        $this->assertSame('관리자', $payload['from']['name']);
        $this->assertSame('홍길동', $payload['to'][0]['name']);
        $this->assertSame('제목', $payload['subject']);
        $this->assertSame('지원', $payload['replyTo'][0]['name']);
        $this->assertSame('support@example.com', $payload['replyTo'][0]['email']);
        $this->assertSame('테스트', $payload['headers'][0]['value']);
    }

    public function testParseMultipartAlternativeBody(): void
    {
        $client = new Client('http://localhost:8080');
        $boundary = '=_boundary_12345';
        $headers = [
            'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
            'MIME-Version' => '1.0',
        ];

        $rawBody = <<<EOM
This is a multi-part message in MIME format.

--{$boundary}
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: quoted-printable

Hello =ED=99=8D=EA=B8=B8=EB=8F=99!

--{$boundary}
Content-Type: text/html; charset=UTF-8
Content-Transfer-Encoding: quoted-printable

<p>Hello <b>=ED=99=8D=EA=B8=B8=EB=8F=99</b>!</p>

--{$boundary}--
EOM;

        $payload = $client->buildPayload(
            $headers,
            new MailAddress('user@example.com'),
            new MailAddress('wiki@example.com'),
            'Multipart Subject',
            $rawBody
        );

        $this->assertSame('Hello 홍길동!', $payload['text']);
        $this->assertSame('<p>Hello <b>홍길동</b>!</p>', $payload['html']);
        // Transport/MIME headers should NOT leak into supplemental headers
        $this->assertArrayNotHasKey('headers', $payload);
    }

    public function testSendRejectsNon200Response(): void
    {
        $reqMock = new class {
            public function setHeader($name, $value) {}
            public function execute() {
                return new class {
                    public function isOK() { return true; }
                    public function getWikiText() { return ''; }
                };
            }
            public function getStatus() { return 204; } // 204 No Content
            public function getContent() { return ''; }
        };

        $factoryMock = new class($reqMock) {
            private $req;
            public function __construct($req) { $this->req = $req; }
            public function create($url, $options, $caller) { return $this->req; }
        };

        $client = new Client('http://localhost:8080', $factoryMock);
        $payload = [
            'from' => ['email' => 'sender@example.com'],
            'to' => [['email' => 'user@example.com']],
            'subject' => 'Test',
            'text' => 'Hello',
        ];

        $this->expectException(MWException::class);
        $this->expectExceptionMessage('Mail API request failed with HTTP status 204.');
        $client->send($payload);
    }

    public function testSendRejectsMalformedJsonOrMissingId(): void
    {
        $reqMock = new class {
            public function setHeader($name, $value) {}
            public function execute() {
                return new class {
                    public function isOK() { return true; }
                    public function getWikiText() { return ''; }
                };
            }
            public function getStatus() { return 200; }
            public function getContent() { return '{"status":"ok"}'; } // missing 'id'
        };

        $factoryMock = new class($reqMock) {
            private $req;
            public function __construct($req) { $this->req = $req; }
            public function create($url, $options, $caller) { return $this->req; }
        };

        $client = new Client('http://localhost:8080', $factoryMock);
        $payload = [
            'from' => ['email' => 'sender@example.com'],
            'to' => [['email' => 'user@example.com']],
            'subject' => 'Test',
            'text' => 'Hello',
        ];

        $this->expectException(MWException::class);
        $this->expectExceptionMessage("Mail API response malformed (HTTP 200): expected JSON object with non-empty string 'id'.");
        $client->send($payload);
    }
    public function testAcceptedResponseAndBearerHeaders(): void
    {
        $request = new class {
            public $headers = [];
            public function setHeader($name, $value) { $this->headers[$name] = $value; }
            public function execute() { return new class { public function isOK() { return true; } }; }
            public function getStatus() { return 202; }
            public function getContent() { return '{"id":"queued-message"}'; }
        };
        $factory = new class($request) {
            private $request;
            public function __construct($request) { $this->request = $request; }
            public function create($url, $options, $caller) { return $this->request; }
        };
        $client = new Client('http://localhost:8080', $factory, 'test-token');
        $this->assertSame(['id' => 'queued-message'], $client->send(['text' => 'Hello']));
        $this->assertSame('Bearer test-token', $request->headers['Authorization']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $request->headers['Idempotency-Key']);
        $firstKey = $request->headers['Idempotency-Key'];
        $client->send(['text' => 'Another message']);
        $this->assertNotSame($firstKey, $request->headers['Idempotency-Key']);
    }

    public function testTokenRejectsHeaderInjection(): void
    {
        $this->expectException(MWException::class);
        new Client('http://localhost:8080', null, "token\r\nX-Injected: yes");
    }

    public function testEmptyBodyAndRepeatedRecipientHeaders(): void
    {
        $client = new Client('http://localhost:8080');
        $payload = $client->buildPayload(
            ['Cc' => ['one@example.com', 'two@example.com']],
            new MailAddress('to@example.com'),
            new MailAddress('from@example.com'),
            'Subject',
            ['text' => '']
        );
        $this->assertSame('', $payload['text']);
        $this->assertSame([['email' => 'one@example.com'], ['email' => 'two@example.com']], $payload['cc']);
    }

}
