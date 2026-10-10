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
real XF app with only this add-on active. **The suite needs framework 5.20 or later, and the
constraint says so**, because three of its releases each fixed something that made a test here
pass while proving nothing:

- **below 5.0** this add-on's extensions on pre-2.3 class names were silently not applied in an
  isolated suite, so a test of the registration write path ran XenForo's own class;
- **below 5.4** a render had no `$xf` parameter, and every permission check in the queue macro
  reads `$xf.visitor` — the gated rows rendered their else branch and `UserInfoMacroTest`
  tested nothing;
- **below 5.7** `UsesDatabaseTransactions` rolled back nothing when the code under test had
  committed, leaving the rows in the forum. Any DDL commits implicitly, including the `TRUNCATE`
  XenForo runs when it compiles a template — which this suite provokes, for the reason in the
  next paragraph.

5.6's `assertNoUnresolvedPhrases()` and 5.19's `createUserAccount()` and
`actingAsAdministrator()` are called directly, so a lower resolve fatals rather than misleads.
`TESTING.md` lists what each Feature test settles.

**On a development install the macro tests render the `_output/` copy of a template, not the
database's.** XenForo's development template watcher compares the compiled template's
`FROM HASH` with the `_output/` file on every render and re-imports the file when they differ. So
an edit to a template's `_output/` file is tested with no import, and an edit made only in the
database — a mutation, say — is silently reverted at render time and the test sees the original.
Mutate the `_output/` file, and restore it and `_metadata.json` afterwards.

**That is templates only.** Only the template handler is attached as a watcher, so an edit under
`_output/template_modifications/` is not seen until `xf-dev:import` — and this add-on's four
modifications are its most fragile part. Import before running `TemplateModificationsTest` after
changing one, or it reports on the previous version.

**`tests/Feature/RegistrationWritesUserDataTest` registers real users** through the real service,
because the registration extension is the only writer of `xf_aqp_user_data`. It runs inside
`UsesDatabaseTransactions` and leaves nothing behind, but it does write to the forum the suite
points at.

**It needs strict SQL mode to be satisfiable on that forum, and that is deliberate.** An isolated
test application loads only this add-on, so a `NOT NULL` column with no default that some other
add-on has added to a core table is absent from XenForo's `INSERT`, and strict mode rejects the
registration before any of this add-on's code runs. The failure is
`MySQL query error [1364]`, naming that column. The suite dropped strict mode for its own
connection until 2026-10-10 to get past exactly that; it no longer does, because the column is the
other add-on's defect and absorbing it here hides the next one. The error names the column, which
names the add-on to report it to.

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

**None of the three is required, and `user_agent` is the one that matters.**
`Request::getUserAgent()` returns `false` when the header is absent, so making it required made
the row fail validation and took the whole registration with it — which anything scripted met and
no browser ever did. An empty value means the client sent no header, and the display macro omits
the row.

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

## The third listener declares that there is nothing to hide

`Listener::adminApiRedaction()` answers the `hampel_admin_api_redaction` event with an empty entry
keyed by this add-on's id. The add-on that defines that event returns a forum's option and
`config.php` values with credentials removed, and it withholds the *text* settings of any add-on
that has not declared — so an empty declaration is what moves this add-on's settings from "set" to
shown, rather than hiding anything. Neither option is a credential: one is a queue sort order, the
other an on/off switch and a number of days. The add-on reads no `config.php` keys at all.

**It is not a dependency and must not become one.** An event that no installed add-on defines is
never fired, so on a forum without that add-on the listener is inert — nothing goes in
`addon.json`'s `require`, and nothing in the method refers to that add-on's namespace or classes.

`SecretsDeclarationTest` checks the declaration against `_output/options/` rather than against
itself, because the way this breaks is silent: renaming an option, or adding a credential later,
leaves a declaration that still passes while publishing a value. Every assertion in it sits
outside its loop — with an empty declaration the loops run zero times, and a test whose only
assertions are inside one asserts nothing while counting as coverage.

## The clean-up cron runs daily, and `dom: [-1]` is why

`Cron\CleanUp::runDailyCleanup()` returns early unless `Option\UserDataCleanUp::isEnabled()`, then
calls `Repository\UserData::pruneUserData()`, which deletes rows whose user is now `valid` and
registered on or before `\XF::$time - delay * 86400`.

**`dom: [-1]` means "any day", not "the last day".** XenForo's own docblock on
`XF\Service\CronEntry\CalculateNextRunService::calculateNextRunTime()` states the convention:
*"-1 means 'any', any other value means on those specific occurances"*. So
`_output/cron_entries/approvalQueuePlusCleanup.json` — `day_type: dom`, `dom: [-1]`, hour 4,
minute 26 — schedules the prune **every day at 04:26**, and the method's name is right. Confirmed
against that service: from midday on a 10th it answers the 11th and then the 12th, where
`dom: [31]` answers the 31st.

This file said it ran monthly until 2026-10-10, which read as a deliberate finding and was simply
a misreading of `-1`. `dom` is validated against the range 1 to 31 in
`XF\Entity\CronEntry::verifyRunRules()`, so `-1` is not a day of the month at all — and XenForo
uses `['-1']` as the default for a new entry, which is a daily one.

**A delay of `0` means "do not prune", not "prune now".** `getDelay()` returns `0` for a missing
or non-numeric value — a cleared delay box — and the cut-off that produces is *now*, which would
take every approved user's row. The ACP spinbox has a minimum of 1, so `0` is never deliberate;
the cron returns early below 1. `getDelay()` still reports the option faithfully, and
`pruneUserData($cutOff)` still honours a cut-off passed to it.

