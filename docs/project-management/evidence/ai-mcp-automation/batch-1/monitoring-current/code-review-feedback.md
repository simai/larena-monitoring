# Code Review Feedback

Accepted clarifications:

- Health statuses distinguish healthy, degraded, down, unknown and muted states.
- Health diagnostics must be sanitized before incident opening or operator display.
- Metric descriptors carry type, tags, thresholds and retention policy refs.
- Raw metric retention must be bounded.
- Incidents correlate repeated signals and track active/resolved lifecycle.
- Dashboard snapshot is a contract, not admin UI implementation.

Deferred to implementation planning:

- `monitoring.yaml` schema examples and descriptor validation fixtures.
- Metric storage, aggregation and cleanup strategy.
- Incident workflow depth and notification request handoff.
- External observability export boundaries.
- Admin dashboard DTOs and screen-level contracts.

No canonical graph/spec promotion is required from this batch.
