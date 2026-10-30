# Changelog

All notable changes to this package are documented here, following
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

Behavior changes land here in the commit that makes them, not at tag time.

## [Unreleased]

## [0.3.1] - 2026-10-30

### Added
- `mailroom:replay`: ask a type set about messages with known answers and report agreement,
  changing nothing.

## [0.3.0] - 2026-10-30

### Added
- `mailroom:announce {typing}` re-fires a typing whose listener failed.
- `MessageUnreadable`: a message the provider permanently refuses is skipped instead of
  stopping the mailbox.
- A person can rescue a message from Spam or Trash and it gets typed.
- Partial unique index: at most one current typing per message and type set.

### Changed
- `partId` on attachments is Gmail's stable part id; `attachment()` re-resolves it.
- An image counts as embedded only when the HTML references it by `cid:`, it isn't marked
  `attachment`, and it is under 100 KB.
- Permanent OAuth refusals (`invalid_client`, 403 `insufficientPermissions`) disconnect the
  mailbox so a person is told.
- A gateway answer outside the type set is retried three times, then the message goes to a
  person; it no longer counts as an outage.
- Gateway state lists all attachment names (`Attachments:`), not only PDFs.
- Resync skips drafts, spam and trash.
- Requires `ext-posix`.

### Fixed
- asPdf: mail that merely mentions `file:/`, `view-source`, `//localhost` or `//127.` as text
  renders; a timeout kills the whole Chrome tree; a Subject containing `endobj` no longer
  breaks the date stamp.

### Removed
- `watch.interval_seconds` config key (read by nothing).

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
