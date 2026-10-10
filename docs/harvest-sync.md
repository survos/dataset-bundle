# Harvest sync (consuming apps)

An app that reads folios from Harvest enables the sync instead of writing its own:

```yaml
# config/packages/survos_dataset.yaml
survos_dataset:
    harvest_sync:
        scope: folio_sets          # or "all": every published dataset
        # lock_factory: app.harvest_sync_lock_factory   # PostgreSQL advisory store in production
```

It needs folio-bundle with `dataset_api` enabled (`HARVEST_SERVER`, `HARVEST_READ_TOKEN`).

- `harvest:sync --full` fetches the whole catalog; `harvest:sync` replays Harvest's change feed
  from the stored cursor. Both end in the same local state.
- `harvest:sync:status` shows source, cursor, last success and folios held.
- The `harvest` schedule runs `harvest:sync` every five minutes; consume it with
  `messenger:consume scheduler_harvest` (one replica). `schedule: false` leaves it out.

Selection: scope `folio_sets` holds the members of `survos_folio.folio_sets` (translated variants
included), scope `all` every dataset. A selected folio is pulled when its revision changes;
with `survos_folio.local_passthrough`, or when Harvest publishes no archive, the file already on
disk is the answer. When folios or set membership change, the sync runs `folio:sets:sync`.

App-specific work listens to `Survos\DatasetBundle\Event\HarvestSyncedEvent` (entries, receipts,
`changed`). It is dispatched before the checkpoint is saved: a listener that throws leaves the
checkpoint unchanged and the next run retries.

The checkpoint is the `harvest_sync_checkpoint` table (`Harvest\Entity\HarvestSyncCheckpoint`),
mapped into the app's default entity manager. Like other shared tables, the app's own migration
creates it:

```sql
CREATE TABLE harvest_sync_checkpoint (id VARCHAR(32) NOT NULL, checkpoint JSON NOT NULL, PRIMARY KEY (id))
```

Harvest itself leaves `harvest_sync` off; nothing here is registered unless it is enabled.
