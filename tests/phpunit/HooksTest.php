<?php

namespace MediaWiki\Extension\MailAPI\Tests;

use MailAddress;
use MediaWiki\Extension\MailAPI\Hooks;
use MWException;
use PHPUnit\Framework\TestCase;

class HooksTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        global $wgMailAPIEndpoint, $wgMailAPIToken, $wgMailAPIWaitTimeout;
        $wgMailAPIWaitTimeout = null;
        $wgMailAPIToken = null;
        $wgMailAPIEndpoint = null;
    }

    public function testOnAlternateUserMailerWithoutEndpointFallsBackToDefaultMailer(): void
    {
        global $wgMailAPIEndpoint, $wgMailAPIToken;
        $wgMailAPIToken = null;
        $wgMailAPIEndpoint = '';

        $hooks = new Hooks();
        $ret = $hooks->onAlternateUserMailer(
            [],
            new MailAddress('to@example.com'),
            new MailAddress('from@example.com'),
            'Subject',
            'Body'
        );

        $this->assertTrue($ret);
    }
    public function testInvalidWaitConfigurationUsesDefaultAndWarns(): void
    {
        global $wgMailAPIEndpoint, $wgMailAPIWaitTimeout;
        $wgMailAPIEndpoint = 'http://localhost:8080';
        $wgMailAPIWaitTimeout = 30;
        $logger = new class {
            public array $warnings = [];
            public function warning($message, $context = []) { $this->warnings[] = [$message, $context]; }
        };
        $hooks = new Hooks($logger);
        $settings = new \ReflectionMethod($hooks, 'settings');
        $settings->setAccessible(true);
        $this->assertSame(0, $settings->invoke($hooks)[2]);
        $this->assertCount(1, $logger->warnings);
        $this->assertSame(30, $logger->warnings[0][1]['value']);
    }

}
