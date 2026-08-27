<?php

/**
 * Waits for the CI MariaDB service and asserts which database the suite will
 * use.
 *
 * Done through PDO rather than the mysql client, which is not guaranteed on the
 * runner image. Asserting the name means a green run can never be a run against
 * the wrong database.
 */
for ($attempt = 1; $attempt <= 30; $attempt++) {
    try {
        $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=madad_test', 'root', 'root');
        $name = $pdo->query('SELECT DATABASE()')->fetchColumn();

        echo "SELECT DATABASE() = {$name}", PHP_EOL;

        exit($name === 'madad_test' ? 0 : 1);
    } catch (PDOException $e) {
        echo "waiting for MariaDB ({$attempt}/30)", PHP_EOL;
        sleep(2);
    }
}

echo 'MariaDB never became ready', PHP_EOL;
exit(1);
