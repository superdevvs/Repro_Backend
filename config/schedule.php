<?php

return [
    // Operations calendar follows the same Eastern business day as dashboard
    // reporting. Individual appointment dates/times retain each property's zone.
    'calendar_timezone' => env('SCHEDULE_CALENDAR_TIMEZONE', 'America/New_York'),
];
