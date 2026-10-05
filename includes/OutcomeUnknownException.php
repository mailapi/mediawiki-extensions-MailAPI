<?php

namespace MediaWiki\Extension\MailAPI;

use MWException;

/** Submission may still complete; starting a new submission could duplicate it. */
class OutcomeUnknownException extends MWException
{
}
