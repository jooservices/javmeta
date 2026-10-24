<?php

declare(strict_types=1);

return [
    'whisper_model' => 'large-v3',
    'api_url' => 'http://127.0.0.1:8085',
    'output_folder' => storage_path('app/private/media/output'),
    'max_parallel' => 1,
    'max_attempts' => 3,
    'lease_seconds' => 7500,
    'job_timeout_sec' => 7200,
    'poll_sec' => 5,
    'thumb_interval_sec' => 30,
    'scene_threshold' => 0.3,
    'ffmpeg_threads' => 2,
];
