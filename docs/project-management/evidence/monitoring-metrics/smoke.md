# Smoke

On larena.test, after the Root assembly carries the package: the migration runs, a package call recorded through
`Metrics` appears in `larena_monitoring_samples` after the request, `php artisan larena:monitoring:rollup` builds
rollups and `php artisan larena:monitoring:report --json` lists the declared metrics with verdicts.
