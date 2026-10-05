<?php

namespace {
    require_once __DIR__ . '/../../vendor/autoload.php';

    if (!class_exists('MWException')) {
        class MWException extends \Exception {}
    }

    if (!class_exists('MailAddress')) {
        class MailAddress
        {
            /** @var string */
            public $address;
            /** @var string */
            public $name;
            /** @var string */
            public $realName;

            public function __construct(string $address = '', string $name = '', string $realName = '')
            {
                $this->address = $address;
                $this->name = $name;
                $this->realName = $realName;
            }

            public function getEmail(): string
            {
                return $this->address;
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getRealName(): string
            {
                return $this->realName;
            }
        }
    }
}

namespace MediaWiki\Hook {
    if (!interface_exists(AlternateUserMailerHook::class)) {
        interface AlternateUserMailerHook
        {
            public function onAlternateUserMailer($headers, $to, $from, $subject, $body);
        }
    }
}

namespace MediaWiki\Hook {
    if (!interface_exists(UserMailerTransformMessageHook::class)) {
        interface UserMailerTransformMessageHook {
            public function onUserMailerTransformMessage($to, $from, &$subject, &$headers, &$body, &$error);
        }
    }
}
