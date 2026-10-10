# CHANGELOG

## 3.6.3 (2026-10-10)

- now requires PHP 7.4 or later — above the PHP 7.0 that XenForo 2.2 itself requires, and needed
  by the new command line checks below. XenForo 2.3 already requires PHP 7.2, so this affects only
  a XenForo 2.2 forum still running PHP 7.3 or older
- new: two command line checks. `approval-queue-plus:config` prints what the Approval Queue is set
  up to show and what has been recorded; `approval-queue-plus:validate` checks that it all works —
  that the queue's template modifications still apply, that the Cloudflare header map still
  produces a location, that the *View User Agents* permission is present, and that the scheduled
  clean up will run when it says it will. Neither command writes, deletes or sends anything, so
  both are safe to run on a live forum
- `approval-queue-plus:validate` exits 1 when a check failed and 0 otherwise, and `--strict` exits
  2 for warnings alone, so a monitoring script can watch a forum's configuration
- `approval-queue-plus:validate` reports a clean up that can never run: switching it on while
  leaving *Delete User Agent data after* empty makes the scheduled prune decline every time, which
  is safe but silent
- the add-on now tells the Admin API add-on that none of its settings are credentials, so they can
  be reported rather than withheld

## 3.6.2 (2026-10-04)

- fixed: registration failed for any client that sent no `User-Agent` header — browsers always
  send one, so ordinary sign-ups were unaffected, but a scripted or API registration was refused
- fixed: clearing the *Delete User Agent data after* box no longer deletes every approved user's
  recorded data on the next scheduled clean up

## 3.6.1 (2026-09-22)

- the Cloudflare location in the Approval Queue now ends with the continent — for example
  *Sydney, NSW, Australia (Oceania)* — for users who registered on 3.6.0 or later, which is when
  continent began to be recorded

## 3.6.0 (2026-09-20)

- now requires XenForo 2.2.0 or later
- fixed: Cloudflare continent information was never recorded, because the `CF-IPContinent` header
  was misspelled internally — it is now recorded, and shown on the Cloudflare location test page
  under *Tools > Checks and Tests*
- fixed: the user agent clean up option's on/off setting is now honoured — switching it off did
  not previously stop the scheduled clean up from running
- the `viewUserAgents` permission is now `hampelAqpViewUserAgents`, so that it cannot clash with
  a permission of the same name from another add-on — existing permission settings are
  migrated automatically during the upgrade

## 3.5.3 (2025-12-11)

- run enqueuePostUpgradeCleanUp during upgrades if we're running XF2.3+

## 3.5.2 (2024-10-16)

- assert admin permission on tools

## 3.5.1 (2024-05-12)

- fix bug with upgrade process which saw some columns not being created in some circumstances

## 3.5.0 (2024-05-11)

- rename table to xf_aqp_user_data
- if Cloudflare headers found, store registration location data in database and display in Approval Queue
- Admin tool to test Cloudflare headers

## 3.4.0 (2024-05-10)

- rename permission phrase to make it clear where the permission comes from
- rename user agent table from xf_user_agent to xf_aqp_user_agent to comply with resource standards
- bugfix - gracefully handle case where user agent doesn't exist for user
- fix approval_item_user template modification for compatibility with XF 2.3

## 3.3.1 (2020-06-09)

- use regex for template modification approvalQueuePlusRemoveTopInfo to avoid clobbering changes made by other addons

## 3.3.0 (2019-10-02)

- automatic cleanup of old UserAgent data for approved users
- hide core user item header from queue
- split out user email into separate line

## 3.2.0 (2019-10-01)

- added User Agent to the list of fields shown
- added permissions for who can view User Agents

## 3.1.0 (2019-10-01)

- added Time Zone to list of fields shown

## 3.0.0 (2019-03-06)

- XF 2.1 version with new addon_id since Approval Queue has changed completely in XF v2.1
- Changed name to "Approval Queue Plus" and addon_id to `Hampel/ApprovalQueuePlus`
- this version reverts some of the core changes made to XF v2.1 by showing additional information about users awaiting 
  approval
- added admin option to reverse the default sort order of the Approval Queue

## 2.0.0a (2018-08-13)

- XF 2.0 version (re-release, new addon_id)
- now known as `Hampel/ModeratedUsers`

## 2.0.0 (2018-05-19)

- XF 2.0 version

## 1.0.0 (2015-06-18)

- initial working version (XF 1.5)