**The daily schedule is what sets the stakes on that guard.** Before it, a forum whose delay box
was empty lost every approved user's recorded data on the next run — which was the next morning,
not the end of the month, and again each morning after as new rows accrued. The guard belongs in
the cron method rather than in the repository: `pruneUserData($cutOff)` takes an explicit cut-off
so it can still be called deliberately, and the option is about the scheduled path.

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

### The legacy upgrade runs the steps, not `install()`

`addon.json` declares `"legacy_addon_id": "ModeratedUsers"` — the **XF1** add-on, unprefixed, which
this one upgrades in place from. `AddOn::__construct()` matches an uninstalled add-on to an
installed record carrying that id, so on such a site the "installed version" is the XF1 add-on's
`version_id`, and XenForo runs the **upgrade** path. `install()` never fires.

That decides which code creates the table, and it is not the obvious one:

- The XF1 add-on declared `version_id="1"` and no install callback, so it had no schema at all — it
  was template modifications only.
- `StepRunnerUpgradeTrait` resumes at installed `version_id + 1`, so from `1` **every** step runs.
- `upgrade3050170Step1()` finds none of the three historical table names and falls to its final
  `else { $this->createTables(); }`. **That branch is the only thing that creates the table on a
  legacy upgrade.** Deleting it as redundant — "`install()` handles a fresh install" — would leave
  a converted XF1 site with no table, and the add-on fatals on the first registration.
- `upgrade3060011Step1()` finds no `general/viewUserAgents` permission and returns early, which is
  correct: the add-on's own data import creates the prefixed one afterwards.

`preUpgrade()` renames the installed record to this add-on's id, and
`DataManager::updateRelatedIds()` rewrites `addon_id` on every artifact type **before** the steps
run — so a step that filters on `addon_id` still matches on this path.

## Two self-check commands, and why an add-on this small has them

`approval-queue-plus:config` prints what this forum is configured to show and what it has
recorded; `approval-queue-plus:validate` proves it can. Both are read-only — this add-on sends
nothing, and the one destructive thing it owns is counted rather than run — so `--unattended` is
accepted for a uniform monitor command line and changes nothing.

| | all fine | warnings only | any failure |
|---|---|---|---|
| `validate` | 0 | 0 | 1 |
| `validate --strict` | 0 | 2 | 1 |

**The reason it earns them is the clean-up, not the options.** Of the three questions that decide
this, only one is a yes: nothing here is opaque, but the prune is unattended work that deletes
rows, every morning. And the guard added in 3.6.2 made its worst misconfiguration *safe* by
returning early, which also made it **silent** — a forum whose delay box is empty now has a
clean-up that will never run, with nothing anywhere saying so. `validate` reports that as a
`[fail]`, and an add-on with the clean-up switched off entirely as a `[warn]`, because nothing is
broken and every recorded user agent and IP is being kept for ever.

**The Cloudflare check is the other half of the case.** It runs the real header map against a
synthetic request carrying all ten headers as Cloudflare spells them, which is the only thing
short of a live zone that can catch a misspelt header name — a CLI request and a development forum
both record nothing either way, which is exactly how one went unnoticed through several releases.
Reintroducing that misspelling now produces `a synthetic Cloudflare request produced 9 of 11
values - missing continent_code, continent` and exit 1.

### What this costs, and what it constrains

**The add-on now declares `require.php` 7.4**, which is above what XenForo 2.2 itself enforces
(7.0). `Cli/RendersReport.php` uses typed properties, and the version-support policy's second rule
— drop a version the moment supporting it needs a conditional — makes stripping them the wrong
answer. So a forum on XenForo 2.2 with PHP 7.0 to 7.3 can run 3.6.2 and not this. That is a
support change and belongs in the CHANGELOG as one, not as a fix. `README.md` says the same, and
`composer.json` carries no runtime `require` at all, so there is no platform pin to keep in step —
nothing ships from it.

**`Cli/RendersReport.php` is a copy, not a dependency.** It is `Hampel/Monolog`'s file with the
namespace changed and nothing else, and its method names are those of `hampel/console-report`.
Do not improve it here: correct it there and take it again. It cannot be the package because
that needs PHP 8.3, and it cannot be a shared add-on because XenForo puts every add-on's Composer
classes into one loader and the first registered wins.

### Three traps, each fatal for every command on the forum

XenForo loads every add-on's command classes simply to list them, so one that cannot load stops
`cmd.php` for every add-on — not only this one. `tests/Unit/CommandClassesTest.php` covers all
three:

- **Extend Symfony's `Command`, never `XF\Cli\Command\AbstractCommand`**, which
  `xf-make:cli-command` scaffolds and which does not exist on XenForo 2.2.
- **Never name a helper `run()`** — it collides with `Command::run()`, which is public and is what
  Symfony calls.
- **Never write a grey colour tag.** XenForo 2.2 ships its own fork of `symfony/console` that
  knows only the eight basic colours and throws on `gray`. Grey goes through
  `RendersReport::muted()`, which falls back.

**Neither command has been run on XenForo 2.2 yet.** Reading 2.2's source is not the same check —
the colour trap survived exactly that in another add-on — so a 2.2 sandbox run belongs in the
release, and `--strict`'s exit code has to be read through `docker exec` rather than `xf-cli`,
which does not return it.

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
