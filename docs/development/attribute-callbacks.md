# Callback development and verification

## Dependency target

The package targets PHP 8.5.13+, DI 6, Config 4, Reflection 3, and Scope 1. Production `composer.json` expresses those semantic versions. The feature branch has its real `4.0.x-dev` alias; the DI compatibility branch has its real `6.0.x-dev` alias.

The verified DI source is [`e4f19ab9a9f6bd18da85fc52c8d478433a32baec`](https://github.com/componenta/di/commit/e4f19ab9a9f6bd18da85fc52c8d478433a32baec), based on the current parameter-resolution contract and updated to accept Config 4. Released dependencies resolved to Config 4.0.0, Reflection 3.0.0, Scope 1.0.0, and Validation 3.1.0. No old major is relabelled as a new one.

## Reproduce from a clean checkout

Run these commands from this package's feature-branch checkout with PHP 8.5.13+ and Composer:

```sh
git clone --no-checkout https://github.com/componenta/di.git .dependencies/di
git -C .dependencies/di switch -c fix/callback-dependency-baseline-2026-11-26 e4f19ab9a9f6bd18da85fc52c8d478433a32baec
php tools/development-composer.php
COMPOSER=.composer-development.json composer update --no-interaction --prefer-dist
composer test
composer analyse
composer bench:smoke
```

The helper writes an ignored development manifest and leaves the tracked manifest unchanged. It checks package identity, uses a real path repository and explicit root development constraint, and preserves normal platform and dependency validation. Its optional first argument can select an existing DI checkout on the same branch. CI checks out that branch and performs the same installation on PHP 8.5.13 and the latest PHP 8.5 patch.

## Observed verification

On PHP 8.5.13 and Composer 2.11.2:

- The 11 new callback contract cases failed on the baseline and passed after implementation.
- The complete package suite passed: 208 tests, 462 assertions.
- PHPStan at maximum level passed for production code and public consumers with concrete result callback types. The consumer first reproduced four incompatible PHPDoc diagnostics, then passed after the documentation was aligned with native invocation.
- PHPBench smoke passed all 60 first/repeated invocation variants, including both new callback attributes.
- The serializer package's real DI/attribute/Symfony integration also passed using this source tree.

The callback cases verify continuation count/control, exact semantic inputs, error identity, mixed native callback ordering, skipped inner factory creation, repeated materialization, native function references, and private static scope for two classes sharing a trait. Existing ownership, scopes, native declaration errors, and factory integration tests remain active preservation controls.

### Follow-up verification

The dependency baseline incorporates the newer DI main contract, which rejects parameter resolution until container assembly has completed, including after failed finalization. The package uses the published merged source pinned above.

Targeted negative controls skipped `After` and `InterceptCallback` execution in isolated source copies. The existing public callback tests detected both violations; unmodified controls passed. A native DI pipeline combining `After` with `Serialize` produced the current tenant's result on three repeated calls and released all nine observed request, target, and invocation-context weak references after completion.

## Focused performance observation

The initial implementation measurement below predates the follow-up DI update. The follow-up run used the smoke suite to verify the benchmark scenarios.

The PHPBench repeated-invocation comparison used 10,000 revolutions, five iterations, and 1,000 warmup revolutions on the same Linux x86-64 / PHP 8.5.13 process configuration, with Xdebug and CLI OPcache disabled:

| Scenario | Reported mode | Relative standard deviation |
|---|---:|---:|
| Existing native attribute | 10.827 µs | 5.45% |
| `After` | 12.270 µs | 9.81% |
| `InterceptCallback` | 11.616 µs | 1.77% |

These are local overhead observations, not a production latency target or evidence of a speedup. The callbacks reuse the existing runtime adapters and metadata path. Reproduce on the same environment when evaluating performance changes:

```sh
php vendor/bin/phpbench run --filter=benchRepeatedInvocation \
  '--variant=^attribute-(native|after|callback)$' \
  --revs=10000 --iterations=5 --report=aggregate --report=env
```
