<?php

return [
    // 25 frames at 480p to bound the cost of preview media jobs.
    'origin' => 'https://ai.api.nvidia.com/v1/cosmos/nvidia/cosmos3-nano',
    'max_response_bytes' => 48000000,
    'max_media_bytes' => 35000000,
    'worker_timeout' => 840,
    'retention_hours' => 24,
];
