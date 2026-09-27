<?php

namespace App\Services\MailHosting;

use RuntimeException;

/** ADR-190 — l'hébergeur a refusé, ou ne répond pas. Le message est destiné à l'écran. */
final class MailHostingException extends RuntimeException {}
