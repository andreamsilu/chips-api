<?php

return ['session_minutes' => (int) env('GUEST_SESSION_TTL_MINUTES', 10080), 'order_minutes' => (int) env('GUEST_ORDER_TTL_MINUTES', 43200)];
