<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class NotificationTemplate extends Model
{
    protected $table = 'notification_templates';

    protected $guarded = [];
}
