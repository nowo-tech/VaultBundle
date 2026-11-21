# Concurrency

How the vault stays correct when several people, workers or retried requests write the same rows at the same time
(nowo-tech REQ-RUNTIME-003). This page lists the write paths, the conflict answers and codes, the host options and the
residual risks.

## Table of contents

- [Rules the vault follows](#rules-the-vault-follows)
- [Write paths](#write-paths)
- [Two people editing one item](#two-people-editing-one-item)
- [Conflict answers and codes](#conflict-answers-and-codes)
- [What is not a conflict](#what-is-not-a-conflict)
- [Host options](#host-options)
- [Retries](#retries)
- [Tests and CI](#tests-and-ci)
- [Residual risks](#residual-risks)

## Rules the vault follows

- **One transaction per user action.** The page actions that write several rows run in one transaction through the
  vault's write unit (`VaultWriteUnit`, built on nowo-tech/transaction-doctrine's `DoctrineTransactionRunner`): an
  item and its tags (create and edit), a scope item with its catalogue flag and tags, and a folder trash with the items
  it detaches. The other writes are one `flush()` or one SQL statement each, which the database runs in one
  transaction (see the table). Grant revocations and purges already ran in `wrapInTransaction()`.
- **Your entity manager is never flushed, cleared or reset.** The vault writes only through its own private entity
  manager (the only mode since 2.0), and a failed unit clears or recreates only that one. Changes your application left
  pending in its own manager stay pending.
- **Nested units repair themselves.** Inside an application transaction a unit is a savepoint: after a failure the
  runner rolls back to it and repairs the nesting level (savepoint-aware; a lost savepoint after a MySQL deadlock is
  never treated as success). The vault's DBAL and bulk DQL writes inside an application transaction already used their
  own savepoint (`VaultSavepoint`, 1.19).
- **Notifications after the commit.** The change events of the vault (`ITEM_CHANGED`, `FOLDER_CHANGED`,
  `TAG_CHANGED`, `GRANT_*`) dispatched during a unit are held until the unit has committed, and dropped when it fails
  (`DeferredVaultEventDispatcher`). Since 2.1.0, inside an application transaction (where the unit is a savepoint)
  they wait for the application's outermost commit, follow its released and rolled-back savepoints, are dropped when
  it rolls back, and are dispatched at the next idle point (never inside the commit call). doctrine/dbal 4 only (the
  private manager's connection hook); with DBAL 3 they are released when the unit ends. The check and veto events
  (access checks, list queries, grant policy) are dispatched at once, since their listeners answer the vault.
- **Exact keys.** Owner, creator, grantee and scope ids compare byte for byte (binary collation with
  `user_mapping: id`, post-filters on the secret, GDPR and token paths; REQ-SEC-006 / LQ rule since 1.17 and 1.19.3).
- **No I/O under a lock.** The only lock the vault takes is the item row lock of an edit (`SELECT … FOR UPDATE`),
  held while the payload is decrypted, encrypted and written; no HTTP call, mail or file is involved (an uploaded
  attachment is already in memory).
- **FrankenPHP worker mode with kernel reset off.** The write unit is stateless; the deferred dispatcher's queue lives
  only for one unit, and what it holds for an application transaction is released or dropped at the end of every
  request, message and command and emptied at the start of the next main request (and at `kernel.reset`); the commit ledger of the conflict answer is a monotonic counter
  of the process, read by difference only.

## Write paths

| Write path | Transaction | Control | Conflict answer |
| --- | --- | --- | --- |
| Item create (`VaultManageController::newItem`, `VaultScopeController::newItem`) | One unit: the item, its catalogue flag (scope) and its tags | Insert; tags are found or created per owner under the unique keys `(creator_id, name)` / `(owner_scope, name)` | Lock conflict: 409 `CONFLICT_001` / `CONFLICT_002`. A tag created at the same time by another request: 409 `VAULT_DUPLICATE_KEY` (domain), nothing saved |
| Item edit (`VaultManageController::editItem` POST) | One unit with the item row locked (`SELECT … FOR UPDATE`) | Row lock, then the edit token of the page compared with the row ([below](#two-people-editing-one-item)); the stored secrets kept are read from the locked row | Stale page: 409 `VAULT_ITEM_CHANGED` (domain), nothing saved, the current item shown. Lock conflict: 409 `CONFLICT_001` / `CONFLICT_002`. Duplicate new tag: 409 `VAULT_DUPLICATE_KEY` |
| Folder trash (`VaultManageController::trashFolder`) | One unit: the active items leave the folder, then the folder is marked deleted | Updates by id | Lock conflict: 409 `CONFLICT_001` / `CONFLICT_002`; nothing changed (the items stay in the folder) |
| Item trash / restore (`trashItem`, `restoreItem`) | One flush (one row) | Update by id | Lock conflict: 409 `CONFLICT_001` / `CONFLICT_002` |
| Item / folder purge (`purgeItem`, trash page) | One transaction: the resource and its grants (`purgeResourceWithGrants()`, 1.16) | Delete by id | Lock conflict: 409 `CONFLICT_001` / `CONFLICT_002` |
| Folder create (manage and scope) | One flush (one row) | Insert | Lock conflict: 409 `CONFLICT_001` / `CONFLICT_002` |
| Tag delete (`deleteTag`) | One flush (the tag; its links by cascade) | Delete by id, owner checked | Lock conflict: 409 `CONFLICT_001` / `CONFLICT_002` |
| Share / grant change / revoke (`shareItem`, `shareFolder`, `revoke*Grant`, `VaultGrantService`) | One statement each; a revoke of every grant of a resource in `wrapInTransaction()` | Unique `(resource_type, resource_id, grantee_type, grantee_id)` with insert-or-catch (a concurrent grant becomes an update); conditional update / delete by id (a grant purged meanwhile is inserted again once, a revoke that deleted nothing emits no event) | Lock conflict: 409 `CONFLICT_001` / `CONFLICT_002` |
| Runtime configuration save (`VaultRuntimeConfigController`, `VaultRuntimeConfigWriter`) | One flush (the settings row) | Last write wins (one admin screen) | Lock conflict: 409 `CONFLICT_001` / `CONFLICT_002` |
| Browser extension login / logout / token touch (`/api/vault/extension/*`) | One flush each (one token row) | Random token hash with a unique key; `last_used_at` touched at most once every five minutes | Lock conflict: 409 JSON `{"error", "code"}` + `X-Conflict-Code` |
| Personal data erasure (`VaultPersonalDataEraser`) | One transaction **per chunk** (grants, items, folders, tags, user references) | Delete / update by id | Rethrown to the caller (no HTTP route of the vault) |
| Payload re-encryption (`nowo:vault:reencrypt`) | One flush **per batch** | None: run it in a maintenance window ([residual risks](#residual-risks)) | Console error; the batches before it stay done and a re-run skips them |
| Expired grants purge (`nowo:vault:grants:purge-expired`, `VaultGrantExpiryService`) | One conditional `DELETE … WHERE id AND expires_at <= now` per grant, in batches | Conditional delete: two purgers emit each GRANT_EXPIRED once; a grant extended meanwhile survives | Console error; run the command again |
| Expired extension tokens purge (`nowo:vault:extension-tokens:purge`) | One bulk `DELETE` (in a savepoint inside an application transaction) | Delete by expiry | Console error; run the command again |

`flush()` in `src/`: the repositories' single-row `save()` / `remove()` (inside a write unit when a page action writes
several rows), `detachItemsFromFolder()` (inside the folder-trash unit), `saveBatch()` (one per re-encryption batch),
and the grant repository's revoke inside `wrapInTransaction()`.

## Two people editing one item

Until 1.19 two people saving the same item could silently lose a change:

- a page opened before another person's save posted every field it showed, so it wrote back the older username, URLs,
  notes, title and folder over that save;
- two saves at the same moment could both read the old row; the second write then kept the old stored password, so the
  first person's new password was lost.

Since 1.20:

- the edit page carries an **edit token** (hidden field `editToken`, a SHA-256 of the ciphertext, title, folder,
  catalogue flag and tag names; `VaultItemEditToken`). The save locks the item row and compares the token: a page
  opened before another save is refused with **409** and `X-Error-Code: VAULT_ITEM_CHANGED`, nothing is written, and
  the edit page of the current item is shown with a message. Saving that page again is a deliberate save over the
  other change;
- the save re-reads the row under the lock, so a save that waits for another one keeps the secrets that one stored
  (an empty secret field keeps the stored value from the locked row, never from an older read);
- a form that posts no token (a host form built before 1.20) is not refused; it still gets the row lock.

## Conflict answers and codes

On the routes of the vault (`VaultConflictListener`, controllers of the vault only; your own routes keep your error
handling):

| Code | When | Status | `Retry-After` | Client should |
| --- | --- | --- | --- | --- |
| `CONFLICT_001` | Deadlock, lock wait timeout or serialization failure (MySQL 1213 / 1205, MariaDB 1020, PostgreSQL 40001 / 40P01 / 55P03, anywhere in the exception chain) and nothing committed since the request started | 409 (`conflict_response.status`) | Yes: `conflict_response.retry_after`, else 1 s | Retry |
| `CONFLICT_002` | The same after a commit earlier in the request (transaction-doctrine's request mark and ledger, `ConflictCode::forRequest()`, when its bundle is registered with `commit_detection`; otherwise the vault's own ledger, fed by transaction-doctrine's commit-tracking middleware on the vault's connection, see `conflict_response.commit_tracking`), a `ConcurrencyConflict` that committed, or a lost savepoint with no deadlock behind it (outcome unknown) | 409 (`conflict_response.status`) | No | Reload, do not repeat blindly |
| `VAULT_ITEM_CHANGED` (`X-Error-Code`) | An item edit page opened before another save of that item | 409 | No | Look at the current item and apply the change again |
| `VAULT_DUPLICATE_KEY` (`X-Error-Code`) | An item save that creates a tag another request created at the same moment (unique key) | 409 | No | Submit the form again (the tag now exists) |

- The `CONFLICT_*` code is in the `X-Conflict-Code` header, which only ever holds `CONFLICT_001` or `CONFLICT_002`. The
  domain codes are in `X-Error-Code`, never in `X-Conflict-Code`.
- HTML pages are rendered by your application's error page (an `HttpException` with the status, the headers and a
  fixed, client-safe message; the storage error is its previous exception, for your logs). `VAULT_ITEM_CHANGED` is the
  vault's own edit page with a flash message.
- The browser-extension API and the JSON actions of the manage UI (reveal, password generator, attachment) answer
  their usual shape `{"error": "…"}` with the code added as `"code"`; the extension API also sends its CORS headers
  and `Access-Control-Expose-Headers: X-Conflict-Code, Retry-After` for an allowed origin.
- Clients key on `code` or the header, never on the `error` text.

## What is not a conflict

- **Unique violations** are not lock conflicts. The only one a vault page can meet under concurrency is the tag
  name, answered with the domain code `VAULT_DUPLICATE_KEY`; the grant unique key is caught by `VaultGrantService`
  (insert-or-update). Anywhere else a unique violation is a real error (500).
- **Lost connections** and any other storage error are real errors (500), never a 409.

## Host options

```yaml
nowo_vault:
    conflict_response:
        status: ~          # 409 (default) or 503; null follows transaction-doctrine's conflict_response, else 409
        retry_after: ~     # seconds of a CONFLICT_001 answer; null follows transaction-doctrine, else 1
        commit_tracking: bundle   # bundle (the connection of database.entity_manager and of every manager mapping the vault's entities), all, or off
```

- With nowo-tech/transaction-doctrine's bundle registered and `commit_detection` on (its
  `nowo_transaction_doctrine.commit_tracking_middleware` service exists), its commit ledger is reused and the vault
  adds no middleware (`VaultCommitTrackingPass`). A `CommitLedger` service without that middleware is ignored: the
  vault keeps its own ledger (audit UE3-CF-2).
- `bundle` wraps the connection of `database.entity_manager` and of every other manager whose mapping covers
  `Nowo\VaultBundle\Entity` (audit UE3-CF-1); never the host's other connections.
- The write unit runs on the vault's own manager (since 2.0 always; the 1.18 mode on yours was removed).
- A host that answers 503 for lock conflicts sets `status: 503`; the codes and headers stay the same.

## Retries

- A `CONFLICT_001` answer is safe to retry: nothing of the request was committed. A `CONFLICT_002` answer is not: reload
  first.
- The vault's write unit runs once (no automatic retry). It is a `DoctrineTransactionRunner`, not transaction-doctrine's
  replaying `WriteUnit`, so `forManagers()` does not apply: the runner only ever repairs the manager it runs on (the
  vault's private manager). A kernel test checks that a failed
  unit writes only through the vault's manager (transaction-doctrine audit UI2-1).
- Conflicts are detected through transaction-doctrine's bounded walk (`findConflict()`, `ThrowableWalk`, 1.7.1): the
  `getPrevious()` chain and Messenger's wrapped exceptions, each throwable once, at most 256. A host that aliases nowo-tech/transaction-contract's runner to
  a retrying adapter does not change the vault's unit; the item edit re-reads its row under the lock on every attempt,
  and no entity from a failed attempt is reused (the failed unit clears or detaches it).
- The vault has no Messenger handlers of its own. Its commands are idempotent by design: the re-encryption skips rows already on
  the new key, the purges delete only what is still expired.
- Principals are re-resolved on every request; nothing about the user is cached across requests (REQ-RUNTIME-002).

## Tests and CI

- `tests/E2E/VaultConcurrencyDatabaseTest.php` (group `database`): two connections (a second connection of the test,
  and a separate PHP process for the writer that commits while the request waits) on MySQL 8.4, MariaDB 11.4 and
  PostgreSQL 16, with the private entity manager:
  - a page opened before another save is refused (`VAULT_ITEM_CHANGED`) and the other save is kept;
  - a save that waits for another one keeps that one's password (no lost update);
  - a locked item answers 409 `CONFLICT_001` + `Retry-After` with nothing saved, and the retry works on the same
    connection;
  - a commit earlier in the request gives `CONFLICT_002` without `Retry-After`;
  - a folder trash that meets a lock leaves every item in the folder;
  - two requests creating the same new tag give one tag and the domain 409; saving again works.

  Every session sets `innodb_lock_wait_timeout` / `lock_timeout`, so a hang fails fast.
- Unit and kernel tests: the conflict mapping (`VaultConflictListenerTest`: every lock-conflict SQLSTATE / code, 002
  after a commit, JSON shapes, unique violations and lost connections not mapped, host routes untouched), the commit
  tracking pass, the write unit (`VaultWriteUnitKernelTest`, including a unit that leaves the application's pending
  changes pending) and the deferred notifications.
- CI job **Databases on MySQL 8.4 / MariaDB 11.4 / PostgreSQL 16 - DBAL 3 / 4** (`--fail-on-skipped`) is a required
  check, with the **Dependency floor** job (lowest nowo-tech/transaction-* versions, demo lock).
- Locally: `make test-with-db DB=mysql`, `DB=mariadb`, `DB=postgres`.

## Residual risks

- **Key rotation with writers running.** `nowo:vault:reencrypt` reads a batch, re-encrypts it and writes it
  back without a row lock: an item saved by a user between the read and the write of its batch would get its previous
  payload back. Run the rotation in a maintenance window, as [ENCRYPTION-KEY-ROTATION.md](ENCRYPTION-KEY-ROTATION.md)
  already requires (mixed keys also fail closed for readers during a rotation).
- **Runtime configuration.** Two admins saving the configuration screen at once: the last save wins (one settings row,
  one screen, admin only).
- **A deliberate save after `VAULT_ITEM_CHANGED`.** Saving the page shown with the 409 overwrites the other change with
  what is on that page (it shows the current item, so only the fields the person changes again differ).
- **Folder and tag organisation of an item edited by a grantee.** A grantee with Write changes the content only; the
  owner's folder and tags are never taken from the grantee's page (1.18, SEC-004).
- **Inside your transaction.** When you call the vault inside your own transaction, the vault's unit is a savepoint:
  its notifications are dispatched when the savepoint is released, before your commit. If you then roll back, the
  listeners have seen a change that did not happen.
- **Pending changes of your own.** The vault never flushes them (2.0): code that relied on a vault write to flush its
  own entities must flush them itself (see [UPGRADING](UPGRADING.md#private-manager-only)).
