<?php

declare(strict_types=1);

return [
    'merge_name_similarity' => 1.0,
    'review_name_similarity' => 0.82,
    // Most strong or review candidates kept per source record; the best by similarity win.
    'max_candidates_per_source' => 50,
];
