# Changelog

All notable changes to this package are documented here, following
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

Behavior changes land here in the commit that makes them, not at tag time.

## [Unreleased]

### Added
- Gmail adapter (`GmailProvider`) and file-based `CredentialResolver`. Calls that create
  something (send, insert, draft) are never retried after an ambiguous failure.
- `mailroom:watch`: history polling, cursor-expiry resync, typing (rules then gateway),
  person corrections from label changes, gateway-outage tracking.
- Signed webhooks with backoff and `mailroom:webhooks` recovery.
- `asPdf` through Browsershot and pinned Chrome-for-Testing, safe for untrusted mail HTML.

### Fixed
- A failed label change no longer leaves a write record that could swallow a person's
  later correction.
