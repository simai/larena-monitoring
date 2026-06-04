# Tests

Package-local checks required for this batch:

- `composer validate --strict`
- `composer dump-autoload`
- `composer run validate:larena`
- `composer run lint`
- `composer run analyse`
- `composer run test`
- `composer run quality:gate`
- `git diff --check`
- `.githooks/pre-commit`
- `.githooks/pre-push`

Semantic coverage:

- health status can distinguish degraded/down/unknown operational problems;
- unsanitized health status cannot open an incident;
- metric descriptors carry type, tags, thresholds and retention refs;
- raw sample retention is bounded;
- active incidents are open or acknowledged only.
