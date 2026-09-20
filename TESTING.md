# Testing Approval Queue Plus

What this add-on touches, what breaks quietly, and which checks a person still has to do by
hand. The automated half is `vendor/bin/phpunit`; the rest of this file exists because most of
what this add-on does is rendered inside someone else's template, behind a moderator login, from
headers only a real proxy sends.

## Surfaces

Everything below is declared in `_output/`. Regenerate the inventory from there if it drifts.

| Surface | What it does |
|---|---|
| `XF\Entity\User` extension | adds the `AqpData` relation and `canViewUserAgents()` |
| `XF\Service\User\Registration` extension | the **only** writer: creates the row in `_save()` |
| `XF\Admin\Controller\Tools` extension | the ACP page that dumps the Cloudflare headers |
| `app_setup` listener | registers the `aqp.cloudflare` sub-container |
| `user_delete_clean_init` listener | removes the row when the user is deleted |
| `approval_item_user` × 2 | empties the header phrase, then swaps in this add-on's macro |
| `approval_queue.less` | appends the `.approvalQueuePlus` rules |
| `PAGE_CONTAINER` | rewrites the approval-queue link to carry a sort order |
| cron entry | prunes rows for users who have since been approved |
| options × 2 | default queue order; clean-up on/off plus a delay in days |
| permission `general/viewUserAgents` | gates the user agent row |
| admin navigation | the *Checks and Tests* entry for the Cloudflare page |
| table `xf_aqp_user_data` | `user_agent`, `iso_code`, `cf_location`; dropped on uninstall |

## Fragile points

**All four template modifications fail silently.** The queue still renders; the extra information
is simply absent. Nothing logs it, so an XF upgrade can remove the feature without removing the
add-on.

- **`PAGE_CONTAINER` is an exact `str_replace`** on the literal `{{ link('approval-queue') }}`.
  Any reformatting of that line in core breaks it. It is the most brittle thing here — check it
  first after any XenForo upgrade.
- **The `approval_item_user` swap matches two forms of the same macro call** — the
  `template=`/`name=` pair and the `id="custom_fields_macros::custom_fields_view"` form XF 2.3
  emits. A third form would break it on that version only, so testing one XenForo version proves
  nothing about the other.
- **The replacement macro calls the custom-fields macro again at its own end.** It displaced that
  call and has to put it back; drop it and registration custom fields vanish from the queue.
- **The Cloudflare header map is fixed strings against a third party's header names.** A
  misspelling costs one row and looks like an install that simply has no such data —
  `HTTP_CF_IPCONTINTENT` sat in that map from 3.5.0 to 3.5.3. The suite now walks all ten.
- **A row exists only for users who registered through the registration service** while the
  add-on was installed. Accounts created in the admin panel, imported, or registered earlier
  have none, and every consumer has to tolerate that.

## Automated

```bash
composer install
vendor/bin/phpunit                                  # whole suite
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --filter CloudflareLocationTest
```

**Read the per-suite counts, not just the exit code.** A test file whose name does not end
`Test.php` is never collected, and the run still reports `OK`.

**Confirm the template modifications still apply**, which no unit test can — XenForo records an
apply count per modification when it compiles a template:

```sql
SELECT m.modification_key, m.template, l.apply_count
FROM xf_template_modification AS m
LEFT JOIN xf_template_modification_log AS l ON (l.modification_id = m.modification_id)
WHERE m.addon_id = 'Hampel/ApprovalQueuePlus'
ORDER BY m.template, m.execution_order;
```

Four rows, apply counts 1, 1, 1 and 2. A zero or a missing log row means the modification no
longer matches the core template.

**Check the declared XF floor against an older install**, without installing anything there: load
that install's app, read its core templates, and run each modification's `find` against them.
`_output/template_modifications/public/*.json` holds the patterns. The match counts should equal
the apply counts above. Do this whenever `require.XF` changes or a supported XenForo version is
released.

## Needs a human

None of these can be settled from a shell, and the first two are the add-on's whole purpose.

1. **The queue display.** Enable manual approval in the user registration options, register an
   account, and open the approval queue as a moderator. Expect username, email, joined, last
   activity, registration IP, location, time zones and user agent — each one subject to the
   viewing moderator's permissions, which is itself worth checking with a second, less privileged
   moderator.
2. **Real Cloudflare headers.** The suite proves the map matches what Cloudflare *documents*, not
   that Cloudflare sends it. Only a registration through a real Cloudflare zone with
   *Rules → Transform Rules → Managed Transforms → Add visitor location headers* enabled settles
   that, and the add-on's own ACP page under *Checks and Tests* is the place to look — it dumps
   whatever arrived on that request.
3. **The sort-order option.** Set *Default Queue Order* to descending, reload the forum index, and
   confirm the approval-queue link has gained the order and direction parameters.
4. **The upgrade path from the currently published version**, not from the last tag — they are not
   the same thing. Install the published zip into a forum that is *not* a development install,
   then upgrade it with the new one.
5. **The post-upgrade clean-up job.** `Setup::postUpgrade()` queues XenForo's file clean-up on XF
   2.3 and later. An upgrade that reports success proves only that the extraction worked; run the
   job queue afterwards and re-check the site and the server error log.
6. **The prune, against data that is old enough to prune.** A development forum usually has none,
   so the cron does nothing and reports nothing — which looks exactly like success.
