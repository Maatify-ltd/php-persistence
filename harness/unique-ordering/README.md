# WU-C1 UNIQUE ordering proof

This opt-in harness uses real InnoDB with the repository's pinned disposable
Compose services. It exercises the public Ordering API and compares experimental
SQL statements on isolated fixtures. The named PHPUnit suites and distributed
package exclude this harness.

Run from the repository root after installing development dependencies.

```sh
php harness/unique-ordering/run.php --cell=mysql-8.4.13 --suite-passes=2
php harness/unique-ordering/run.php --cell=mariadb-11.8.9 --suite-passes=2
```

Each `UNIQUE-PROOF` JSON line reports a tested observation. Position lists are
in row ID order so a permutation can be distinguished from an unchanged set.
The fixture creates global and scoped indexes, a normalized active-only key,
and a bounded domain. The tests assert intermediate positions and expected
duplicate-key errors as well as committed state and caller ownership.

The two-writer tests use explicit transaction milestones. A conflicting lock
request runs during the first writer's transaction with a one-second lock
timeout. The deadlock test uses the existing partner process and its line
barrier. The Protected checkout case checks fencing before test cleanup. If
discard leaves the fixture connection open, the observer stops that connection
on the disposable server before dropping tables.

The cost observation counts affected rows, UPDATE statements and elapsed client
time for only those UPDATE statements. Each strategy uses the same starting
dense scope, index and full-scope locking read. The timings exclude connection
setup, reads, commit and parallel contention, so they describe the local
fixture rather than production latency.
