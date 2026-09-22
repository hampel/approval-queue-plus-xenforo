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
 * Needs framework 5.4 or later. Before it a render had no `$xf` parameter, and every permission
 * check in this macro reads `$xf.visitor`.
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

		return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
	}
}
