# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Scope

This is one XenForo add-on — `Hampel/ApprovalQueuePlus`, "Approval Queue Plus". It puts enough
detail about a pending registration into the Approval Queue that a moderator can decide without
opening each account, and it can reverse the queue's default sort order. It sits at
`src/addons/Hampel/ApprovalQueuePlus/` inside a XenForo install and is its own git repository; the
install around it is not.

Where the install has an `AGENTS.md` at its root, read it first — it carries the XF conventions,
the `_output`/`_data` boundary, the `cmd.php` command signatures and the per-add-on git layout.
This file covers only what is specific to this add-on.

## Commands

Run them from this directory — it is the git repo. `cmd.php` resolves the install from its own
location rather than from the working directory, so the relative path works unchanged.

```bash
composer install                                  # first time; vendor/ is gitignored
vendor/bin/phpunit                                # whole suite
vendor/bin/phpunit --testsuite Unit               # one suite
vendor/bin/phpunit --filter CloudflareLocationTest # one class

php ../../../../cmd.php xf-dev:import --addon=Hampel/ApprovalQueuePlus   # _output/ -> database
php ../../../../cmd.php xf-addon:export Hampel/ApprovalQueuePlus         # database -> _output/
php ../../../../cmd.php xf-addon:build-release Hampel/ApprovalQueuePlus  # release only
```

**The Composer dependencies are dev-only and `addon.json` declares no `composer_autoload`.** That
is deliberate rather than an omission: an add-on that declares it registers its whole `vendor/`
onto XenForo's class loader for every add-on on the install, which is how one add-on's vendored
PHPUnit kills another add-on's test run. Nothing here is needed at runtime, so nothing is
declared.

`tests/TestCase.php` sets `$addonsToLoad = ['Hampel/ApprovalQueuePlus']`, so the suite boots a
real XF app with only this add-on active.

**`build.json` strips `vendor/`, `tests/`, `phpunit.xml`, the Composer manifests, `TESTING.md` and
both Claude files from the build output**, then moves every remaining root `*.md` up to the zip
root. Two things about that are load-bearing:

- **The `rm` lines have to stay before the `mv`.** After it, the files they name have already been
  renamed to the zip root, and `rm -f` reports nothing while they ship anyway.
- **`vendor/` would otherwise ship.** XenForo's builder excludes `_*` directories and dotfiles;
  `vendor` is neither, so the whole dev tree — PHPUnit included — lands in the release unless the
  `rm` names it.

## One row per registration, written from a single service extension

Everything displayed comes from `xf_aqp_user_data`, and exactly one thing writes to it: the
`XF\Service\User\Registration::_save()` extension, which creates the row immediately after
`parent::_save()` returns the user. Columns are `user_agent` (from the request), `iso_code` (the
Cloudflare country code) and `cf_location` (a `JSON_ARRAY` holding whatever Cloudflare headers
were present).

So a row exists only for accounts that registered through the normal registration service while
the add-on was installed. Accounts created in the ACP, imported, or registered before installation
have none, and every consumer has to cope with that: the `AqpData` relation added to
`XF\Entity\User` is a `TO_ONE` that can be null, and the display macro guards with
`$user.AqpData AND ...` ahead of every field. Keep that guard when adding one — 3.4.0 exists
because a missing row was a fatal.

Rows leave by two routes: user deletion, through `Listener::userDeleteCleanInit()` on the
`user_delete_clean_init` event, and the prune below.

## Cloudflare data arrives only if Managed Transforms is on

`SubContainer\Cloudflare` is registered as the `aqp.cloudflare` container key by
`Listener::appSetup()` on `app_setup`. `getCloudflareLocation()` walks the fixed header-to-key map
held in `cf.headers` and keeps only the headers actually present, so an install behind no proxy
simply stores an empty array.

Cloudflare sends those headers only when *Add visitor location headers* is enabled under
`Rules > Transform Rules > Managed Transforms` in the Cloudflare console. A correct install with
that switch off records nothing and looks broken. The ACP page under *Checks and Tests* —
`XF\Admin\Controller\Tools::actionHampelAqpShowCfLocation`, admin permission `option` — dumps what
arrived on that request and exists to answer exactly that question.

`Data\CountryCodes` turns `country_code` and `continent_code` into names, falling back to the raw
code, and is reached as an add-on data object rather than instantiated.

## The clean-up cron is not daily, whatever it is called

`Cron\CleanUp::runDailyCleanup()` returns early unless `Option\UserDataCleanUp::isEnabled()`, then
calls `Repository\UserData::pruneUserData()`, which deletes rows whose user is now `valid` and
registered on or before `\XF::$time - delay * 86400`. Two things about it are not what the names
suggest:

- **It runs monthly.** `_output/cron_entries/approvalQueuePlusCleanup.json` has `day_type: dom`,
  `dom: [-1]` — the last day of each month, at 04:26 — while the entry and the method are both
  named for a daily run. Renaming the method would be an artifact change as well as a code one;
  the schedule is the thing to read, not the name.
