Consumer stand-ins the package tests against — fixture models applying the
package's traits, and the migrations creating their tables (register the
migration *path* with the migrator in `TestCase::getEnvironmentSetUp()`, not
`loadMigrationsFrom()`, which makes Testbench rebuild the schema per test).

PHPStan analyses this directory alongside `src/` — see `phpstan.neon`. Without
fixtures, a trait no fixture uses is unanalysable and reports `trait.unused`.
