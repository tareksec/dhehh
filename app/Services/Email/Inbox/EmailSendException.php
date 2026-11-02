<?php

namespace App\Services\Email\Inbox;

/** A reason a message cannot be written or sent, worded for the person who pressed the button (the message is already translated). */
class EmailSendException extends \RuntimeException {}