- **`getDelay()` returns `0` for a non-numeric value**, which puts the cut-off at *now* and prunes
  every approved user's row. Reachable if the delay box is cleared while the option stays on.

The guard belongs in the cron method rather than in the repository: `pruneUserData($cutOff)` takes
an explicit cut-off so it can still be called deliberately, and the option is about the scheduled
path.

## Display is four template modifications, and two of them are fragile

They are the whole user-facing surface, and a XenForo upgrade breaks them silently: the queue
renders, the extra information is just absent.

| modification | template | what it does |
|---|---|---|
| `approvalQueuePlusRemoveTopInfo` (order 9) | `approval_item_user` | empties `$headerPhraseHtml` |
| `approvalQueuePlusApprovalItemUser` (order 10) | `approval_item_user` | swaps in our macro |
| `approvalQueuePlusCss` | `approval_queue.less` | appends `.approvalQueuePlus` rules |
| `approvalQueuePlusPageContainerReverse` | `PAGE_CONTAINER` | rewrites the queue link |

The second is the one to understand. Its `preg_replace` matches XF's call to
`custom_fields_macros::custom_fields_view` in **either** form — the `template=`/`name=` pair and
the `id="custom_fields_macros::custom_fields_view"` form XF 2.3 emits — and replaces it with
`approval_queue_plus_user_macros::user_info`. That macro **calls the custom-fields macro again at
its own end**, so the fields it displaced still render; drop that call and registration custom
fields vanish from the queue.

`approvalQueuePlusPageContainerReverse` is a plain `str_replace` on the literal
`{{ link('approval-queue') }}`, wrapping it in a conditional that appends `order`/`direction` when
`approvalQueuePlusDefaultOrder` is `desc`. An exact-string modification against a core template is
the most brittle thing here; check it first after any XF upgrade.

Three visibility gates apply inside the macro, and they are separate permissions: email needs
`canBypassUserPrivacy()`, the registration IP needs `canViewIps()`, and the user agent needs
`canViewUserAgents()` — the one permission this add-on defines,
`general/hampelAqpViewUserAgents`, added by the `XF\Entity\User` extension. It was
`general/viewUserAgents` until 3.6.0; `Setup::upgrade3060011Step1()` renames it in place so that
existing grants move with it, which is a thing XenForo does for you and only if you rename rather
than replace.

## Setup carries three table names, and the branches are the upgrade history

The table has been `xf_user_agent`, then `xf_aqp_user_agent`, then `xf_aqp_user_data`, and 3.5.0
shipped a bug that renamed it without adding the new columns. `upgrade3050170Step1()` therefore
branches over all four possible starting states, including the broken one it tests for with
`columnExists('xf_aqp_user_data', 'iso_code')`. **Do not collapse or renumber those branches** —
each is reachable from a version still in the wild.

`postUpgrade()` calls `enqueuePostUpgradeCleanUp()` only on XF 2.3+ (`\XF::$versionId >= 2030000`),
because the method does not exist below that.

`addon.json` declares `"legacy_addon_id": "ModeratedUsers"`: this add-on upgrades in place from the
XF 2.0 add-on of that name.

## Versioning and packaging

`addon.json` carries `version_id` and `version_string` and they must be bumped together — the
`abbccde` encoding is documented beside `XF::$versionId` in `src/XF.php`, where `d` is stability
(alpha 1, beta 3, RC 5, stable 7) and `e` the level within it — so `3050370` / `"3.5.3"` is stable
level 0 and `3050411` / `"3.5.4a1"` is alpha 1 of the next patch. `version_id` drives upgrade
ordering; `version_string` only names the zip and shows in the ACP, so a mismatch survives
unnoticed.

**Open a development cycle by bumping to the next patch alpha before the first change**, with
`xf-addon:bump-version`, which writes `addon.json` and the installed record together. Until that
happens the branch carries the last release's version string, so any build on it overwrites that
release's zip — the filename comes from the version string alone — and `xf:addon-list` reports the
install as running a released version while it runs branch code.

**`addon.json` has no trailing newline, and that is the format.** `json_hash` comes from
`hashTextFile`, which strips `\r` but not `\n`, so adding one leaves the hash stale and the ACP
reports the add-on as modified until `xf-addon:sync-json` runs.

**The only declared floor is XF 2.2.0 (`2020070`), and there is deliberately no `require.php`.**
XenForo states its own PHP minimum per release — 7.0 for 2.2, 7.2 for 2.3 — so an add-on floor
below that declares a combination that cannot exist. Declare one only if the add-on genuinely
needs more PHP than its XF floor already guarantees.

The XF floor is set by what can be tested, not by what the code uses: nothing here needs 2.2, but
2.1 cannot be verified against any available install. The class-extension `from_class` names are
the pre-2.3 spellings, which is what a 2.2 floor requires — XF 2.3 aliases them forward, while 2.2
has no alias mechanism at all, so adopting the 2.3 names would be a floor change whether or not
`addon.json` said so.

Phrases are prefixed `hampel_aqp_`, with one survivor from before the convention:
`approval_queue_plus_user_agent`. Leave it — renaming a phrase loses any customisation an
installed forum has made.
