<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * The Approval Queue as XenForo assembles it, rather than this add-on's macro on its own.
 *
 * `UserInfoMacroTest` renders the macro directly, which settles every field and permission gate
 * but says nothing about whether the macro is reached: that depends on
 * `approvalQueuePlusApprovalItemUser` still matching the call it replaces in `approval_item_user`,
 * and `TemplateModificationsTest` can only read the apply count XenForo recorded the last time it
 * compiled that template. Between them they can both pass while a moderator sees nothing new -
 * which is the failure this add-on is most exposed to, because a XenForo upgrade causes it and
 * nothing logs it.
 *
 * So this dispatches the real route, renders the real reply, and looks for this add-on's output in
 * the page.
 *
 * **Only positive assertions belong here.** The page shows every pending registration on the forum
 * - up to fifty of them - so on a development install it carries other members' rows too, and
 * `assertStringNotContainsString()` against the whole page would be answering for them rather than
 * for the member this test made. What is absent for a member with no recorded data is settled in
 * `UserInfoMacroTest`, where the render holds one user and nothing else.
 *
 * Needs framework 5.23 or later: 5.19 for `createUserAccount()`, 5.2 for `dispatch()`, 5.20 for
 * the two-prefix `assertNoUnresolvedPhrases()`, and 5.23 for the failure messages below - a
 * message passed to `assertSeeText()` before it was discarded without a word.
 *
 * 5.23 also made `assertNoUnresolvedPhrases()` read the page parameters as well as the rendered
 * HTML, which is worth more here than it looks: this page sets its title through `<xf:title>`, and
 * a phrase that came out unresolved there was invisible to the old call.
 */
class ApprovalQueuePageTest extends TestCase
{
	use UsesDatabaseTransactions;

	const USER_AGENT = 'Mozilla/5.0 (queue page test)';

	/**
	 * The queue is ordered by `content_date` ascending and shows the first fifty, and
	 * `content_date` is the registration date. A forum under test may already hold more pending
	 * registrations than that, so this member registers long enough ago to be first in the queue
	 * whatever else is in it.
	 */
	const REGISTERED = 946684800; // 2000-01-01

	protected function setUp(): void
	{
		parent::setUp();

		$this->fakesErrors();

		// XF\Pub\Controller\ApprovalQueueController::actionIndex() rebuilds the unapproved counts
		// into the data registry whenever its own count disagrees, which it does in an isolated
		// app. That row is written by the live forum too, and a held transaction racing it is what
		// deadlocked UserDeletionRemovesUserDataTest - so the registry is faked for this test.
		$this->fakesRegistry();
	}

	public function test_the_queue_page_shows_what_this_add_on_recorded()
	{
		$user = $this->pendingMemberWithRecordedData();

		$this->actingAsModerator();

		$reply = $this->dispatch('approval-queue');

		$this->assertReplyIsView($reply);
		$this->assertReplyTemplate($reply, 'approval_queue');

		$html = $this->renderReply($reply);

		$this->assertNoTemplateErrors();
		$this->assertNoUnresolvedPhrases($html, ['hampel_aqp_', 'approval_queue_plus_']);

		// assertSeeText() rather than assertStringContainsString(): PHPUnit's own string
		// assertions print the whole subject, and the subject here is a page carrying up to fifty
		// queue items - a miss printed ten thousand characters with the value at the end of them.
		// The third argument is the failure message, which framework 5.23 added to these
		// assertions; before it, one passed here was discarded in silence and said nothing.
		$this->assertSeeText($html, $user->username,
			'the member this test registered is not in the queue at all, so nothing below here'
				. ' answers for anything');

		$this->assertSeeText($html, self::USER_AGENT,
			'the user agent is absent, which means the macro was not reached:'
				. ' approvalQueuePlusApprovalItemUser has stopped matching the call it replaces in'
				. ' approval_item_user. That is the failure a XenForo upgrade causes, and it is'
				. ' silent everywhere else');

		$this->assertSeeText($html, 'Sydney, NSW, Australia (Oceania)',
			'the location row is absent or assembled differently - the macro builds it from'
				. ' cf_location, with the country and continent names resolved through'
				. ' Data\CountryCodes');

		$this->assertSeeText($html, 'Australia/Sydney',
			'the timezone is absent from the location block');
	}

	/**
	 * What `approvalQueuePlusRemoveTopInfo` empties is not a phrase: `$headerPhraseHtml` in
	 * `approval_item_user` holds XenForo's own registration IP link and email address, and this
	 * add-on's macro shows both itself, behind the same two permissions. The modification exists
	 * so that they appear once rather than twice.
	 *
	 * So the count is the assertion. A moderator holding `bypassUserPrivacy` sees the email in the
	 * macro's own row; if the modification stops applying, XenForo's header renders it again above
	 * the same item and the count goes to two. That is the whole visible symptom, and it is one
	 * nothing logs.
	 */
	public function test_the_email_appears_once_rather_than_twice_on_an_item()
	{
		$user = $this->pendingMemberWithRecordedData();

		$this->actingAsModerator();

		$text = $this->textOf($this->renderReply($this->dispatch('approval-queue')));

		$this->assertSame(1, substr_count($text, $user->email),
			'the email should appear once, in this add-on\'s own row. Twice means'
				. ' approvalQueuePlusRemoveTopInfo has stopped emptying XenForo\'s item header;'
				. ' none means the macro was not reached at all');
	}

	/**
	 * `general.view` is the one that is easy to miss, and its absence looks like the queue
	 * refusing a moderator: every public controller asserts it in `preDispatchType()` before any
	 * controller of its own runs, so without it the route answers 403 and nothing of this add-on
	 * is reached. A macro render needs none of these, which is why `UserInfoMacroTest` does not
	 * grant it.
	 */
	protected function actingAsModerator(): void
	{
		$this->actingAsMember(['is_moderator' => true], ['general' => [
			'view' => true,
			'bypassUserPrivacy' => true,
			'viewIps' => true,
			'hampelAqpViewUserAgents' => true,
			'approveRejectUser' => true,
		]]);
	}

	/**
	 * A member awaiting approval, with the row this add-on would have written for them. XenForo
	 * puts them in the queue itself: `XF\Entity\User::_postSave()` sees a member inserted as
	 * `moderated` and saves an `xf_approval_queue` row dated from their registration.
	 */
	protected function pendingMemberWithRecordedData(): \XF\Entity\User
	{
		$user = $this->createUserAccount(null, [
			'user_state' => 'moderated',
			'register_date' => self::REGISTERED,
		]);

		$this->createEntity('Hampel\ApprovalQueuePlus:UserData', [
			'user_id' => $user->user_id,
			'user_agent' => self::USER_AGENT,
			'iso_code' => 'AU',
			'cf_location' => [
				'city' => 'Sydney',
				'country' => 'Australia',
				'country_code' => 'AU',
				'continent' => 'Oceania',
				'continent_code' => 'OC',
				'region_code' => 'NSW',
				'timezone' => 'Australia/Sydney',
			],
		]);

		return $user;
	}
}
