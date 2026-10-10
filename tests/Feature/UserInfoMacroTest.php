<?php namespace Tests\Feature;

use Hampel\ApprovalQueuePlus\Entity\UserData;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use XF\Entity\User;
use XF\Util\Ip;

/**
 * Everything this add-on adds to the Approval Queue is this one macro, which a template
 * modification swaps into `approval_item_user` - so rendering it is the nearest a test gets to
 * what a moderator sees.
 *
 * Needs framework 5.6 or later: 5.4 for the `$xf` parameter, without which every permission check
 * in this macro reads a null `$xf.visitor` and the gated rows render their else branch, and 5.6
 * for `assertNoUnresolvedPhrases()`.
 */
class UserInfoMacroTest extends TestCase
{
	const EMAIL = 'pending@example.com';
	const IP = '203.0.113.9';
	const USER_AGENT = 'Mozilla/5.0 (pending registrant)';

	/**
	 * The three rows a moderator sees only with a permission, and the permission for each.
	 */
	public static function gatedRows(): array
	{
		return [
			'email'      => ['bypassUserPrivacy', self::EMAIL],
			'ip'         => ['viewIps', self::IP],
			'user agent' => ['hampelAqpViewUserAgents', self::USER_AGENT],
		];
	}

	protected function setUp(): void
	{
		parent::setUp();

		// a template error is otherwise written to the forum's real error log
		$this->fakesErrors();
	}

	public function test_a_moderator_with_every_permission_sees_every_row()
	{
		$text = $this->textOfRender($this->allPermissions(), $this->pendingUser());

		$this->assertStringContainsString('PendingUser', $text);
		foreach (self::gatedRows() as [, $value])
		{
			$this->assertStringContainsString($value, $text);
		}
		$this->assertStringContainsString('Sydney, NSW, Australia', $text);
		$this->assertStringContainsString('Australia/Sydney', $text);
		$this->assertStringContainsString('Down Under', $text);
		$this->assertStringContainsString('Europe/London', $text);
	}

	/**
	 * Each gate is proved on its own: withhold one permission and only that row goes. The other
	 * two are asserted present, so a render that produced nothing cannot pass this.
	 */
	#[DataProvider('gatedRows')]
	public function test_each_gated_row_is_hidden_without_its_own_permission($permission, $value)
	{
		$permissions = $this->allPermissions();
		unset($permissions['general'][$permission]);

		$text = $this->textOfRender($permissions, $this->pendingUser());

		$this->assertStringNotContainsString($value, $text);
		foreach (self::gatedRows() as [$other, $otherValue])
		{
			if ($other !== $permission)
			{
				$this->assertStringContainsString($otherValue, $text);
			}
		}
	}

	/**
	 * Accounts created in the admin panel, imported, or registered before the add-on was installed
	 * have no row at all. The rows built from it must simply be absent.
	 */
	public function test_a_user_with_no_recorded_data_renders_without_those_rows()
	{
		$user = $this->pendingUser();
		$user->hydrateRelation('AqpData', null);

		$text = $this->textOfRender($this->allPermissions(), $user);

		$this->assertStringContainsString('PendingUser', $text);
		$this->assertStringContainsString('Down Under', $text);
		$this->assertStringNotContainsString(self::USER_AGENT, $text);
		$this->assertStringNotContainsString((string) \XF::phrase('hampel_aqp_location'), $text);
		$this->assertStringNotContainsString((string) \XF::phrase('hampel_aqp_timezone'), $text);
	}

	public function test_the_location_row_leaves_out_parts_cloudflare_did_not_send()
	{
		$user = $this->pendingUser(['country' => 'Australia', 'country_code' => 'AU']);

		$text = $this->textOfRender($this->allPermissions(), $user);

		$this->assertMatchesRegularExpression('/' . preg_quote((string) \XF::phrase('hampel_aqp_location'), '/')
			. ' Australia /', $text, 'a country alone should render without stray separators');
	}

