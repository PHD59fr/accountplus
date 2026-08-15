# Continuous security policy

The security workflow runs for pull requests, master/main pushes, weekly rebuilds
and manual dispatch. It has a read-only GitHub token and no deployment secrets.
Actions are pinned to verified upstream commit IDs; scanner images to release
manifest digests. Review Dependabot changes and refresh the scanner versions and
checksums at least monthly. Both amd64 and native arm64 runners build and test the
same Dockerfiles. Local arm64 availability is recorded separately in the report.

All HIGH and CRITICAL OS/library findings block delivery, including unfixed issues.
An OS marked EOSL by Trivy also blocks delivery. The separate mandatory PHP version
guard is necessary because the Alpine 3.20 scanner database did not report the known
PHP CVEs in the old 8.2.28 image; scanner silence is not evidence of a patched runtime.
Scanner/network/database failures also fail the job. No global ignore list or
ignore-unfixed flag is used. No exceptions are currently approved. An exception
requires a reviewed record of image digest, package, CVE, exploitability analysis,
compensating controls, owner and expiration/review date; it must never silently
cover unrelated or future findings. This policy can require delaying deployment
until the upstream packages are fixed.

Gitleaks scans accessible Git history (--all with fetch-depth 0) and the working
tree, with redacted output. A generated synthetic credential verifies the detector
returns the expected failure status. Tests fail normally on any nonzero result.
JSON image and redacted secret reports are uploaded for 14 days even on failure.
No reports may contain production secrets or private accounting archives.

Local equivalents: build both images using the workflow commands, then run
`python3 tests/Integration/security_stack.py`. This creates only uniquely named
test resources and memory-backed databases; it never attaches production volumes.
Trivy accepts `docker save` tar files, so the scanner needs no Docker socket mount.
Do not run the legacy integration suite against a populated or production database.


## Deployment cleanup checks

`bash -n clean4prod.sh` checks the cleanup script syntax.
`python3 tests/clean4prod_test.py` exercises hosting and Docker cleanup on temporary
synthetic deployment copies. It checks dry runs, repeated cleanup, symlinks and
preservation of configuration, actual secrets, persistent storage, CLI/runtime
files, languages and licenses. It does not run cleanup on the checkout or connect
to a database. The workflow runs these checks before the PHP unit suite.

## Manual database and browser checks

The PHP integration runner requires PDO MySQL and an empty dedicated database
named `accountplus_test` or `accountplus_test_<suffix>`. Its deployment account needs
the migration privileges and TRIGGER on that test database, because the suite creates
a trigger to verify rollback. Supply the test password through the environment:

```sh
TEST_DB_HOST=127.0.0.1 TEST_DB_DATABASE=accountplus_test \
TEST_DB_USERNAME=test_deployment_user TEST_DB_PASSWORD="$TEST_PASSWORD" \
php tests/Integration/run.php
```

For an HTTP test deployment seeded by that runner, use:

```sh
python3 tests/Integration/web_check.py --url http://127.0.0.1:8080
```

The `--missing-config` option checks that setup cannot reclaim a database when its
configuration is absent. The browser fixture `tests/Integration/charts.html` can be
copied into a test deployment as `chart-check.html`. It uses the synthetic test login,
selects the seeded company and checks chart pixels, legends and dashboard settings
under the application's CSP. Never deploy this fixture to production.

The isolated Docker runner can perform the browser checks itself when supplied with
a Chrome/Chromium executable:

```sh
SECURITY_BROWSER=/path/to/chrome python3 tests/Integration/security_stack.py
```

To exercise the database image transition and rollback with fresh, retained test
volumes, run `python3 tests/Integration/upgrade_rehearsal.py`. It builds the frozen
source archive in `tests/fixtures/upgrade/`, verifies its SHA-256 and creates
representative accounting data. The fixture is versioned with the test and works
without the historical Git object, including after a squash or repository recreation.
`python3 tests/upgrade_baseline_test.py` verifies Git-free loading and failure on
missing/corrupt archives, unsafe entries and existing destination files.
`python3 tests/Integration/upgrade_rehearsal.py --check-baseline` validates the fixture
without starting Docker.
The full process is described in [deployment and recovery](upgrade.md).
