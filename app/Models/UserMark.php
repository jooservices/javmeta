<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserMark extends Model
{
    protected $table = 'user_marks';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['*'];
}