	public function test_the_location_row_ends_with_the_continent()
	{
		$user = $this->pendingUser([
			'city' => 'Sydney',
			'region_code' => 'NSW',
			'country' => 'Australia',
			'continent' => 'Oceania',
		]);

		$text = $this->textOfRender($this->allPermissions(), $user);

		$this->assertStringContainsString('Sydney, NSW, Australia (Oceania)', $text);
	}

	/**
	 * Rows recorded before 3.6.0 carry no continent - the header name was misspelled - and
	 * nothing backfills them. They must render as they always did.
	 */
	public function test_a_row_with_no_continent_shows_none()
	{
		$user = $this->pendingUser(['city' => 'Sydney', 'region_code' => 'NSW', 'country' => 'Australia']);

		$text = $this->textOfRender($this->allPermissions(), $user);

		$this->assertStringContainsString('Sydney, NSW, Australia', $text);
		$this->assertStringNotContainsString('Australia (', $text);
	}

	/**
	 * Country AQ and continent AN are both "Antarctica" - the one pair in the lookup tables that
	 * shares a name - and the row should not say it twice.
	 */
	public function test_a_continent_named_like_its_country_is_not_repeated()
	{
		$user = $this->pendingUser(['country' => 'Antarctica', 'continent' => 'Antarctica']);

		$text = $this->textOfRender($this->allPermissions(), $user);

		$this->assertStringContainsString('Antarctica', $text);
		$this->assertStringNotContainsString('(Antarctica)', $text);
	}

	/**
	 * A client that sent no User-Agent header leaves the column empty. The row must be omitted
	 * rather than rendered with a blank value beside its label.
	 */
	public function test_an_empty_user_agent_renders_no_row()
	{
		$user = $this->pendingUser();
		$user->AqpData->user_agent = '';

		$text = $this->textOfRender($this->allPermissions(), $user);

		$this->assertStringContainsString('PendingUser', $text);
		$this->assertStringNotContainsString((string) \XF::phrase('approval_queue_plus_user_agent'), $text);
	}

	protected function allPermissions(): array
	{
		return ['general' => [
			'bypassUserPrivacy' => true,
			'viewIps' => true,
			'hampelAqpViewUserAgents' => true,
		]];
	}

	protected function pendingUser(?array $cfLocation = null): User
	{
		$user = $this->makeEntity('XF:User', [
			'username' => 'PendingUser',
			'email' => self::EMAIL,
			'user_state' => 'moderated',
			'register_date' => 1700000000,
			'last_activity' => 1700000500,
			'timezone' => 'Europe/London',
		]);
		$user->setTrusted('user_id', 999);

		$user->hydrateRelation('Profile', $this->makeEntity('XF:UserProfile', ['location' => 'Down Under']));

		/** @var UserData $data */
		$data = $this->makeEntity('Hampel\ApprovalQueuePlus:UserData', [
			'user_agent' => self::USER_AGENT,
			'iso_code' => 'AU',
			'cf_location' => $cfLocation ?? [
				'city' => 'Sydney',
				'region_code' => 'NSW',
				'country' => 'Australia',
				'country_code' => 'AU',
				'timezone' => 'Australia/Sydney',
			],
		]);
		$user->hydrateRelation('AqpData', $data);

		return $user;
	}

	/**
	 * Render the macro as a moderator holding the given permissions, and return its text with the
	 * markup stripped and whitespace collapsed - the template spreads one row over several lines.
	 */
	protected function textOfRender(array $permissions, User $user): string
	{
		$this->actingAsMember([], $permissions);

		$html = $this->renderMacro('public:approval_queue_plus_user_macros', 'user_info', [
			'user' => $user,
			'userIp' => Ip::stringToBinary(self::IP),
		]);

		$this->assertNoTemplateErrors();

		// A phrase XenForo cannot find renders as its own key, which no assertion below would
		// notice - they look for values, not labels. This covers all of this add-on's phrases on
		// every render in this class, including the one survivor from before the naming
		// convention. Checked on the raw markup: neither prefix appears in it as ordinary text,
		// so the macro's own template name cannot be mistaken for an unresolved phrase.
		$this->assertNoUnresolvedPhrases($html, 'hampel_aqp_');
		$this->assertNoUnresolvedPhrases($html, 'approval_queue_plus_');

		return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
	}
}
