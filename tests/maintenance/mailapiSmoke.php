<?php

// Run inside a disposable, installed MediaWiki 1.43+ instance with MailAPI loaded:
// php extensions/MailAPI/tests/maintenance/mailapiSmoke.php --endpoint http://127.0.0.1:8099
require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 4)) . '/maintenance/Maintenance.php';

class MailAPISmoke extends Maintenance
{
    public function __construct()
    {
        parent::__construct();
        $this->addDescription('Check MailAPI through real UserMailer and HookContainer using a mock HTTP server.');
        $this->addOption('endpoint', 'Base URL of the smokeRouter.php mock server', true, true);
    }

    public function execute()
    {
        global $wgMailAPIEndpoint, $wgMailAPIToken, $wgMailAPIWaitTimeout;
        $base = rtrim($this->getOption('endpoint'), '/');
        $wgMailAPIToken = 'smoke-token';
        $wgMailAPIWaitTimeout = 2;
        foreach (['success', 'terminal', 'pending', 'transport'] as $scenario) {
            $wgMailAPIEndpoint = $scenario === 'transport'
                ? 'http://127.0.0.1:1' : $base . '/' . $scenario;
            $status = UserMailer::send(
                new MailAddress('recipient@example.com'),
                new MailAddress('sender@example.com'),
                'MailAPI smoke test',
                'Smoke test message body.'
            );
            if ($scenario === 'success') {
                if (!$status->isOK()) {
                    $this->fatal('Successful API submission did not suppress the default mail transport.');
                }
            } else {
                $errors = $status->getErrors();
                if ($status->isOK() || ($errors[0]['message'] ?? '') !== 'php-mail-error') {
                    $this->fatal('Expected fatal php-mail-error for ' . $scenario);
                }
                $detail = $errors[0]['params'][0] ?? '';
                $expected = $scenario === 'terminal' ? 'submission failed' : 'outcome is unknown';
                if (!str_contains($detail, $expected) || str_contains($detail, 'private-provider-detail')) {
                    $this->fatal('Unexpected user-facing error for ' . $scenario);
                }
            }
            $this->output($scenario . ": PASS\n");
        }
    }
}

$maintClass = MailAPISmoke::class;
require_once RUN_MAINTENANCE_IF_MAIN;
