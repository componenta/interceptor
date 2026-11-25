# Interceptor benchmarks

Run `composer bench:smoke` to check the fixture results, or `composer bench` for measurements.

The suite compares direct PHP and DI calls against empty and populated pipelines, native/factory attributes, short-circuiting, nesting, DI parameter preparation, prepared argument edits, native and prepared magic dispatch (including logical edits) and context copying. Each subject must return the same value (7); fixtures reject an incorrect result outside the measured operation.

The first-invocation subjects create a new pipeline and clear Reflection's public cache before timing one invocation. Repeated subjects warm the same pipeline and target, then measure steady-state calls. Container construction is outside the timed operation. All subjects use an operation closure so their harness overhead is shared.

Compare results on the same PHP patch, OPcache configuration, hardware and dependency revisions. Record the PHPBench environment report alongside measurements. Do not compare absolute timings from different environments. The suite does not claim a memory-leak proof or a particular performance improvement.

The Verify workflow can run the complete measurement and line coverage on demand: enable the `audit` input when dispatching it or include `[audit]` in a pushed commit message. Regular checks only run the smoke suite. The coverage driver is enabled after PHPBench completes to avoid instrumenting the measurements.
