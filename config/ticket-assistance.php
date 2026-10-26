<?php

return [
    // Enable only after the registered v2 workflow and queue have passed acceptance.
    'enabled' => env('TICKET_ASSISTANCE_ENABLED', false),
    'actor_id' => (int) env('TICKET_ASSISTANCE_ACTOR_ID', 0),
    'batch_size' => (int) env('TICKET_ASSISTANCE_BATCH_SIZE', 20),
];
