# Approval Queue Plus for XenForo 2.2+

Adds the information needed to judge a pending registration to the Approval Queue itself, so a
moderator can decide without opening each account. There is also an admin option to reverse the
queue's default sort order.

## Requirements

XenForo 2.2.0 or later. No PHP requirement beyond the one XenForo itself enforces for your
version, and no third-party libraries.

## What it adds

The following is shown for each user awaiting approval:

- Username
- Date joined
- Date of last activity
- Registration IP
- Cloudflare location (see the note below)
- Profile location (user entered)
- Cloudflare time zone (see the note below)
- Profile time zone (user selected)
- User Agent

Email addresses, IP addresses and user agents are each shown subject to the viewing moderator's
own permissions.

## Cloudflare location data

**Location and time zone are only available if your site is behind Cloudflare, and only once
visitor location headers are switched on** — they are off by default, and without them these rows
simply do not appear.

In the Cloudflare dashboard, go to `Rules > Transform Rules > Managed Transforms` and enable
*Add visitor location headers*.

An admin page under *Tools > Checks and Tests* shows which of those headers reached the server on
your own request, which is the quickest way to tell a Cloudflare configuration problem from an
add-on problem.

Location data is recorded at the moment a user registers, so it is only present for accounts that
registered after this add-on was installed.

---

By [Simon Hampel](https://xenforo.com/community/members/sim.4264/).

- [Addon: Approval Queue Plus](https://xenforo.com/community/resources/approval-queue-plus.7411/)
- [Discussion and support: Approval Queue Plus](https://xenforo.com/community/threads/approval-queue-plus.170554/)
