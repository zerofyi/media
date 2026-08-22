# Contributing to zerofyi/media

Thank you for considering a contribution. Here's how to get started.

## Setup

```bash
git clone https://github.com/zerofyi/media.git
cd media
composer install
```

## Running Tests

```bash
composer test           # full suite
composer test:unit      # unit tests only
composer test:feature   # feature tests only
```

Tests use an in-memory SQLite database and a faked Laravel filesystem disk — no external services required.

## Requirements before opening a PR

- All existing tests must pass.
- New behaviour must be covered by tests.
- Follow the existing `declare(strict_types=1)` and `final` class conventions.
- One concern per PR — bug fixes and features in separate PRs please.

## Reporting Issues

Open an issue at https://github.com/zerofyi/media/issues with a minimal reproduction case.