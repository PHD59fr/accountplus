# Frozen upgrade baseline

This fixture contains the tracked source tree audited as
`83f7d77add126563025a22db3d46658be904b877`. The source revision is provenance only;
the rehearsal never fetches or resolves it. The versioned archive works in a
shallow clone, a squashed/recreated repository, or a source copy without `.git`.
`baseline.json` records the archive's SHA-256, which is checked before extraction
or Docker operations. Missing, changed or unsafe fixtures fail the test.

The archive was produced from `git archive --format=tar` and gzip-compressed with
mtime zero. It includes the old migrations, application, integration tests and
Alpine 3.20 database entrypoint. No generated configuration, database volume,
accounting records or real credentials are included. Passwords in its tests are
synthetic. The project's MIT licensing information and the vendored Chart.js license are retained in the archive.

The legacy runtime and initializer intentionally reproduce the former deployment.
Use them only for isolated migration tests, never as deployable production images.
Keep this reference frozen when modifying the current application or migrations.
Any replacement must be reviewed as a change in compatibility coverage, include
its source provenance/checksum and retain representative data-preservation and
rollback assertions. There is no fallback to the current source or a skipped test.
