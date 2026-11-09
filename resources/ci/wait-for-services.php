<?php

/** CI-only readiness check for disposable database and cache services. */
for ($attempt = 0; $attempt < 60; $attempt++) {
    try {
        new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_TIMEOUT => 2]);
        $redis = new Redis;
        $redis->connect(getenv('REDIS_HOST'), 6379, 2);
        $redis->ping();
        echo "MySQL and Redis are ready.\n";
        exit(0);
    } catch (Throwable) {
        sleep(1);
    }
}
fwrite(STDERR, "CI MySQL/Redis services did not become ready.\n");
exit(1);
