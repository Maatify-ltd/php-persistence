# WU-B2.2 experimental proof

This opt-in integration exercise is repository-only. PHPUnit's named suites
and the Composer distribution exclude it. Use a Docker daemon and Compose,
PHP with pdo_mysql, and the repository's dev dependencies.

~~~sh
composer install
php harness/verified-apply-discard/run.php --cell=mysql-8.4.13 --suite-passes=2
php harness/verified-apply-discard/run.php --cell=mariadb-11.8.9 --suite-passes=2
~~~

The runner uses the pinned Compose matrix, generates disposable credentials and a
random project name, verifies the server version, checks for database residue
between passes and tears down the project. The relay uses local non-TLS traffic
and prints only the injected control milestone.

Both Host paths use the same insert/read decisions and current Protected lease.
C1 adds an operation window to that lease in the fixture. C2 wraps the lease in
a parallel entry point with an Owned mode and records the acknowledged end
before the connection check. The private closure bindings make those seams
observable for this exercise. They are unavailable to ordinary consumers.

The writer and independent observer distinguish pending writes from committed
writes. A retained writer handle is used directly only in the raw comparison,
negative controls, measurements, or teardown. The failure cases separate the
receipt of a control reply, database effect and connection disposition. The
engine observations apply to the selected disposable cells. The before-dispatch
callback injects false/throw at a fixture seam. The relay verifies real server
replies for the transport cases. Qualification of a future production API,
production registry integration and driver combinations outside these cells
are separate acceptance work.
