<?php

declare(strict_types=1);

namespace App\Contracts;

/** An alert notifier whose result records an audit event rather than external delivery. */
interface AlertAuditNotifier extends AlertNotifier {}
