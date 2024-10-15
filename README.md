Approval Queue Plus for XenForo 2.x
===================================

This XenForo 2.x addon adds extra info to the entries in the Approval Queue to help make approval decisions easier.

There is also an admin option to reverse the default sort order of the Approval Queue.

The following information is added to the queue display when approving users:

* Username
* Date joined
* Date of last activity
* Registration IP
* Cloudflare location (see note below)
* Profile location (user entered)
* Cloudflare time zone (see note below)
* Profile time zone (user selected)
* User Agent

**Note:** Cloudflare location and time zone are only shown if the site is using Cloudflare. To enable display of this 
data, you must turn on _Managed Transforms_ in your Cloudflare admin console. Go to 
`Rules > Transform Rules > Managed Transforms` and enable _Add visitor location headers_.

Display of Email addresses, IP addresses and User Agents is controlled by moderator permissions.

By [Simon Hampel](https://xenforo.com/community/members/sim.4264/).

* [Addon: Approval Queue Plus](https://xenforo.com/community/resources/approval-queue-plus.7411/)
* [Discussion and support: Approval Queue Plus](https://xenforo.com/community/threads/approval-queue-plus.170554/)
